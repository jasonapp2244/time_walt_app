<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Withdrawal Request Received</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333333; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;">
@php $isAdmin = $isAdmin ?? false; @endphp
<div style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">

    @if($isAdmin)
    <div style="background-color: #1a1a2e; padding: 10px 20px; text-align: center;">
        <span style="color: #f2cf7a; font-size: 12px; font-weight: bold; letter-spacing: 2px; text-transform: uppercase;">&#9679; Admin Notification</span>
    </div>
    @endif

    <div style="background-color: #935510; padding: 30px; text-align: center;">
        <h1 style="color: #f2cf7a; margin: 0; font-size: 28px; font-weight: bold; letter-spacing: 0.5px;">Withdrawal Request Received</h1>
    </div>

    <div style="padding: 40px 30px;">

        <p style="color: #333333; font-size: 18px; margin-top: 0; font-weight: bold;">Dear {{ $isAdmin ? 'Admin' : $user->full_name }},</p>

        <p style="color: #333333; font-size: 16px; line-height: 1.8;">
            {{ $isAdmin
                ? 'A withdrawal request has been received from the following customer and is being processed.'
                : ($totalTransfers > 1 ? 'We have received your withdrawal request. Your transfers are being processed and will be completed shortly.' : 'We have received your withdrawal request. Your transfer is being processed and will be completed shortly.')
            }}
        </p>

        <div style="background-color: #f0f4ff; border-left: 4px solid #1a1a2e; padding: 15px 20px; border-radius: 4px; margin: 25px 0;">
            <p style="color: #1a1a2e; font-size: 12px; font-weight: bold; margin: 0 0 8px 0; text-transform: uppercase; letter-spacing: 1px;">{{ $isAdmin ? 'Customer Details' : 'Your Details' }}</p>
            <p style="color: #333333; font-size: 14px; margin: 4px 0;"><strong>Name:</strong> {{ $user->full_name }}</p>
            <p style="color: #333333; font-size: 14px; margin: 4px 0;"><strong>Email:</strong> {{ $user->email }}</p>
        </div>

        <div style="background-color: #f2cf7a; padding: 25px; border-radius: 8px; margin: 25px 0; text-align: center; border-left: 4px solid #935510;">
            <p style="color: #6b3a0a; font-size: 14px; font-weight: bold; margin: 0 0 10px 0; text-transform: uppercase; letter-spacing: 1px;">Total Amount</p>
            <p style="color: #000000; font-size: 36px; font-weight: bold; margin: 0;">
                ${{ number_format($processedAmount, 2) }}
            </p>
            @if($requestedAmount != $processedAmount)
            <p style="color: #6b3a0a; font-size: 12px; margin: 10px 0 0 0;">Requested: ${{ number_format($requestedAmount, 2) }}</p>
            @endif
        </div>

        @if($totalTransfers > 1)
        <div style="background-color: #f9f9f9; padding: 20px; border-radius: 8px; margin: 25px 0;">
            <h3 style="color: #935510; font-size: 15px; font-weight: bold; margin: 0 0 15px 0; text-transform: uppercase; letter-spacing: 1px;">Transfer Breakdown</h3>
            @foreach($transfers as $transfer)
            <div style="border-bottom: 1px solid #e8e8e8; padding: 14px 0; {{ $loop->last ? 'border-bottom: none;' : '' }}">
                @if($transfer->hold && $transfer->hold->title)
                <p style="color: #6b3a0a; font-size: 15px; font-weight: bold; margin: 0 0 6px 0;">{{ $transfer->hold->title }}</p>
                @else
                <p style="color: #6b3a0a; font-size: 15px; font-weight: bold; margin: 0 0 6px 0;">Transfer {{ $loop->iteration }}</p>
                @endif
                <p style="color: #333333; font-size: 14px; margin: 3px 0;"><strong>Amount:</strong> ${{ number_format($transfer->amount, 2) }} {{ strtoupper($transfer->currency) }}</p>
                <p style="color: #333333; font-size: 14px; margin: 3px 0;"><strong>Status:</strong> {{ ucfirst($transfer->status) }}</p>
            </div>
            @endforeach
        </div>
        @else
        @foreach($transfers as $transfer)
        <div style="background-color: #f9f9f9; padding: 20px; border-radius: 8px; margin: 25px 0;">
            @if($transfer->hold && $transfer->hold->title)
            <p style="color: #6b3a0a; font-size: 16px; font-weight: bold; margin: 0 0 10px 0;">{{ $transfer->hold->title }}</p>
            @endif
            <p style="color: #333333; font-size: 14px; margin: 3px 0;"><strong>Amount:</strong> ${{ number_format($transfer->amount, 2) }} {{ strtoupper($transfer->currency) }}</p>
            <p style="color: #333333; font-size: 14px; margin: 3px 0;"><strong>Status:</strong> {{ ucfirst($transfer->status) }}</p>
        </div>
        @endforeach
        @endif

        @if($requestedAmount != $processedAmount)
        <div style="background-color: #fff8e1; border-left: 4px solid #f59e0b; padding: 15px 20px; margin: 20px 0; border-radius: 4px;">
            <p style="color: #78350f; font-size: 14px; margin: 0;">
                <strong>Note:</strong> The processed amount (${{ number_format($processedAmount, 2) }}) differs from the requested amount (${{ number_format($requestedAmount, 2) }}) due to available balance.
            </p>
        </div>
        @endif

        @if(!$isAdmin)
        <p style="color: #333333; font-size: 16px; line-height: 1.8;">You will receive a confirmation email once your transfer is completed.</p>
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