<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Withdrawal Request Received</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #000000; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;">
    <div style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <!-- Header -->
        <div style="background-color: #935510; padding: 30px; text-align: center;">
            <h1 style="color: #f2cf7a; margin: 0; font-size: 28px; font-weight: bold;">Withdrawal Request Received</h1>
        </div>

        <!-- Content -->
        <div style="padding: 40px 30px;">
            <p style="color: #000000; font-size: 18px; margin-top: 0;">Dear {{ $user->full_name }},</p>
            
            <p style="color: #000000; font-size: 16px; line-height: 1.8;">We have received your withdrawal request. Your transfer{{ $totalTransfers > 1 ? 's are' : ' is' }} being processed and will be completed shortly.</p>

            <!-- Total Amount -->
            <div style="background-color: #f2cf7a; padding: 25px; border-radius: 8px; margin: 30px 0; text-align: center; border-left: 4px solid #935510;">
                <p style="color: #000000; font-size: 14px; margin: 0 0 10px 0; font-weight: bold;">Total Amount</p>
                <p style="color: #000000; font-size: 28px; font-weight: bold; margin: 0;">
                    ${{ number_format($processedAmount, 2) }}
                </p>
                @if($requestedAmount != $processedAmount)
                <p style="color: #666666; font-size: 12px; margin: 10px 0 0 0;">
                    Requested: ${{ number_format($requestedAmount, 2) }}
                </p>
                @endif
            </div>

            @if($totalTransfers > 1)
            <!-- Transfer Breakdown -->
            <div style="background-color: #f9f9f9; padding: 20px; border-radius: 8px; margin: 30px 0;">
                <h3 style="color: #935510; font-size: 18px; margin-top: 0; margin-bottom: 15px;">Transfer Breakdown</h3>
                @foreach($transfers as $transfer)
                <div style="border-bottom: 1px solid #e0e0e0; padding: 15px 0; {{ !$loop->last ? '' : 'border-bottom: none;' }}">
                    <p style="color: #000000; font-size: 16px; font-weight: bold; margin: 0 0 8px 0;">Transfer #{{ $loop->iteration }}</p>
                    @if($transfer->hold && $transfer->hold->title)
                    <p style="color: #935510; font-size: 14px; margin: 4px 0;"><strong>Title:</strong> {{ $transfer->hold->title }}</p>
                    @endif
                    <p style="color: #000000; font-size: 14px; margin: 4px 0;"><strong>Amount:</strong> ${{ number_format($transfer->amount, 2) }} {{ strtoupper($transfer->currency) }}</p>
                    <p style="color: #000000; font-size: 14px; margin: 4px 0;"><strong>Status:</strong> {{ ucfirst($transfer->status) }}</p>
                    @if($transfer->hold)
                    <p style="color: #666666; font-size: 12px; margin: 4px 0;">Hold ID: #{{ $transfer->hold->id }}</p>
                    @endif
                </div>
                @endforeach
            </div>
            @else
            @foreach($transfers as $transfer)
            <div style="background-color: #f9f9f9; padding: 20px; border-radius: 8px; margin: 30px 0;">
                @if($transfer->hold && $transfer->hold->title)
                <p style="color: #935510; font-size: 14px; margin: 4px 0 10px 0;"><strong>Title:</strong> {{ $transfer->hold->title }}</p>
                @endif
                <p style="color: #000000; font-size: 14px; margin: 4px 0;"><strong>Amount:</strong> ${{ number_format($transfer->amount, 2) }} {{ strtoupper($transfer->currency) }}</p>
                <p style="color: #000000; font-size: 14px; margin: 4px 0;"><strong>Status:</strong> {{ ucfirst($transfer->status) }}</p>
                @if($transfer->hold)
                <p style="color: #666666; font-size: 12px; margin: 4px 0;">Hold ID: #{{ $transfer->hold->id }}</p>
                @endif
            </div>
            @endforeach
            @endif

            @if($requestedAmount != $processedAmount)
            <div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px;">
                <p style="color: #856404; font-size: 14px; margin: 0;">
                    <strong>Note:</strong> The processed amount (${{ number_format($processedAmount, 2) }}) differs from the requested amount (${{ number_format($requestedAmount, 2) }}) due to available balance limitations.
                </p>
            </div>
            @endif

            <p style="color: #000000; font-size: 16px; line-height: 1.8;">You will receive a confirmation email once the transfer{{ $totalTransfers > 1 ? 's are' : ' is' }} completed.</p>

            <p style="color: #000000; font-size: 16px; margin-top: 30px; line-height: 1.8;">
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
