<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ProductTag extends Pivot
{
    public $incrementing = false;

    protected $table = 'product_tag';

    protected $fillable = [
        'workspace_id',
        'product_id',
        'tag_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (ProductTag $pivot): void {
            static::assertWorkspaceConsistency($pivot);
        });
    }

    public static function assertWorkspaceConsistency(ProductTag $pivot): void
    {
        $product = Product::withoutWorkspaceScope()->find($pivot->product_id);
        $tag = Tag::withoutWorkspaceScope()->find($pivot->tag_id);

        if (
            $product === null
            || $tag === null
            || $product->workspace_id !== $tag->workspace_id
            || $pivot->workspace_id !== $product->workspace_id
            || $pivot->workspace_id !== $tag->workspace_id
        ) {
            throw new DomainException('Product and tag must belong to the same workspace.');
        }
    }
}
