<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyRentPaymentAllocation extends Model
{
    protected $table = 'property_rent_payment_allocations';

    protected $fillable = [
        'property_rent_payment_id',
        'property_rent_schedule_id',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(
            PropertyRentPayment::class,
            'property_rent_payment_id'
        );
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(
            PropertyRentSchedule::class,
            'property_rent_schedule_id'
        );
    }
}
