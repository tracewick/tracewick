# Security Policy

## Supported versions

Tracewick has no stable release yet. Until 1.0.0, only the latest release receives security fixes.

## Reporting a vulnerability

Please **do not** report security vulnerabilities in public issues, discussions or pull requests.

Report them privately through
[GitHub Security Advisories](https://github.com/tracewick/tracewick/security/advisories/new).
Include a description of the issue, the affected versions, and steps to reproduce it.

We aim to acknowledge a report within 3 business days and to agree on a disclosure timeline with
you. Once a fix is released, we publish an advisory and credit you unless you prefer otherwise.

## Scope

Tracewick runs inside production PHP processes, so we treat the following as security issues:

- leaking secrets or personal data (for example request parameters) into profiles or labels;
- remote code execution or file writes outside the configured destination;
- a way for untrusted input to crash or stall the host application through the profiler.
