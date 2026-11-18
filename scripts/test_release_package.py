"""Release verification against actual package exports and damaged alternatives."""

import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("release_package", ROOT / "scripts/release-package.py")
PACKAGE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PACKAGE)


class ReleasePackageTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name)
        self.assets = self.directory / "assets"
        self.version = "0.0.0-snapshot"
        self.reference = PACKAGE.build(self.assets, self.version)
        self.names = PACKAGE.asset_names(self.version)

    def verify(self, package=None, reference=None):
        PACKAGE.verify(self.assets, self.version, reference or self.reference, True, package)

    def rehash(self):
        (self.assets / "checksums.txt").write_text("".join(
            f"{hashlib.sha256((self.assets / name).read_bytes()).hexdigest()}  {name}\n"
            for name in self.names))

    def installed(self):
        # Only extract the controlled output of our local git archive, never hostile test ZIPs.
        with zipfile.ZipFile(self.assets / self.names[0]) as archive:
            archive.extractall(self.directory / "installed")
        return self.directory / "installed" / f"tracewick-{self.version}"

    def test_export_and_inventory_are_complete_and_do_not_resolve_dev_dependencies(self):
        self.verify()
        document = json.loads((self.assets / self.names[2]).read_bytes())
        names = {c["name"] for c in document["components"]}
        self.assertIn("composer.json", names)
        self.assertIn("bin/tracewick-drain", names)
        self.assertNotIn("verify-release.py", names)
        self.assertFalse(any(p.startswith(("tests/", "vendor/", "scripts/", ".github/")) for p in names))
        self.assertNotIn("phpunit/phpunit", json.dumps(document))
        props = document["metadata"]["component"]["properties"]
        self.assertIn({"name": "composer:require:psr/log", "value": "^1.1 || ^2.0 || ^3.0"}, props)

    def test_different_zip_encoding_and_root_preserve_package_identity(self):
        other = self.directory / "github-style.zip"
        with zipfile.ZipFile(self.assets / self.names[0]) as source, zipfile.ZipFile(
                other, "w", compression=zipfile.ZIP_STORED) as target:
            for entry in source.infolist():
                if not entry.is_dir():
                    target.writestr("different-github-root/" + entry.filename.split("/", 1)[1], source.read(entry))
        self.assertNotEqual(other.read_bytes(), (self.assets / self.names[0]).read_bytes())
        self.verify(other)

    def test_installed_directory_matches(self):
        self.verify(self.installed())

    def test_changed_missing_and_extra_installed_files_are_rejected(self):
        package = self.installed()
        readme = package / "README.md"
        original = readme.read_bytes()
        for mode in ("changed", "missing", "extra"):
            with self.subTest(mode=mode):
                readme.write_bytes(original)
                extra = package / "extra.php"
                extra.unlink(missing_ok=True)
                if mode == "changed":
                    readme.write_bytes(b"altered")
                elif mode == "missing":
                    readme.unlink()
                else:
                    extra.write_bytes(b"unexpected")
                with self.assertRaisesRegex(ValueError, "missing, extra or changed"):
                    self.verify(package)

    def test_wrong_commit_is_rejected(self):
        with self.assertRaisesRegex(ValueError, "Contents manifest"):
            self.verify(reference="f" * 40)

    def test_tampered_archive_is_rejected_before_parsing(self):
        (self.assets / self.names[0]).write_bytes(b"not a ZIP")
        with self.assertRaisesRegex(ValueError, "Checksum mismatch"):
            self.verify()

    def test_fabricated_resolved_dependency_is_rejected(self):
        path = self.assets / self.names[2]
        doc = json.loads(path.read_bytes())
        doc["components"].append({"type": "library", "name": "psr/log", "version": "3.0.2"})
        path.write_text(json.dumps(doc))
        self.rehash()
        with self.assertRaisesRegex(ValueError, "SBOM"):
            self.verify()

    def test_contents_inventory_omission_is_rejected(self):
        path = self.assets / self.names[1]
        doc = json.loads(path.read_bytes())
        doc["files"].pop("README.md")
        path.write_text(json.dumps(doc))
        self.rehash()
        with self.assertRaisesRegex(ValueError, "Contents manifest"):
            self.verify()

    def test_traversal_duplicate_and_symlink_zip_members_are_rejected(self):
        for kind in ("traversal", "duplicate", "symlink", "nul"):
            with self.subTest(kind=kind):
                data = io.BytesIO()
                with zipfile.ZipFile(data, "w") as archive:
                    if kind == "traversal":
                        archive.writestr("root/../outside", b"bad")
                    elif kind == "duplicate":
                        archive.writestr("root/file", b"one")
                        with self.assertWarns(UserWarning):
                            archive.writestr("root/file", b"two")
                    elif kind == "symlink":
                        member = zipfile.ZipInfo("root/link")
                        member.external_attr = (stat.S_IFLNK | 0o777) << 16
                        archive.writestr(member, "outside")
                    else:
                        archive.writestr("root/fileXignored", b"data")
                payload = data.getvalue().replace(b"root/fileXignored", b"root/file\x00ignored")
                with self.assertRaises(ValueError):
                    PACKAGE.zip_tree(payload)

    def test_symlink_installed_file_is_rejected(self):
        package = self.installed()
        (package / "extra").symlink_to(package / "README.md")
        with self.assertRaisesRegex(ValueError, "Symlinks"):
            self.verify(package)

    def test_extra_named_pipe_is_rejected_without_reading_it(self):
        package = self.installed()
        os.mkfifo(package / "extra.php")
        with self.assertRaisesRegex(ValueError, "Non-regular"):
            self.verify(package)

    def test_non_regular_zip_member_is_rejected(self):
        data = io.BytesIO()
        with zipfile.ZipFile(data, "w") as archive:
            member = zipfile.ZipInfo("root/file.php")
            member.external_attr = (stat.S_IFIFO | 0o600) << 16
            archive.writestr(member, b"content")
        with self.assertRaisesRegex(ValueError, "Non-regular"):
            PACKAGE.zip_tree(data.getvalue())

    def test_verifier_asset_is_authenticated_too(self):
        (self.assets / "verify-release.py").write_text("changed verifier")
        with self.assertRaisesRegex(ValueError, "Checksum mismatch"):
            self.verify()

    def test_bad_checksum_entries_and_missing_asset_are_rejected(self):
        manifest = self.assets / "checksums.txt"
        original = manifest.read_text()
        for extra in ("0" * 64 + "  ../outside\n", original.splitlines()[0] + "\n"):
            manifest.write_text(original + extra)
            with self.assertRaises(ValueError):
                self.verify()
        manifest.write_text("\n".join(original.splitlines()[:-1]) + "\n")
        with self.assertRaisesRegex(ValueError, "Missing or unexpected"):
            self.verify()

    def test_signature_failure_is_fatal_by_default(self):
        with patch.object(PACKAGE.subprocess, "run", side_effect=subprocess.CalledProcessError(1, "cosign")):
            with self.assertRaises(subprocess.CalledProcessError):
                PACKAGE.verify(self.assets, self.version, self.reference)
