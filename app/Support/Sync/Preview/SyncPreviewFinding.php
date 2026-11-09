<?php

namespace App\Support\Sync\Preview;

use App\Enums\SyncPreviewFindingCode;

final readonly class SyncPreviewFinding
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        public SyncPreviewFindingCode $code,
        public ?string $subject = null,
        public array $context = [],
    ) {}

    /**
     * @return array{
     *     code: string,
     *     subject: string|null,
     *     message_key: string,
     *     context: array<string, scalar|null>
     * }
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'subject' => $this->subject,
            'message_key' => $this->code->messageKey(),
            'context' => $this->context,
        ];
    }
}
