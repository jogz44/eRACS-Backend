<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BarangayPosition extends Model
{
    use HasFactory;

    protected $fillable = [
        'name'
    ];

    /**
     * Get the barangay users that have this position.
     */
    public function barangayUsers()
    {
        return $this->hasMany(BarangayUser::class, 'position_id');
    }

    /**
     * Scope a query to only include active positions.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the position name.
     */
    public function getNameAttribute($value)
    {
        return ucwords($value);
    }
}
