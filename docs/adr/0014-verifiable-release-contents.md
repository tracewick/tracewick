# 14. Verifiable release contents and package SBOMs

- Status: Proposed
- Date: 2026-11-18
- Updated: 2026-11-18

## Context

Composer obtains this library from GitHub's commit ZIP through Packagist. A local
`git archive` can have different ZIP bytes despite identical package files: this
was reproduced against the published 0.2.0 dist. Signing only our own archive
would not authenticate an installed Composer dist with different ZIP encoding.

A library declares dependency constraints. Its development lock does not describe
the versions resolved by a consuming application, and those packages are not
bundled in this library's archive.

## Decision

Proposed for review; a signed release has not yet been published:

- Keep the existing release-please tags and Composer/Packagist distribution path.
- Publish a package ZIP, a schema-1 contents manifest, a CycloneDX package SBOM
  and a standalone verifier. Sign their SHA-256 checksum manifest with keyless
  Cosign. Only the publishing job on main receives OIDC permission.
- The contents manifest binds the package name, release version, full Git commit
  and every distributed file's SHA-256. Ignore the ZIP's enclosing directory name
  and compression; require an exact file set and reject symlinks, non-regular
  files and unsafe paths.
- The SBOM inventories distributed files and records Composer runtime constraints
  and suggestions as properties. It does not invent installed dependency versions
  or claim to replace an application's resolved SBOM.
- Verify the signed assets after upload and compare GitHub's commit ZIP with the
  same contents manifest. Publish instructions to verify installed dist contents.

## Consequences

One contents identity works across the release archive and Composer dist ZIP.
The verifier is itself covered by the signed checksum manifest; users authenticate
it before execution. The package stays free of release tooling and new runtime
dependencies. Schema changes must remain distinguishable from schema 1.

Composer does not verify Cosign signatures automatically. Verification is opt-in
and requires Cosign/Python tooling outside the host application's runtime. Old
releases remain unsigned; the first normal release must confirm the real OIDC
and publication path. No stability or profiling-performance gate is closed here.
