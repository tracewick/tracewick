<?php

declare(strict_types=1);

namespace Tracewick\Sampler;

/**
 * Why a no-op sampler is used instead of a real one.
 */
enum NullSamplerReason: string
{
    /**
     * Profiling was switched off, for example with `TRACEWICK_ENABLED=0`.
     */
    case Disabled = 'disabled';

    /**
     * ext-excimer is not loaded in this PHP process.
     */
    case ExtensionMissing = 'extension_missing';

    /**
     * The extension is loaded, but setting up the profiler failed.
     */
    case InitializationFailed = 'initialization_failed';
}
