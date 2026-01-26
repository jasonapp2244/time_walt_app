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
        <div style="padding: 30px;">
            <p style="color: #000000; font-size: 16px; margin-top: 0;">Dear {{ $user->full_name }},</p>
            
            <p style="color: #000000; font-size: 16px;">We are pleased to inform you that your payment hold period has ended and your funds are now ready for transfer.</p>

            <!-- Hold Details Box -->
            <div style="background-color: #f2cf7a; padding: 20px; border-radius: 6px; margin: 25px 0; border-left: 4px solid #935510;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="padding: 8px 0; color: #000000; font-weight: bold;">Amount:</td>
                        <td style="padding: 8px 0; color: #000000; text-align: right; font-size: 18px; font-weight: bold;">${{ number_format($hold->amount, 2) }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0; color: #000000; font-weight: bold;">Hold Period:</td>
                        <td style="padding: 8px 0; color: #000000; text-align: right;">{{ ucfirst(str_replace('_', ' ', $hold->hold_period_type ?? 'N/A')) }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0; color: #000000; font-weight: bold;">Hold Start Date:</td>
                        <td style="padding: 8px 0; color: #000000; text-align: right;">{{ $hold->hold_start_at?->format('F d, Y') ?? 'N/A' }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0; color: #000000; font-weight: bold;">Hold End Date:</td>
                        <td style="padding: 8px 0; color: #000000; text-align: right;">{{ $hold->hold_end_at?->format('F d, Y') ?? 'N/A' }}</td>
                    </tr>
                </table>
            </div>

            <p style="color: #000000; font-size: 16px;">You can now request a payout for this amount. The funds will be transferred to your connected account.</p>

            <p style="color: #000000; font-size: 16px; margin-top: 30px;">To request your payout, please log in to your account and submit a payout request.</p>

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
