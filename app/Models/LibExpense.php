<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class LibExpenseClass extends Model
{
    protected $table = 'lib_expense_classes';

    // Relationships
    public function barangay()
    {
        return $this->belongsTo(Barangay::class);
    }

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

class LibExpenseType extends Model
{
    protected $table = 'lib_expense_types';

    // Relationships
    public function expenseClass()
    {
        return $this->belongsTo(LibExpenseClass::class, 'expense_class_id');
    }

    public function items()
    {
        return $this->hasMany(LibExpenseItem::class, 'expense_type_id');
    }

    // Scopes
    public function scopeForClass(Builder $query, $classId): Builder
    {
        return $query->where('expense_class_id', $classId);
    }

    public function scopeWithItems(Builder $query): Builder
    {
        return $query->with(['items' => function($q) {
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

class LibExpenseItem extends Model
{
    protected $table = 'lib_expense_items';

    // Relationships
    public function expenseType()
    {
        return $this->belongsTo(LibExpenseType::class, 'expense_type_id');
    }

    // Scopes
    public function scopeForType(Builder $query, $typeId): Builder
    {
        return $query->where('expense_type_id', $typeId);
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
