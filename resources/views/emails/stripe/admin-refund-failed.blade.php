<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refund Problem</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333333; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;">
@php
    $user = $refund->user;
    $review = $refund->status === 'pending';
@endphp
<div style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">

    <div style="background-color: #1a1a2e; padding: 10px 20px; text-align: center;">
        <span style="color: #f2cf7a; font-size: 12px; font-weight: bold; letter-spacing: 2px; text-transform: uppercase;">&#9679; Admin Notification</span>
    </div>

    <div style="background-color: #c82333; padding: 30px; text-align: center;">
        <h1 style="color: #ffffff; margin: 0; font-size: 26px; font-weight: bold;">{{ $review ? 'Refund Needs Review' : 'Refund Failed' }}</h1>
    </div>

    <div style="padding: 35px 30px;">
        <p style="color: #333333; font-size: 16px; margin-top: 0;">
            The refund of <strong>${{ number_format((float) $refund->amount, 2) }}</strong> to {{ $refund->cardLabel() }}
            for {{ $user?->displayName('Unknown') ?? 'Deleted user' }} (user #{{ $refund->user_id ?? '—' }}, deposit #{{ str_pad((string) $refund->payment_hold_id, 5, '0', STR_PAD_LEFT) }})
            {{ $review ? 'did not get an answer from Stripe.' : 'did not go through.' }}
        </p>

        <div style="background-color: #fdecea; border-left: 4px solid #c82333; padding: 12px 18px; border-radius: 4px; margin: 20px 0;">
            <p style="color: #c82333; font-size: 14px; margin: 0;"><strong>Reason:</strong> {{ $refund->failure_reason ?: 'Not given by Stripe' }}</p>
        </div>

        <p style="color: #333333; font-size: 15px; line-height: 1.8;">
            @if($review)
                The money is held back from the vault until Stripe confirms the outcome. Check this payment in the Stripe Dashboard before doing anything else, and do not refund it again.
            @else
                The money is back in the user's vault and the user was not emailed. You can retry the refund from the Payment Holds page.
            @endif
        </p>

        <p style="text-align: center; margin: 30px 0 10px;">
            <a href="{{ route('admin.refunds.index') }}" style="background-color: #935510; color: #ffffff; text-decoration: none; padding: 12px 26px; border-radius: 6px; font-weight: bold;">Open Refunds</a>
        </p>
    </div>
</div>
</body>
</html>
