# 1. Record architecture decisions

- Status: Accepted
- Date: 2026-10-29
- Updated: 2026-11-01

## Context

Decisions that are expensive to reverse (PHP versions, formats, failure policy, layering) need a
short written record of why they were made, for humans and for agents working in the repo.

## Decision

Architecture Decision Records live in `docs/adr/`, following Michael Nygard's
[Documenting Architecture Decisions](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions).

- Files are named `NNNN-title-in-kebab-case.md` and numbered in order.
- Status is Proposed, Accepted, Deprecated, or Superseded.
- Reversing a decision → new ADR that supersedes the old one.
- **Allowed in place:** fix factual errors, mark implementation gaps, split Accepted vs Proposed
  sections, and shorten wording. Put an `Updated:` date when you do.
- A maintainer accepts or rejects ADRs in pull request review.
- Prefer plain language: Context, Decision, Consequences. No marketing tone.

Use [`template.md`](template.md) for new records.

## Consequences

People can see why the code looks the way it does before changing it. Each decision costs a short
doc and a review.
