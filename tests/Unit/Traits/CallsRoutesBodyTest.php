<?php

declare(strict_types=1);

namespace Semitexa\Testing\Tests\Unit\Traits;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\HttpResponse;
use Semitexa\Testing\Data\TestResponse;
use Semitexa\Testing\Traits\CallsRoutes;

/** What the helpers hand to call(): the request as the server would have parsed it. */
final class CallsRoutesBodyTest extends TestCase
{
    use CallsRoutes;

    /** @var list<array{method: string, uri: string, post: array<string, mixed>, content: ?string}> */
    private array $calls = [];

    /**
     * @param array<string, mixed> $post
     * @param array<string, string> $headers
     */
    protected function call(string $method, string $uri, array $post = [], ?string $content = null, array $headers = []): TestResponse
    {
        $this->calls[] = ['method' => $method, 'uri' => $uri, 'post' => $post, 'content' => $content];

        return new TestResponse(new HttpResponse('ok'));
    }

    #[Test]
    public function a_form_post_carries_its_fields_as_strings_like_a_parsed_body(): void
    {
        // review: an int field went on as an int, and Request::getPost() read it as absent.
        $this->post('/items', ['id' => 7, 'tags' => ['a', 'b']]);

        self::assertSame(['id' => '7', 'tags' => ['a', 'b']], $this->calls[0]['post']);
        self::assertSame('id=7&tags%5B0%5D=a&tags%5B1%5D=b', $this->calls[0]['content']);
    }

    /** The separator came from arg_separator.output: set to &amp;, the second field arrived as amp;b. */
    #[Test]
    public function form_fields_are_joined_by_an_ampersand_whatever_the_ini_says(): void
    {
        $previous = (string) ini_get('arg_separator.output');
        ini_set('arg_separator.output', '&amp;');
        try {
            $this->post('/items', ['a' => '1', 'b' => '2']);
        } finally {
            ini_set('arg_separator.output', $previous);
        }

        self::assertSame('a=1&b=2', $this->calls[0]['content']);
        self::assertSame(['a' => '1', 'b' => '2'], $this->calls[0]['post']);
    }

    #[Test]
    public function an_empty_json_write_still_sends_a_payload_and_a_delete_sends_none(): void
    {
        $this->postJson('/items');
        $this->putJson('/items/1');
        $this->patchJson('/items/1');
        $this->deleteJson('/items/1');

        self::assertSame(['[]', '[]', '[]', null], array_column($this->calls, 'content'));
    }
}
