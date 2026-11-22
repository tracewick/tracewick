# Release SBOMs and signatures

This is optional verification of GitHub release assets. Normal Composer/Packagist
installation is unchanged and does not check these signatures. Existing releases
remain unsigned; the first signed release must complete the publishing job.

## Assets and scope

Each release provides `tracewick-<version>.zip`, a CycloneDX 1.6
`.sbom.json`, `checksums.txt` covering both files, and its Cosign bundle
`checksums.txt.sigstore.json`. The ZIP comes from the exact tag's Git export.

The SBOM inventories this package's files, hashes, license and source commit.
Composer `require`/`suggest` entries are declared constraints stored as properties,
not invented installed versions. This is not the consuming application's resolved
dependency graph or a vulnerability verdict.

## Verify a downloaded release

Install Cosign 3 (CI uses 3.1.4) and use a new directory. Choose an actual signed
release; the version below is an example, not a claim that an RC exists.

```bash
VER=1.0.0-rc.1
PREFIX="tracewick-$VER"
gh release download "v$VER" --repo tracewick/tracewick \
  --pattern "$PREFIX.zip" --pattern "$PREFIX.sbom.json" \
  --pattern checksums.txt --pattern checksums.txt.sigstore.json || exit 1
cosign verify-blob \
  --certificate-identity 'https://github.com/tracewick/tracewick/.github/workflows/release.yml@refs/heads/main' \
  --certificate-oidc-issuer 'https://token.actions.githubusercontent.com' \
  --bundle checksums.txt.sigstore.json checksums.txt || exit 1
sha256sum --check checksums.txt || exit 1
```

On macOS use `shasum -a 256 --check checksums.txt`. Stop on any failure before
extracting or running package code. No downloaded Python verifier is needed.

This authenticates files produced by our release workflow and detects replacement
of the archive or SBOM without a matching trusted signature. It does not protect
against malicious code accepted into main or compromise of the trusted workflow.
Cosign uses GitHub OIDC and an ephemeral key: no persistent signing key or new
signing secret is stored. Checking out a tag does not change the workflow identity
from `refs/heads/main`.

These checks apply to our attached release ZIP. GitHub/Composer ZIPs can encode
the same files differently and will not have the same archive checksum.
We do not provide automatic or installed-file verification for Composer.

## Maintainer procedure

Release-please still creates the version, changelog, tag and GitHub Release.
Only the main publishing job gets `id-token: write`; it checks the tag/main
ancestry, builds, signs, uploads, then downloads and verifies the assets.
The unsigned CI job checks packaging and SBOM generation without publishing.

If publication fails, the GitHub Release and Packagist tag may already exist.
Do not call it verified. Recover with the Release workflow on **main**, setting
`publish_tag` to that existing tag. The tagged source must contain this tooling;
do not retrofit old unsigned releases. Recovery replaces assets with `--clobber`.
The first normal release must confirm real OIDC signing and publication.
