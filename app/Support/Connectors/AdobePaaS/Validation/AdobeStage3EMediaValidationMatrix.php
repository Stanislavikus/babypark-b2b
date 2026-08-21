<?php

namespace App\Support\Connectors\AdobePaaS\Validation;

/**
 * Encodes mandatory future Stage 3E real-target media validation scenarios.
 * No media reconciliation changes in Part 1 — this matrix is the contract gate
 * for a future truth flip when byte identity must be proven on the real target.
 */
final class AdobeStage3EMediaValidationMatrix
{
    /**
     * @return list<array{format: string, scenario: string, byte_identity_required: bool}>
     */
    public static function supportedClasses(): array
    {
        return [
            ['format' => 'jpeg', 'scenario' => 'normal_jpeg', 'byte_identity_required' => true],
            ['format' => 'jpeg', 'scenario' => 'oversized_jpeg', 'byte_identity_required' => true],
            ['format' => 'png', 'scenario' => 'png', 'byte_identity_required' => true],
            ['format' => 'gif', 'scenario' => 'gif_if_v1_supports', 'byte_identity_required' => true],
        ];
    }

    /**
     * @return list<string>
     */
    public static function futureTargetFactsToRecord(): array
    {
        return [
            'exact_adobe_magento_version',
            'edition_or_topology_if_observable',
            'media_storage_backend_if_knowable',
            'image_transforming_modules_if_knowable',
            'validation_date',
            'known_limitations',
        ];
    }

    public static function requiresSecondFullExecution(): bool
    {
        return true;
    }

    public static function secondExecutionExpectations(): string
    {
        return 'zero_duplicate_gallery_entry_and_no_new_media_post_when_content_and_metadata_match';
    }

    public static function byteIdentityFailureAction(): string
    {
        return 'stop_truth_flip_and_return_to_reconciliation_redesign';
    }
}
