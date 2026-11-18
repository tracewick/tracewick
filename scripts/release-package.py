#!/usr/bin/env python3
"""Build a tagged Composer package, its CycloneDX SBOM and SHA-256 checksums."""

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
PREFIX = "tracewick"
ROOT = Path(__file__).resolve().parents[1]
REQUIRED = {"composer.json", "README.md", "LICENSE", "NOTICE", "bin/tracewick-drain"}
ALLOWED_ROOTS = ("src/", "bin/", "examples/")


def digest(data):
    return hashlib.sha256(data).hexdigest()


def build(output, version, ref="HEAD"):
    if not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?", version):
        raise ValueError("Expected a version without the v prefix")
    reference = subprocess.check_output(
        ["git", "rev-parse", "--verify", "--end-of-options", ref + "^{commit}"],
        cwd=ROOT, text=True).strip()
    archive = subprocess.check_output(
        ["git", "archive", "--format=zip", f"--prefix={PREFIX}-{version}/", reference], cwd=ROOT)
    # This archive comes from our Git tree, not an arbitrary downloaded package.
    with zipfile.ZipFile(io.BytesIO(archive)) as source:
        files = {}
        for entry in source.infolist():
            if entry.is_dir():
                continue
            if stat.S_IFMT(entry.external_attr >> 16) not in (0, stat.S_IFREG):
                raise ValueError("Release packages must contain regular files")
            files[entry.filename.split("/", 1)[1]] = source.read(entry)
    if not REQUIRED.issubset(files) or not any(p.startswith("src/") for p in files):
        raise ValueError("Incomplete PHP distribution")
    for path in files:
        if path not in REQUIRED and not path.startswith(ALLOWED_ROOTS):
            raise ValueError(f"Unexpected distribution file: {path}")
    composer = json.loads(files["composer.json"])
    if composer["name"] != NAME:
        raise ValueError("Unexpected Composer package name")
    properties = [{"name": "tracewick:source:commit", "value": reference}]
    for field in ("require", "suggest"):
        properties.extend({"name": f"composer:{field}:{name}", "value": value}
                          for name, value in sorted(composer.get(field, {}).items()))
    # Libraries declare constraints; only a consuming application knows its resolved versions.
    sbom = {"bomFormat": "CycloneDX", "specVersion": "1.6", "version": 1,
            "metadata": {"component": {
                "type": "library", "name": NAME, "version": version,
                "purl": f"pkg:composer/{NAME}@{version}",
                "licenses": [{"license": {"id": composer["license"]}}],
                "properties": properties,
            }},
            "components": [{"type": "file", "bom-ref": "file:" + path, "name": path,
                            "hashes": [{"alg": "SHA-256", "content": digest(data)}]}
                           for path, data in sorted(files.items())]}
    assets = {f"{PREFIX}-{version}.zip": archive,
              f"{PREFIX}-{version}.sbom.json": (json.dumps(sbom, indent=2, sort_keys=True) + "\n").encode()}
    output.mkdir(parents=True, exist_ok=True)
    for name, data in assets.items():
        (output / name).write_bytes(data)
    (output / "checksums.txt").write_text("".join(
        f"{digest(data)}  {name}\n" for name, data in assets.items()))
    return reference


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--version", required=True)
    parser.add_argument("--ref", default="HEAD")
    args = parser.parse_args()
    reference = build(args.output, args.version, args.ref)
    print(f"Built unsigned release assets for {args.version} at {reference}")
