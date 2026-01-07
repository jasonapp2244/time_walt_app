<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OTP Verification</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #f4f4f4; padding: 20px; border-radius: 5px;">
        <h2 style="color: #333; margin-top: 0;">
            @if($type === 'verification')
                Verify Your Account
            @elseif($type === 'password_reset')
                Reset Your Password
            @elseif($type === 'login')
                Login Verification
            @else
                OTP Code
            @endif
        </h2>
        
        <p>Hello,</p>
        
        <p>Your OTP code is:</p>
        
        <div style="background-color: #fff; padding: 20px; border-radius: 5px; text-align: center; margin: 20px 0;">
            <h1 style="color: #007bff; font-size: 32px; letter-spacing: 5px; margin: 0;">{{ $otpCode }}</h1>
        </div>
        
        <p>This code will expire in 5 minutes.</p>
        
        <p style="color: #666; font-size: 14px; margin-top: 30px;">
            If you didn't request this code, please ignore this email.
        </p>
    </div>
</body>
</html>

