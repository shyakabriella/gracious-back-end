<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyRentRefundEvidence extends Model
{
    protected $table = 'property_rent_refund_evidences';

    protected $fillable = [
        'property_rent_refund_id',
        'original_name',
        'disk',
        'file_path',
        'mime_type',
        'file_size',
        'sha256',
        'uploaded_by',
    ];

    protected $hidden = [
        'disk',
        'file_path',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(
            PropertyRentRefund::class,
            'property_rent_refund_id'
        );
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'uploaded_by'
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
