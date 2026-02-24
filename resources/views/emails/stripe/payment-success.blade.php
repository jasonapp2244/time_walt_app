<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Successful</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #000000; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;">
    <div style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <!-- Header -->
        <div style="background-color: #935510; padding: 30px; text-align: center;">
            <h1 style="color: #f2cf7a; margin: 0; font-size: 28px; font-weight: bold;">Payment Successful</h1>
        </div>

        <!-- Content -->
        <div style="padding: 40px 30px;">
            <p style="color: #000000; font-size: 18px; margin-top: 0;">Dear {{ $user->full_name }},</p>
            
            <p style="color: #000000; font-size: 16px; line-height: 1.8;">We are pleased to inform you that your payment has been processed successfully.</p>

            <!-- Payment Amount -->
            <div style="background-color: #f2cf7a; padding: 25px; border-radius: 8px; margin: 30px 0; text-align: center; border-left: 4px solid #935510;">
                @if($hold && $hold->title)
                <p style="color: #935510; font-size: 14px; margin: 0 0 10px 0; font-weight: bold;">
                    {{ $hold->title }}
                </p>
                @endif
                <p style="color: #000000; font-size: 20px; font-weight: bold; margin: 0;">
                    ${{ number_format($payment->amount, 2) }} {{ strtoupper($payment->currency) }}
                </p>
                @if($hold)
                <p style="color: #666666; font-size: 12px; margin: 10px 0 0 0;">
                    Hold ID: #{{ $hold->id }}
                </p>
                @endif
            </div>

            <p style="color: #000000; font-size: 16px; line-height: 1.8;">Thank you for your payment!</p>

            <p style="color: #000000; font-size: 16px; margin-top: 30px; line-height: 1.8;">
                Best regards,<br>
                <strong style="color: #935510;">{{ config('app.name') }} Team</strong>
            </p>
        </div>

        <!-- Footer -->
        <div style="background-color: #f5f5f5; padding: 20px; text-align: center; border-top: 1px solid #e0e0e0;">
            <p style="color: #666666; font-size: 12px; margin: 0;">
                This is an automated email. Please do not reply to this message.<br>
                If you have any questions, please contact our support team.
            </p>
        </div>
    </div>
</body>
</html>
