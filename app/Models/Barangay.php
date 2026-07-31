<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;

class Barangay extends Model

{
    use Notifiable, HasFactory, HasApiTokens;

    protected $fillable = ['name'];

    public function budget()
    {
        return $this->hasMany(Budget::class,'barangay_id');
    }

    public function fiscalYear()
    {
        return $this->hasMany(LibFiscalYear::class,'barangay_id');
    }

    public function users()
    {
        return $this->hasMany(BarangayUser::class);
    }

    public function expenseClasses()
    {
        return $this->hasMany(ExpenseClass::class);
    }

    public function appropriations()
    {
        return $this->hasMany(TranAppropriation::class);
    }

    public function disbursement()
    {
        return $this->hasMany(Disbursement::class);
    }

    // Helper to get all barangays for dropdown
    public static function dropdown()
    {
        return self::orderBy('name')->pluck('name', 'id');
    }

    public function setup()
    {
        return $this->hasOne(BarangaySetup::class);
    }
}
