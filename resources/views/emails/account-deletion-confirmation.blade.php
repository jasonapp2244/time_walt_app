<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Deletion Confirmation</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #f4f4f4; padding: 20px; border-radius: 5px;">
        <h2 style="color: #d9534f; margin-top: 0;">Account Deletion Confirmation</h2>
        
        <p>Hello {{ $userName }},</p>
        
        <p>This email confirms that your account has been permanently deleted as per your request.</p>
        
        <div style="background-color: #fff; padding: 20px; border-radius: 5px; margin: 20px 0;">
            <h3 style="color: #333; margin-top: 0;">Deletion Details:</h3>
            
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>Email:</strong></td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;">{{ $userEmail }}</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>Deleted At:</strong></td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;">{{ $deletedAt }}</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>Balance Transferred:</strong></td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;">${{ number_format($balanceTransferred, 2) }}</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0;"><strong>Transactions Affected:</strong></td>
                    <td style="padding: 8px 0;">{{ $transactionCount }}</td>
                </tr>
            </table>
        </div>
        
        <div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0;">
            <h4 style="color: #856404; margin-top: 0;">What Happened:</h4>
            <ul style="margin: 10px 0; padding-left: 20px;">
                <li>Your account access has been permanently removed</li>
                <li>Your personal information has been anonymized</li>
                @if($balanceTransferred > 0)
                <li>Your remaining balance of ${{ number_format($balanceTransferred, 2) }} has been processed according to our terms</li>
                @endif
                <li>All your transactions have been marked as abandoned</li>
            </ul>
        </div>
        
        <div style="background-color: #d1ecf1; border-left: 4px solid #17a2b8; padding: 15px; margin: 20px 0;">
            <h4 style="color: #0c5460; margin-top: 0;">Data Retention Policy:</h4>
            <p style="margin: 10px 0; color: #0c5460;">
                Transaction records are retained for 7 years as required by financial regulations and tax laws. 
                Your personal data has been anonymized to protect your privacy while maintaining legal compliance.
            </p>
        </div>
        
        <div style="background-color: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0;">
            <h4 style="color: #155724; margin-top: 0;">Can You Come Back?</h4>
            <p style="margin: 10px 0; color: #155724;">
                Yes! You can create a new account anytime using the same email address. 
                Your new account will start fresh with no connection to this deleted account.
            </p>
        </div>
        
        <p style="margin-top: 30px;">Thank you for using our service. We're sorry to see you go!</p>
        
        <p style="color: #666; font-size: 14px; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
            If you did not request this account deletion, please contact our support team immediately at 
            <a href="mailto:support@example.com" style="color: #007bff;">support@example.com</a>
        </p>
        
        <p style="color: #999; font-size: 12px; margin-top: 20px; text-align: center;">
            This is an automated email. Please do not reply to this message.
        </p>
    </div>
</body>
</html>
