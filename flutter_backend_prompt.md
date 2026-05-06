# Time Vault App — Flutter Frontend Integration with Laravel Stripe Backend

You are building a Flutter app (iOS + Android) for "Time Vault" — an app where users make payments that are held for a configurable time period before they can withdraw the funds. The backend is Laravel with Stripe integration. Below is the complete backend API documentation you need to integrate with.

---

## BASE URL & AUTH

- **Base URL:** `https://your-domain.com/api`
- **Auth:** Laravel Sanctum (Bearer token). All protected endpoints require header:
  ```
  Authorization: Bearer {token}
  ```
- **Response format (all endpoints):**
  ```json
  // Success
  { "success": true, "message": "...", "data": { ... } }

  // Error
  { "success": false, "message": "...", "errors": { ... } }
  ```
- **HTTP Status Codes:** 200/201 success, 400 bad request, 401 unauthorized, 403 forbidden, 404 not found, 422 validation error, 500 server error

---

## 1. AUTHENTICATION ENDPOINTS

### Check Email

`POST /api/auth/check-email`

- **Body:** `{ "email": "user@example.com" }`
- **Response:** Returns account status (unverified/verified/deleted)

### Sign Up

`POST /api/auth/signup`

- **Body:**
  ```json
  {
    "full_name": "John Doe",
    "email": "john@example.com",
    "phone": "+1234567890",
    "password": "Password1!",
    "provider": "google",
    "provider_id": "xxx"
  }
  ```
- **Validation:**
  - `full_name`: required
  - `email`: required, valid email
  - `phone`: optional
  - `password`: required, min 8 chars, must contain uppercase + lowercase + number + special character
  - `provider`: optional, one of: google, apple, facebook
  - `provider_id`: optional, required if provider is set
- **Response:** Creates user, sends 4-digit OTP via email (expires in 5 min). Does NOT return a token yet.
- **Note:** If an unverified account with the same email exists, it updates that account instead of creating a duplicate.

### Verify OTP

`POST /api/auth/verify-otp`

- **Body:** `{ "email": "john@example.com", "otp_code": "1234" }`
- **Response:** Returns user object + Sanctum `token`. Save this token for all future authenticated requests.
  ```json
  {
    "success": true,
    "message": "OTP verified successfully",
    "data": {
      "user": {
        "id": 1,
        "full_name": "John Doe",
        "email": "john@example.com",
        "phone": "+1234567890",
        "is_verified": true,
        "status": "active",
        "two_factor_enabled": false,
        "profile": null
      },
      "token": "1|abc123xyz..."
    }
  }
  ```

### Login

`POST /api/auth/login`

- **Body:**
  ```json
  {
    "email": "john@example.com",
    "password": "Password1!",
    "otp_code": "1234",
    "device_id": "xxx",
    "device_type": "ios",
    "fcm_token": "xxx",
    "timezone": "America/New_York",
    "language": "en"
  }
  ```
- **Fields:**
  - `email` or `phone`: required (one of them)
  - `password`: required
  - `otp_code`: only required if user has 2FA enabled. On first attempt without OTP, the server sends an OTP to the user's email. Re-send the login request with the OTP included.
  - `device_id`, `device_type`, `fcm_token`, `timezone`, `language`: all optional, used for push notifications and device tracking
- **Response:** User object + Sanctum `token`
- **2FA Flow:**
  1. Send login request without `otp_code`
  2. If 2FA is enabled, response says OTP was sent → prompt user for OTP
  3. Re-send login request with same credentials + `otp_code`

### Forgot Password

`POST /api/auth/forgot-password`

- **Body:** `{ "email": "john@example.com" }` (or `"phone"`)
- **Response:** Sends OTP to email (expires in 5 min)

### Reset Password

`POST /api/auth/reset-password`

- **Body:**
  ```json
  {
    "email": "john@example.com",
    "otp_code": "1234",
    "new_password": "NewPass1!"
  }
  ```
- **Response:** Resets password and revokes ALL existing tokens (user must log in again)

### Logout

`POST /api/auth/logout` (auth required)

- Deletes the current access token

### Delete Account

