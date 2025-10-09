<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OTP Verification</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
        <h1 style="color: white; margin: 0; font-size: 28px;">OTP Verification</h1>
    </div>

    <div style="background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
        <p style="font-size: 16px; margin-bottom: 20px;">Hello,</p>

        <p style="font-size: 16px; margin-bottom: 20px;">Your OTP verification code is:</p>

        <div style="background: white; padding: 20px; text-align: center; border-radius: 8px; margin: 30px 0; border: 2px dashed #667eea;">
            <h2 style="color: #667eea; font-size: 36px; letter-spacing: 8px; margin: 0; font-weight: bold;">{{ $otp }}</h2>
        </div>

        <p style="font-size: 14px; color: #666; margin-bottom: 20px;">
            <strong>This code will expire in 10 minutes.</strong>
        </p>

        <p style="font-size: 14px; color: #666; margin-bottom: 20px;">
            If you didn't request this code, please ignore this email.
        </p>

        <hr style="border: none; border-top: 1px solid #ddd; margin: 30px 0;">

        <p style="font-size: 12px; color: #999; text-align: center; margin: 0;">
            This is an automated message, please do not reply to this email.
        </p>
    </div>
</body>
</html>
