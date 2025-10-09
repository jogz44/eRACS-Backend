<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\ScopesBarangay as BarangayScope;

class SupplementalBudgetDetail extends Model
{
    protected $fillable = [
        'budget_id',
        'barangay_id',
        'supplement_type',
        'source_of_supplement',
        'supplement_amount',
        'utilized_amount',
        'remaining_amount',
        'emergency_justification',
        'urgency_level',
        'impact_assessment',
        'request_date',
        'effective_date',
        'expiry_date',
        'implementation_notes',
        'supplement_status',
        'supplement_approved_at',
        'supplement_approved_by',
        'original_budget_id'
    ];

    protected $casts = [
        'supplement_amount' => 'decimal:2',
        'utilized_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'request_date' => 'date',
        'effective_date' => 'date',
        'expiry_date' => 'date',
        'supplement_approved_at' => 'datetime'
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BarangayScope);
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    public function supplementApprovedBy(): BelongsTo
    {
        return $this->belongsTo(BarangayUser::class, 'supplement_approved_by');
    }

    public function originalBudget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'original_budget_id');
    }

    // Scopes
    public function scopeByType($query, string $type)
    {
        return $query->where('supplement_type', $type);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('supplement_status', $status);
    }

    public function scopeByUrgency($query, string $urgency)
    {
        return $query->where('urgency_level', $urgency);
    }

    public function scopeRequested($query)
    {
        return $query->where('supplement_status', 'requested');
    }

    public function scopeUnderReview($query)
    {
        return $query->where('supplement_status', 'under_review');
    }

    public function scopeApproved($query)
    {
        return $query->where('supplement_status', 'approved');
    }

    public function scopeActive($query)
    {
        return $query->where('supplement_status', 'active');
    }

    public function scopeExpired($query)
    {
        return $query->where('supplement_status', 'expired');
    }

    public function scopeCancelled($query)
    {
        return $query->where('supplement_status', 'cancelled');
    }

    public function scopeEmergency($query)
    {
        return $query->where('urgency_level', 'critical');
    }

    public function scopeHighUrgency($query)
    {
        return $query->whereIn('urgency_level', ['high', 'critical']);
    }

    // Helper methods
    public function isRequested(): bool
    {
        return $this->supplement_status === 'requested';
    }

    public function isUnderReview(): bool
    {
        return $this->supplement_status === 'under_review';
    }

    public function isApproved(): bool
    {
        return $this->supplement_status === 'approved';
    }

    public function isActive(): bool
    {
        return $this->supplement_status === 'active';
    }

    public function isExpired(): bool
    {
        return $this->supplement_status === 'expired';
    }

    public function isCancelled(): bool
    {
        return $this->supplement_status === 'cancelled';
    }

    public function isEmergency(): bool
    {
        return $this->urgency_level === 'critical';
    }

    public function isHighUrgency(): bool
    {
        return in_array($this->urgency_level, ['high', 'critical']);
    }

    public function approve(BarangayUser $user): void
    {
        $this->update([
            'supplement_status' => 'approved',
            'supplement_approved_at' => now(),
            'supplement_approved_by' => $user->id
        ]);
    }

    public function activate(): void
    {
        $this->update(['supplement_status' => 'active']);
    }

    public function expire(): void
    {
        $this->update(['supplement_status' => 'expired']);
    }

    public function cancel(): void
    {
        $this->update(['supplement_status' => 'cancelled']);
    }

    public function updateUtilization(float $amount): void
    {
        $this->increment('utilized_amount', $amount);
        $this->decrement('remaining_amount', $amount);
    }

    public function isExpiredByDate(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }
}