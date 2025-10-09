<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\Concerns\ScopesBarangay as BarangayScope;

class BudgetAugmentation extends Model
{
    protected $fillable = [
        'barangay_id',
        'budget_id',
        'ref_number',
        'augmentation_date',
        'total_amount',
        'remarks',
        'user_id'
    ];

    protected $casts = [
        'augmentation_date' => 'date',
        'total_amount' => 'decimal:2'
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BarangayScope);
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(BarangayUser::class, 'user_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(BudgetAugmentationDetail::class);
    }

    public function scopeForBarangay($query, $barangayId)
    {
        return $query->where('barangay_id', $barangayId);
    }

    public function adminReviews(): MorphMany
    {
        return $this->morphMany(AdminReview::class, 'reviewable');
    }
} 