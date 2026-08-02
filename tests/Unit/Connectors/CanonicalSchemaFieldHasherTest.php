<?php

namespace Tests\Unit\Connectors;

use App\Support\Connectors\AdobePaaS\AdobePaaSAttributeNormalizer;
use App\Support\Connectors\CanonicalSchemaFieldHasher;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CanonicalSchemaFieldHasherTest extends TestCase
{
    private AdobePaaSAttributeNormalizer $normalizer;

    private CanonicalSchemaFieldHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new AdobePaaSAttributeNormalizer;
        $this->hasher = new CanonicalSchemaFieldHasher;
    }

    #[Test]
    public function fixture_a_select_field_with_reversed_options_and_ignored_sort_order_key(): void
    {
        $raw = json_decode(
            <<<'JSON'
            {
              "attribute_code": "color",
              "default_frontend_label": "Color",
              "frontend_input": "select",
              "is_required": true,
              "scope": "global",
              "options": [
                {"label": "Red", "value": "red"},
                {"label": "Blue", "value": "blue"}
              ],
              "position": 10,
              "sort_order": 999
            }
            JSON,
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        );

        $expected = 'c682d3be9f513964755497790337d7135f392fa480e16c59285f1cca68d11ed0';
        $actual = $this->hasher->hash($this->normalizer->normalize($raw));

        $this->assertSame(64, strlen($expected));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $expected);
        $this->assertSame($expected, $actual);
    }

    #[Test]
    public function fixture_b_non_selectable_field_with_nulls(): void
    {
        $raw = json_decode(
            <<<'JSON'
            {
              "attribute_code": "sku_note",
              "default_frontend_label": null,
              "frontend_input": "text",
              "is_required": null,
              "scope": "store",
              "position": null
            }
            JSON,
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        );

        $expected = '265eb3b67e8cc47250f96be8dc2f8b38ae38113ef0ecb2e80a5e311d339efa26';
        $actual = $this->hasher->hash($this->normalizer->normalize($raw));

        $this->assertSame(64, strlen($expected));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $expected);
        $this->assertSame($expected, $actual);
    }

    #[Test]
    public function fixture_c_selectable_field_with_empty_options_list(): void
    {
        $raw = json_decode(
            <<<'JSON'
            {
              "attribute_code": "empty_select",
              "default_frontend_label": "Empty Select",
              "frontend_input": "select",
              "is_required": false,
              "scope": "global",
              "options": [],
              "position": null
            }
            JSON,
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        );

        $expected = 'a472885d1f35cdcc452bc490376d2b63b7cbd74f28a5b3503ef4b648f4336940';
        $actual = $this->hasher->hash($this->normalizer->normalize($raw));

        $this->assertSame(64, strlen($expected));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $expected);
        $this->assertSame($expected, $actual);
    }

    #[Test]
    public function fixture_e_unicode_label_with_slash(): void
    {
        $raw = json_decode(
            <<<'JSON'
            {
              "attribute_code": "unicode_note",
              "default_frontend_label": "Синій / Blue",
              "frontend_input": "text",
              "is_required": false,
              "scope": "store",
              "position": 0
            }
            JSON,
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        );

        $expected = '575118a7c91eed8301c1af0cebf5abc1b5179e8337e6c9c63ec9135f22ea7456';
        $actual = $this->hasher->hash($this->normalizer->normalize($raw));

        $this->assertSame(64, strlen($expected));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $expected);
        $this->assertSame($expected, $actual);
    }
}
