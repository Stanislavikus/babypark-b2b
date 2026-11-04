<?php

namespace App\Models;

use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductFieldValue extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'product_id',
        'field_binding_id',
        'value_text',
        'value_num',
        'value_jsonb',
    ];

    protected function casts(): array
    {
        return [
            'value_num' => 'decimal:6',
            'value_jsonb' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function fieldBinding(): BelongsTo
    {
        return $this->belongsTo(FieldBinding::class);
    }
}
