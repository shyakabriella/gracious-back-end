<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyRentAdjustment extends Model
{
    protected $table = 'property_rent_adjustments';

    protected $fillable = [
        'property_rental_id',
        'property_rent_schedule_id',
        'reference',
        'type',
        'amount',
        'currency',
        'reason',
        'effective_date',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'effective_date' => 'date',
    ];

    public function rental(): BelongsTo
    {
        return $this->belongsTo(
            PropertyRental::class,
            'property_rental_id'
        );
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(
            PropertyRentSchedule::class,
            'property_rent_schedule_id'
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }
}
