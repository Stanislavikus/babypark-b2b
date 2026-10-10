<?php

namespace Tests\Unit;

use App\Support\CanonicalRegistry\CanonicalRegistryValidator;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChannelDecisionValidatorTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureRoot = sys_get_temp_dir().'/channel-decision-test-'.uniqid('', true);
        File::copyDirectory(base_path('docs/data'), $this->fixtureRoot.'/data');
        File::copy(
            base_path('docs/CANONICAL_PRODUCT_FIELD_REGISTRY.md'),
            $this->fixtureRoot.'/CANONICAL_PRODUCT_FIELD_REGISTRY.md',
        );
        File::copy(
            base_path('docs/IMPLEMENTATION_GAPS.md'),
            $this->fixtureRoot.'/IMPLEMENTATION_GAPS.md',
        );
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixtureRoot);

        parent::tearDown();
    }

    #[Test]
    public function production_registry_passes_with_channel_decisions(): void
    {
        $validator = new CanonicalRegistryValidator(
            $this->fixtureRoot.'/data',
            $this->fixtureRoot.'/CANONICAL_PRODUCT_FIELD_REGISTRY.md',
            $this->fixtureRoot.'/IMPLEMENTATION_GAPS.md',
        );

        $result = $validator->validate();

        $this->assertSame([], $result['errors'], implode("\n", $result['errors']));
    }

    #[Test]
    public function mapping_decision_conflict_fails(): void
    {
        $path = $this->fixtureRoot.'/data/canonical_product_field_channel_decisions.csv';
        $content = File::get($path);
        $content .= "\ncd999,sku,adobe_commerce,deferred,all_contexts,DEC-010,2.4.9-admin-rest,verified,channel_decision:cd999";
        File::put($path, $content);

        $sourcesPath = $this->fixtureRoot.'/data/canonical_product_field_sources.csv';
        $sources = File::get($sourcesPath);
        $sources .= "\ns999,channel_decision,channel_decision:cd999,official_web_doc,Adobe,Test,https://example.com,not_applicable,unversioned,2026-07-16,test,test";
        File::put($sourcesPath, $sources);

        $result = $this->validate();

        $this->assertTrue(
            collect($result['errors'])->contains(fn (string $e) => str_contains($e, 'all_contexts decision cd999 conflicts')),
        );
    }

    /**
     * @return array{errors: list<string>, warnings: list<string>, metrics: array<string, int>}
     */
    private function validate(): array
    {
        return (new CanonicalRegistryValidator(
            $this->fixtureRoot.'/data',
            $this->fixtureRoot.'/CANONICAL_PRODUCT_FIELD_REGISTRY.md',
            $this->fixtureRoot.'/IMPLEMENTATION_GAPS.md',
        ))->validate();
    }
}
