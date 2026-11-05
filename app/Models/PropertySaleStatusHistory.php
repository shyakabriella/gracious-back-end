<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertySaleStatusHistory extends Model
{
    protected $fillable = [
        'property_sale_id',
        'from_status',
        'to_status',
        'notes',
        'changed_by',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(PropertySale::class, 'property_sale_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
