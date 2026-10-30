# 11. Random request sampling

- Status: Accepted
- Date: 2026-10-30

## Context

`ProfilerConfig::sampleRate` lets an application profile only a share of its units of work, such
as one request in ten. Something has to decide, for each unit of work, whether it is profiled.
The decision runs on every request, including the ones that are not profiled, so it must cost
next to nothing and never block.

Two ways were considered: a counter that profiles every N-th unit of work, and an independent
random draw for each one.

## Decision

`Profiler::start()` draws a uniform random number from 0 inclusive to 1 exclusive and profiles the
unit of work when the number is below the rate. No number is drawn for the rates 0 and 1.

The default source is `mt_rand() / (mt_getrandmax() + 1)`:

- `mt_rand()` is the Mersenne Twister built into PHP, seeded per process, uniform on every
  platform, and costs a few tens of nanoseconds. Since PHP 7.1 `rand()` is the same generator.
- The decision needs no secrecy, so a cryptographically secure source such as `random_int()`,
  which asks the operating system, would cost more for nothing.
- `Random\Randomizer::nextFloat()` would do the same, but it needs PHP 8.3 and PHP 8.2 is
  supported ([ADR 2](0002-supported-php-versions.md)).

A counter was rejected:

- Under PHP-FPM every request starts with a clean state, so a counter would have to live in
  shared memory such as APCu, with a write and a lock on every request.
- A counter is periodic. When the traffic is periodic too, such as a health check every tenth
  request or a load balancer that cycles through backends, every N-th unit of work can keep
  hitting the same kind of request and bias the profiles. Independent draws cannot line up with
  any pattern in the traffic. Sentry and the OpenTelemetry ratio sampler decide the same way.

## Consequences

- The number of profiled units of work varies around the expected value: with a rate of 0.1,
  1000 requests give about 100 profiles, between 82 and 119 in 95 % of cases. Shares within the
  profiles are unaffected, and those are what a flame graph shows.
- Absolute totals in a profile backend shrink by the rate, which [the documentation](../runtime.md)
  explains.
- A process that calls `mt_rand()` and then forks shares its random sequence with its children.
  PHP-FPM workers draw for the first time after the fork, so they are not affected.
- Forcing a profile for a single request, for example by a header, is a separate feature and can
  be added on top without changing this decision.