`POST /api/auth/delete-account` (auth required)

- Permanently deletes account
- Transfers any remaining balance to admin
- Marks all active transactions as "abandoned"
- Anonymizes user data (GDPR compliant)
- Revokes all tokens
- Sends confirmation email

### Change Password

`POST /api/profile/change-password` (auth required)

- **Body:** `{ "current_password": "...", "new_password": "..." }`

---

## 2. STRIPE PAYMENT FLOW (Payment Sheet for Mobile)

The app uses **Stripe Payment Sheet** for mobile payments. This is the recommended approach for Flutter. The flow is three steps:

### Step 1: Create Payment Intent

`POST /api/stripe/create-payment-intent` (auth required)

- **Body:**
  ```json
  {
    "amount": 50.00,
    "currency": "usd",
    "hold_period_type": "custom",
    "hold_start_at": "2026-04-30",
    "hold_end_at": "2026-05-30",
    "title": "My Savings Goal"
  }
  ```
- **Validation:**
  - `amount`: required, numeric, minimum 1
  - `currency`: required, one of: `usd`, `eur`, `gbp`
  - `hold_period_type`: required, must be `"custom"`
  - `hold_start_at`: required, date format, must be >= today
  - `hold_end_at`: required, date format, must be after `hold_start_at`
  - `title`: optional, string, max 255 characters
- **Response:**
  ```json
  {
    "success": true,
    "data": {
      "payment_intent_id": "pi_xxx",
      "client_secret": "pi_xxx_secret_xxx",
      "customer_id": "cus_xxx",
      "ephemeral_key": "ek_xxx",
      "publishable_key": "pk_test_xxx"
    }
  }
  ```

### Step 2: Present Stripe Payment Sheet in Flutter

Use the `flutter_stripe` package. Initialize and present the Payment Sheet with the values from Step 1:

```dart
// Initialize Payment Sheet
await Stripe.instance.initPaymentSheet(
  paymentSheetParameters: SetupPaymentSheetParameters(
    paymentIntentClientSecret: data['client_secret'],
    customerEphemeralKeySecret: data['ephemeral_key'],
    customerId: data['customer_id'],
    merchantDisplayName: 'Time Vault',
    // Optional: Apple Pay config
    applePay: const PaymentSheetApplePay(
      merchantCountryCode: 'US',
    ),
    // Optional: Google Pay config
    googlePay: const PaymentSheetGooglePay(
      merchantCountryCode: 'US',
      testEnv: true, // set to false in production
    ),
  ),
);

// Present Payment Sheet
await Stripe.instance.presentPaymentSheet();
// If no exception, payment succeeded → proceed to Step 3
```

### Step 3: Confirm Payment (after Payment Sheet succeeds)

`POST /api/stripe/confirm-payment` (auth required)

- **Body:** `{ "payment_intent_id": "pi_xxx" }`
- **Response:**
  ```json
  {
    "success": true,
    "message": "Payment confirmed successfully",
    "data": {
      "payment": {
        "id": 1,
        "payment_intent_id": "pi_xxx",
        "amount": "50.00",
        "currency": "usd",
        "status": "succeeded",
        "card_brand": "visa",
        "card_last4": "4242",
        "card_exp_month": 12,
        "card_exp_year": 2028,
        "card_funding": "credit",
        "card_country": "US",
        "payment_method_type": "card",
        "paid_at": "2026-04-29T15:30:00Z"
      },
      "hold": {
        "id": 1,
        "amount": "50.00",
        "remaining_amount": "50.00",
        "hold_period_type": "custom",
        "hold_start_at": "2026-04-30T15:30:00Z",
        "hold_end_at": "2026-05-30T15:30:00Z",
        "hold_days": 30,
        "status": "holding",
        "title": "My Savings Goal"
      }
    }
  }
  ```

### Complete Payment Flow Diagram

```
User enters amount + hold period dates
            │
            ▼
POST /api/stripe/create-payment-intent
   → Returns client_secret, ephemeral_key, customer_id
            │
            ▼
Flutter: Init & present Stripe Payment Sheet
   → User enters card / Apple Pay / Google Pay
            │
            ▼
Payment Sheet completes successfully
            │
            ▼
POST /api/stripe/confirm-payment
   → Returns payment + hold details
            │
            ▼
Show success screen with hold info
```

