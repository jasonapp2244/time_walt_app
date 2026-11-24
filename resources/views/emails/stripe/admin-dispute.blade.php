<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chargeback</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333333; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;">
@php
    $user = $dispute->user;
    $colour = $event === 'won' ? '#1e8c3a' : '#c82333';
    $title = match ($event) { 'won' => 'Chargeback Won', 'lost' => 'Chargeback Lost', default => 'Chargeback Opened' };
    $deposit = $dispute->payment_hold_id ? '#'.str_pad((string) $dispute->payment_hold_id, 5, '0', STR_PAD_LEFT) : '—';
    $isSubscription = $dispute->subscription_invoice_id !== null;
@endphp
<div style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">

    <div style="background-color: #1a1a2e; padding: 10px 20px; text-align: center;">
        <span style="color: #f2cf7a; font-size: 12px; font-weight: bold; letter-spacing: 2px; text-transform: uppercase;">&#9679; Admin Notification</span>
    </div>

    <div style="background-color: {{ $colour }}; padding: 30px; text-align: center;">
        <h1 style="color: #ffffff; margin: 0; font-size: 26px; font-weight: bold;">{{ $title }}</h1>
    </div>

    <div style="padding: 35px 30px;">
        <p style="color: #333333; font-size: 16px; margin-top: 0;">
            {{ $user?->displayName('Unknown') ?? 'Deleted user' }} (user #{{ $dispute->user_id ?? '—' }}) disputed
            <strong>${{ number_format((float) $dispute->amount, 2) }}</strong> of {{ $isSubscription ? 'a subscription payment' : 'deposit '.$deposit }} with their bank.
        </p>

        <div style="background-color: #fff8e8; border-left: 4px solid #935510; padding: 15px 20px; border-radius: 4px; margin: 20px 0;">
            <p style="color: #333333; font-size: 14px; margin: 4px 0;"><strong>Reason:</strong> {{ $dispute->reasonLabel() }}</p>
            <p style="color: #333333; font-size: 14px; margin: 4px 0;"><strong>Stripe status:</strong> {{ str_replace('_', ' ', $dispute->stripe_status) }}</p>
            @if($dispute->evidence_due_by && $event === 'opened')
            <p style="color: #c82333; font-size: 14px; margin: 4px 0;"><strong>Send evidence before:</strong> {{ $dispute->evidence_due_by->setTimezone(config('app.admin_timezone'))->format('d M Y, h:i A') }}</p>
            @endif
            <p style="color: #333333; font-size: 14px; margin: 4px 0;"><strong>Stripe dispute:</strong> …{{ substr($dispute->stripe_dispute_id, -8) }}</p>
        </div>

        <p style="color: #333333; font-size: 15px; line-height: 1.8;">
            @if($isSubscription)
                @if($event === 'opened')
                    This is a subscription payment, so no vault money is involved. Answer the dispute in the Stripe Dashboard (Payments → Disputes) before the deadline.
                @elseif($event === 'won')
                    The bank decided for Time Vault. The subscription payment stands.
                @else
                    The bank returned this subscription payment to the customer.
                @endif
            @elseif($event === 'opened')
                <strong>This deposit is now frozen:</strong> the user cannot withdraw it and it cannot be refunded until the bank decides.
                Answer the dispute in the Stripe Dashboard (Payments → Disputes) before the deadline.
            @elseif($event === 'won')
                The bank decided for Time Vault. The freeze is lifted and the money stays in the user's vault.
            @else
                The bank returned the money to the customer. ${{ number_format((float) $dispute->vault_amount, 2) }} was taken out of the user's vault so it cannot be paid out again.
            @endif
        </p>

        @if($dispute->needs_attention)
        <div style="background-color: #fdecea; border-left: 4px solid #c82333; padding: 12px 18px; border-radius: 4px; margin: 20px 0;">
            <p style="color: #c82333; font-size: 14px; margin: 0; font-weight: bold;">Needs attention: {{ $dispute->note }}</p>
        </div>
        @endif

        <p style="text-align: center; margin: 30px 0 10px;">
            <a href="{{ route('admin.refunds.index') }}" style="background-color: #935510; color: #ffffff; text-decoration: none; padding: 12px 26px; border-radius: 6px; font-weight: bold;">Open Refunds &amp; Disputes</a>
        </p>
    </div>
</div>
</body>
</html>
