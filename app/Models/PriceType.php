<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceType extends Model
{
    public $timestamps = false;

    public const CODE_CONTRACT_PRICE = 'contract_price';

    public const CODE_LIST_PRICE = 'list_price';

    public const CODE_COST_OF_GOODS_SOLD = 'cost_of_goods_sold';

    protected $fillable = [
        'code',
        'name',
        'gmc_term',
        'is_contractor_specific',
    ];

    protected function casts(): array
    {
        return [
            'is_contractor_specific' => 'boolean',
        ];
    }

    public function productPrices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }
}
