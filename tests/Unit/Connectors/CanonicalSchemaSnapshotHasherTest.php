<?php

namespace Tests\Unit\Connectors;

use App\Enums\ConnectorDiscoverySchemaValidationReason;
use App\Support\Connectors\AdobePaaS\AdobePaaSAttributeNormalizer;
use App\Support\Connectors\CanonicalSchemaFieldHash;
use App\Support\Connectors\CanonicalSchemaFieldHasher;
use App\Support\Connectors\CanonicalSchemaSnapshotHasher;
use App\Support\Connectors\Exceptions\ConnectorDiscoverySchemaValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CanonicalSchemaSnapshotHasherTest extends TestCase
{
    private AdobePaaSAttributeNormalizer $normalizer;

    private CanonicalSchemaFieldHasher $fieldHasher;

    private CanonicalSchemaSnapshotHasher $snapshotHasher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new AdobePaaSAttributeNormalizer;
        $this->fieldHasher = new CanonicalSchemaFieldHasher;
        $this->snapshotHasher = new CanonicalSchemaSnapshotHasher;
    }

    #[Test]
    public function fixture_d_snapshot_hash_from_field_hashes(): void
    {
        $hashA = $this->fieldHasher->hash($this->normalizer->normalize(json_decode(
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
              "position": 10
            }
            JSON,
            associative: false,
            depth: 512,
            flags: JSON_THROW_ON_ERROR,
        )));

        $hashB = $this->fieldHasher->hash($this->normalizer->normalize(json_decode(
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
        )));

        $expected = '0559d420286001846f0e4c8971ff8c332173bd4b8681b77bf8ee533a64cfb924';
        $actual = $this->snapshotHasher->hash([
            CanonicalSchemaFieldHash::create('color', $hashA),
            CanonicalSchemaFieldHash::create('sku_note', $hashB),
        ]);

        $this->assertSame(64, strlen($expected));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $expected);
        $this->assertSame($expected, $actual);

        $reversed = $this->snapshotHasher->hash([
            CanonicalSchemaFieldHash::create('sku_note', $hashB),
            CanonicalSchemaFieldHash::create('color', $hashA),
        ]);

        $this->assertSame($expected, $reversed);
    }

    #[Test]
    public function fixture_f_unicode_snapshot_pair(): void
    {
        $pair = CanonicalSchemaFieldHash::create(
            'ключ/α',
            str_repeat('0', 64),
        );

        $expected = '5dc4d5275351713af676d554a0b506d530557931ccddbb11d7e48df0e888c4fe';
        $actual = $this->snapshotHasher->hash([$pair]);

        $this->assertSame(64, strlen($expected));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $expected);
        $this->assertSame($expected, $actual);
    }

    #[Test]
    public function fixture_g_empty_snapshot(): void
    {
        $expected = '36b37da63258b0fc25c6a2385bdc447d1295a9c587886e5c5bf15addc37bc02d';
        $actual = $this->snapshotHasher->hash([]);

        $this->assertSame(64, strlen($expected));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $expected);
        $this->assertSame($expected, $actual);
    }

    #[Test]
    public function duplicate_external_field_key_fails(): void
    {
        try {
            $this->snapshotHasher->hash([
                CanonicalSchemaFieldHash::create('color', str_repeat('a', 64)),
                CanonicalSchemaFieldHash::create('color', str_repeat('b', 64)),
            ]);
            $this->fail('Expected validation exception');
        } catch (ConnectorDiscoverySchemaValidationException $exception) {
            $this->assertSame(ConnectorDiscoverySchemaValidationReason::DuplicateExternalFieldKey, $exception->reason);
            $this->assertSame('fields', $exception->path);
        }
    }

    #[Test]
    public function pagination_order_invariance_produces_same_snapshot_hash(): void
    {
        $items = [
            json_decode('{"attribute_code":"color","default_frontend_label":"Color","frontend_input":"select","is_required":true,"scope":"global","options":[{"label":"Red","value":"red"}],"position":10}', associative: false, depth: 512, flags: JSON_THROW_ON_ERROR),
            json_decode('{"attribute_code":"sku_note","default_frontend_label":null,"frontend_input":"text","is_required":null,"scope":"store","position":null}', associative: false, depth: 512, flags: JSON_THROW_ON_ERROR),
        ];

        $pairsA = [];
        foreach ($items as $item) {
            $field = $this->normalizer->normalize($item);
            $pairsA[] = CanonicalSchemaFieldHash::create(
                $field->externalFieldKey(),
                $this->fieldHasher->hash($field),
            );
        }

        $pairsB = [];
        foreach (array_reverse($items) as $item) {
            $field = $this->normalizer->normalize($item);
            $pairsB[] = CanonicalSchemaFieldHash::create(
                $field->externalFieldKey(),
                $this->fieldHasher->hash($field),
            );
        }

        $this->assertSame(
            $this->snapshotHasher->hash($pairsA),
            $this->snapshotHasher->hash($pairsB),
        );
    }

    #[Test]
    public function fixture_h_non_ascii_snapshot_pair_bytewise_sort_order(): void
    {
        $fields = [
            CanonicalSchemaFieldHash::create(
                'ä_field',
                str_repeat('b', 64),
            ),
            CanonicalSchemaFieldHash::create(
                'z_field',
                str_repeat('a', 64),
            ),
        ];

        $expected = '614119395247c5b610ca60ebfeed6718df847f1214351d2fc824155674b520cd';
        $actual = $this->snapshotHasher->hash($fields);

        $this->assertSame(64, strlen($expected));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $expected);
        $this->assertSame($expected, $actual);
        $this->assertSame($expected, $this->snapshotHasher->hash(array_reverse($fields)));
    }

    #[Test]
    public function snapshot_pairs_are_sorted_bytewise_not_locale_aware(): void
    {
        $hashZ = str_repeat('a', 64);
        $hashA = str_repeat('b', 64);

        $sorted = $this->snapshotHasher->hash([
            CanonicalSchemaFieldHash::create('z_field', $hashZ),
            CanonicalSchemaFieldHash::create('a_field', $hashA),
        ]);

        $alreadySorted = $this->snapshotHasher->hash([
            CanonicalSchemaFieldHash::create('a_field', $hashA),
            CanonicalSchemaFieldHash::create('z_field', $hashZ),
        ]);

        $this->assertSame($sorted, $alreadySorted);
    }
}
