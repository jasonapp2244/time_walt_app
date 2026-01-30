<?php

namespace Database\Seeders;

use App\Models\PrivacyPolicy;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PrivacyPolicySeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        PrivacyPolicy::create([
            'title' => 'Privacy Policy',
            'content' => $this->getPrivacyPolicyContent(),
            'is_active' => true,
            'effective_date' => now(),
        ]);
    }

    /**
     * Get the privacy policy content.
     */
    private function getPrivacyPolicyContent(): string
    {
        return 'Last Updated: '.now()->format('F d, Y')."

1. Introduction
Welcome to Time Walt App. We respect your privacy and are committed to protecting your personal data. This privacy policy will inform you about how we handle your personal data when you use our mobile application.

2. Information We Collect
We collect and process the following information:
- Personal identification information (Name, email address, phone number)
- Profile information and photographs
- Payment information processed through Stripe
- Device information (device ID, device type, FCM token)
- Location data (timezone)
- Language preferences
- Transaction history and payment records

3. How We Use Your Information
We use your information for:
- Creating and managing your account
- Processing payments and transactions
- Sending notifications about your account and transactions
- Verifying your identity through OTP
- Providing customer support
- Improving our services
- Complying with legal obligations

4. Payment Processing
All payment information is processed securely through Stripe. We do not store your complete credit card information on our servers. Please refer to Stripe's privacy policy for more information on how they handle your payment data.

5. Data Security
We implement appropriate security measures to protect your personal information:
- Encrypted data transmission (HTTPS/SSL)
- Secure password storage using hashing
- Two-factor authentication options
- Regular security audits
- Limited access to personal data

6. Data Sharing
We do not sell your personal information. We may share your data with:
- Payment processors (Stripe) for transaction processing
- Cloud service providers for data storage
- Legal authorities when required by law

7. Your Rights
You have the right to:
- Access your personal data
- Correct inaccurate data
- Request deletion of your data
- Object to data processing
- Withdraw consent at any time
- Export your data

8. Data Retention
We retain your personal data for as long as necessary to provide our services and comply with legal obligations. You may request deletion of your account at any time.

9. Notifications
You can manage your notification preferences in the app settings:
- Password alerts
- Transaction alerts
- Push notifications
- Email alerts
- Lock/Unlock alerts

10. Children's Privacy
Our service is not intended for users under the age of 18. We do not knowingly collect personal information from children.

11. International Data Transfers
Your information may be transferred to and processed in countries other than your country of residence. We ensure appropriate safeguards are in place.

12. Changes to This Policy
We may update this privacy policy from time to time. We will notify you of any changes by posting the new privacy policy in the app.

13. Contact Us
If you have any questions about this privacy policy, please contact us at:
Email: support@timewaltapp.com
Phone: +1 (555) 123-4567

14. Cookie Policy
Our mobile application may use cookies and similar technologies to enhance user experience and analyze app usage.

15. Third-Party Services
Our app integrates with third-party services:
- Stripe for payment processing
- Firebase Cloud Messaging for notifications
- Social media platforms for authentication (Google, Apple, Facebook)

By using Time Walt App, you acknowledge that you have read and understood this privacy policy.";
    }
}
