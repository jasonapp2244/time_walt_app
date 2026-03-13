<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OTP Verification - Time Vault</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333333; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;">
<div style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">

    {{-- Header --}}
    <div style="background-color: #935510; padding: 30px; text-align: center;">
        <h1 style="color: #f2cf7a; margin: 0; font-size: 28px; font-weight: bold;">Time Vault</h1>
        <p style="color: #f2cf7a; margin: 8px 0 0 0; font-size: 14px; letter-spacing: 1px; text-transform: uppercase; opacity: 0.85;">
            @if($type === 'verification')
                Account Verification
            @elseif($type === 'password_reset')
                Password Reset
            @elseif($type === 'login')
                Login Verification
            @else
                OTP Code
            @endif
        </p>
    </div>

    {{-- Body --}}
    <div style="padding: 40px 30px;">

        <p style="color: #000000; font-size: 18px; margin-top: 0;">Hello,</p>

        <p style="color: #000000; font-size: 16px; line-height: 1.8;">
            @if($type === 'verification')
                Thank you for registering with Time Vault. Please use the verification code below to confirm your account.
            @elseif($type === 'password_reset')
                We received a request to reset your password. Use the code below to proceed.
            @else
                Please use the verification code below to complete your request.
            @endif
        </p>

        {{-- OTP Box --}}
        <div style="background-color: #f2cf7a; padding: 25px; border-radius: 8px; margin: 25px 0; text-align: center; border-left: 4px solid #935510;">
            <p style="color: #935510; font-size: 12px; font-weight: bold; margin: 0 0 10px 0; text-transform: uppercase; letter-spacing: 2px;">Verification Code</p>
            <p style="color: #000000; font-size: 38px; font-weight: bold; letter-spacing: 10px; margin: 0; font-family: 'Courier New', monospace;">{{ $otpCode }}</p>
            <p style="color: #935510; font-size: 13px; margin: 10px 0 0 0; font-weight: bold;">Valid for 5 minutes</p>
        </div>

        {{-- Security Notice --}}
        <div style="background-color: #fdf6ec; border-left: 4px solid #935510; padding: 15px 20px; border-radius: 4px; margin: 25px 0;">
            <p style="color: #935510; font-size: 12px; font-weight: bold; margin: 0 0 6px 0; text-transform: uppercase; letter-spacing: 1px;">Security Notice</p>
            <p style="color: #000000; font-size: 14px; margin: 0; line-height: 1.7;">
                If you did not request this code, please ignore this email and ensure your account is secure. Do not share this code with anyone.
            </p>
        </div>

        <p style="color: #000000; font-size: 16px; margin-top: 30px; line-height: 1.8;">
            Best regards,<br>
            <strong style="color: #935510;">{{ config('app.name') }} Team</strong>
        </p>

    </div>

    {{-- Footer --}}
    <div style="background-color: #f5f5f5; padding: 20px; text-align: center; border-top: 1px solid #e0e0e0;">
        <p style="color: #666666; font-size: 12px; margin: 0;">
            This is an automated message. Please do not reply to this email.<br>
            If you have any questions, please contact our support team.<br>
            &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
        </p>
    </div>

</div>
</body>
</html>