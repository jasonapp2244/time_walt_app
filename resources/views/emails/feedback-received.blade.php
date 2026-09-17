<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New User Feedback</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #000000; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;">
    <div style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <!-- Header -->
        <div style="background-color: #935510; padding: 30px; text-align: center;">
            <h1 style="color: #f2cf7a; margin: 0; font-size: 26px; font-weight: bold;">New User Feedback</h1>
        </div>

        <!-- Content -->
        <div style="padding: 40px 30px;">
            <p style="color: #000000; font-size: 16px; margin-top: 0;">A user has submitted new feedback on {{ config('app.name') }}.</p>

            <!-- Rating -->
            <div style="background-color: #f2cf7a; padding: 20px; border-radius: 8px; margin: 24px 0; text-align: center; border-left: 4px solid #935510;">
                <p style="color: #000000; font-size: 14px; font-weight: bold; margin: 0 0 6px; text-transform: uppercase; letter-spacing: 0.5px;">Rating</p>
                <p style="color: #935510; font-size: 24px; font-weight: bold; margin: 0;">
                    {{ str_repeat('★', $feedback->rating) }}{{ str_repeat('☆', 5 - $feedback->rating) }}
                    <span style="font-size:16px; color:#000;">({{ $feedback->rating }}/5)</span>
                </p>
            </div>

            <!-- User -->
            <table style="width:100%; border-collapse: collapse; margin-bottom: 24px;">
                <tr>
                    <td style="padding:8px 0; font-size:14px; color:#666; width:120px;">User</td>
                    <td style="padding:8px 0; font-size:15px; color:#000; font-weight:bold;">{{ $user->full_name ?? 'Unknown' }}</td>
                </tr>
                <tr>
                    <td style="padding:8px 0; font-size:14px; color:#666;">Email</td>
                    <td style="padding:8px 0; font-size:15px; color:#000;">{{ $user->email ?? '—' }}</td>
                </tr>
                <tr>
                    <td style="padding:8px 0; font-size:14px; color:#666;">Submitted</td>
                    <td style="padding:8px 0; font-size:15px; color:#000;">
                        {{ $feedback->created_at?->setTimezone(config('app.admin_timezone'))->format('d M Y H:i') }}
                    </td>
                </tr>
            </table>

            <!-- Message -->
            <p style="color: #000000; font-size: 14px; font-weight: bold; margin: 0 0 8px; text-transform: uppercase; letter-spacing: 0.5px;">Feedback</p>
            <div style="background-color: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px;">
                <p style="color: #000000; font-size: 16px; line-height: 1.8; margin: 0;">{{ $feedback->message }}</p>
            </div>
        </div>

        <!-- Footer -->
        <div style="background-color: #f5f5f5; padding: 20px; text-align: center; border-top: 1px solid #e0e0e0;">
            <p style="color: #666666; font-size: 12px; margin: 0;">
                This is an automated notification from {{ config('app.name') }}.<br>
                You can review all feedback in the admin panel.
            </p>
        </div>
    </div>
</body>
</html>
