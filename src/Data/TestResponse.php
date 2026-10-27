<?php

declare(strict_types=1);

namespace Semitexa\Testing\Data;

use PHPUnit\Framework\Assert;
use Semitexa\Core\HttpResponse;

/** What the application answered a test request, with the assertions a test asks of it. */
final class TestResponse
{
    public function __construct(public readonly HttpResponse $response) {}

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function body(): string
    {
        return $this->response->getContent();
    }

    /** A header's value, case-insensitive; null when it was not sent, or sent with no value. */
    public function header(string $name): ?string
    {
        foreach ($this->response->getHeaders() as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                if ($value === []) {
                    return null;
                }

                return is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
            }
        }

        return null;
    }

    /** The decoded JSON body, or the value at a dot path (`data.items.0.id`). */
    public function json(?string $path = null): mixed
    {
        $decoded = json_decode($this->body(), true);
        Assert::assertSame(JSON_ERROR_NONE, json_last_error(), 'The response is not JSON: ' . $this->excerpt());
        if ($path === null) {
            return $decoded;
        }
        foreach (explode('.', $path) as $key) {
            Assert::assertIsArray($decoded, "No '{$path}' in the response: " . $this->excerpt());
            Assert::assertArrayHasKey($key, $decoded, "No '{$path}' in the response: " . $this->excerpt());
            $decoded = $decoded[$key];
        }

        return $decoded;
    }

    public function assertStatus(int $expected): self
    {
        Assert::assertSame($expected, $this->status(), "Expected status {$expected}, got {$this->status()}: " . $this->excerpt());

        return $this;
    }

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    public function assertHeader(string $name, ?string $value = null): self
    {
        $actual = $this->header($name);
        Assert::assertNotNull($actual, "No {$name} header in the response.");
        if ($value !== null) {
            Assert::assertSame($value, $actual, "Header {$name}");
        }

        return $this;
    }

    public function assertJsonPath(string $path, mixed $expected): self
    {
        Assert::assertSame($expected, $this->json($path), "JSON at '{$path}'");

        return $this;
    }

    /** The text appears in the body, as is. */
    public function assertSee(string $text): self
    {
        Assert::assertStringContainsString($text, $this->body());

        return $this;
    }

    /** The text is absent from a body that has something in it: an empty body proves nothing. */
    public function assertDontSee(string $text): self
    {
        Assert::assertNotSame('', $this->body(), "The body is empty, so not seeing '{$text}' in it says nothing.");
        Assert::assertStringNotContainsString($text, $this->body());

        return $this;
    }

    private function excerpt(): string
    {
        $body = $this->body();

        return strlen($body) > 500 ? substr($body, 0, 500) . '…' : $body;
    }
}
