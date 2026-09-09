<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LibExpenseSubSubType extends Model
{
    protected $table = 'lib_expense_sub_sub_types';

    protected $fillable = [
        'sub_type_id',
        'name',
        'order',
    ];

    public function subType(): BelongsTo
    {
        return $this->belongsTo(
            LibExpenseSubType::class,
            'sub_type_id'
        );
    }
}
