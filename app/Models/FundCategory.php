<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FundCategory extends Model
{
   protected $table = 'fund_categories';

    protected $primaryKey = 'F_ID';

    protected $fillable = [
        'F_Code',
        'F_Description'
    ];

    public $timestamps = true;
}
