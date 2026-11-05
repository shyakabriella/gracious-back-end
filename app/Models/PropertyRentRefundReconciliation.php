<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyRentRefundReconciliation extends Model
{
    protected $table = 'property_rent_refund_reconciliations';

    protected $fillable = [
        'property_rent_refund_id',
        'evidence_id',
        'confirmation_source',
        'provider_transaction_reference',
        'confirmed_amount',
        'currency',
        'outcome',
        'verified_by',
        'verified_at',
        'verification_notes',
        'confirmed_recipient',
        'provider_confirmed_at',
    ];

    protected $hidden = [
        'confirmed_recipient',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_amount' => 'decimal:2',
            'verified_at' => 'datetime',
            'provider_confirmed_at' => 'datetime',
        ];
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(
            PropertyRentRefund::class,
            'property_rent_refund_id'
        );
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(
            PropertyRentRefundEvidence::class,
            'evidence_id'
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
