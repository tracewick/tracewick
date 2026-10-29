<?php

declare(strict_types=1);

namespace Tracewick\Tests\Support;

use Tracewick\Profile\Labels;
use Tracewick\Profile\Profile;
use Tracewick\Sampler\EventType;
use Tracewick\Sampler\Frame;
use Tracewick\Sampler\Sample;
use Tracewick\Sampler\SampleBatch;

/**
 * Hand-written profiles shared by the encoder golden-file tests.
 */
final class ProfileFixtures
{
    public const START_NS = 1_798_000_000_000_000_000;

    public const PERIOD_NS = 10_000_000;

    /**
     * A web request in a Symfony-like application: a controller that queries a database and
     * renders a template, with a closure and frames that differ only by the executing line.
     */
    public static function webRequest(): Profile
    {
        $main = new Frame(null, null, '/app/public/index.php', 5);
        $handle = new Frame('handle', 'App\Kernel', '/app/src/Kernel.php', 40);
        $controller = new Frame('show', 'App\Controller\PostController', '/app/src/Controller/PostController.php', 22);
        $find = new Frame('find', 'App\Repository\PostRepository', '/app/src/Repository/PostRepository.php', 31);
        $query = new Frame('query', 'PDO', null, 0);
        $render = new Frame('render', 'Twig\Environment', '/app/vendor/twig/twig/src/Environment.php', 300);
        $filter = new Frame('{closure}', null, '/app/src/Twig/AppExtension.php', 18, 17);
        $strlen = new Frame('strlen');

        return Profile::fromBatch(new SampleBatch(EventType::Wall, self::PERIOD_NS, self::START_NS, self::START_NS + 200_000_000, [
            new Sample([$main, $handle, $controller, $find, $query], 1, self::START_NS + 10_000_000),
            new Sample([$main, $handle, $controller, $find, $query], 3, self::START_NS + 20_000_000),
            new Sample([$main, $handle, $controller, new Frame('find', 'App\Repository\PostRepository', '/app/src/Repository/PostRepository.php', 35), $query], 2, self::START_NS + 50_000_000),
            new Sample([$main, $handle, $controller, $render], 4, self::START_NS + 70_000_000),
            new Sample([$main, $handle, $controller, $render, $filter, $strlen], 1, self::START_NS + 110_000_000),
            new Sample([$main, $handle], 1, self::START_NS + 120_000_000),
            new Sample([$main], 2, self::START_NS + 130_000_000),
        ]), new Labels(['service' => 'blog']));
    }

    /**
     * Stacks that need special handling: truncated stacks, a sample without frames, and names
     * containing the separators of text formats.
     */
    public static function edgeCases(): Profile
    {
        $deep = new Frame('recurse', 'App\Tree', '/app/src/Tree.php', 12);
        $odd = new Frame("weird;name\nwith\rbreaks", 'App\Odd');

        return Profile::fromBatch(new SampleBatch(EventType::Cpu, self::PERIOD_NS, self::START_NS, self::START_NS + 100_000_000, [
            new Sample([$deep, $deep, $deep], 5, self::START_NS + 10_000_000, true),
            new Sample([$deep, $deep], 1, self::START_NS + 20_000_000),
            new Sample([], 2, self::START_NS + 30_000_000),
            new Sample([new Frame(null, null, '/app/bin/console', 3), $odd], 1, self::START_NS + 40_000_000),
        ]));
    }
}
