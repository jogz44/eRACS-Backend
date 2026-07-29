<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BarangaySetup extends Model
{
    protected $table = 'barangay_setup';

    protected $fillable = [

        'barangay_id',

        'registered_user_id',

        'barangay_position_id',

        //transfered to brgy bank accounts table for multiple accnts
        // 'bank_id',
        // 'account_number',

        'noted_by',
        'noted_by_position_id',

        'certified_by',
        'certified_by_position_id',

    ];

    public function barangay()
    {
        return $this->belongsTo(Barangay::class);
    }

    public function user()
    {
        return $this->belongsTo(BarangayUser::class,'registered_user_id');
    }

    public function position()
    {
        return $this->belongsTo(BarangayPosition::class,'barangay_position_id');
    }

    public function notedByPosition()
    {
        return $this->belongsTo(BarangayPosition::class, 'noted_by_position_id');
    }

    public function certifiedByPosition()
    {
        return $this->belongsTo(BarangayPosition::class, 'certified_by_position_id');
    }

    public function bank()
    {
        return $this->belongsTo(LibBank::class);
    }

    public function getPreparedByAttribute()
    {
        if (!$this->user) {
            return null;
        }

        return collect([
            $this->user->first_name,
            $this->user->middle_name,
            $this->user->last_name,
        ])->filter()->implode(' ');
    }

    public function getBarangayPositionAttribute()
    {
        return optional($this->position)->name;
    }

    public function getNotedByPositionNameAttribute()
    {
        return optional($this->notedByPosition)->name;
    }

    public function getCertifiedByPositionNameAttribute()
    {
        return optional($this->certifiedByPosition)->name;
    }

    public function bankAccounts()
    {
        return $this->hasMany(
            BarangayBankAccount::class,
            'barangay_setup_id'
        );
    }

    public function defaultBankAccount()
    {
        return $this->hasOne(
            BarangayBankAccount::class,
            'barangay_setup_id'
        )->where('is_default', true);
    }
}
