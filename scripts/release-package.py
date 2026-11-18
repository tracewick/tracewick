#!/usr/bin/env python3
"""Build release inventories and verify signed assets or Composer package contents."""

import argparse
import hashlib
import io
import json
from pathlib import Path
import re
import stat
import subprocess
import zipfile

NAME = "tracewick/tracewick"
IDENTITY = "https://github.com/tracewick/tracewick/.github/workflows/release.yml@refs/heads/main"
ISSUER = "https://token.actions.githubusercontent.com"
ROOT = Path(__file__).resolve().parents[1]


def digest(data):
    return hashlib.sha256(data).hexdigest()


def encode(value):
    return (json.dumps(value, indent=2, sort_keys=True) + "\n").encode()


def validate_identity(version, reference):
    if not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?", version):
        raise ValueError("Expected a version without the v prefix")
    if not re.fullmatch(r"[a-f0-9]{40}", reference):
        raise ValueError("Expected the full Git commit reference")


def safe_path(name):
    if "\x00" in name or "\\" in name or any(p in ("", ".", "..") for p in name.split("/")):
        raise ValueError("Unsafe package path")


def zip_tree(data):
    """Ignore one enclosing directory, not file bytes or extra package files."""
    files, roots = {}, set()
    with zipfile.ZipFile(io.BytesIO(data)) as archive:
        for entry in archive.infolist():
            name = entry.orig_filename.rstrip("/") if entry.is_dir() else entry.orig_filename
            safe_path(name)
            if stat.S_ISLNK(entry.external_attr >> 16):
                raise ValueError("Symlinks are not release package files")
            parts = name.split("/", 1)
            roots.add(parts[0])
            if entry.is_dir():
                continue
            if len(parts) != 2 or parts[1] in files:
                raise ValueError("Missing archive root or duplicate package file")
            files[parts[1]] = archive.read(entry)
    if len(roots) != 1 or not files:
        raise ValueError("Expected one nonempty package root")
    return files


def directory_tree(directory):
    if not directory.is_dir() or directory.is_symlink():
        raise ValueError("Expected a package directory, not a symlink")
    result = {}
    for path in directory.rglob("*"):
        if path.is_symlink():
            raise ValueError("Symlinks are not release package files")
        if path.is_file():
            result[path.relative_to(directory).as_posix()] = path.read_bytes()
    return result


def validate_package(files):
    required = {"composer.json", "README.md", "LICENSE", "NOTICE", "bin/tracewick-drain"}
    if not required.issubset(files) or not any(p.startswith("src/") for p in files):
        raise ValueError("Incomplete PHP distribution")
    for path in files:
        safe_path(path)
        if path not in required and not path.startswith(("src/", "bin/", "examples/")):
            raise ValueError(f"Unexpected distribution file: {path}")
    composer = json.loads(files["composer.json"])
    if composer.get("name") != NAME:
        raise ValueError("Unexpected Composer package name")
    return composer


def contents(files, version, reference):
    return {"schema_version": 1, "name": NAME, "version": version, "reference": reference,
            "files": {path: digest(data) for path, data in sorted(files.items())}}


def sbom(files, version, reference):
    composer = validate_package(files)
    # Composer libraries do not ship the consumer's resolved dependency graph.
    # Record declared constraints as properties, never invented installed versions.
    properties = [{"name": "tracewick:source:commit", "value": reference}]
    for field in ("require", "suggest"):
        properties.extend({"name": f"composer:{field}:{name}", "value": value}
                          for name, value in sorted(composer.get(field, {}).items()))
    return {"bomFormat": "CycloneDX", "specVersion": "1.6", "version": 1,
            "metadata": {"component": {
                "type": "library", "name": NAME, "version": version,
                "purl": f"pkg:composer/{NAME}@{version}",
                "licenses": [{"license": {"id": composer["license"]}}],
                "properties": properties,
            }},
            "components": [{"type": "file", "bom-ref": "file:" + path, "name": path,
                            "hashes": [{"alg": "SHA-256", "content": digest(data)}]}
                           for path, data in sorted(files.items())]}


