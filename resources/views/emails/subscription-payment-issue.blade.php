<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $kind === 'action_required' ? 'Confirm your payment' : 'Payment failed' }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #000000; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;">
    <div style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        @if($isAdmin ?? false)
        <div style="background-color: #1a1a2e; padding: 10px 20px; text-align: center;">
            <span style="color: #f2cf7a; font-size: 12px; font-weight: bold; letter-spacing: 2px; text-transform: uppercase;">&#9679; Admin Notification</span>
        </div>
        @endif

        <!-- Header -->
        <div style="background-color: #935510; padding: 30px; text-align: center;">
            <h1 style="color: #f2cf7a; margin: 0; font-size: 26px; font-weight: bold;">
                {{ $kind === 'action_required' ? 'Confirm Your Payment' : 'Renewal Payment Failed' }}
            </h1>
        </div>

        <!-- Content -->
        <div style="padding: 40px 30px;">
            @if($isAdmin ?? false)
            <p style="color: #000000; font-size: 16px; margin-top: 0;">Hi Admin,</p>

            <p style="color: #000000; font-size: 16px;">
                {{ $kind === 'action_required' ? 'A renewal is waiting for the customer to confirm 3-D Secure.' : 'A renewal payment was declined. Stripe will retry it automatically; the customer has been emailed a link to pay.' }}
            </p>

            <div style="background-color: #f0f4ff; border-left: 4px solid #1a1a2e; padding: 15px 20px; border-radius: 4px; margin: 25px 0;">
                <p style="color: #333333; font-size: 14px; margin: 4px 0;"><strong>Customer:</strong> {{ $name }} ({{ $userEmail }})</p>
                <p style="color: #333333; font-size: 14px; margin: 4px 0;"><strong>Plan:</strong> {{ $subscription->plan?->name ?? '—' }}</p>
                <p style="color: #333333; font-size: 14px; margin: 4px 0;"><strong>Subscription:</strong> #{{ str_pad($subscription->id, 5, '0', STR_PAD_LEFT) }}</p>
            </div>
            @else
            <p style="color: #000000; font-size: 16px; margin-top: 0;">Hi {{ $name }},</p>

            @if($kind === 'action_required')
                <p style="color: #000000; font-size: 16px;">
                    Your bank needs you to confirm the renewal payment for your
                    <strong>{{ $subscription->plan?->name }}</strong> subscription before it can be charged.
                </p>
            @else
                <p style="color: #000000; font-size: 16px;">
                    We could not charge your card for your <strong>{{ $subscription->plan?->name }}</strong>
                    subscription. We will try again over the next few days. If every attempt fails, the
                    subscription ends and the free-tier limits apply again.
                </p>
            @endif

            @if($invoiceUrl)
                <div style="text-align: center; margin: 32px 0;">
                    <a href="{{ $invoiceUrl }}"
                       style="background-color: #935510; color: #f2cf7a; padding: 14px 28px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 16px;">
                        {{ $kind === 'action_required' ? 'Confirm payment' : 'Pay now' }}
                    </a>
                </div>
            @endif

            <p style="color: #000000; font-size: 16px;">
                You can also update your card from <strong>My Subscription</strong> in the {{ config('app.name') }} app.
            </p>
            @endif
        </div>

        <!-- Footer -->
        <div style="background-color: #f5f5f5; padding: 20px; text-align: center; border-top: 1px solid #e0e0e0;">
            <p style="color: #666666; font-size: 12px; margin: 0;">
                You are receiving this because you have a {{ config('app.name') }} subscription.<br>
                Replying to this email reaches our support team directly.
            </p>
        </div>
    </div>
</body>
</html>
