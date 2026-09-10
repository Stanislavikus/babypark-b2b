<?php

namespace Tests\Unit\Connectors\AdobePaaS;

use App\Support\Connectors\AdobePaaS\AdobePaaSServiceOnlyAttributeEligibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdobePaaSServiceOnlyAttributeEligibilityTest extends TestCase
{
    private AdobePaaSServiceOnlyAttributeEligibility $eligibility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eligibility = new AdobePaaSServiceOnlyAttributeEligibility;
    }

    #[Test]
    public function matching_service_only_flags_are_skipped(): void
    {
        $raw = $this->decode('{
            "attribute_code":"links_title",
            "frontend_input":null,
            "is_user_defined":false,
            "is_visible":false,
            "apply_to":["downloadable"]
        }');

        $this->assertTrue($this->eligibility->shouldSkip($raw));
    }

    #[Test]
    public function null_frontend_input_with_visible_true_is_not_skipped(): void
    {
        $raw = $this->decode('{
            "attribute_code":"hidden_flag",
            "frontend_input":null,
            "is_user_defined":false,
            "is_visible":true
        }');

        $this->assertFalse($this->eligibility->shouldSkip($raw));
    }

    #[Test]
    public function null_frontend_input_with_user_defined_true_is_not_skipped(): void
    {
        $raw = $this->decode('{
            "attribute_code":"custom_flag",
            "frontend_input":null,
            "is_user_defined":true,
            "is_visible":false
        }');

        $this->assertFalse($this->eligibility->shouldSkip($raw));
    }

    #[Test]
    #[DataProvider('invalidEligibilityFlagProvider')]
    public function wrong_or_missing_eligibility_flags_are_not_skipped(string $json): void
    {
        $this->assertFalse($this->eligibility->shouldSkip($this->decode($json)));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidEligibilityFlagProvider(): array
    {
        return [
            'missing frontend_input' => ['{"attribute_code":"links_title","is_user_defined":false,"is_visible":false}'],
            'missing is_user_defined' => ['{"attribute_code":"links_title","frontend_input":null,"is_visible":false}'],
            'missing is_visible' => ['{"attribute_code":"links_title","frontend_input":null,"is_user_defined":false}'],
            'non-boolean is_visible string zero' => ['{"attribute_code":"links_title","frontend_input":null,"is_user_defined":false,"is_visible":"0"}'],
            'non-boolean is_visible integer zero' => ['{"attribute_code":"links_title","frontend_input":null,"is_user_defined":false,"is_visible":0}'],
            'non-boolean is_user_defined string zero' => ['{"attribute_code":"links_title","frontend_input":null,"is_user_defined":"0","is_visible":false}'],
            'non-boolean is_user_defined integer zero' => ['{"attribute_code":"links_title","frontend_input":null,"is_user_defined":0,"is_visible":false}'],
        ];
    }

    #[Test]
    #[DataProvider('invalidAttributeCodeProvider')]
    public function invalid_attribute_code_with_matching_skip_flags_is_not_skipped(string $json): void
    {
        $this->assertFalse($this->eligibility->shouldSkip($this->decode($json)));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidAttributeCodeProvider(): array
    {
        $flags = '"frontend_input":null,"is_user_defined":false,"is_visible":false';

        return [
            'missing attribute_code' => ['{'.$flags.'}'],
            'null attribute_code' => ['{"attribute_code":null,'.$flags.'}'],
            'empty attribute_code' => ['{"attribute_code":"","frontend_input":null,"is_user_defined":false,"is_visible":false}'],
            'numeric attribute_code' => ['{"attribute_code":123,'.$flags.'}'],
            'object attribute_code' => ['{"attribute_code":{"code":"links_title"},'.$flags.'}'],
        ];
    }

    #[Test]
    public function non_object_raw_item_is_not_skipped(): void
    {
        $this->assertFalse($this->eligibility->shouldSkip(['attribute_code' => 'links_title']));
    }

    private function decode(string $json): \stdClass
    {
        return json_decode($json, associative: false, depth: 512, flags: JSON_THROW_ON_ERROR);
    }
}
