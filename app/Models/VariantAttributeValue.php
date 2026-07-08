<?php

namespace App\Models;

use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VariantAttributeValue extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'variant_id',
        'attribute_definition_id',
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

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function attributeDefinition(): BelongsTo
    {
        return $this->belongsTo(AttributeDefinition::class);
    }
}
