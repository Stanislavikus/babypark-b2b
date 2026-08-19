<?php

namespace App\Models;

use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class ExternalRecordLink extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'connector_account_id',
        'product_id',
        'product_variant_id',
        'external_identifier',
    ];

    protected static function booted(): void
    {
        static::saving(function (ExternalRecordLink $link): void {
            $link->assertSubjectXor();
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function connectorAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectorAccount::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    private function assertSubjectXor(): void
    {
        $hasProduct = $this->product_id !== null;
        $hasVariant = $this->product_variant_id !== null;

        if ($hasProduct === $hasVariant) {
            throw new InvalidArgumentException('ExternalRecordLink requires exactly one of product_id or product_variant_id.');
        }
    }
}
