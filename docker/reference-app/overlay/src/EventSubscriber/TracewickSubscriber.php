<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Tracewick\Profile\Labels;
use Tracewick\Runtime\Profiler;
use Tracewick\Runtime\ProfilerConfig;
use Tracewick\Sink\PyroscopeConfig;
use Tracewick\Sink\PyroscopeSink;
use Tracewick\Sink\Sink;
use Tracewick\Sink\SpoolSink;
use Tracewick\Transport\SpoolConfig;

/**
 * Added to the Symfony Demo by Tracewick's reference app: profiles every main request with
 * Tracewick, labelled with its route, the way an application without a framework integration
 * would wire the library by hand.
 *
 * The profile starts before the router runs, gets the route label once the router has matched
 * the request, and is delivered on kernel.terminate, after the response has been sent: Symfony
 * calls fastcgi_finish_request() under PHP-FPM, and RoadRunner has already answered the client.
 *
 * TRACEWICK_SINK picks the sink: "spool" writes files that tracewick-drain sends on, "pyroscope"
 * sends from the PHP process itself. Defaults are in config/packages/tracewick.yaml.
 */
final class TracewickSubscriber implements EventSubscriberInterface
{
    private const APPLICATION = 'symfony-demo';

    private readonly Profiler $profiler;

    public function __construct(
        LoggerInterface $logger,
        #[Autowire(env: 'TRACEWICK_RUNTIME')]
        string $runtime,
        #[Autowire(env: 'TRACEWICK_SINK')]
        string $sink,
        #[Autowire(env: 'TRACEWICK_SPOOL_DIR')]
        string $spoolDir,
        #[Autowire(env: 'PYROSCOPE_URL')]
        string $pyroscopeUrl,
    ) {
        $this->profiler = Profiler::create(
            $this->sink($sink, $spoolDir, $pyroscopeUrl, $logger),
            new ProfilerConfig(new Labels(['runtime' => $runtime])),
            logger: $logger,
        );
        // Delivers what is still running or buffered if PHP exits before kernel.terminate.
        $this->profiler->registerShutdown();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [
                ['startProfile', 4096],
                // The router listener runs at priority 32.
                ['labelRoute', 31],
            ],
            KernelEvents::TERMINATE => ['deliverProfile', -4096],
        ];
    }

    public function startProfile(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            // Requests the router cannot match keep this value.
            $this->profiler->start(['route' => 'unmatched', 'method' => $event->getRequest()->getMethod()]);
        }
    }

    public function labelRoute(RequestEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route');
        if ($event->isMainRequest() && \is_string($route)) {
            $this->profiler->label('route', $route);
        }
    }

    public function deliverProfile(TerminateEvent $event): void
    {
        $this->profiler->stop();
        $this->profiler->flush();
    }

    private function sink(string $sink, string $spoolDir, string $pyroscopeUrl, LoggerInterface $logger): Sink
    {
        if ('pyroscope' === $sink) {
            return new PyroscopeSink(new PyroscopeConfig($pyroscopeUrl, self::APPLICATION), logger: $logger);
        }

        return new SpoolSink(self::APPLICATION, new SpoolConfig($spoolDir));
    }
}