---

## 3. PAYMENT HOLDS & WALLET

### Hold Status Values

| Status | Meaning |
|--------|---------|
| `holding` | Money is locked, hold period has NOT ended yet |
| `ready_for_transfer` | Hold period ended, user CAN withdraw |
| `transferred` | Fully withdrawn to bank account |
| `partial_transferred` | Partially withdrawn (some amount remains) |
| `abandoned` | Account was deleted, funds returned to admin |

### Hold Status Flow

```
holding
   │
   │  (hold_end_at reached, backend cron marks it ready)
   ▼
ready_for_transfer
   │
   ├──── full withdrawal ────► transferred
   │
   └──── partial withdrawal ──► partial_transferred
                                    │
                                    ├── another partial ──► partial_transferred
                                    │
                                    └── final withdrawal ──► transferred
```

### Get Wallet Summary

`GET /api/payment-holds/summary` (auth required)

- **Response:**
  ```json
  {
    "success": true,
    "data": {
      "locked_amount": "100.00",
      "ready_amount": "50.00",
      "total_amount": "150.00"
    }
  }
  ```
- `locked_amount`: holds still in holding period (cannot withdraw yet)
- `ready_amount`: holds ready for withdrawal
- `total_amount`: sum of all active holds

### Get Transaction History

`GET /api/payment-holds/` (auth required)

- **Query params:** `?page=1` (paginated)
- Returns user's payment holds (checkouts) and transfers combined, sorted by date

---

## 4. WITHDRAWAL / PAYOUT

> **Prerequisite:** User MUST have a bank account linked before withdrawing. Adding the first bank account auto-creates a Stripe Connect account on the backend.

### Withdraw Specific Amount

`POST /api/payment-holds/withdraw` (auth required)

- **Body:**
  ```json
  {
    "amount": 25.00
  }
  ```
- **Validation:**
  - `amount`: required, numeric, min 0.50, max 999999.99
  - Must be <= total available (ready) amount
- **Behavior:** Withdraws from ready holds in FIFO order (oldest hold first). If the amount spans multiple holds, it creates multiple transfers.
- **Response:** Returns array of transfer details and summary
- **Error cases:**
  - No bank account linked → error message prompting to add bank account
  - Amount exceeds available balance → error with available amount
  - No ready holds → error message

### Request Payout for Specific Hold

`POST /api/payment-holds/{hold_id}/request-payout` (auth required)

- No request body needed
- Transfers the entire `remaining_amount` of that specific hold
- Hold must be `ready_for_transfer` or `holding` with expired `hold_end_at`
- **Response:** Returns transfer details

### Withdrawal Flow Diagram

```
User checks wallet summary (GET /api/payment-holds/summary)
   → Sees locked_amount and ready_amount
            │
            ▼
Option A: Withdraw specific amount
   POST /api/payment-holds/withdraw { amount: 25.00 }
   → Pulls from oldest ready holds first (FIFO)
            │
Option B: Payout specific hold
   POST /api/payment-holds/{hold_id}/request-payout
   → Transfers entire remaining amount of that hold
            │
            ▼
Backend creates Stripe Transfer to user's Connect account
   → Transfer status starts as 'pending'
            │
            ▼
Backend cron job verifies transfer completion
   → Updates status to 'completed' or 'failed'
   → Sends email notification to user
```

---

## 5. TRANSACTION HISTORY ENDPOINTS

All require auth. All return paginated results.

| Endpoint | Description |
|----------|-------------|
| `GET /api/transactions/all` | All transactions (checkouts + withdrawals + holds) |
| `GET /api/transactions/checkouts` | Only checkout/payment transactions |
| `GET /api/transactions/withdraws` | Only withdrawal transactions |
| `GET /api/transactions/hold-amounts` | Only hold amount records |
| `GET /api/transactions/ready-for-transfer` | Only holds that are ready for withdrawal |

---

## 6. BANK ACCOUNT MANAGEMENT

