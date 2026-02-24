<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hold Period Ended</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #000000; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;">
    <div style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <!-- Header -->
        <div style="background-color: #935510; padding: 30px; text-align: center;">
            <h1 style="color: #f2cf7a; margin: 0; font-size: 28px; font-weight: bold;">Hold Period Ended</h1>
        </div>

        <!-- Content -->
        <div style="padding: 40px 30px;">
            <p style="color: #000000; font-size: 18px; margin-top: 0;">Dear {{ $user->full_name }},</p>

            <p style="color: #000000; font-size: 16px; line-height: 1.8;">Great news! Your hold period has ended and your funds are now ready for transfer.</p>

            <!-- Amount Box -->
            <div style="background-color: #f2cf7a; padding: 25px; border-radius: 8px; margin: 30px 0; text-align: center; border-left: 4px solid #935510;">
                @if($hold->title)
                <p style="color: #935510; font-size: 14px; margin: 0 0 10px 0; font-weight: bold;">
                    {{ $hold->title }}
                </p>
                @endif
                <p style="color: #000000; font-size: 20px; font-weight: bold; margin: 0;">
                    ${{ number_format($hold->amount, 2) }} USD
                </p>
                <p style="color: #666666; font-size: 14px; margin: 10px 0 0 0;">
                    Ready for payout
                </p>
                <p style="color: #666666; font-size: 12px; margin: 10px 0 0 0;">
                    Hold ID: #{{ $hold->id }}
                </p>
            </div>

            <p style="color: #000000; font-size: 16px; line-height: 1.8; text-align: center;">You can now request your payout through the app.</p>

            <!-- Button -->
            <div style="text-align: center; margin: 35px 0;">
                <a href="{{ config('app.frontend_url', config('app.url')) }}/payment-holds" 
                   style="display: inline-block; background-color: #935510; color: #f2cf7a; padding: 15px 40px; text-decoration: none; border-radius: 6px; font-size: 16px; font-weight: bold;">
                    Request Payout
                </a>
            </div>

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
