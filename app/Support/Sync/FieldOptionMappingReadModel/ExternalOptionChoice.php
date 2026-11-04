<?php

namespace App\Support\Sync\FieldOptionMappingReadModel;

final readonly class ExternalOptionChoice
{
    public function __construct(
        public string $value,
        public string $label,
    ) {}

    public function presentationLabel(): string
    {
        if ($this->label !== '') {
            return $this->label;
        }

        return $this->value;
    }
}
