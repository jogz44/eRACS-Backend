<?php

namespace App\Services;

use App\Models\Otp;
use App\Mail\SendOtpMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class OtpService
{
    public $error = "";

    /**
     * Generate a new OTP and send it via email.
     */
    public function generate(string $email): bool
    {
        // 1️⃣ Generate OTP (6 digits)
        $otp = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

        // 2️⃣ Store OTP in the database (hashed)
        Otp::updateOrCreate(
            ['email' => $email],
            [
                'pass' => bcrypt($otp),
                'timestamp' => Carbon::now(),
            ]
        );

        try {
            // 3️⃣ Send OTP via Laravel Mail
            Mail::to($email)->send(new SendOtpMail($otp));
            return true;
        } catch (\Exception $e) {
            $this->error = "Failed to send OTP email: " . $e->getMessage();
            return false;
        }
    }

    /**
     * Verify an OTP.
     */
    public function verify(string $email, string $pass): bool
    {
        $otp = Otp::where('email', $email)->first();

        if (!$otp) {
            $this->error = "OTP not found.";
            return false;
        }

        // Check expiry (15 mins default)
        $validMinutes = config('otp.validity', 15);
        if (Carbon::parse($otp->timestamp)->addMinutes($validMinutes)->isPast()) {
            $this->error = "OTP expired.";
            return false;
        }

        // Check password
        if (!password_verify($pass, $otp->pass)) {
            $this->error = "Incorrect OTP.";
            return false;
        }

        // Delete OTP after successful verification
        $otp->delete();
        return true;
    }
}
