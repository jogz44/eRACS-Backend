<?php

use Illuminate\Support\Facades\Route;
use App\Services\OtpService;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/test-mail', function () {
    try {
        Mail::raw('This is a test email from ERACS backend.', function ($message) {
            $message->to('annjogz@gmail.com')
                    ->subject('Test Mail');
        });
        return 'Email sent!';
    } catch (\Exception $e) {
        return 'Error: ' . $e->getMessage();
    }
});

Route::get('/send-otp-test', function () {
    $service = new OtpService();
    if ($service->generate('annjogz@gmail.com')) {
        return "OTP sent!";
    } else {
        return "Error: " . $service->error;
    }
});