Users must add a bank account before they can withdraw funds. The first bank account added automatically creates a Stripe Connect account on the backend.

### Add Bank Account

`POST /api/bank-account` (auth required)

- **Body:**
  ```json
  {
    "dob": "1990-01-15",
    "account_number": "000123456789",
    "bank_name": "Chase",
    "routing_number": "110000000",
    "iban": "",
    "account_type": "checking",
    "country": "US",
    "currency": "usd"
  }
  ```
- **Validation:**
  - `dob`: required, date, must be before today
  - `account_number`: required, 5-34 characters
  - `bank_name`: required
  - `routing_number`: 9 digits (required for US accounts)
  - `iban`: for non-US accounts
  - `account_type`: required, one of: `savings`, `checking`, `current`
  - `country`: required, 2-letter country code
  - `currency`: required, 3-letter currency code
- **Response:** Bank account data with masked account number (last 4 digits only)
- **Note:** First bank account is automatically set as primary

### Get All Bank Accounts

`GET /api/bank-account` (auth required)

- **Response:**
  ```json
  {
    "success": true,
    "data": {
      "has_bank_account": true,
      "total": 2,
      "bank_accounts": [
        {
          "id": 1,
          "bank_name": "Chase",
          "account_number": "****6789",
          "routing_number": "****0000",
          "account_type": "checking",
          "country": "US",
          "currency": "usd",
          "is_primary": true
        }
      ]
    }
  }
  ```

### Set Primary Bank Account

`POST /api/bank-account/{id}/set-primary` (auth required)

- No request body needed
- Sets the specified bank account as primary (used for withdrawals)

### Delete Bank Account

`DELETE /api/bank-account/{id}` (auth required)

- Fails if there are pending withdrawals associated with this account

---

## 7. NOTIFICATION SETTINGS

### Get Notification Settings

`GET /api/notification-settings` (auth required)

- Auto-creates default settings (all enabled) if none exist
- **Response:**
  ```json
  {
    "success": true,
    "data": {
      "password_alert": true,
      "transaction_alert": true,
      "push_notification_alert": true,
      "email_alert": true,
      "lock_alert": true,
      "unlock_alert": true
    }
  }
  ```

### Update Notification Settings

`POST /api/notification-settings/update` (auth required)

- **Body:** Any combination of the 6 boolean fields:
  ```json
  {
    "transaction_alert": false,
    "email_alert": false
  }
  ```
- Only include the fields you want to change

---

## 8. SUPPORTED CURRENCIES

| Code | Name |
|------|------|
| `usd` | US Dollar |
| `eur` | Euro |
| `gbp` | British Pound |

---

## 9. BACKEND AUTOMATED PROCESSES (For Context)

These happen automatically on the backend. The Flutter app does NOT call these, but you should understand them for proper UX:

### Hold Period Check (Cron Job)
- Runs periodically on the server
- Finds all holds where `hold_end_at` has passed and status is `holding`
- Updates them to `ready_for_transfer`
- Sends notification to user that funds are now available
- **UX implication:** After the hold period ends, the user's wallet summary will show the amount move from `locked_amount` to `ready_amount`. You may want to refresh the wallet summary periodically or on app resume.

### Transfer Verification (Cron Job)
- Runs periodically on the server
- Checks pending transfers with Stripe to verify completion
- Updates transfer status to `completed` or `failed`
- Sends email notification to user
- **UX implication:** After a withdrawal request, the transfer may be `pending` for a short time. Show appropriate pending state in the UI.

### Email Notifications Sent by Backend
- Payment succeeded
- Payment failed
- Hold period ended (funds now available)
- Transfer completed (single or batch summary)
- Transfer failed
- Withdrawal requested
- Payout requested
- **UX implication:** Users can control these via notification settings

---

## 10. FLUTTER IMPLEMENTATION NOTES

### Required Packages

```yaml
dependencies:
  flutter_stripe: ^latest    # Stripe Payment Sheet
  flutter_secure_storage: ^latest  # Secure token storage
  dio: ^latest                # HTTP client (or http package)
  # Social login packages as needed:
  google_sign_in: ^latest
  sign_in_with_apple: ^latest
```

