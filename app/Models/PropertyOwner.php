<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PropertyOwner extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'reference',
        'owner_type',
        'name',
        'phone',
        'email',
        'province',
        'district',
        'sector',
        'address',
        'notes',
        'status',
        'created_by',
    ];

    protected $attributes = [
        'owner_type' => 'individual',
        'status' => 'active',
    ];

    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(
            Property::class,
            'property_owner'
        )->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
