<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OTP Verification - Time Vault</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f8f9fa;">
    <div style="background-color: #ffffff; padding: 40px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
        <div style="text-align: center; margin-bottom: 30px;">
            <h1 style="color: #007bff; margin: 0; font-size: 24px;">Time Vault</h1>
        </div>
        
        <h2 style="color: #333; margin-top: 0; font-size: 20px;">
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
        
        <p style="margin: 20px 0;">Hello,</p>
        
        <p style="margin: 20px 0;">
            @if($type === 'verification')
                Thank you for registering with Time Vault. Please use the code below to verify your account.
            @elseif($type === 'password_reset')
                You requested to reset your password. Use the code below to proceed.
            @else
                Please use the verification code below.
            @endif
        </p>
        
        <div style="background-color: #f8f9fa; padding: 30px; border-radius: 8px; text-align: center; margin: 30px 0; border: 2px dashed #007bff;">
            <p style="margin: 0 0 10px 0; color: #666; font-size: 14px; text-transform: uppercase;">Verification Code</p>
            <h1 style="color: #007bff; font-size: 36px; letter-spacing: 8px; margin: 0; font-weight: bold;">{{ $otpCode }}</h1>
        </div>
        
        <p style="margin: 20px 0; color: #666;">
            <strong>Important:</strong> This code will expire in <strong>5 minutes</strong>.
        </p>
        
        <div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 30px 0; border-radius: 4px;">
            <p style="margin: 0; color: #856404; font-size: 14px;">
                <strong>Security Notice:</strong> If you didn't request this code, please ignore this email and ensure your account is secure.
            </p>
        </div>
        
        <hr style="border: none; border-top: 1px solid #e0e0e0; margin: 30px 0;">
        
        <p style="color: #999; font-size: 12px; text-align: center; margin: 20px 0 0 0;">
            This is an automated message from Time Vault. Please do not reply to this email.<br>
            © {{ date('Y') }} Time Vault. All rights reserved.
        </p>
    </div>
</body>
</html>

