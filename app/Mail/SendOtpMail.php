<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SendOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public $otp;

    /**
     * Create a new message instance.
     */
    public function __construct($otp)
    {
        $this->otp = $otp;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject('Your eRACS OTP Code')
                    ->html("
                        <h2>eRACS Verification</h2>
                        <p>Your OTP is <strong>{$this->otp}</strong>.</p>
                        <p>Please enter it on the verification page to continue.</p>
                    ");
    }
}
