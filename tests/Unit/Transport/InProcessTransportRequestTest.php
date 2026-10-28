<?php

declare(strict_types=1);

namespace Semitexa\Testing\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Request;
use Semitexa\Testing\Data\TestCaseDescriptor;
use Semitexa\Testing\Transport\InProcessTransport;

/** The request an in-process call builds must read as the same request sent over HTTP. */
final class InProcessTransportRequestTest extends TestCase
{
    /**
     * The path's own query was never parsed, a body's values stayed ints
     * (getQuery() reads strings only), and a body after a path query made
     * '/search?q=shop?page=2'.
     */
    #[Test]
    public function the_query_is_read_from_the_path_and_the_body_as_over_http(): void
    {
        $plain = $this->request('/search?q=shop', null);
        $body = $this->request('/items', ['page' => 2]);
        $both = $this->request('/search?q=shop', ['page' => 2]);

        self::assertSame(['shop', '2', 'shop', '2'], [$plain->getQuery('q'), $body->getQuery('page'), $both->getQuery('q'), $both->getQuery('page')]);
        self::assertSame('q=shop&page=2', $both->getQueryString());
    }

    /** Header names are case-insensitive: 'cOoKiE' carried no cookie, and the session was a new one. */
    #[Test]
    public function the_cookie_header_is_found_whatever_its_case(): void
    {
        $request = $this->request('/', null, ['cOoKiE' => 'semitexa_session=abc; theme=dark']);

        self::assertSame(['abc', 'dark'], [$request->getCookie('semitexa_session'), $request->getCookie('theme')]);
    }

    /** @param array<string, string> $headers */
    private function request(string $path, mixed $body, array $headers = []): Request
    {
        $transport = (new \ReflectionClass(InProcessTransport::class))->newInstanceWithoutConstructor();
        $case = new TestCaseDescriptor('probe', 'GET', $path, $headers, $body, 200);

        return (fn (TestCaseDescriptor $case): Request => $this->buildRequest($case))->call($transport, $case);
    }
}
