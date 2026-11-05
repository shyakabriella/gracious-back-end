<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyRentRefundDestination extends Model
{
    protected $table = 'property_rent_refund_destinations';

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'property_rent_refund_id',
        'refund_method',
        'recipient_name',
        'recipient_identifier',
        'provider_name',
        'status',
        'recorded_by',
        'approved_by',
        'approved_at',
    ];

    protected $hidden = [
        'recipient_identifier',
    ];

    protected function casts(): array
    {
        return [
            'recipient_identifier' => 'encrypted',
            'approved_at' => 'datetime',
        ];
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(
            PropertyRentRefund::class,
            'property_rent_refund_id'
        );
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'recorded_by'
        );
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'approved_by'
        );
    }
}
