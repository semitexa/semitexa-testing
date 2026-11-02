<?php

declare(strict_types=1);

namespace Semitexa\Testing\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Testing\Traits\CallsRoutes;

/**
 * An in-process request renders a Twig page (bench-inprocess-twig-500): with
 * no worker started, every page used to answer 500, because the registries a
 * render reads are wired by worker start-up listeners. CallsRoutes now runs
 * that phase once per process.
 *
 * The pages are whatever this install serves: its public GET routes without
 * path parameters, the first ten in path order. None may answer 500, and at
 * least one must answer 200 with a document.
 */
final class InProcessPageRenderTest extends TestCase
{
    use CallsRoutes;

    #[Test]
    public function public_pages_render_in_process_without_a_worker(): void
    {
        $paths = [];
        /** @var AttributeDiscovery $discovery */
        $discovery = ContainerFactory::get()->get(AttributeDiscovery::class);
        foreach ($discovery->getRoutes() as $route) {
            $path = $route['path'] ?? null;
            $methods = $route['methods'] ?? [$route['method'] ?? 'GET'];
            $public = ($route['accessType'] ?? null) === PayloadAccessType::Public || ($route['accessType'] ?? null) === 'public';
            if (is_string($path) && $public && is_array($methods) && in_array('GET', $methods, true) && !str_contains($path, '{') && !str_starts_with($path, '/__')) {
                $paths[$path] = true;
            }
        }
        $paths = array_keys($paths);
        sort($paths);
        self::assertNotSame([], $paths, 'no public page to render: this test would check nothing');

        $rendered = 0;
        foreach (array_slice($paths, 0, 10) as $path) {
            $response = $this->get($path, ['Accept' => 'text/html']);
            self::assertNotSame(500, $response->status(), $path . ' answered 500 in process');
            if ($response->status() === 200 && str_contains(strtolower($response->body()), '<html')) {
                $rendered++;
            }
        }
        self::assertGreaterThan(0, $rendered, 'no page answered 200 with a document');
    }
}
