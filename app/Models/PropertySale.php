<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PropertySale extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'status' => 'negotiation',
        'currency' => 'RWF',
    ];

    protected $fillable = [
        'reference',
        'property_id',
        'customer_id',
        'lead_id',
        'agent_id',
        'asking_price',
        'agreed_price',
        'currency',
        'status',
        'agreement_date',
        'expected_completion_date',
        'completed_at',
        'notes',
        'cancellation_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'asking_price' => 'decimal:2',
            'agreed_price' => 'decimal:2',
            'agreement_date' => 'date',
            'expected_completion_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completionDocuments(): HasMany
    {
        return $this->hasMany(
            PropertySaleCompletionDocument::class,
            'property_sale_id'
        );
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PropertySalePayment::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(PropertySaleStatusHistory::class);
    }
}
