<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyRentPaymentStatusHistory extends Model
{
    protected $table = 'property_rent_payment_status_histories';

    protected $fillable = [
        'property_rent_payment_id',
        'from_status',
        'to_status',
        'notes',
        'changed_by',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(
            PropertyRentPayment::class,
            'property_rent_payment_id'
        );
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
