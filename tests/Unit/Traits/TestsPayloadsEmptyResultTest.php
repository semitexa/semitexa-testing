<?php

declare(strict_types=1);

namespace Semitexa\Testing\Tests\Unit\Traits;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\SkippedTest;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Testing\Attributes\TestablePayload;
use Semitexa\Testing\Contract\TransportInterface;
use Semitexa\Testing\Data\ResponseResult;
use Semitexa\Testing\Data\TestCaseDescriptor;
use Semitexa\Testing\PhpUnitExtension;
use Semitexa\Testing\Strategy\HttpMethodStrategy;
use Semitexa\Testing\Traits\TestsPayloads;

#[AsPublicPayload(path: '/fixture/no-strategies', methods: ['GET'])]
final class NoStrategiesFixturePayload
{
}

#[AsPublicPayload(path: '/fixture/every-method', methods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'])]
#[TestablePayload(strategies: [HttpMethodStrategy::class])]
final class EveryMethodFixturePayload
{
}

/**
 * A run that checked nothing is a skip either way; the message has to say
 * whether nothing was declared or the declared strategies had nothing to do.
 */
final class TestsPayloadsEmptyResultTest extends TestCase
{
    use TestsPayloads;

    private ReflectionProperty $transport;
    private mixed $previous;

    protected function setUp(): void
    {
        // No case is generated, so nothing is ever sent: a transport that
        // refuses to be used keeps the test off the application container.
        $this->transport = new ReflectionProperty(PhpUnitExtension::class, 'transport');
        $this->previous = $this->transport->getValue();
        $this->transport->setValue(null, new class implements TransportInterface {
            public function send(TestCaseDescriptor $case): ResponseResult
            {
                throw new \LogicException('No case should reach the transport.');
            }
        });
    }

    protected function tearDown(): void
    {
        $this->transport->setValue(null, $this->previous);
    }

    #[Test]
    public function a_payload_without_strategies_is_told_to_declare_some(): void
    {
        self::assertSame(
            'No contract strategies declared for ' . NoStrategiesFixturePayload::class . ': add #[TestablePayload(strategies: [...])] to the payload to check it.',
            $this->skipMessage(NoStrategiesFixturePayload::class),
        );
    }

    #[Test]
    public function declared_strategies_that_generate_no_case_are_not_called_undeclared(): void
    {
        self::assertSame(
            'No contract cases generated for ' . EveryMethodFixturePayload::class . ': its declared strategies found nothing to check.',
            $this->skipMessage(EveryMethodFixturePayload::class),
        );
    }

    /** @param class-string $payloadClass */
    private function skipMessage(string $payloadClass): string
    {
        try {
            $this->assertPayloadContract($payloadClass);
        } catch (SkippedTest $skipped) {
            return $skipped->getMessage();
        }
        self::fail("{$payloadClass} was not skipped.");
    }
}
