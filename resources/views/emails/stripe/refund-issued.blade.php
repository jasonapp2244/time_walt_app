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
    $hold = $refund->hold;
    $payment = $refund->payment;
    $supportEmail = config('mail.support_email');
@endphp
<div style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">

    <div style="background-color: #935510; padding: 30px; text-align: center;">
        <h1 style="color: #f2cf7a; margin: 0; font-size: 28px; font-weight: bold; letter-spacing: 0.5px;">Your Refund Is On Its Way</h1>
    </div>

    <div style="padding: 40px 30px;">

        <p style="color: #333333; font-size: 18px; margin-top: 0; font-weight: bold;">Dear {{ $user?->displayName('Customer') ?? 'Customer' }},</p>

        <p style="color: #333333; font-size: 16px; line-height: 1.8;">
            @if($refund->isPartial())
                We have issued a partial refund of your deposit. The money is going back to the same card you paid with.
            @else
                We have issued a refund for your deposit. The money is going back to the same card you paid with.
            @endif
        </p>

        <div style="background-color: #f2cf7a; padding: 25px; border-radius: 8px; margin: 25px 0; text-align: center; border-left: 4px solid #935510;">
            @if($hold && $hold->title)
            <p style="color: #6b3a0a; font-size: 18px; margin: 0 0 12px 0; font-weight: bold;">{{ $hold->title }}</p>
            @endif
            <p style="color: #000000; font-size: 32px; font-weight: bold; margin: 0;">
                ${{ number_format((float) $refund->amount, 2) }} <span style="font-size: 16px;">{{ strtoupper($refund->currency) }}</span>
            </p>
            <p style="color: #6b3a0a; font-size: 13px; margin: 10px 0 0 0; font-weight: bold;">Refunded to {{ $refund->cardLabel() }}</p>
        </div>

        <div style="background-color: #f0f4ff; border-left: 4px solid #1a1a2e; padding: 15px 20px; border-radius: 4px; margin: 25px 0;">
            <p style="color: #1a1a2e; font-size: 12px; font-weight: bold; margin: 0 0 8px 0; text-transform: uppercase; letter-spacing: 1px;">Refund Details</p>
            <p style="color: #333333; font-size: 14px; margin: 4px 0;"><strong>Refund reference:</strong> {{ $refund->reference() }}</p>
            <p style="color: #333333; font-size: 14px; margin: 4px 0;"><strong>Refund date:</strong> {{ $refund->created_at?->format('d M Y') }}</p>
            <p style="color: #333333; font-size: 14px; margin: 4px 0;"><strong>Card:</strong> {{ $refund->cardLabel() }}</p>
            @if($refund->isPartial() && $payment)
            <p style="color: #333333; font-size: 14px; margin: 4px 0;"><strong>Refund type:</strong> Partial — total refunded so far ${{ number_format((float) $payment->refunded_amount, 2) }} of ${{ number_format((float) $payment->amount, 2) }}</p>
            @endif
            @if($payment)
            <p style="color: #333333; font-size: 14px; margin: 4px 0;"><strong>Original deposit:</strong> ${{ number_format((float) $payment->amount, 2) }} on {{ ($payment->paid_at ?? $payment->created_at)?->format('d M Y') }}</p>
            @endif
        </div>

        <p style="color: #333333; font-size: 15px; line-height: 1.8;">
            Card refunds usually take <strong>5–10 business days</strong> to appear on your statement, depending on your bank.
            If the refund was made on the same day you paid, the original charge may simply disappear from your statement instead.
        </p>

        @if($supportEmail)
        <p style="color: #333333; font-size: 15px; line-height: 1.8;">
            If you have not received it after 10 business days, contact us at
            <a href="mailto:{{ $supportEmail }}" style="color: #935510; font-weight: bold;">{{ $supportEmail }}</a>
            and quote your refund reference.
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
