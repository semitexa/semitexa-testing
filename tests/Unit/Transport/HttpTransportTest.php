<?php

declare(strict_types=1);

namespace Semitexa\Testing\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Client\FakeHttpClient;
use Semitexa\Core\Http\Client\HttpResponse;
use Semitexa\Core\Http\Client\HttpTransportKind;
use Semitexa\Core\Http\Exception\HttpTransportException;
use Semitexa\Testing\Data\TestCaseDescriptor;
use Semitexa\Testing\Transport\HttpTransport;

/** The real-HTTP test transport, over the framework's client instead of its own curl. */
final class HttpTransportTest extends TestCase
{
    #[Test]
    public function an_array_body_goes_as_json_and_the_answer_comes_back_whole(): void
    {
        $http = (new FakeHttpClient())->queue(new HttpResponse(201, '{"id":7}', ['content-type' => ['application/json'], 'x-trace' => ['a', 'b']]));

        $result = (new HttpTransport('http://app.test/', $http))->send(self::case('POST', '/api/items', ['name' => 'x']));

        $request = $http->recorded()[0];
        self::assertSame(['POST', 'http://app.test/api/items', '{"name":"x"}'], [$request->method, $request->url, $request->body]);
        self::assertSame('application/json', $request->header('Content-Type'));
        self::assertSame(201, $result->statusCode);
        self::assertSame('{"id":7}', $result->body);
        self::assertSame('b', $result->headers['x-trace'], 'a repeated header keeps its last value');
        self::assertFalse($http->recordedOptions()[0]->retry->appliesTo('GET'), 'the server under test answers once');
    }

    #[Test]
    public function a_content_type_of_the_case_is_kept_and_a_get_sends_no_body(): void
    {
        $http = (new FakeHttpClient())->queue(HttpResponse::make(200, 'ok'), HttpResponse::make(200, 'ok'));
        $transport = new HttpTransport('http://app.test', $http);

        $transport->send(new TestCaseDescriptor('form', 'POST', '/f', ['Content-Type' => 'text/plain'], 'raw', 200));
        $transport->send(self::case('GET', '/g', ['ignored' => true]));

        self::assertSame('text/plain', $http->recorded()[0]->header('Content-Type'));
        self::assertSame('', $http->recorded()[1]->body);
    }

    #[Test]
    public function broken_unicode_in_a_body_is_sent_not_thrown(): void
    {
        $http = (new FakeHttpClient())->queue(HttpResponse::make(400, 'bad'));

        $result = (new HttpTransport('http://app.test', $http))->send(self::case('POST', '/x', ['name' => "\xED\xA0\x80"]));

        self::assertSame(400, $result->statusCode);
        self::assertStringContainsString('\ufffd', $http->recorded()[0]->body);
    }

    #[Test]
    public function no_answer_is_status_zero(): void
    {
        $http = (new FakeHttpClient())->queue(new HttpTransportException(HttpTransportKind::Connect, 'GET', 'http://app.test/x', 'Connection refused'));

        $result = (new HttpTransport('http://app.test', $http))->send(self::case('GET', '/x'));

        self::assertSame([0, ''], [$result->statusCode, $result->body]);
    }

    private static function case(string $method, string $path, mixed $body = null): TestCaseDescriptor
    {
        return new TestCaseDescriptor('case', $method, $path, [], $body, 200);
    }
}
