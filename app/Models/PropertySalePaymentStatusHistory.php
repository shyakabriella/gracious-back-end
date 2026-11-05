<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertySalePaymentStatusHistory extends Model
{
    protected $table = 'property_sale_payment_status_histories';

    protected $fillable = [
        'property_sale_payment_id',
        'from_status',
        'to_status',
        'notes',
        'changed_by',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(
            PropertySalePayment::class,
            'property_sale_payment_id'
        );
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
