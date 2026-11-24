<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refund Issued</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333333; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;">
@php
    $user = $refund->user;
    $details = [
        'Deposit (hold)' => $refund->payment_hold_id ? '#'.str_pad((string) $refund->payment_hold_id, 5, '0', STR_PAD_LEFT) : '—',
        'Card' => $refund->cardLabel(),
        'Amount' => '$'.number_format((float) $refund->amount, 2),
        'Type' => $refund->isPartial() ? 'Partial refund' : 'Full refund',
        'Refunded on this deposit so far' => $refund->payment ? '$'.number_format((float) $refund->payment->refunded_amount, 2).' of $'.number_format((float) $refund->payment->amount, 2) : '—',
        'From the vault' => '$'.number_format((float) $refund->vault_amount, 2),
        'Pulled back from Connect' => '$'.number_format((float) $refund->reversed_amount, 2),
        'Reason' => $refund->reasonLabel(),
        'Note' => $refund->reason_note ?? '—',
        'Done by' => $refund->source === 'stripe_dashboard' ? 'Stripe Dashboard' : ($refund->admin?->displayName('Admin') ?? 'Admin'),
        'Time' => $refund->created_at?->setTimezone(config('app.admin_timezone'))->format('d M Y, h:i A'),
        'Stripe refund' => $refund->safe('stripe_refund_id'),
    ];
@endphp
<div style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">

    <div style="background-color: #1a1a2e; padding: 10px 20px; text-align: center;">
        <span style="color: #f2cf7a; font-size: 12px; font-weight: bold; letter-spacing: 2px; text-transform: uppercase;">&#9679; Admin Notification</span>
    </div>

    <div style="background-color: #935510; padding: 30px; text-align: center;">
        <h1 style="color: #f2cf7a; margin: 0; font-size: 26px; font-weight: bold;">Refund Issued</h1>
    </div>

    <div style="padding: 35px 30px;">
        <p style="color: #333333; font-size: 16px; margin-top: 0;">
            ${{ number_format((float) $refund->amount, 2) }} {{ strtoupper($refund->currency) }} was refunded to {{ $refund->cardLabel() }}.
            Stripe status: <strong>{{ ucfirst($refund->status) }}</strong>.
        </p>

        @if($refund->needs_attention)
        <div style="background-color: #fdecea; border-left: 4px solid #c82333; padding: 12px 18px; border-radius: 4px; margin: 20px 0;">
            <p style="color: #c82333; font-size: 14px; margin: 0; font-weight: bold;">Needs attention: {{ $refund->failure_reason }}</p>
        </div>
        @endif

        <div style="background-color: #f0f4ff; border-left: 4px solid #1a1a2e; padding: 15px 20px; border-radius: 4px; margin: 20px 0;">
            <p style="color: #1a1a2e; font-size: 12px; font-weight: bold; margin: 0 0 8px 0; text-transform: uppercase; letter-spacing: 1px;">Customer</p>
            <p style="color: #333333; font-size: 14px; margin: 4px 0;"><strong>Name:</strong> {{ $user?->displayName('Unknown') ?? 'Deleted user' }}</p>
            <p style="color: #333333; font-size: 14px; margin: 4px 0;"><strong>Email:</strong> {{ $user?->displayEmail() ?? '—' }}</p>
            <p style="color: #333333; font-size: 14px; margin: 4px 0;"><strong>User ID:</strong> {{ $refund->user_id ?? '—' }}</p>
        </div>

        <div style="background-color: #fff8e8; border-left: 4px solid #935510; padding: 15px 20px; border-radius: 4px; margin: 20px 0;">
            <p style="color: #6b3a0a; font-size: 12px; font-weight: bold; margin: 0 0 8px 0; text-transform: uppercase; letter-spacing: 1px;">Refund</p>
            @foreach($details as $label => $value)
            <p style="color: #333333; font-size: 14px; margin: 4px 0;"><strong>{{ $label }}:</strong> {{ $value }}</p>
            @endforeach
        </div>

        <p style="text-align: center; margin: 30px 0 10px;">
            <a href="{{ route('admin.refunds.index') }}" style="background-color: #935510; color: #ffffff; text-decoration: none; padding: 12px 26px; border-radius: 6px; font-weight: bold;">Open Refunds</a>
        </p>
    </div>
</div>
</body>
</html>
