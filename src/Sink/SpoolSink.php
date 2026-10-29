<?php

declare(strict_types=1);

namespace Tracewick\Sink;

use Tracewick\Encoder\PprofEncoder;
use Tracewick\Exception\LogicException;
use Tracewick\Profile\Profile;
use Tracewick\Transport\Delivery;
use Tracewick\Transport\Payload;
use Tracewick\Transport\SpoolConfig;
use Tracewick\Transport\SpoolTransport;
use Tracewick\Transport\Transport;

/**
 * Writes profiles to a local spool directory, from which a separate process delivers them.
 *
 * This is the recommended setup for high-load services: writing a file takes microseconds and
 * does not depend on the backend being reachable (ADR 5, ADR 7). Each profile becomes one spool
 * file holding pprof and a header with the application name, the labels and the time window.
 * Labels are stored as the application set them; the process that delivers the file applies the
 * rules of its backend.
 *
 * By default files are written by a {@see SpoolTransport}.
 *
 * @see https://github.com/tracewick/tracewick/blob/main/docs/spool-format.md
 */
final readonly class SpoolSink implements Sink
{
    public const MEDIA_TYPE = 'application/vnd.tracewick.spool';

    private Transport $transport;

    private PprofEncoder $encoder;

    /**
     * @param string      $applicationName becomes the service name of every profile
     * @param SpoolConfig $config          the spool directory and its limits
     * @param ?Transport  $transport       writes the files; a {@see SpoolTransport} by default
     *
     * @throws LogicException when the application name is invalid
     */
    public function __construct(
        private string $applicationName,
        SpoolConfig $config,
        ?Transport $transport = null,
    ) {
        ApplicationName::assertValid($applicationName);
        $this->transport = $transport ?? new SpoolTransport($config);
        $this->encoder = new PprofEncoder();
    }

    public function send(Profile $profile): Delivery
    {
        try {
            if ($profile->isEmpty()) {
                return Delivery::skipped('The profile has no samples.');
            }

            $file = new SpoolFile(
                $this->applicationName,
                $profile->eventType,
                $profile->periodNs,
                $profile->startNs,
                $profile->endNs,
                $profile->labels->toArray(),
                SpoolFile::FORMAT_PPROF,
                $this->encoder->encode($profile),
            );

            return $this->transport->send(new Payload($file->toBytes(), self::MEDIA_TYPE));
        } catch (\Throwable $e) {
            return Delivery::failed(\sprintf('Cannot write the profile to the spool: %s', $e->getMessage()));
        }
    }
}
