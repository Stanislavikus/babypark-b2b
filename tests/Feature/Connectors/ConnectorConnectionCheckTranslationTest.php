<?php

namespace Tests\Feature\Connectors;

use App\Enums\ConnectorConnectionCheckErrorCode;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConnectorConnectionCheckTranslationTest extends TestCase
{
    #[Test]
    public function every_producible_message_key_exists_in_all_supported_locales(): void
    {
        $locales = ['en', 'uk', 'ru'];
        $messageKeys = array_unique(array_map(
            static fn (ConnectorConnectionCheckErrorCode $case) => $case->messageKey(),
            ConnectorConnectionCheckErrorCode::cases(),
        ));

        foreach ($locales as $locale) {
            $translations = json_decode(
                file_get_contents(base_path("lang/{$locale}.json")),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            foreach ($messageKeys as $messageKey) {
                $this->assertArrayHasKey(
                    $messageKey,
                    $translations,
                    "Missing translation for {$messageKey} in {$locale}.json",
                );
                $this->assertNotSame('', $translations[$messageKey]);
            }
        }
    }
}
