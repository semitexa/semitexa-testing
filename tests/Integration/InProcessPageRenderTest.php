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
 * least one must answer with an HTML document a layout drew, whatever its
 * status: a 404 page is rendered through the same templates, while the
 * framework's built-in error page (no template) does not count. Which pages answer 200
 * depends on the install: on the release clone, `/` and `/blog` are bound to
 * other hosts and answer 404 to a request without one, so "a 200" failed a
 * render that worked (preflight 2026-11-03).
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
            self::assertLessThan(500, $response->status(), $path . ' answered ' . $response->status() . ' in process');
            // Drawn by a layout: it declares a viewport, which the framework's
            // built-in error pages (ExceptionMapper, ErrorRenderer) do not.
            if (str_starts_with(strtolower(ltrim($response->body())), '<!doctype html') && str_contains($response->body(), 'name="viewport"')) {
                $rendered++;
            }
        }
        // verify:accept-test-change a rendered HTML document of any status counts, not only a 200: which pages answer 200 depends on the install (release clone: / and /blog are host-bound, 404 without a Host), while the regression guarded here is a 500
        self::assertGreaterThan(0, $rendered, 'no page answered with an HTML document a layout drew');
    }
}
