<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyRentRefundStatusHistory extends Model
{
    protected $table = 'property_rent_refund_status_histories';

    protected $fillable = [
        'property_rent_refund_id',
        'from_status',
        'to_status',
        'notes',
        'changed_by',
    ];

    public function refund(): BelongsTo
    {
        return $this->belongsTo(
            PropertyRentRefund::class,
            'property_rent_refund_id'
        );
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'changed_by'
        );
    }
}
