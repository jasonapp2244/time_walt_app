<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Withdrawal Reversed</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333333; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;">
@php
    $user = $transfer->user;
    $deposit = $transfer->hold_id ? '#'.str_pad((string) $transfer->hold_id, 5, '0', STR_PAD_LEFT) : '—';
@endphp
<div style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">

    <div style="background-color: #1a1a2e; padding: 10px 20px; text-align: center;">
        <span style="color: #f2cf7a; font-size: 12px; font-weight: bold; letter-spacing: 2px; text-transform: uppercase;">&#9679; Admin Notification</span>
    </div>

    <div style="background-color: #935510; padding: 30px; text-align: center;">
        <h1 style="color: #f2cf7a; margin: 0; font-size: 26px; font-weight: bold;">Withdrawal Reversed in Stripe</h1>
    </div>

    <div style="padding: 35px 30px;">
        <p style="color: #333333; font-size: 16px; margin-top: 0;">
            Someone reversed <strong>${{ number_format($amount, 2) }}</strong> of withdrawal #{{ str_pad((string) $transfer->id, 5, '0', STR_PAD_LEFT) }}
            directly in the Stripe Dashboard. It was not done from the admin panel.
        </p>

        <div style="background-color: #fff8e8; border-left: 4px solid #935510; padding: 15px 20px; border-radius: 4px; margin: 20px 0;">
            <p style="color: #333333; font-size: 14px; margin: 4px 0;"><strong>User:</strong> {{ $user?->displayName('Unknown') ?? 'Deleted user' }} (user #{{ $transfer->user_id ?? '—' }})</p>
            <p style="color: #333333; font-size: 14px; margin: 4px 0;"><strong>Deposit:</strong> {{ $deposit }}</p>
            <p style="color: #333333; font-size: 14px; margin: 4px 0;"><strong>Withdrawal amount:</strong> ${{ number_format((float) $transfer->amount, 2) }}</p>
            <p style="color: #333333; font-size: 14px; margin: 4px 0;"><strong>Reversed by hand in total:</strong> ${{ number_format((float) $transfer->manual_reversed_amount, 2) }}</p>
        </div>

        <p style="color: #333333; font-size: 15px; line-height: 1.8;">
            The money is back in the platform balance, so it was put back in the user's vault: the user can withdraw it again.
            If this reversal was a mistake, contact the user. Use the admin panel for refunds next time.
        </p>

        @if($user)
        <p style="text-align: center; margin: 30px 0 10px;">
            <a href="{{ route('admin.users.show', $transfer->user_id) }}" style="background-color: #935510; color: #ffffff; text-decoration: none; padding: 12px 26px; border-radius: 6px; font-weight: bold;">Open User</a>
        </p>
        @endif
    </div>
</div>
</body>
</html>
