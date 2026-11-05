<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Property extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'verification_status' => 'draft',
        'availability_status' => 'available',
        'is_published' => false,
    ];

    protected $fillable = [
        'reference',
        'title',
        'slug',
        'description',
        'category_id',
        'listing_type',
        'asking_price',
        'currency',
        'is_negotiable',
        'province',
        'district',
        'sector',
        'cell',
        'village',
        'address',
        'latitude',
        'longitude',
        'land_size_sqm',
        'building_size_sqm',
        'bedrooms',
        'bathrooms',
        'parking_spaces',
        'floors',
        'is_furnished',
        'agent_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'asking_price' => 'decimal:2',
            'land_size_sqm' => 'decimal:2',
            'building_size_sqm' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'bedrooms' => 'integer',
            'bathrooms' => 'integer',
            'parking_spaces' => 'integer',
            'floors' => 'integer',
            'is_negotiable' => 'boolean',
            'is_furnished' => 'boolean',
            'is_published' => 'boolean',
            'is_featured' => 'boolean',
            'verified_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Property $property) {
            if (
                $property->verification_status !== 'verified'
                || $property->availability_status !== 'available'
                || !$property->is_published
            ) {
                $property->is_featured = false;
            }
        });

        static::deleting(function (Property $property) {
            if ($property->is_featured) {
                $property->forceFill([
                    'is_featured' => false,
                ])->saveQuietly();
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PropertyCategory::class, 'category_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function images(): HasMany
    {
        return $this->hasMany(PropertyImage::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PropertyDocument::class);
    }

    public function verificationHistory(): HasMany
    {
        return $this->hasMany(PropertyVerificationHistory::class);
    }


    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(
            PropertyOwner::class,
            'property_owner'
        )->withTimestamps();
    }
}
