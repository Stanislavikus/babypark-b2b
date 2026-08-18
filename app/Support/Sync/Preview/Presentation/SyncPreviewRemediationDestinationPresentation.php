<?php

namespace App\Support\Sync\Preview\Presentation;

use App\Enums\SyncPreviewRemediationActionability;
use App\Enums\SyncPreviewRemediationArea;

final readonly class SyncPreviewRemediationDestinationPresentation
{
    public function __construct(
        public SyncPreviewRemediationArea $area,
        public SyncPreviewRemediationActionability $actionability,
        public string $label,
        public ?string $actionLabel,
        public ?string $actionUrl,
        public ?string $statusMessage,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'area' => $this->area->value,
            'actionability' => $this->actionability->value,
            'label' => $this->label,
            'action_label' => $this->actionLabel,
            'action_url' => $this->actionUrl,
            'status_message' => $this->statusMessage,
        ];
    }
}
