<?php

declare(strict_types=1);

namespace Semitexa\Testing\Tests\Unit\Data;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\HttpResponse;
use Semitexa\Testing\Data\TestResponse;

final class TestResponseTest extends TestCase
{
    #[Test]
    public function it_reads_and_asserts_what_the_application_answered(): void
    {
        $response = new TestResponse(new HttpResponse('{"data":{"items":[{"id":7}]}}', 201, ['Content-Type' => 'application/json']));

        $response->assertStatus(201)
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('data.items.0.id', 7)
            ->assertSee('"id":7')
            ->assertDontSee('error');
        self::assertSame(['id' => 7], $response->json('data.items.0'));
        self::assertNull($response->header('X-Missing'));
    }

    #[Test]
    public function a_failed_assertion_says_what_came_back(): void
    {
        $response = new TestResponse(new HttpResponse('{"error":"not_found"}', 404));

        try {
            $response->assertOk();
            self::fail('a 404 passed assertOk');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString('not_found', $e->getMessage());
        }

        $this->expectException(AssertionFailedError::class);
        $response->assertJsonPath('data.id', 1);
    }
}