def asset_names(version):
    prefix = f"tracewick-{version}"
    return [prefix + ".zip", prefix + ".contents.json", prefix + ".sbom.json", "verify-release.py"]


def build(output, version, ref="HEAD"):
    reference = subprocess.check_output(
        ["git", "rev-parse", "--verify", "--end-of-options", ref + "^{commit}"], cwd=ROOT,
        text=True).strip()
    validate_identity(version, reference)
    archive = subprocess.check_output(
        ["git", "archive", "--format=zip", f"--prefix=tracewick-{version}/", reference], cwd=ROOT)
    files = zip_tree(archive)
    validate_package(files)
    names = asset_names(version)
    payloads = [archive, encode(contents(files, version, reference)),
                encode(sbom(files, version, reference)), Path(__file__).read_bytes()]
    output.mkdir(parents=True, exist_ok=True)
    for name, payload in zip(names, payloads):
        (output / name).write_bytes(payload)
    (output / "checksums.txt").write_text("".join(
        f"{digest(payload)}  {name}\n" for name, payload in zip(names, payloads)))
    return reference


def verify(artifacts, version, reference, unsigned=False, package=None):
    validate_identity(version, reference)
    manifest = artifacts / "checksums.txt"
    if not unsigned:
        subprocess.run([
            "cosign", "verify-blob", "--certificate-identity", IDENTITY,
            "--certificate-oidc-issuer", ISSUER,
            "--bundle", str(artifacts / "checksums.txt.sigstore.json"), str(manifest),
        ], check=True)
    entries = {}
    for line in manifest.read_text().splitlines():
        match = re.fullmatch(r"([a-f0-9]{64})  ([A-Za-z0-9_.-]+)", line)
        if not match or match[2] in entries:
            raise ValueError("Unsafe or duplicate checksum entry")
        entries[match[2]] = match[1]
    names = asset_names(version)
    if set(entries) != set(names):
        raise ValueError("Missing or unexpected release asset")
    for name in names:
        path = artifacts / name
        if path.is_symlink() or digest(path.read_bytes()) != entries[name]:
            raise ValueError(f"Checksum mismatch: {name}")
    files = zip_tree((artifacts / names[0]).read_bytes())
    validate_package(files)
    expected = contents(files, version, reference)
    if json.loads((artifacts / names[1]).read_bytes()) != expected:
        raise ValueError("Contents manifest does not match version, reference or archive")
    if json.loads((artifacts / names[2]).read_bytes()) != sbom(files, version, reference):
        raise ValueError("SBOM does not describe the distributed package")
    if package is not None:
        installed = zip_tree(package.read_bytes()) if package.is_file() else directory_tree(package)
        actual = {name: digest(data) for name, data in sorted(installed.items())}
        if actual != expected["files"]:
            raise ValueError("Package has missing, extra or changed files")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    builder = commands.add_parser("build", help="Create unsigned local assets; does not publish")
    builder.add_argument("--output", type=Path, required=True)
    builder.add_argument("--version", required=True)
    builder.add_argument("--ref", default="HEAD")
    checker = commands.add_parser("verify", help="Authenticate release assets and optionally a Composer dist")
    checker.add_argument("--artifacts", type=Path, required=True)
    checker.add_argument("--version", required=True)
    checker.add_argument("--reference", required=True)
    checker.add_argument("--package", type=Path, help="Installed directory or ZIP with one root directory")
    checker.add_argument("--unsigned", action="store_true", help="Local/PR fixtures only; no authenticity claim")
    args = parser.parse_args()
    if args.command == "build":
        reference = build(args.output, args.version, args.ref)
        print(f"Built unsigned package inventory for {args.version} at {reference}")
    else:
        verify(args.artifacts, args.version, args.reference, args.unsigned, args.package)
        print("Verified release contents" + (" (UNSIGNED fixture)" if args.unsigned else " and signature"))


if __name__ == "__main__":
    main()
