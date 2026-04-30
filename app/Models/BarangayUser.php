<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;

class BarangayUser extends Authenticatable
{
    use Notifiable, HasFactory, HasApiTokens;

    protected $table = 'barangay_users';
    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'barangay_id',
        'position_id',
        'suffix',
        'photo_path',
        'email',
        'username',
        'password',
        'role',
        'is_approved',
        'permissions',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'is_approved' => 'boolean',
        'last_login_at' => 'datetime',
        'permissions' => 'array',
    ];

     // Relationship to Barangay
    public function barangay()
    {
        return $this->belongsTo(Barangay::class);
    }

    // Relationship to BarangayPosition
    public function position()
    {
        return $this->belongsTo(BarangayPosition::class, 'position_id');
    }

    // Check if user is admin
    public function isAdmin()
    {
        return $this->role === 'admin';
    }

      //Check if user is approved
    //public function isApproved()
    //{
       // return $this->is_approved;
  //  }


    /**
     * Get the user's full name.
     *
     * @return string
     */
    public function getFullNameAttribute()
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * Get the URL to the user's profile photo.
     *
     * @return string
     */
    public function getPhotoUrlAttribute()
    {
        return $this->photo_path
            ? Storage::disk('public')->url($this->photo_path)
            : $this->defaultPhotoUrl();
    }

    /**
     * Get the default profile photo URL if no photo is uploaded.
     *
     * @return string
     */
    protected function defaultPhotoUrl()
    {
        $name = trim(collect(explode(' ', $this->full_name))->map(function ($segment) {
            return mb_substr($segment, 0, 1);
        })->join(""));

        return 'https://ui-avatars.com/api/?name='.urlencode($name).'&color=7F9CF5&background=EBF4FF';
    }

    /**
     * Scope a query to only include approved users.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeApproved($query)
    {
        return $query->where('is_approved', true);
    }

    /**
     * Scope a query to only include users from a specific barangay.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $barangay
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFromBarangay($query, $barangay)
    {
        return $query->where('barangay', $barangay);
    }

    /**
     * Record the user's last login time and IP address.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    public function recordLogin($request)
    {
        $this->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);
    }
}
