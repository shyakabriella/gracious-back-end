<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertySubmissionMedia extends Model
{
    use SoftDeletes;

    protected $table = 'property_submission_media';

    // Prevent uncontrolled mass assignment.
    protected $guarded = ['*'];

    protected $attributes = [
        'storage_disk' => 'local',
        'review_status' => 'pending',
        'sort_order' => 0,
        'is_primary' => false,
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'sort_order' => 'integer',
            'is_primary' => 'boolean',
            'reviewed_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(
            PropertySubmission::class,
            'property_submission_id'
        );
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'reviewed_by'
        );
    }
}
