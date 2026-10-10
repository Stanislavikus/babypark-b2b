<?php

namespace Tests\Unit\Connectors\Transport;

use PHPUnit\Framework\Assert;
use Throwable;

trait AssertsTransportSecretsSafely
{
    private const CANARY = 'CANARY_SECRET_MARKER_4B2A2A';

    protected function assertExceptionDoesNotLeakCanary(Throwable $exception): void
    {
        $this->assertStringNotContainsString(self::CANARY, $exception->getMessage());
        $this->assertStringNotContainsString(self::CANARY, (string) $exception);

        foreach ((array) $exception as $property => $value) {
            if (is_string($value)) {
                Assert::assertStringNotContainsString(self::CANARY, $value, "Property {$property} leaked canary.");
            }
        }

        $previous = $exception->getPrevious();
        while ($previous !== null) {
            $this->assertStringNotContainsString(self::CANARY, $previous->getMessage());
            $previous = $previous->getPrevious();
        }

        foreach ($exception->getTrace() as $frame) {
            foreach ($frame['args'] ?? [] as $arg) {
                if (is_string($arg)) {
                    Assert::assertStringNotContainsString(self::CANARY, $arg, 'Trace argument leaked canary.');
                }
            }
        }

        Assert::assertStringNotContainsString(self::CANARY, $exception->getTraceAsString());
    }

    protected function canaryHeader(): string
    {
        return 'Bearer '.self::CANARY;
    }

    protected function canaryBody(): string
    {
        return 'body-'.self::CANARY;
    }

    protected function canaryQuery(): string
    {
        return 'canary='.self::CANARY;
    }
}
