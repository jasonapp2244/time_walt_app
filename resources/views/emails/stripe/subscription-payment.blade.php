<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $isRenewal ? 'Subscription Renewed' : 'Subscription Active' }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333333; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;">
<div style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">

    @if($isAdmin)
    <div style="background-color: #1a1a2e; padding: 10px 20px; text-align: center;">
        <span style="color: #f2cf7a; font-size: 12px; font-weight: bold; letter-spacing: 2px; text-transform: uppercase;">&#9679; Admin Notification</span>
    </div>
    @endif

    <div style="background-color: #935510; padding: 30px; text-align: center;">
        <h1 style="color: #f2cf7a; margin: 0; font-size: 28px; font-weight: bold; letter-spacing: 0.5px;">
            {{ $isRenewal ? 'Subscription Renewed' : 'Subscription Active' }}
        </h1>
    </div>

    <div style="padding: 40px 30px;">

        <p style="color: #333333; font-size: 18px; margin-top: 0; font-weight: bold;">Dear {{ $isAdmin ? 'Admin' : $name }},</p>

        <p style="color: #333333; font-size: 16px; line-height: 1.8;">
            @if($isAdmin)
                {{ $isRenewal ? 'A subscription renewed automatically and the payment was collected.' : 'A user has subscribed and the first payment was collected.' }}
            @elseif($isRenewal)
                Your subscription renewed automatically and your payment was received. Thank you for staying with {{ config('app.name') }}.
            @else
                Thank you for subscribing. Your payment was received and your subscription is active now: locks and withdrawals are no longer limited.
            @endif
        </p>

        @if($isAdmin)
        <div style="background-color: #f0f4ff; border-left: 4px solid #1a1a2e; padding: 15px 20px; border-radius: 4px; margin: 25px 0;">
            <p style="color: #1a1a2e; font-size: 12px; font-weight: bold; margin: 0 0 8px 0; text-transform: uppercase; letter-spacing: 1px;">Customer Details</p>
            <p style="color: #333333; font-size: 14px; margin: 4px 0;"><strong>Name:</strong> {{ $name }}</p>
            <p style="color: #333333; font-size: 14px; margin: 4px 0;"><strong>Email:</strong> {{ $userEmail }}</p>
            <p style="color: #333333; font-size: 14px; margin: 4px 0;"><strong>User ID:</strong> #{{ $invoice->user_id }}</p>
        </div>
        @endif

        <div style="background-color: #f2cf7a; padding: 25px; border-radius: 8px; margin: 25px 0; text-align: center; border-left: 4px solid #935510;">
            <p style="color: #6b3a0a; font-size: 18px; margin: 0 0 12px 0; font-weight: bold;">{{ $plan?->name ?? 'Subscription' }}</p>
            <p style="color: #000000; font-size: 32px; font-weight: bold; margin: 0;">
                {{ number_format((float) $invoice->amount, 2) }} <span style="font-size: 16px;">{{ strtoupper($invoice->currency) }}</span>
            </p>
            <p style="color: #6b3a0a; font-size: 13px; margin: 10px 0 0 0; font-weight: bold;">
                {{ $plan?->interval === 'year' ? 'Billed yearly' : 'Billed monthly' }}
                @if($invoice->period_end)
                    &middot; {{ $isAdmin ? 'Paid until' : 'Next renewal' }} {{ $invoice->period_end->format('d M Y') }}
                @endif
            </p>
        </div>

        <table style="width: 100%; font-size: 14px; color: #333333; margin: 0 0 20px 0; border-collapse: collapse;">
            @if($invoice->invoice_number)
            <tr><td style="padding: 4px 0; color: #666666;">Invoice</td><td style="padding: 4px 0; text-align: right;">{{ $invoice->invoice_number }}</td></tr>
            @endif
            <tr><td style="padding: 4px 0; color: #666666;">Paid on</td><td style="padding: 4px 0; text-align: right;">{{ $invoice->paid_at->format('d M Y H:i') }} UTC</td></tr>
            <tr><td style="padding: 4px 0; color: #666666;">Type</td><td style="padding: 4px 0; text-align: right;">{{ $invoice->typeLabel() }}</td></tr>
        </table>

        @if(! $isAdmin && $invoice->hosted_invoice_url)
        <div style="text-align: center; margin: 28px 0;">
            <a href="{{ $invoice->hosted_invoice_url }}"
               style="background-color: #935510; color: #f2cf7a; padding: 12px 26px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 15px;">
                View receipt
            </a>
        </div>
        @endif

        @if(! $isAdmin)
        <p style="color: #666666; font-size: 13px;">
            You can cancel any time from <strong>My Subscription</strong> in the app; you keep access until the end of the paid period.
        </p>
        @endif

        <p style="color: #333333; font-size: 16px; margin-top: 30px; line-height: 1.8;">
            Best regards,<br>
            <strong style="color: #935510;">{{ config('app.name') }} Team</strong>
        </p>
    </div>

    <div style="background-color: #f5f5f5; padding: 20px; text-align: center; border-top: 1px solid #e0e0e0;">
        <p style="color: #888888; font-size: 12px; margin: 0;">
            This is an automated email. Please do not reply to this message.<br>
            If you have any questions, please contact our support team.
        </p>
    </div>
</div>
</body>
</html>
