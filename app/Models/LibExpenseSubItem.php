<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LibExpenseSubItem extends Model
{
    protected $table = 'lib_expense_sub_items';

    protected $fillable = [
        'expense_item_id',
        'name',
        'order',
    ];

    public function expenseItem(): BelongsTo
    {
        return $this->belongsTo(
            LibExpenseItem::class,
            'expense_item_id'
        );
    }

    public function subTypes(): HasMany
    {
        return $this->hasMany(
            LibExpenseSubType::class,
            'sub_item_id'
        )->orderBy('order');
    }
}
