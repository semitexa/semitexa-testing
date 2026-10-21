<?php

declare(strict_types=1);

namespace Semitexa\Testing\Traits;

use Semitexa\Core\Application;
use Semitexa\Core\Request;
use Semitexa\Testing\Data\TestResponse;

/**
 * Requests to the application from a test, in the process: the whole request
 * pipeline (routing, auth, validation, the handler, the response) runs, and no
 * server or network is involved.
 *
 *     $this->getJson('/api/orders/42')->assertOk()->assertJsonPath('data.id', 42);
 *     $this->postJson('/api/orders', ['sku' => 'A-1'], ['Authorization' => 'Bearer …'])->assertStatus(201);
 *
 * Cookies given with withCookie() go with every request after it, so a test
 * can carry a session; headers given to one call go with that call only and
 * win over the defaults (Accept, Content-Type, Host: localhost).
 */
trait CallsRoutes
{
    private ?Application $httpTestApplication = null;

    /** @var array<string, string> */
    private array $httpTestCookies = [];

    protected function withCookie(string $name, string $value): static
    {
        $this->httpTestCookies[$name] = $value;

        return $this;
    }

    /** @param array<string, string> $headers */
    protected function get(string $uri, array $headers = []): TestResponse
    {
        return $this->call('GET', $uri, headers: $headers);
    }

    /** @param array<string, string> $headers */
    protected function getJson(string $uri, array $headers = []): TestResponse
    {
        return $this->call('GET', $uri, headers: self::headersOver(['Accept' => 'application/json'], $headers));
    }

    /**
     * A form post.
     *
     * @param array<string, mixed> $fields
     * @param array<string, string> $headers
     */
    protected function post(string $uri, array $fields = [], array $headers = []): TestResponse
    {
        return $this->call('POST', $uri, post: $fields, content: http_build_query($fields), headers: self::headersOver(['Content-Type' => 'application/x-www-form-urlencoded'], $headers));
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    protected function postJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->json('POST', $uri, $data, $headers);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    protected function putJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->json('PUT', $uri, $data, $headers);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    protected function patchJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->json('PATCH', $uri, $data, $headers);
    }

    /** @param array<string, string> $headers */
    protected function deleteJson(string $uri, array $headers = []): TestResponse
    {
        return $this->json('DELETE', $uri, [], $headers);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    protected function json(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call(
            $method,
            $uri,
            content: $data === [] ? null : (string) json_encode($data, JSON_THROW_ON_ERROR),
            headers: self::headersOver(['Accept' => 'application/json', 'Content-Type' => 'application/json'], $headers),
        );
    }

    /**
     * Any request. The query string in $uri is parsed as the server would.
     *
     * @param array<string, mixed> $post
     * @param array<string, string> $headers
     */
    protected function call(string $method, string $uri, array $post = [], ?string $content = null, array $headers = []): TestResponse
    {
        $method = strtoupper($method);
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        $headers = self::headersOver(['Host' => 'localhost'], $headers);
        $cookies = $this->httpTestCookies;
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'Cookie') === 0) {
                foreach (explode(';', $value) as $pair) {
                    [$cookieName, $cookieValue] = array_pad(explode('=', trim($pair), 2), 2, '');
                    if ($cookieName !== '') {
                        $cookies[$cookieName] = $cookieValue;
                    }
                }
            }
        }

        // As the server adapter builds it: lowercase server keys, the path
        // alone in request_uri, the query parsed apart.
        $request = new Request(
            method: $method,
            uri: $uri,
            headers: $headers,
            query: $query,
            post: $post,
            server: ['request_method' => $method, 'request_uri' => $path, 'remote_addr' => '127.0.0.1'],
            cookies: $cookies,
            content: $content,
        );

        // handleRequest() resets per-request state itself. An exception it
        // lets out reaches the test, where the server would answer 500.
        return new TestResponse(($this->httpTestApplication ??= new Application())->handleRequest($request));
    }

    /**
     * The caller's headers over the defaults, names compared without case,
     * so `['accept' => 'text/html']` replaces the default Accept.
     *
     * @param array<string, string> $defaults
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function headersOver(array $defaults, array $headers): array
    {
        foreach ($defaults as $name => $value) {
            foreach (array_keys($headers) as $given) {
                if (strcasecmp((string) $given, $name) === 0) {
                    continue 2;
                }
            }
            $headers[$name] = $value;
        }

        return $headers;
    }
}
