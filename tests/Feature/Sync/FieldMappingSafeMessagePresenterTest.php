<?php

namespace Tests\Feature\Sync;

use App\Support\Sync\Exceptions\FieldMappingConflictException;
use App\Support\Sync\Exceptions\FieldMappingValidationException;
use App\Support\Sync\FieldMappingPresentation\FieldMappingSafeMessagePresenter;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FieldMappingSafeMessagePresenterTest extends TestCase
{
    #[Test]
    public function presenter_returns_layer_b_messages_without_technical_exception_text(): void
    {
        $presenter = app(FieldMappingSafeMessagePresenter::class);
        $configurationId = (string) Str::uuid();
        $bindingId = (string) Str::uuid();
        $externalKey = "field'with\\quote";

        $stale = FieldMappingValidationException::mappingNotFound($configurationId, $bindingId);
        $staleMessage = $presenter->present($stale);

        $this->assertSame(__('sync_mappings.errors.stale_state'), $staleMessage);
        $this->assertStringNotContainsString($configurationId, $staleMessage);
        $this->assertStringNotContainsString($bindingId, $staleMessage);
        $this->assertStringNotContainsString('Field binding', $staleMessage);
        $this->assertStringNotContainsString('sync configuration', strtolower($staleMessage));

        $conflict = FieldMappingConflictException::externalFieldAlreadyMapped($externalKey);
        $conflictMessage = $presenter->present($conflict);

        $this->assertSame(__('sync_mappings.errors.conflict'), $conflictMessage);
        $this->assertStringNotContainsString($externalKey, $conflictMessage);
        $this->assertStringNotContainsString('External field key', $conflictMessage);

        $invalid = FieldMappingValidationException::archivedBinding($bindingId);
        $invalidMessage = $presenter->present($invalid);

        $this->assertSame(__('sync_mappings.errors.invalid_action'), $invalidMessage);
        $this->assertStringNotContainsString($bindingId, $invalidMessage);
        $this->assertStringNotContainsString('archived', strtolower($invalidMessage));
    }
}
