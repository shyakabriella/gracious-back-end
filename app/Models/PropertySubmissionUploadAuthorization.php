<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertySubmissionUploadAuthorization extends Model
{
    protected $table =
        'property_submission_upload_authorizations';

    // Upload credentials must not be mass assigned.
    protected $guarded = ['*'];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(
            PropertySubmission::class,
            'property_submission_id'
        );
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
