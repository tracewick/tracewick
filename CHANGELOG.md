# Changelog

## [0.2.0](https://github.com/tracewick/tracewick/compare/v0.1.0...v0.2.0) (2026-11-09)


### Documentation

* mark Tracewick 0.2.x as beta ([ddf2c35](https://github.com/tracewick/tracewick/commit/ddf2c350f3e23e55bd9c483b1287d2d99c1c31c0))

## 0.1.0 (2026-11-03)


### Added

* **diagnostics:** host counters, empty vs skipped, cardinality and PII warn ([#44](https://github.com/tracewick/tracewick/issues/44)) ([af277ff](https://github.com/tracewick/tracewick/commit/af277ff357cf7f8ce807047ed955e3d0b9981ebf))
* **encoder:** add pprof encoder ([#17](https://github.com/tracewick/tracewick/issues/17)) ([ff9f3d3](https://github.com/tracewick/tracewick/commit/ff9f3d3c3200d2604947a5369a7d4d4c321075dd))
* **encoder:** add the collapsed stack encoder ([1c0fb92](https://github.com/tracewick/tracewick/commit/1c0fb92a44dca490d21fc21d837c389bfbe7fe04))
* **encoder:** add the speedscope encoder ([#16](https://github.com/tracewick/tracewick/issues/16)) ([9f2a98b](https://github.com/tracewick/tracewick/commit/9f2a98b00655aa5125eb3f804e39e7e9961da005))
* **profile:** add the immutable profile model ([1c0fb92](https://github.com/tracewick/tracewick/commit/1c0fb92a44dca490d21fc21d837c389bfbe7fe04))
* **runtime:** aggregate compact spool files before Pyroscope upload ([#39](https://github.com/tracewick/tracewick/issues/39)) ([9e4fb6f](https://github.com/tracewick/tracewick/commit/9e4fb6fcfd61ddac7761631425281c83237171e4))
* **runtime:** auto_prepend bootstrap with process profiler holder ([#49](https://github.com/tracewick/tracewick/issues/49)) ([db593e0](https://github.com/tracewick/tracewick/commit/db593e0f50e34dd13e06203e6dbdb5fbeeaa1f97))
* **runtime:** count what happens to profiles and report problems to a PSR-3 logger ([#28](https://github.com/tracewick/tracewick/issues/28)) ([6eb287a](https://github.com/tracewick/tracewick/commit/6eb287abb0f85725baf9e3b238ba4987ece4f56f))
* **runtime:** drain the spool to Pyroscope with tracewick-drain ([#24](https://github.com/tracewick/tracewick/issues/24)) ([705b422](https://github.com/tracewick/tracewick/commit/705b42280781654bf10d2bdfe204ae1404a88c33))
* **runtime:** profile a configurable share of units of work ([#26](https://github.com/tracewick/tracewick/issues/26)) ([52b2789](https://github.com/tracewick/tracewick/commit/52b2789beee3b8abfddc13b6da579cd08d38dc68))
* **runtime:** profile units of work and deliver after them with Profiler ([#25](https://github.com/tracewick/tracewick/issues/25)) ([2cfe122](https://github.com/tracewick/tracewick/commit/2cfe1227651700355a695151f87c3fdd1e59b3df))
* **runtime:** safe alpha defaults for labels, sample rate, and stand ([#52](https://github.com/tracewick/tracewick/issues/52)) ([2c215c7](https://github.com/tracewick/tracewick/commit/2c215c70a615454419a901e0a11b7ee68b9f5d92))
* **runtime:** split UnitOfWorkProfiler and ContinuousProfiler ([#42](https://github.com/tracewick/tracewick/issues/42)) ([bce3857](https://github.com/tracewick/tracewick/commit/bce3857116b2b15f5e09bd405cc55fa33db05c52))
* **sampler:** add the sampler layer over ExcimerProfiler ([#10](https://github.com/tracewick/tracewick/issues/10)) ([715d4d2](https://github.com/tracewick/tracewick/commit/715d4d2ae51f4a31631585216e69681b02f967ab))
* **sampler:** cap Excimer memory with flush callback and stack aggregation ([#33](https://github.com/tracewick/tracewick/issues/33)) ([72e704d](https://github.com/tracewick/tracewick/commit/72e704d011f1e3e235d67b292a9c3ab9065ad133))
* **sampler:** expand kill switch precedence and recreate Excimer after fork ([#34](https://github.com/tracewick/tracewick/issues/34)) ([4efeae8](https://github.com/tracewick/tracewick/commit/4efeae8db674783b5432192303790f6e307e33dc))
* **sampler:** stabilize frame names and re-anchor SystemClock ([#43](https://github.com/tracewick/tracewick/issues/43)) ([88055b9](https://github.com/tracewick/tracewick/commit/88055b989b69d1436f4a45afed2ce343422ef91c))
* **sink:** deliver pprof over Pyroscope push with one sample type ([#41](https://github.com/tracewick/tracewick/issues/41)) ([9ea1993](https://github.com/tracewick/tracewick/commit/9ea19935eb11850da593b11f74bf2b948dffe417))
* **sink:** deliver profiles to Grafana Pyroscope ([#19](https://github.com/tracewick/tracewick/issues/19)) ([0657aa6](https://github.com/tracewick/tracewick/commit/0657aa68c8684f5f4d0f650f05c6e253e82bd5d1))
* **sink:** drop spool v1; keep compact-stacks only ([#40](https://github.com/tracewick/tracewick/issues/40)) ([bdde1c5](https://github.com/tracewick/tracewick/commit/bdde1c542b27e4a7ebc7bd67c2394b4220dcf449))
* **sink:** forward spool files to Pyroscope ([#23](https://github.com/tracewick/tracewick/issues/23)) ([0c1d5cf](https://github.com/tracewick/tracewick/commit/0c1d5cf2ebd09bb3dda4190769263a85f11761db))
* **sink:** write profiles to a local spool directory ([#20](https://github.com/tracewick/tracewick/issues/20)) ([d57c64d](https://github.com/tracewick/tracewick/commit/d57c64d90efd7e727549be9b0e69e808954de62c))
* **sink:** write spool v2 compact stacks with soft admission ([#38](https://github.com/tracewick/tracewick/issues/38)) ([f6c15be](https://github.com/tracewick/tracewick/commit/f6c15bed20a90e8dc6bfbfeea2ca2b59b74fc39b))
* **stand:** add docker-compose development stand ([9578d09](https://github.com/tracewick/tracewick/commit/9578d0926f00b8a94d76d5e37ecba19055aeaf1a))
* **stand:** add docker-compose development stand ([a329c02](https://github.com/tracewick/tracewick/commit/a329c02d02bfbbf28e1d9b089bf31ca56391b945))
* **stand:** add the Symfony Demo as a reference application ([5147a2b](https://github.com/tracewick/tracewick/commit/5147a2bd1ddb071192026faa564167329fc5ab4e))
* **transport:** classify delivery failures and harden the circuit store ([#35](https://github.com/tracewick/tracewick/issues/35)) ([773dce9](https://github.com/tracewick/tracewick/commit/773dce9e1b43a320c8e7ff31be306734a7ff1692))
* **transport:** read the spool directory alongside other readers ([#22](https://github.com/tracewick/tracewick/issues/22)) ([80a6ffb](https://github.com/tracewick/tracewick/commit/80a6ffbc58253aa27faed9c5c528225e4321292f))
* **transport:** share the circuit breaker state between the processes of a host ([#27](https://github.com/tracewick/tracewick/issues/27)) ([9a78f36](https://github.com/tracewick/tracewick/commit/9a78f36e169daa51d8aa7bf85c882b343abeeee6))


### Fixed

* **profile:** transactional BoundedProfileBuilder for caps ([#54](https://github.com/tracewick/tracewick/issues/54)) ([d34b130](https://github.com/tracewick/tracewick/commit/d34b130fe867e075aac752ddc9ef93bfbbdf18b5))
* **runtime:** ContinuousProfiler Excimer callback is flag-only (S6) ([#57](https://github.com/tracewick/tracewick/issues/57)) ([f45f5d0](https://github.com/tracewick/tracewick/commit/f45f5d0fea779783570cdc2b8e9d3e5209f6cddf))
* **runtime:** drain without window wait; byte budgets and O(1) codec ([#55](https://github.com/tracewick/tracewick/issues/55)) ([056c0cb](https://github.com/tracewick/tracewick/commit/056c0cb715796547a69341c7d87196f83978ff8a))
* **runtime:** harden Profiler start lifecycle and surface sampler diagnostics ([#32](https://github.com/tracewick/tracewick/issues/32)) ([b297510](https://github.com/tracewick/tracewick/commit/b297510f0653d5e7783e1dbca93c2d9fdd1806b5))
* **runtime:** resolve DNS before spool claim in drain (S7) ([#59](https://github.com/tracewick/tracewick/issues/59)) ([700335d](https://github.com/tracewick/tracewick/commit/700335d4c3bc9adb7ca4848a7d5cd3ebdad83cca))
* **runtime:** soft spool honesty and drain-scoped CardinalityGate (S8–S9) ([#60](https://github.com/tracewick/tracewick/issues/60)) ([5634718](https://github.com/tracewick/tracewick/commit/5634718b49bfa197be794303e00e09ed77028011))
* **stand:** enable the app profile when running the reference app setup ([028acff](https://github.com/tracewick/tracewick/commit/028acff16ce652788b865e2cb6856925edc0c708))
* **stand:** pin ext-excimer 1.2.6, the latest PECL release ([8b1641d](https://github.com/tracewick/tracewick/commit/8b1641dfdc58582724128bd63cc816fd39f0cbbd))
* **tests:** make RecordingLogger compatible with psr/log 1.x ([#51](https://github.com/tracewick/tracewick/issues/51)) ([f52dac4](https://github.com/tracewick/tracewick/commit/f52dac470531dcdb0c72301e15412f9ddc50befb))
* **tests:** widen circuit-breaker skip timing budget on chaos stand ([#58](https://github.com/tracewick/tracewick/issues/58)) ([d65db1a](https://github.com/tracewick/tracewick/commit/d65db1a20861079cacae76f4d65d232d853a1ed8))
* **transport:** harden AtomicFile, process-static log limits, and LOCK_NB ([#53](https://github.com/tracewick/tracewick/issues/53)) ([e4ea509](https://github.com/tracewick/tracewick/commit/e4ea5099051e98875f5f290352eeb16fa4eb9185))
* **transport:** memory circuit breaker by default; acquire/complete ([#56](https://github.com/tracewick/tracewick/issues/56)) ([cfbe305](https://github.com/tracewick/tracewick/commit/cfbe30557b9a452a0dd30b905ccc1d87c77a1c94))
* **transport:** retry EINTR and tighten request-path HTTP timeouts ([#36](https://github.com/tracewick/tracewick/issues/36)) ([ad9c8fa](https://github.com/tracewick/tracewick/commit/ad9c8fa5fe4d775eb59ea7353a2428844c8fb842))


### Chores

* release 0.1.0 ([110965e](https://github.com/tracewick/tracewick/commit/110965ed2c8ec0edcf0f79bc8206cb7a436004e1))

## Changelog

All notable changes to this project are documented in this file. It is generated from
[Conventional Commits](https://www.conventionalcommits.org/) by
[release-please](https://github.com/googleapis/release-please) when a release is cut; do not edit
it by hand in feature pull requests.

The project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Until 1.0.0 the
public API may change in any minor release.
