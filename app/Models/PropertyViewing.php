<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyViewing extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'status' => 'requested',
    ];

    protected $fillable = [
        'reference',
        'lead_id',
        'property_id',
        'agent_id',
        'scheduled_start_at',
        'scheduled_end_at',
        'status',
        'notes',
        'cancellation_reason',
        'completed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
