<?php

namespace Tests\Unit\Connectors;

use App\Support\Connectors\ConnectorSchemaSourceEndpointPathValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorSchemaSourceEndpointPathValidatorTest extends TestCase
{
    private ConnectorSchemaSourceEndpointPathValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new ConnectorSchemaSourceEndpointPathValidator;
    }

    #[Test]
    #[DataProvider('validPathProvider')]
    public function accepts_valid_paths(string $path): void
    {
        $this->assertTrue($this->validator->isValid($path));
        $this->assertSame($path, $this->validator->normalize($path));
    }

    #[Test]
    #[DataProvider('invalidPathProvider')]
    public function rejects_invalid_paths(?string $path): void
    {
        $this->assertFalse($this->validator->isValid($path));

        if ($path !== null) {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Invalid endpoint path.');

            $this->validator->normalize($path);
        }
    }

    #[Test]
    public function normalize_strips_leading_slashes_consistently(): void
    {
        $this->assertSame('/V1/products/attributes', $this->validator->normalize('/V1/products/attributes'));
        $this->assertSame('/V1/products/attributes', $this->validator->normalize('//V1/products/attributes'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validPathProvider(): array
    {
        return [
            'adobe attributes' => ['/V1/products/attributes'],
            'nested resource' => ['/V1/categories/attributes'],
            'single segment' => ['/V1'],
        ];
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function invalidPathProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'absolute url' => ['https://example.com/V1/products/attributes'],
            'query string' => ['/V1/products/attributes?page=1'],
            'fragment' => ['/V1/products/attributes#top'],
            'credentials' => ['/V1/user@host/products'],
            'port segment' => ['/V1:8080/products'],
            'dot segment' => ['/V1/./products'],
            'parent segment' => ['/V1/../products'],
            'missing leading slash' => ['V1/products/attributes'],
        ];
    }
}
