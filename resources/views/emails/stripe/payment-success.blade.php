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
        <div style="padding: 30px;">
            <p style="color: #000000; font-size: 16px; margin-top: 0;">Dear {{ $user->full_name }},</p>
            
            <p style="color: #000000; font-size: 16px;">We are pleased to inform you that your payment has been processed successfully.</p>

            <!-- Payment Details Box -->
            <div style="background-color: #f2cf7a; padding: 20px; border-radius: 6px; margin: 25px 0; border-left: 4px solid #935510;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="padding: 8px 0; color: #000000; font-weight: bold;">Payment Amount:</td>
                        <td style="padding: 8px 0; color: #000000; text-align: right; font-size: 18px; font-weight: bold;">${{ number_format($payment->amount, 2) }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0; color: #000000; font-weight: bold;">Currency:</td>
                        <td style="padding: 8px 0; color: #000000; text-align: right; text-transform: uppercase;">{{ $payment->currency }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0; color: #000000; font-weight: bold;">Payment ID:</td>
                        <td style="padding: 8px 0; color: #000000; text-align: right; font-family: monospace; font-size: 12px;">{{ $payment->payment_intent_id }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0; color: #000000; font-weight: bold;">Date:</td>
                        <td style="padding: 8px 0; color: #000000; text-align: right;">{{ $payment->paid_at?->format('F d, Y h:i A') ?? now()->format('F d, Y h:i A') }}</td>
                    </tr>
                </table>
            </div>

            @if($hold)
            <div style="background-color: #f9f9f9; padding: 15px; border-radius: 6px; margin: 20px 0;">
                <p style="color: #000000; font-size: 14px; margin: 0;">
                    <strong>Hold Period:</strong> {{ $hold->hold_period_type }} 
                    @if($hold->hold_end_at)
                        (Ends: {{ $hold->hold_end_at->format('F d, Y') }})
                    @endif
                </p>
            </div>
            @endif

            <p style="color: #000000; font-size: 16px;">Your payment has been received and is now being held according to your selected hold period.</p>

            <p style="color: #000000; font-size: 16px; margin-top: 30px;">Thank you for your payment!</p>

            <p style="color: #000000; font-size: 16px; margin-top: 20px;">
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
