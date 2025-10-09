<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Otp extends Model
{
    protected $table = 'tblotp';
    public $timestamps = false; // since you're using a custom timestamp column

    protected $fillable = ['email', 'pass', 'timestamp'];
}
