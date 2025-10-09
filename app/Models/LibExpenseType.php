<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LibExpenseType extends Model
{
    protected $table = 'lib_expense_types';

      protected $fillable = [
        'expense_class_id',
        'name',
        'order',
    ];

     protected static function booted()
    {
        static::addGlobalScope('ordered', function (Builder $builder) {
            $builder->orderBy('order', 'asc');
        });
    }
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

    // Add this scope for barangay filtering
public function scopeForBarangay($query, $barangayId)
{
    return $query->whereHas('expenseClass', function($q) use ($barangayId) {
        $q->where('barangay_id', $barangayId);
    });
}
}
