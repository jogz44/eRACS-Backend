<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\LibFiscalYear;
use App\Models\Concerns\ScopesBarangay as BarangayScope;
use App\BudgetType;

class Budget extends Model
{
    protected $fillable = [
        'barangay_id',
        'fiscal_year_id',
        'start_date',
        'end_date',
        'description',
        'budget_type',
        'status',
        'approved_at',
        'approved_by',
        'effective_date',
        'original_amount',
        'current_amount',
        'augmentation',
        'return_amount',
        'user_id'
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'effective_date' => 'date',
        'approved_at' => 'datetime',
        'budget_type' => BudgetType::class,
        'original_amount' => 'decimal:2',
        'current_amount' => 'decimal:2',
        'augmentation' => 'decimal:2',
        'return_amount' => 'decimal:2'
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BarangayScope);
    }

    public function tranAppropriations(): HasMany
    {
        return $this->hasMany(TranAppropriation::class,'budget_id');
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class,'barangay_id');
    }

    public function fiscalYear() {
        return $this->belongsTo(LibFiscalYear::class,'fiscal_year_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(BarangayUser::class, 'approved_by');
    }

    public function annualBudgetDetail(): HasOne
    {
        return $this->hasOne(AnnualBudgetDetail::class);
    }

    public function supplementalBudgetDetail(): HasOne
    {
        return $this->hasOne(SupplementalBudgetDetail::class);
    }

    // Scopes for budget types
    public function scopeAnnual(Builder $query): void
    {
        $query->where('budget_type', BudgetType::ANNUAL);
    }

    public function scopeSupplemental(Builder $query): void
    {
        $query->where('budget_type', BudgetType::SUPPLEMENTAL);
    }

    public function scopeByType(Builder $query, BudgetType $type): void
    {
        $query->where('budget_type', $type);
    }

    // Status scopes
    public function scopeDraft(Builder $query): void
    {
        $query->where('status', 'draft');
    }

    public function scopeApproved(Builder $query): void
    {
        $query->where('status', 'approved');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    public function scopeClosed(Builder $query): void
    {
        $query->where('status', 'closed');
    }

    // Helper methods
    public function isAnnual(): bool
    {
        return $this->budget_type === BudgetType::ANNUAL;
    }

    public function isSupplemental(): bool
    {
        return $this->budget_type === BudgetType::SUPPLEMENTAL;
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function approve(BarangayUser $user): void
    {
        $this->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $user->id
        ]);
    }

    public function activate(): void
    {
        $this->update(['status' => 'active']);
    }

    public function close(): void
    {
        $this->update(['status' => 'closed']);
    }
}
