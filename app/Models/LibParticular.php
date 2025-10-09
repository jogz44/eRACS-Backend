<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LibParticular extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'lib_particulars';

    protected $fillable = [
        'barangay_id',
        'particular_name',
        'is_active',
        'created_by'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function barangay()
    {
        return $this->belongsTo(Barangay::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
