<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PropertyRentPayment extends Model
{
    protected $table = 'property_rent_payments';

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'reference',
        'property_rental_id',
        'payment_method',
        'amount',
        'currency',
        'transaction_reference',
        'payment_date',
        'status',
        'notes',
        'rejection_reason',
        'recorded_by',
        'verified_by',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
            'verified_at' => 'datetime',
        ];
    }

    public function rental(): BelongsTo
    {
        return $this->belongsTo(
            PropertyRental::class,
            'property_rental_id'
        );
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(
            PropertyRentPaymentAllocation::class,
            'property_rent_payment_id'
        );
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(
            PropertyRentPaymentEvidence::class,
            'property_rent_payment_id'
        );
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(
            PropertyRentPaymentStatusHistory::class,
            'property_rent_payment_id'
        );
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
