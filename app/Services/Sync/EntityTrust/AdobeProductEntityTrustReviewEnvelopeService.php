<?php

namespace App\Services\Sync\EntityTrust;

use App\Enums\EntityTrust\EntityTrustConfirmationMode;
use App\Support\Connectors\AdobePaaS\EntityTrust\AdobeConnectorAccountTargetSnapshot;
use App\Support\Sync\EntityTrust\Exceptions\EntityTrustException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

final class AdobeProductEntityTrustReviewEnvelopeService
{
    private const int TTL_MINUTES = 15;

    private const int ENVELOPE_VERSION = 1;

    /**
     * @param  list<array{subject_key: string, sku: string, type: string, logical_entity_id: int, remote_fingerprint: string}>  $subjects
     */
    public function issue(
        string $actorUserId,
        string $workspaceId,
        string $connectorAccountId,
        string $syncConfigurationId,
        string $configurationRevision,
        string $productId,
        EntityTrustConfirmationMode $mode,
        string $localFingerprint,
        array $subjects,
        ?string $existingParentSkuHint,
        bool $explicitRelink,
        AdobeConnectorAccountTargetSnapshot $targetSnapshot,
    ): string {
        $payload = [
            'v' => self::ENVELOPE_VERSION,
            'actor_user_id' => $actorUserId,
            'workspace_id' => $workspaceId,
            'connector_account_id' => $connectorAccountId,
            'sync_configuration_id' => $syncConfigurationId,
            'configuration_revision' => $configurationRevision,
            'product_id' => $productId,
            'mode' => $mode->value,
            'local_fingerprint' => $localFingerprint,
            'subjects' => $subjects,
            'existing_parent_sku_hint' => $existingParentSkuHint,
            'explicit_relink' => $explicitRelink,
            'target_snapshot' => $targetSnapshot->toEnvelopeArray(),
            'issued_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES)->toIso8601String(),
        ];

        return Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    public function validate(
        string $token,
        string $actorUserId,
        string $workspaceId,
        string $connectorAccountId,
        string $syncConfigurationId,
        string $configurationRevision,
        string $productId,
        EntityTrustConfirmationMode $mode,
        string $localFingerprint,
        ?string $existingParentSkuHint,
        bool $explicitRelink,
    ): array {
        try {
            $decoded = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw EntityTrustException::confirmationExpiredOrInvalid();
        }

        if (! is_array($decoded)) {
            throw EntityTrustException::confirmationExpiredOrInvalid();
        }

        if (($decoded['v'] ?? null) !== self::ENVELOPE_VERSION) {
            throw EntityTrustException::invalidReviewEvidence();
        }

        $expiresAt = isset($decoded['expires_at']) ? Carbon::parse((string) $decoded['expires_at']) : null;

        if ($expiresAt === null || $expiresAt->isPast()) {
            throw EntityTrustException::confirmationExpiredOrInvalid();
        }

        foreach ([
            'actor_user_id' => $actorUserId,
            'workspace_id' => $workspaceId,
            'connector_account_id' => $connectorAccountId,
            'sync_configuration_id' => $syncConfigurationId,
            'configuration_revision' => $configurationRevision,
            'product_id' => $productId,
            'mode' => $mode->value,
            'local_fingerprint' => $localFingerprint,
        ] as $key => $expected) {
            if (($decoded[$key] ?? null) !== $expected) {
                throw EntityTrustException::invalidReviewEvidence();
            }
        }

        if (($decoded['existing_parent_sku_hint'] ?? null) !== $existingParentSkuHint) {
            throw EntityTrustException::invalidReviewEvidence();
        }

        if (($decoded['explicit_relink'] ?? null) !== $explicitRelink) {
            throw EntityTrustException::invalidReviewEvidence();
        }

        $targetSnapshot = AdobeConnectorAccountTargetSnapshot::fromEnvelopeArray(
            is_array($decoded['target_snapshot'] ?? null) ? $decoded['target_snapshot'] : [],
        );

        if ($targetSnapshot === null) {
            throw EntityTrustException::invalidReviewEvidence();
        }

        $subjects = $decoded['subjects'] ?? null;

        if (! is_array($subjects) || $subjects === []) {
            throw EntityTrustException::invalidReviewEvidence();
        }

        $decoded['_resolved_target_snapshot'] = $targetSnapshot;

        return $decoded;
    }
}
