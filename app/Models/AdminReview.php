<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AdminReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'admin_id',
        'reviewable_type',
        'reviewable_id',
        'remarks',
    ];


    /**
     * Get the admin that made the review.
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * Get the reviewable model (appropriation, disbursement, or augmentation).
     */
    public function reviewable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope to get reviews for a specific admin.
     */
    public function scopeForAdmin($query, $adminId)
    {
        return $query->where('admin_id', $adminId);
    }

    /**
     * Scope to get reviews for a specific reviewable item.
     */
    public function scopeForReviewable($query, $reviewableType, $reviewableId)
    {
        return $query->where('reviewable_type', $reviewableType)
                    ->where('reviewable_id', $reviewableId);
    }
}