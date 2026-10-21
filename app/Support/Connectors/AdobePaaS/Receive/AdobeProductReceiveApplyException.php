<?php

namespace App\Support\Connectors\AdobePaaS\Receive;

use RuntimeException;

final class AdobeProductReceiveApplyException extends RuntimeException
{
    private function __construct(
        public readonly string $reasonCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notAuthorized(): self
    {
        return new self('receive_apply_not_authorized', 'Receive Apply is not authorized for this workspace.');
    }

    public static function contextInvalid(): self
    {
        return new self('receive_apply_context_invalid', 'Receive Apply correlation context is invalid.');
    }

    public static function proposalUnavailable(): self
    {
        return new self('receive_apply_proposal_unavailable', 'Receive proposal is missing, expired, mismatched, or already consumed.');
    }

    public static function proposalShapeNotExecutable(): self
    {
        return new self('receive_apply_proposal_shape_not_executable', 'Receive proposal does not contain an executable supported Apply action.');
    }

    public static function mappingChanged(?\Throwable $previous = null): self
    {
        return new self('receive_apply_mapping_changed', 'Receive field mapping or option mapping changed after the proposal was issued.', $previous);
    }

    public static function trustedLinkChanged(): self
    {
        return new self('receive_apply_trusted_link_changed', 'Trusted external identity changed after the proposal was issued.');
    }

    public static function remoteIdentityChanged(): self
    {
        return new self('receive_apply_remote_identity_changed', 'Remote Magento logical identity or SKU changed after the proposal was issued.');
    }

    public static function remoteValueChanged(): self
    {
        return new self('receive_apply_remote_value_changed', 'Participating remote Magento value changed after the proposal was issued.');
    }

    public static function remoteReadFailed(?\Throwable $previous = null): self
    {
        return new self('receive_apply_remote_read_failed', 'Fresh Magento Product read failed during Receive Apply.', $previous);
    }

    public static function localValueChanged(?\Throwable $previous = null): self
    {
        return new self('receive_apply_local_value_changed', 'Participating local value changed after the proposal was issued.', $previous);
    }

    public static function configurationChanged(): self
    {
        return new self('receive_apply_configuration_changed', 'Sync configuration changed after Receive Apply admission.');
    }

    public static function runNotExecutable(): self
    {
        return new self('receive_apply_run_not_executable', 'Receive Apply run is no longer executable.');
    }
}