### Stripe SDK Initialization

```dart
void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // You can set a default publishable key here,
  // or set it dynamically from the create-payment-intent response
  Stripe.publishableKey = 'pk_test_xxx';

  runApp(MyApp());
}
```

### Auth Token Management

- Store the Sanctum token securely using `flutter_secure_storage`
- Include on ALL protected requests: `Authorization: Bearer {token}`
- On 401 response, redirect to login screen (token expired or revoked)
- Clear stored token on logout

### Error Handling Pattern

```dart
try {
  final response = await dio.post('/api/stripe/create-payment-intent', data: {...});
  if (response.data['success'] == true) {
    // Handle success
    final data = response.data['data'];
  } else {
    // Handle API-level error
    showError(response.data['message']);
  }
} on DioException catch (e) {
  if (e.response?.statusCode == 422) {
    // Validation errors
    final errors = e.response?.data['errors'];
    // Show field-specific errors
  } else if (e.response?.statusCode == 401) {
    // Token expired, redirect to login
  } else {
    // Network or server error
    showError(e.response?.data['message'] ?? 'Something went wrong');
  }
}
```

### Social Login Flow

1. Authenticate with Google/Apple/Facebook using respective Flutter packages
2. Get the provider ID token
3. Call signup or login endpoint with `provider` and `provider_id` fields
4. Social signup auto-verifies the account (no OTP needed)

### Key UX Considerations

1. **Before withdrawal:** Always check if user has a bank account (`GET /api/bank-account`). If not, redirect to add bank account flow first.
2. **Hold period display:** Show a countdown or progress bar for active holds (calculate from `hold_start_at` and `hold_end_at`).
3. **Wallet refresh:** Refresh wallet summary on app resume and after any payment/withdrawal action.
4. **Payment Sheet errors:** Handle `StripeException` from `presentPaymentSheet()` — user may cancel, card may be declined, etc.
5. **Pending transfers:** After withdrawal, show transfer as "pending" until backend cron confirms it. You can poll the transactions endpoint or rely on push notifications.
6. **Currency formatting:** Format amounts based on the currency code (USD → $, EUR → EUR, GBP → £).
7. **Date handling:** All dates from the API are in ISO 8601 format. Convert to user's local timezone for display.
8. **Apple Pay / Google Pay:** These work automatically through the Stripe Payment Sheet — no additional backend integration needed.

---

## 11. COMPLETE APP FLOW SUMMARY

```
┌─────────────────────────────────────┐
│           ONBOARDING                │
│                                     │
│  Check Email → Sign Up → Verify OTP │
│       OR                            │
│  Login (with optional 2FA)          │
│       OR                            │
│  Social Login (Google/Apple)        │
│                                     │
│  → Store Sanctum token securely     │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│           HOME / DASHBOARD          │
│                                     │
│  Wallet Summary:                    │
│  - Locked: $100.00                  │
│  - Ready: $50.00                    │
│  - Total: $150.00                   │
│                                     │
│  Recent Transactions List           │
│  [+ New Payment] button             │
└──────────────┬──────────────────────┘
               │
        ┌──────┴──────┐
        ▼             ▼
┌──────────────┐ ┌──────────────────┐
│ NEW PAYMENT  │ │   WITHDRAWAL     │
│              │ │                  │
│ Enter amount │ │ Check bank acct  │
│ Pick dates   │ │  → Add if none   │
│ Add title    │ │                  │
│     │        │ │ Enter amount     │
│     ▼        │ │  OR pick hold    │
│ Payment Sheet│ │     │            │
│ (Stripe UI)  │ │     ▼            │
│     │        │ │ Confirm withdraw │
│     ▼        │ │     │            │
│ Confirm      │ │     ▼            │
│ Show success │ │ Show pending     │
│ + hold info  │ │ transfer status  │
└──────────────┘ └──────────────────┘

┌─────────────────────────────────────┐
│           SETTINGS                  │
│                                     │
│  - Bank Accounts (add/remove/primary│
│  - Notification Preferences         │
│  - Change Password                  │
│  - Delete Account                   │
│  - Logout                           │
└─────────────────────────────────────┘
```
