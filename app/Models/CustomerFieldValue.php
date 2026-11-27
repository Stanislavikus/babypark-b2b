<?php

namespace App\Models;

use App\Support\Workspace\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerFieldValue extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id',
        'customer_id',
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function fieldBinding(): BelongsTo
    {
        return $this->belongsTo(FieldBinding::class);
    }
}
