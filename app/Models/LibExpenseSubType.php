<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LibExpenseSubType extends Model
{
    protected $table = 'lib_expense_sub_types';

    protected $fillable = [
        'sub_item_id',
        'name',
        'order',
    ];

    public function subItem(): BelongsTo
    {
        return $this->belongsTo(
            LibExpenseSubItem::class,
            'sub_item_id'
        );
    }

    public function subSubTypes(): HasMany
    {
        return $this->hasMany(
            LibExpenseSubSubType::class,
            'sub_type_id'
        )->orderBy('order');
    }
}
