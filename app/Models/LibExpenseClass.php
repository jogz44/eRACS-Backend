<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LibExpenseClass extends Model
{
    // Relationships
    public function barangay()
    {
        return $this->belongsTo(Barangay::class);
    }

    protected $fillable = [
        'barangay_id',
        'fiscal_year_id',
        'name',
        'order',
    ];

    public function fiscalYear()
    {
        return $this->belongsTo(LibFiscalYear::class, 'fiscal_year_id');
    }

    public function types()
    {
        return $this->hasMany(LibExpenseType::class, 'expense_class_id');
    }

    // Scopes
    public function scopeForBarangay(Builder $query, $barangayId): Builder
    {
        return $query->where('barangay_id', $barangayId);
    }

    public function scopeForFiscalYear(Builder $query, $fiscalYearId): Builder
    {
        return $query->where('fiscal_year_id', $fiscalYearId);
    }

    public function scopeWithTypes(Builder $query): Builder
    {
        return $query->with(['types' => function($q) {
            $q->ordered();
        }]);
    }

    public function scopeOrdered(Builder $query, string $direction = 'asc'): Builder
    {
        return $query->orderBy('order', $direction);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
