<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertySaleCompletionDocument extends Model
{
    protected $table = 'property_sale_completion_documents';

    protected $fillable = [
        'property_sale_id',
        'document_type',
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

    public function sale(): BelongsTo
    {
        return $this->belongsTo(
            PropertySale::class,
            'property_sale_id'
        );
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
