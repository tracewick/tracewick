# 1. Record architecture decisions

- Status: Accepted
- Date: 2026-10-29

## Context

Tracewick is a long-lived open-source project with several contributors, including AI coding
agents. Decisions that are expensive to change (supported PHP versions, public API, data formats,
failure policy) need a durable, reviewable record that explains why they were made.

## Decision

We record architecture decisions as Architecture Decision Records (ADRs) in `docs/adr/`, using the
format described by Michael Nygard in
[Documenting Architecture Decisions](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions).

- Files are named `NNNN-title-in-kebab-case.md` and numbered sequentially.
- Each ADR has a status: Proposed, Accepted, Deprecated, or Superseded by a later ADR.
- An accepted ADR is not edited in substance; a change of mind is a new ADR that supersedes it.
- A maintainer accepts or rejects ADRs in pull request review.

Use [`template.md`](template.md) for new records.

## Consequences

Contributors and agents can learn why the code is shaped the way it is before changing it. Each
significant decision costs a short document and a review.
