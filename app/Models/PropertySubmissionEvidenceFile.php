<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PropertySubmissionEvidenceFile extends Model
{
    protected $table = 'property_submission_evidence_files';

    /**
     * Evidence metadata must only be assigned explicitly
     * by trusted server-side code.
     */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'property_submission_id' => 'integer',
            'uploaded_by' => 'integer',
            'withdrawn_by' => 'integer',
            'file_size' => 'integer',
            'withdrawn_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $evidence): void {
            if (empty($evidence->reference)) {
                $evidence->reference = 'EV-' . Str::ulid();
            }
        });
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(
            PropertySubmission::class,
            'property_submission_id'
        );
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'uploaded_by'
        );
    }

    public function withdrawer(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'withdrawn_by'
        );
    }
}
