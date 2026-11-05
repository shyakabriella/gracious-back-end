<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertySalePaymentEvidence extends Model
{
    protected $table = 'property_sale_payment_evidences';

    protected $fillable = [
        'property_sale_payment_id',
        'original_name',
        'disk',
        'file_path',
        'mime_type',
        'file_size',
        'uploaded_by',
    ];

    protected $hidden = [
        'disk',
        'file_path',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(
            PropertySalePayment::class,
            'property_sale_payment_id'
        );
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
