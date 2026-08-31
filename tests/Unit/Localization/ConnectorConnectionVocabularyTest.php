<?php

namespace Tests\Unit\Localization;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConnectorConnectionVocabularyTest extends TestCase
{
    #[Test]
    public function connected_status_is_evidence_scoped_in_supported_locales(): void
    {
        $root = dirname(__DIR__, 3);
        $key = 'connectors.ui.integrations.status.connected';

        $this->assertSame('Підключення перевірено', $this->readLangValue($root.'/lang/uk.json', $key));
        $this->assertSame('Connection verified', $this->readLangValue($root.'/lang/en.json', $key));
        $this->assertSame('Подключение проверено', $this->readLangValue($root.'/lang/ru.json', $key));
    }

    private function readLangValue(string $path, string $key): string
    {
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);

        $data = json_decode($raw, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey($key, $data);
        $this->assertIsString($data[$key]);

        return $data[$key];
    }
}

