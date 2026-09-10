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
    public function rejects_invalid_utf8(): void
    {
        $this->assertFalse($this->validator->isValid("/V1/\xC3\x28/products"));
    }

    #[Test]
    public function rejects_ascii_control_characters(): void
    {
        $this->assertFalse($this->validator->isValid("/V1/products\x07/attributes"));
    }

    #[Test]
    public function rejects_backslashes(): void
    {
        $this->assertFalse($this->validator->isValid('/V1\\products\\attributes'));
    }

    #[Test]
    public function rejects_scheme_relative_paths(): void
    {
        $this->assertFalse($this->validator->isValid('//evil.example.com/V1/products'));
    }

    #[Test]
    public function rejects_percent_encoded_traversal_segments(): void
    {
        $this->assertFalse($this->validator->isValid('/V1/%2e%2E/products'));
        $this->assertFalse($this->validator->isValid('/V1/%2e/products'));
        $this->assertFalse($this->validator->isValid('/V1/%2E/products'));
    }

    #[Test]
    public function rejects_encoded_slash_and_backslash_segments(): void
    {
        $this->assertFalse($this->validator->isValid('/V1/foo%2Fbar'));
        $this->assertFalse($this->validator->isValid('/V1/foo%2fbar'));
        $this->assertFalse($this->validator->isValid('/V1/foo%5Cbar'));
        $this->assertFalse($this->validator->isValid('/V1/foo%5cbar'));
    }

    #[Test]
    public function rejects_double_and_triple_encoded_traversal_segments(): void
    {
        $this->assertFalse($this->validator->isValid('/V1/%252e%252e/products'));
        $this->assertFalse($this->validator->isValid('/V1/%252E/products'));
        $this->assertFalse($this->validator->isValid('/V1/%25252e%25252e/products'));
    }

    #[Test]
    public function rejects_double_encoded_separator_segments(): void
    {
        $this->assertFalse($this->validator->isValid('/V1/foo%252Fbar'));
        $this->assertFalse($this->validator->isValid('/V1/foo%252fbar'));
        $this->assertFalse($this->validator->isValid('/V1/foo%255Cbar'));
        $this->assertFalse($this->validator->isValid('/V1/foo%255cbar'));
    }

    #[Test]
    public function accepts_percent_encoded_space_in_segment(): void
    {
        $path = '/V1/product%20attributes';

        $this->assertTrue($this->validator->isValid($path));
        $this->assertSame($path, $this->validator->normalize($path));
    }

    #[Test]
    public function accepts_normal_adobe_endpoint_path(): void
    {
        $path = '/V1/products/attributes';

        $this->assertTrue($this->validator->isValid($path));
        $this->assertSame($path, $this->validator->normalize($path));
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
            'double leading slash' => ['//V1/products/attributes'],
        ];
    }
}
