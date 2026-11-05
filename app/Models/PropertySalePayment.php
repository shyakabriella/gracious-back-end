<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PropertySalePayment extends Model
{
    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'reference',
        'property_sale_id',
        'payment_type',
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

    public function evidences(): HasMany
    {
        return $this->hasMany(
            PropertySalePaymentEvidence::class,
            'property_sale_payment_id'
        );
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(
            PropertySalePaymentStatusHistory::class,
            'property_sale_payment_id'
        );
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(
            PropertySale::class,
            'property_sale_id'
        );
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'recorded_by'
        );
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'verified_by'
        );
    }
}
