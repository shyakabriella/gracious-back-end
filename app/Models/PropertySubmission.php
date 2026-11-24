<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PropertySubmission extends Model
{
    protected $table = 'property_submissions';

    protected $fillable = [
        'submitter_name',
        'submitter_phone',
        'submitter_email',
        'submitter_role',

        'owner_name',
        'owner_phone',

        'submission_purpose',
        'property_title',
        'property_description',
        'category_id',

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
    ];

    protected $attributes = [
        'status' => 'pending_review',
        'source' => 'website',
        'currency' => 'RWF',
        'is_negotiable' => false,
        'is_furnished' => false,
    ];

    protected function casts(): array
    {
        return [
            'asking_price' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'land_size_sqm' => 'decimal:2',
            'building_size_sqm' => 'decimal:2',

            'category_id' => 'integer',
            'bedrooms' => 'integer',
            'bathrooms' => 'integer',
            'parking_spaces' => 'integer',
            'floors' => 'integer',

            'is_negotiable' => 'boolean',
            'is_furnished' => 'boolean',

            'terms_accepted_at' => 'datetime',
            'authorization_confirmed_at' => 'datetime',
            'contact_verified_at' => 'datetime',

            'reviewed_by' => 'integer',
            'reviewed_at' => 'datetime',

            'converted_property_id' => 'integer',
            'converted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $submission): void {
            if (empty($submission->reference)) {
                $submission->reference = 'SUB-' . Str::ulid();
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(
            PropertyCategory::class,
            'category_id'
        );
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'reviewed_by'
        );
    }

    public function convertedProperty(): BelongsTo
    {
        return $this->belongsTo(
            Property::class,
            'converted_property_id'
        );
    }
}
