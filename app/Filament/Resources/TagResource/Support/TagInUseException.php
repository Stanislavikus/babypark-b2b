<?php

namespace App\Filament\Resources\TagResource\Support;

use RuntimeException;

class TagInUseException extends RuntimeException
{
    public function __construct(public readonly int $productCount)
    {
        parent::__construct("Tag is used by {$productCount} products.");
    }
}
