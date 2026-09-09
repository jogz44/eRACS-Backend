<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LibExpenseItem extends Model
{
     protected $table = 'lib_expense_items';

      protected $fillable = [
        'expense_type_id',
        'parent_item_id',
        'name',
        'order',
    ];

    // Relationships
    public function expenseType()
    {
        return $this->belongsTo(LibExpenseType::class, 'expense_type_id');
    }

    public function parentItem()
    {
        return $this->belongsTo(LibExpenseItem::class, 'parent_item_id');
    }

    public function childItems()
    {
        return $this->hasMany(LibExpenseItem::class, 'parent_item_id');
    }

    public function subItems(): HasMany
    {
        return $this->hasMany(
            LibExpenseSubItem::class,
            'expense_item_id'
        )->orderBy('order');
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

    public function scopeRootItems(Builder $query): Builder
    {
        return $query->whereNull('parent_item_id');
    }

    public function scopeChildItems(Builder $query): Builder
    {
        return $query->whereNotNull('parent_item_id');
    }
}
