<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\ScopesBarangay as BarangayScope;

class AnnualBudgetDetail extends Model
{
    protected $fillable = [
        'budget_id',
        'barangay_id',
        'budget_category',
        'source_of_funds',
        'allocated_amount',
        'utilized_amount',
        'remaining_amount',
        'justification',
        'implementation_plan',
        'planned_start_date',
        'planned_end_date',
        'allocation_status',
        'allocation_approved_at',
        'allocation_approved_by'
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
        'utilized_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'planned_start_date' => 'date',
        'planned_end_date' => 'date',
        'allocation_approved_at' => 'datetime'
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

    public function allocationApprovedBy(): BelongsTo
    {
        return $this->belongsTo(BarangayUser::class, 'allocation_approved_by');
    }

    // Scopes
    public function scopeByCategory($query, string $category)
    {
        return $query->where('budget_category', $category);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('allocation_status', $status);
    }

    public function scopePlanned($query)
    {
        return $query->where('allocation_status', 'planned');
    }

    public function scopeApproved($query)
    {
        return $query->where('allocation_status', 'approved');
    }

    public function scopeActive($query)
    {
        return $query->where('allocation_status', 'active');
    }

    public function scopeCompleted($query)
    {
        return $query->where('allocation_status', 'completed');
    }

    // Helper methods
    public function isPlanned(): bool
    {
        return $this->allocation_status === 'planned';
    }

    public function isApproved(): bool
    {
        return $this->allocation_status === 'approved';
    }

    public function isActive(): bool
    {
        return $this->allocation_status === 'active';
    }

    public function isCompleted(): bool
    {
        return $this->allocation_status === 'completed';
    }

    public function isCancelled(): bool
    {
        return $this->allocation_status === 'cancelled';
    }

    public function approve(BarangayUser $user): void
    {
        $this->update([
            'allocation_status' => 'approved',
            'allocation_approved_at' => now(),
            'allocation_approved_by' => $user->id
        ]);
    }

    public function activate(): void
    {
        $this->update(['allocation_status' => 'active']);
    }

    public function complete(): void
    {
        $this->update(['allocation_status' => 'completed']);
    }

    public function cancel(): void
    {
        $this->update(['allocation_status' => 'cancelled']);
    }

    public function updateUtilization(float $amount): void
    {
        $this->increment('utilized_amount', $amount);
        $this->decrement('remaining_amount', $amount);
    }
}