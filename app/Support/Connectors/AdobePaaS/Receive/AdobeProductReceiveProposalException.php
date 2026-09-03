<?php

namespace App\Support\Connectors\AdobePaaS\Receive;

use RuntimeException;

final class AdobeProductReceiveProposalException extends RuntimeException
{
    private function __construct(
        public readonly string $reasonCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function connectorAccountNotFound(string $connectorAccountId, string $workspaceId): self
    {
        return new self(
            'connector_account_not_found',
            "Connector account '{$connectorAccountId}' does not belong to workspace '{$workspaceId}'.",
        );
    }

    public static function configurationNotFound(): self
    {
        return new self(
            'receive_configuration_not_found',
            'No enabled Products sync configuration was found for connector-backed Receive.',
        );
    }

    public static function configurationNotEnabled(string $configurationId): self
    {
        return new self(
            'receive_configuration_not_enabled',
            "Sync configuration '{$configurationId}' is not enabled for Receive proposal build.",
        );
    }

    public static function importNotEnabled(string $configurationId): self
    {
        return new self(
            'receive_import_not_enabled',
            "Sync configuration '{$configurationId}' does not enable the import operation.",
        );
    }

    public static function invalidConfigurationRevision(string $configurationId): self
    {
        return new self(
            'receive_configuration_revision_invalid',
            "Sync configuration '{$configurationId}' does not expose a valid configuration revision.",
        );
    }

    public static function targetNotFound(string $targetType, string $targetId): self
    {
        return new self(
            'receive_target_not_found',
            "Receive target '{$targetType}:{$targetId}' was not found.",
        );
    }

    public static function targetWorkspaceMismatch(string $targetType, string $targetId): self
    {
        return new self(
            'receive_target_workspace_mismatch',
            "Receive target '{$targetType}:{$targetId}' does not belong to the requested workspace.",
        );
    }

    public static function invalidVariantOwnership(string $targetId): self
    {
        return new self(
            'receive_variant_product_relation_invalid',
            "Product variant '{$targetId}' does not resolve to a valid owning product in the same workspace.",
        );
    }

    public static function trustedExternalLinkMissing(): self
    {
        return new self(
            'trusted_external_link_missing',
            'Receive proposal requires an existing merchant-confirmed trusted ExternalRecordLink.',
        );
    }

    public static function trustedExternalLinkAmbiguous(): self
    {
        return new self(
            'trusted_external_link_ambiguous',
            'Receive proposal cannot continue because the trusted ExternalRecordLink lookup is ambiguous.',
        );
    }

    public static function invalidTrustedLogicalEntityId(string $externalRecordDiscriminator): self
    {
        return new self(
            'trusted_logical_entity_id_invalid',
            "Trusted ExternalRecordLink discriminator '{$externalRecordDiscriminator}' is not a supported positive logical entity id.",
        );
    }

    public static function invalidTrustedExpectedSku(): self
    {
        return new self(
            'trusted_expected_sku_invalid',
            'Trusted ExternalRecordLink external identifier is not a valid expected SKU precondition.',
        );
    }

    public static function safeSyncReadFailed(string $reasonCode, ?\Throwable $previous = null): self
    {
        return new self(
            $reasonCode,
            'Safe Sync entity-bound product read failed during Receive proposal build.',
            $previous,
        );
    }

    public static function safeSyncContextInvalid(?\Throwable $previous = null): self
    {
        return new self(
            'safe_sync_context_invalid',
            'Safe Sync request context is invalid for Receive proposal build.',
            $previous,
        );
    }

    public static function productReadFailed(string $reasonCode, ?\Throwable $previous = null): self
    {
        return new self(
            $reasonCode,
            'Stock Magento Product document read failed during Receive proposal build.',
            $previous,
        );
    }

    public static function productReadContextInvalid(?\Throwable $previous = null): self
    {
        return new self(
            'product_read_context_invalid',
            'Magento Product request context is invalid for Receive proposal build.',
            $previous,
        );
    }

    public static function configurationChanged(): self
    {
        return new self(
            'receive_configuration_changed',
            'Sync configuration changed during Receive proposal build and the proposal was invalidated.',
        );
    }

    public static function trustedExternalLinkChanged(): self
    {
        return new self(
            'trusted_external_link_changed',
            'Trusted ExternalRecordLink changed during Receive proposal build and the proposal was invalidated.',
        );
    }

    public static function fieldMappingAmbiguous(string $externalFieldKey): self
    {
        return new self(
            'receive_field_mapping_ambiguous',
            "Receive mapping for external field '{$externalFieldKey}' is ambiguous.",
        );
    }

    public static function noExecutableNameMapping(): self
    {
        return new self(
            'receive_no_executable_name_mapping',
            'No executable Receive mapping exists for the canonical external product name field.',
        );
    }

    public static function persistenceInvariantBroken(string $message): self
    {
        return new self(
            'receive_persistence_invariant_broken',
            $message,
        );
    }
}
