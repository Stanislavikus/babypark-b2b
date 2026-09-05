<?php

namespace Tests\Feature\Sync;

use App\Support\Sync\Exceptions\FieldMappingValidationException;
use App\Support\Sync\Exceptions\FieldOptionMappingConflictException;
use App\Support\Sync\Exceptions\FieldOptionMappingStaleMutationException;
use App\Support\Sync\FieldOptionMappingPresentation\FieldOptionMappingSafeMessagePresenter;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FieldOptionMappingSafeMessagePresenterTest extends TestCase
{
    #[Test]
    public function presenter_returns_safe_messages_without_technical_exception_text(): void
    {
        $presenter = app(FieldOptionMappingSafeMessagePresenter::class);
        $configurationId = (string) Str::uuid();
        $mappingId = (string) Str::uuid();
        $definitionId = (string) Str::uuid();
        $internalKey = 'green';

        $staleMessage = $presenter->present(FieldOptionMappingStaleMutationException::configurationChanged());
        $this->assertSame(__('sync_option_mappings.errors.stale_state'), $staleMessage);

        $conflictMessage = $presenter->present(
            FieldOptionMappingConflictException::internalOptionAlreadyMapped($internalKey),
        );
        $this->assertSame(__('sync_option_mappings.errors.conflict'), $conflictMessage);
        $this->assertStringNotContainsString($internalKey, $conflictMessage);

        $invalidInternal = FieldMappingValidationException::invalidInternalOptionKey($internalKey, $definitionId);
        $invalidInternalMessage = $presenter->present($invalidInternal);
        $this->assertSame(__('sync_option_mappings.errors.invalid_action'), $invalidInternalMessage);
        $this->assertStringNotContainsString($internalKey, $invalidInternalMessage);
        $this->assertStringNotContainsString($definitionId, $invalidInternalMessage);
        $this->assertStringNotContainsString($invalidInternal->getMessage(), $invalidInternalMessage);

        $mappingNotFound = FieldMappingValidationException::mappingNotFound($configurationId, $mappingId);
        $mappingNotFoundMessage = $presenter->present($mappingNotFound);
        $this->assertSame(__('sync_option_mappings.errors.invalid_action'), $mappingNotFoundMessage);
        $this->assertStringNotContainsString($configurationId, $mappingNotFoundMessage);
        $this->assertStringNotContainsString($mappingId, $mappingNotFoundMessage);
        $this->assertStringNotContainsString($mappingNotFound->getMessage(), $mappingNotFoundMessage);

        $archivedBinding = FieldMappingValidationException::archivedBinding($mappingId);
        $archivedBindingMessage = $presenter->present($archivedBinding);
        $this->assertSame(__('sync_option_mappings.errors.invalid_action'), $archivedBindingMessage);
        $this->assertStringNotContainsString($archivedBinding->getMessage(), $archivedBindingMessage);
    }
}
