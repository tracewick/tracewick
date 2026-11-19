"""Check actual Git exports, SBOM contents and standard checksum verification."""

import hashlib
import importlib.util
import io
import json
from pathlib import Path
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
        with zipfile.ZipFile(self.assets / f"{PACKAGE.PREFIX}-{self.version}.zip") as archive:
            self.files = {p.filename.split("/", 1)[1]: archive.read(p)
                          for p in archive.infolist() if not p.is_dir()}

    def test_actual_export_preserves_runtime_and_excludes_development_files(self):
        self.assertTrue(PACKAGE.REQUIRED.issubset(self.files))
        self.assertTrue(any(p.startswith("src/") for p in self.files))
        self.assertNotIn("infection.json5", self.files)
        self.assertFalse(any(p.startswith(("docs/", "tests/", "vendor/", "scripts/", ".github/"))
                             for p in self.files))

    def test_sbom_describes_distributed_bytes_and_declared_constraints(self):
        document = json.loads(next(self.assets.glob("*.sbom.json")).read_text())
        component = document["metadata"]["component"]
        self.assertEqual(PACKAGE.NAME, component["name"])
        self.assertEqual(self.version, component["version"])
        self.assertIn({"name": "tracewick:source:commit", "value": self.reference},
                      component["properties"])
        self.assertEqual({p: hashlib.sha256(data).hexdigest() for p, data in self.files.items()},
                         {c["name"]: c["hashes"][0]["content"] for c in document["components"]})
        self.assertTrue(all(c["type"] == "file" for c in document["components"]))
        composer = json.loads(self.files["composer.json"])
        expected = {f"composer:{field}:{name}": value for field in ("require", "suggest")
                    for name, value in composer.get(field, {}).items()}
        actual = {p["name"]: p["value"] for p in component["properties"]
                  if p["name"].startswith("composer:")}
        self.assertEqual(expected, actual)
        self.assertNotIn("phpunit/phpunit", json.dumps(document))

    def checksum_result(self):
        return subprocess.run(["sha256sum", "--check", "checksums.txt"], cwd=self.assets,
                              capture_output=True).returncode

    def test_standard_checksums_cover_both_assets(self):
        lines = (self.assets / "checksums.txt").read_text().splitlines()
        self.assertEqual({f"{PACKAGE.PREFIX}-{self.version}.zip",
                          f"{PACKAGE.PREFIX}-{self.version}.sbom.json"},
                         {line.split("  ")[1] for line in lines})
        self.assertEqual(0, self.checksum_result())

    def test_standard_checksum_verification_rejects_changed_or_missing_assets(self):
        for path in self.assets.glob(f"{PACKAGE.PREFIX}-*"):
            original = path.read_bytes()
            path.write_bytes(original + b"changed")
            self.assertNotEqual(0, self.checksum_result())
            path.unlink()
            self.assertNotEqual(0, self.checksum_result())
            path.write_bytes(original)

    def fixture_archive(self, remove=None, extra=None):
        stream = io.BytesIO()
        with zipfile.ZipFile(stream, "w") as archive:
            for name, data in self.files.items():
                if name != remove and not (remove == "src/" and name.startswith("src/")):
                    archive.writestr("root/" + name, data)
            if extra:
                archive.writestr("root/" + extra, b"development file")
        return stream.getvalue()

    def test_missing_runtime_file_aborts_build(self):
        for name in ("composer.json", "src/"):
            with self.subTest(name=name), patch.object(PACKAGE.subprocess, "check_output",
                    side_effect=[self.reference, self.fixture_archive(remove=name)]):
                with self.assertRaisesRegex(ValueError, "Incomplete"):
                    PACKAGE.build(self.directory / "invalid", self.version)

    def test_accidentally_exported_development_file_aborts_build(self):
        with patch.object(PACKAGE.subprocess, "check_output",
                          side_effect=[self.reference, self.fixture_archive(extra="tests/leaked.php")]):
            with self.assertRaisesRegex(ValueError, "Unexpected distribution file"):
                PACKAGE.build(self.directory / "invalid", self.version)

    def test_invalid_version_cannot_become_an_asset_path(self):
        with self.assertRaises(ValueError):
            PACKAGE.build(self.directory / "invalid", "../outside")


if __name__ == "__main__":
    unittest.main()
