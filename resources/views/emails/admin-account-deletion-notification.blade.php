<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin: User Account Deleted</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #f4f4f4; padding: 20px; border-radius: 5px;">
        <div style="background-color: #d9534f; color: white; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
            <h2 style="margin: 0;">🚨 User Account Deleted</h2>
        </div>
        
        <p>Hello Admin,</p>
        
        <p>A user has permanently deleted their account. Below are the details for your records:</p>
        
        <div style="background-color: #fff; padding: 20px; border-radius: 5px; margin: 20px 0; border: 2px solid #d9534f;">
            <h3 style="color: #d9534f; margin-top: 0;">👤 User Information:</h3>
            
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee; width: 40%;"><strong>User ID:</strong></td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;">#{{ $userId }}</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>Name:</strong></td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;">{{ $userName }}</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>Email:</strong></td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;">{{ $userEmail }}</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>Phone:</strong></td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;">{{ $userPhone }}</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0;"><strong>Deleted At:</strong></td>
                    <td style="padding: 8px 0;">{{ $deletedAt }}</td>
                </tr>
            </table>
        </div>
        
        <div style="background-color: #fff; padding: 20px; border-radius: 5px; margin: 20px 0; border: 2px solid #28a745;">
            <h3 style="color: #28a745; margin-top: 0;">💰 Financial Summary:</h3>
            
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee; width: 40%;"><strong>Forfeited Amount:</strong></td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee; color: #28a745; font-size: 18px; font-weight: bold;">
                        ${{ number_format($forfeitedAmount, 2) }}
                    </td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;"><strong>Payment Holds:</strong></td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #eee;">{{ $paymentHoldsCount }} holds</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0;"><strong>Transfer Records:</strong></td>
                    <td style="padding: 8px 0;">{{ $transfersCount }} transfers</td>
                </tr>
            </table>
        </div>
        
        @if($forfeitedAmount > 0)
        <div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0;">
            <h4 style="color: #856404; margin-top: 0;">💵 Balance Transfer:</h4>
            <p style="margin: 10px 0; color: #856404;">
                The user's remaining balance of <strong>${{ number_format($forfeitedAmount, 2) }}</strong> has been forfeited 
                and transferred to the admin account. Virtual transfer records have been created for tracking purposes.
            </p>
        </div>
        @endif
        
        <div style="background-color: #d1ecf1; border-left: 4px solid #17a2b8; padding: 15px; margin: 20px 0;">
            <h4 style="color: #0c5460; margin-top: 0;">📊 Data Status:</h4>
            <ul style="margin: 10px 0; padding-left: 20px; color: #0c5460;">
                <li><strong>User Account:</strong> Anonymized (email/phone freed for reuse)</li>
                <li><strong>Payment Holds:</strong> Marked as "abandoned"</li>
                <li><strong>Transfers:</strong> Marked as "abandoned"</li>
                <li><strong>Transaction History:</strong> Preserved for compliance (7 years)</li>
                <li><strong>Personal Data:</strong> Anonymized (GDPR compliant)</li>
            </ul>
        </div>
        
        <div style="background-color: #f8d7da; border-left: 4px solid #dc3545; padding: 15px; margin: 20px 0;">
            <h4 style="color: #721c24; margin-top: 0;">⚠️ Important Notes:</h4>
            <ul style="margin: 10px 0; padding-left: 20px; color: #721c24;">
                <li>The original email ({{ $userEmail }}) is now available for new registrations</li>
                <li>User cannot login with this account anymore</li>
                <li>Transaction records remain in database for legal compliance</li>
                <li>All Sanctum tokens have been revoked</li>
            </ul>
        </div>
        
        <div style="background-color: #e9ecef; padding: 15px; border-radius: 5px; margin-top: 30px;">
            <p style="margin: 0; color: #495057; font-size: 14px;">
                <strong>📍 Action Required:</strong><br>
                No immediate action needed. This is for your records and compliance tracking.
            </p>
        </div>
        
        <p style="color: #999; font-size: 12px; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; text-align: center;">
            This is an automated notification for admin records. Time: {{ $deletedAt }}
        </p>
    </div>
</body>
</html>
