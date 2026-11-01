<?php

declare(strict_types=1);

namespace Tracewick\Sink;

use Tracewick\Encoder\CompactStacksCodec;
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
 * This is the recommended setup for high-load services: writing a file is a local rename and
 * does not depend on the backend being reachable (ADR 5, ADR 7). Each profile becomes one spool
 * v2 file with a compact stack→weight payload ([ADR 13](../../docs/adr/0013-spool-v2-compact-payload.md)).
 * The file name carries a hash of the aggregation key ([ADR 12](../../docs/adr/0012-aggregate-before-delivery.md)).
 *
 * By default files are written by a {@see SpoolTransport}.
 *
 * @see https://github.com/tracewick/tracewick/blob/main/docs/spool-format.md
 */
final readonly class SpoolSink implements Sink
{
    public const MEDIA_TYPE = 'application/vnd.tracewick.spool';

    private Transport $transport;

    private CompactStacksCodec $codec;

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
        $this->codec = new CompactStacksCodec();
    }

    public function send(Profile $profile): Delivery
    {
        try {
            if ($profile->isEmpty()) {
                return Delivery::skipped('The profile has no samples.');
            }

            $labels = $profile->labels->toArray();
            $file = new SpoolFile(
                $this->applicationName,
                $profile->eventType,
                $profile->periodNs,
                $profile->startNs,
                $profile->endNs,
                $labels,
                SpoolFile::FORMAT_COMPACT_STACKS,
                $this->codec->encode($profile),
                SpoolFile::VERSION_2,
            );

            return $this->transport->send(new Payload($file->toBytes(), self::MEDIA_TYPE, [
                SpoolTransport::PARAM_AGGREGATION_KEY_HASH => SpoolAggregationKey::hash(
                    $this->applicationName,
                    $labels,
                    $profile->eventType,
                    $profile->periodNs,
                ),
            ]));
        } catch (\Throwable $e) {
            return Delivery::failed(\sprintf('Cannot write the profile to the spool: %s', $e->getMessage()));
        }
    }
}
