<?php

namespace App\Support\Sync\Live;

final readonly class SyncLiveFinding
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        public string $code,
        public ?string $subject = null,
        public array $context = [],
    ) {}

    /**
     * @return array{
     *     code: string,
     *     subject: string|null,
     *     context: array<string, scalar|null>
     * }
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'subject' => $this->subject,
            'context' => $this->context,
        ];
    }
}
