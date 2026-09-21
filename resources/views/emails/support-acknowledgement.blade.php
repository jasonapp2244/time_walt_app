<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>We received your request</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #000000; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;">
    <div style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <!-- Header -->
        <div style="background-color: #935510; padding: 30px; text-align: center;">
            <h1 style="color: #f2cf7a; margin: 0; font-size: 26px; font-weight: bold;">We're On It</h1>
        </div>

        <!-- Content -->
        <div style="padding: 40px 30px;">
            <p style="color: #000000; font-size: 16px; margin-top: 0;">Hi {{ $name }},</p>

            <p style="color: #000000; font-size: 16px;">
                Thanks for contacting {{ config('app.name') }} support. Your request has reached our team and
                someone will reply to this email address as soon as they have looked into it.
            </p>

            <!-- Subject -->
            <div style="background-color: #f2cf7a; padding: 20px; border-radius: 8px; margin: 24px 0; border-left: 4px solid #935510;">
                <p style="color: #000000; font-size: 14px; font-weight: bold; margin: 0 0 6px; text-transform: uppercase; letter-spacing: 0.5px;">Your subject</p>
                <p style="color: #935510; font-size: 18px; font-weight: bold; margin: 0;">{{ $supportRequest->subject }}</p>
            </div>

            <!-- Message -->
            <p style="color: #000000; font-size: 14px; font-weight: bold; margin: 0 0 8px; text-transform: uppercase; letter-spacing: 0.5px;">What you sent us</p>
            <div style="background-color: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px;">
                <p style="color: #000000; font-size: 16px; line-height: 1.8; margin: 0;">{{ $supportRequest->message }}</p>
            </div>

            <p style="color: #666666; font-size: 14px; margin-top: 24px;">
                Reference #{{ str_pad($supportRequest->id, 5, '0', STR_PAD_LEFT) }} &middot;
                {{ $supportRequest->created_at?->setTimezone(config('app.admin_timezone'))->format('d M Y H:i') }}
            </p>
        </div>

        <!-- Footer -->
        <div style="background-color: #f5f5f5; padding: 20px; text-align: center; border-top: 1px solid #e0e0e0;">
            <p style="color: #666666; font-size: 12px; margin: 0;">
                You are receiving this because you contacted support from the {{ config('app.name') }} app.<br>
                Replying to this email reaches our support team directly.
            </p>
        </div>
    </div>
</body>
</html>
