<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use App\Mail\OtpMail;

class OtpVerification extends Model
{
    protected $fillable = [
        'email',
        'otp',
        'expires_at',
        'is_used'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_used' => 'boolean',
    ];

    /**
     * Generate and send OTP
     */
    public static function generate(string $email): string
    {
        // Generate 6-digit OTP
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Delete old OTPs for this email
        self::where('email', $email)->delete();

        // Create new OTP (expires in 10 minutes)
        self::create([
            'email' => $email,
            'otp' => $otp,
            'expires_at' => now()->addMinutes(10),
        ]);

        // Send email
        Mail::to($email)->send(new OtpMail($otp));

        return $otp;
    }

    /**
     * Verify OTP
     */
    public static function verify(string $email, string $otp): bool
    {
        $record = self::where('email', $email)
            ->where('otp', $otp)
            ->where('is_used', false)
            ->where('expires_at', '>', now())
            ->first();

        if ($record) {
            $record->update(['is_used' => true]);
            return true;
        }

        return false;
    }

    /**
     * Clean up expired OTPs
     */
    public static function cleanExpired(): void
    {
        self::where('expires_at', '<', now())
            ->orWhere('is_used', true)
            ->delete();
    }
}
