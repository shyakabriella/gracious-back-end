<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyRentalStatusHistory extends Model
{
    protected $table = 'property_rental_status_histories';

    protected $fillable = [
        'property_rental_id',
        'from_status',
        'to_status',
        'notes',
        'changed_by',
    ];

    public function rental(): BelongsTo
    {
        return $this->belongsTo(
            PropertyRental::class,
            'property_rental_id'
        );
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
