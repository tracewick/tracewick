# Verify a release or Composer dist

The signed-release workflow adds optional verification assets to GitHub Releases.
It does not change normal `composer require tracewick/tracewick`, Packagist's dist
URL, package dependencies or runtime behavior. Composer does **not** run Cosign.
The existing v0.1.0 and v0.2.0 releases remain unsigned. The first signed release must finish
the new publishing job successfully; the examples below do not imply an RC exists.

## Assets and SBOM scope

Each signed release provides:

- `tracewick-<version>.zip`: files exported from the exact tag with `.gitattributes`.
- `tracewick-<version>.contents.json`: schema version, package name, release version,
  full commit reference and SHA-256 of every distributed file.
- `tracewick-<version>.sbom.json`: CycloneDX 1.6 inventory of those files, library
  license and declared Composer `require`/`suggest` properties.
- `verify-release.py`: standalone Python 3 verifier, excluded from the Composer dist.
- `checksums.txt` covering those four assets, plus its keyless Cosign bundle
  `checksums.txt.sigstore.json`.

The SBOM describes **this package**, not the consuming application's dependency
graph. `psr/log: ^1.1 || ^2.0 || ^3.0` remains a constraint; we do not list a guessed
resolved psr/log version, development tools, or optional extensions as installed
software. Generate an application SBOM from the application's own installed/locked
dependencies to assess that resolved environment.

GitHub's ZIP and our exported ZIP can differ in bytes and enclosing directory
name. The contents manifest permits those container differences, but requires
identical file bytes and the exact file set. Changed, missing and extra files,
unsafe ZIP paths, symlinks and non-regular files (such as named pipes) fail
verification. It verifies distribution files,
not filesystem permissions, the rest of vendor/, or a running process's memory.

## Authenticate the assets before executing the verifier

Install Cosign 3 (the workflow uses 3.1.4) and Python 3.12+. In a new directory,
choose an actual signed release and its expected full commit reference. Use a
trusted release/tag or, for an installed dist, the matching `composer.lock` entry:

```bash
VER=1.0.0-rc.1  # example only
REF='<full-40-character-commit-reference>'
IDENTITY='https://github.com/tracewick/tracewick/.github/workflows/release.yml@refs/heads/main'
ISSUER='https://token.actions.githubusercontent.com'
gh release download "v$VER" --repo tracewick/tracewick || exit 1
cosign verify-blob --certificate-identity "$IDENTITY" \
  --certificate-oidc-issuer "$ISSUER" \
  --bundle checksums.txt.sigstore.json checksums.txt || exit 1

# Authenticate the verifier itself and the other assets before running it.
sha256sum --check checksums.txt || exit 1
python3 verify-release.py verify --artifacts . --version "$VER" --reference "$REF"
```

On macOS use `shasum -a 256 --check`. Stop on any verification failure. Do not use
`--unsigned` for downloaded releases: that switch is for explicitly unsigned
local/PR fixtures only. The publishing job never uses it.

The exact identity ends in **refs/heads/main**, including manual recovery:
checking out a tag does not change the workflow's OIDC identity. Cosign uses an
ephemeral key and short-lived certificate; no new signing secret or persistent
private key is stored. Public bundles contain signatures, certificates and
verification material, not private keys or OIDC tokens.

## Check an installed Composer dist or a downloaded ZIP

After authenticating the verifier/assets above, use the expected version and
reference of the installed package, not a branch name:

```bash
# In the application's root, inspect the matching lock entry:
jq '.packages[] | select(.name == "tracewick/tracewick") |
    {version, reference: .dist.reference, url: .dist.url}' composer.lock

# Use absolute paths if the authenticated assets are in a different directory.
python3 /trusted/release-assets/verify-release.py verify \
  --artifacts /trusted/release-assets --version "$VER" --reference "$REF" \
  --package vendor/tracewick/tracewick
```

`--package` also accepts a GitHub/Packagist ZIP. Prefer-dist installs can match
exactly; source clones contain `.git` and intentionally fail the exact-set check.
Do not add exclusions to hide unexpected files. Project plugins or post-install
tools that modify package files also invalidate the comparison. This is an
optional verification step, not a new automatic Composer security guarantee.

## Maintainer procedure and recovery

The normal release-please workflow still creates the version, changelog, tag and
GitHub Release. The asset job runs only on main, checks out the actual tag,
requires main ancestry, builds from `git archive`, signs and verifies locally,
then uploads and downloads the assets to verify them again. It also downloads
GitHub's commit ZIP and checks its contents against the signed inventory.
Only this job receives `id-token: write`; the unsigned packaging CI job receives
read-only access and does not publish or sign. Existing GitHub App secrets are
used for their existing release-automation role, not as artifact-signing keys.

If publication fails, assets may be partial: release-please has already created
the GitHub Release and Packagist may already see the tag. Do not describe that
release as verified. Recover via `Release` workflow_dispatch on **main**, with
`publish_tag` set to the existing tag. Its tagged source must include the new
asset tooling; do not retrofit old unsigned tags. Recovery replaces assets with
`--clobber` and checks the downloaded result. There is no unsigned fallback.

Local validation, without creating tags, signing or publishing:

```bash
python3 scripts/release-package.py build --output build/release --version 0.0.0-snapshot
python3 scripts/release-package.py verify --artifacts build/release \
  --version 0.0.0-snapshot --reference "$(git rev-parse HEAD)" --unsigned
python3 -m unittest discover -s scripts -p 'test_*.py' -v
```

The snapshot label is a fixture, not an invented release. Releasing normally is
still necessary to validate GitHub OIDC/Fulcio/Rekor and remote asset access.
See [Proposed ADR 14](adr/0014-verifiable-release-contents.md) for the trade-off.
