# Time Vault — Complete Flutter Payment Integration Prompt

You are building the Flutter (iOS + Android) payment module for "Time Vault" — an app where users deposit money, lock it for a configurable time period, then withdraw to their bank account. Below is everything you need to implement the complete payment lifecycle in Flutter.

---

## Table of Contents

1. [Setup & Dependencies](#1-setup--dependencies)
2. [API Client Configuration](#2-api-client-configuration)
3. [Flow 1 — Deposit & Lock Funds](#3-flow-1--deposit--lock-funds)
4. [Flow 2 — Add Bank Account](#4-flow-2--add-bank-account)
5. [Flow 3 — View Bank Accounts](#5-flow-3--view-bank-accounts)
6. [Flow 4 — Change Primary Bank Account](#6-flow-4--change-primary-bank-account)
7. [Flow 5 — Delete Bank Account](#7-flow-5--delete-bank-account)
8. [Flow 6 — View Wallet & Hold Status](#8-flow-6--view-wallet--hold-status)
9. [Flow 7 — Withdraw Funds](#9-flow-7--withdraw-funds)
10. [Complete Data Models (Dart)](#10-complete-data-models-dart)
11. [Complete API Service (Dart)](#11-complete-api-service-dart)
12. [Complete Payment Provider (State Management)](#12-complete-payment-provider-state-management)
13. [Screen-by-Screen UI Implementation](#13-screen-by-screen-ui-implementation)
14. [Error Handling](#14-error-handling)
15. [Testing with Stripe Test Cards](#15-testing-with-stripe-test-cards)
16. [Complete Flow Diagram](#16-complete-flow-diagram)

---

## 1. Setup & Dependencies

### pubspec.yaml

```yaml
dependencies:
  flutter_stripe: ^11.0.0          # Stripe Payment Sheet SDK
  dio: ^5.4.0                      # HTTP client
  flutter_secure_storage: ^9.0.0   # Secure token storage
  provider: ^6.1.0                 # State management (or use Riverpod/Bloc)
  intl: ^0.19.0                    # Date/currency formatting
```

### Android Setup

**android/app/build.gradle:**
```gradle
android {
    compileSdkVersion 34
    defaultConfig {
        minSdkVersion 21  // Stripe requires minimum SDK 21
    }
}
```

**android/app/src/main/AndroidManifest.xml:**
```xml
<application>
    <meta-data
        android:name="com.google.android.gms.wallet.api.enabled"
        android:value="true" />
</application>
```

### iOS Setup

**ios/Podfile:**
```ruby
platform :ios, '13.0'  # Stripe requires minimum iOS 13
```

### Stripe Initialization (main.dart)

```dart
import 'package:flutter_stripe/flutter_stripe.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Set your Stripe publishable key
  Stripe.publishableKey = 'pk_test_YOUR_PUBLISHABLE_KEY';

  // Required for Apple Pay
  Stripe.merchantIdentifier = 'merchant.com.timevault';

  // Set URL scheme for return URLs (iOS)
  await Stripe.instance.applySettings();

  runApp(const MyApp());
}
```

---

## 2. API Client Configuration

### Base URL & Authentication

```dart
import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class ApiClient {
  static const String baseUrl = 'https://your-domain.com/api';
  final Dio _dio;
  final FlutterSecureStorage _storage = const FlutterSecureStorage();

  ApiClient() : _dio = Dio(BaseOptions(
    baseUrl: baseUrl,
    connectTimeout: const Duration(seconds: 30),
    receiveTimeout: const Duration(seconds: 30),
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    },
  )) {
    _dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) async {
        final token = await _storage.read(key: 'auth_token');
        if (token != null) {
          options.headers['Authorization'] = 'Bearer $token';
        }
        handler.next(options);
      },
      onError: (error, handler) {
        if (error.response?.statusCode == 401) {
          // Token expired — navigate to login screen
          _handleUnauthorized();
        }
        handler.next(error);
      },
    ));
  }

  // Save token after login/signup
  Future<void> saveToken(String token) async {
    await _storage.write(key: 'auth_token', value: token);
  }

  // Clear token on logout
  Future<void> clearToken() async {
    await _storage.delete(key: 'auth_token');
  }

  void _handleUnauthorized() {
    // Navigate to login screen
    // Clear stored token
  }

  // Expose dio for direct access
  Dio get dio => _dio;
}
```

### Standard API Response Format

Every API response follows this format:
```json
{
  "success": true,
  "message": "Human readable message",
  "data": { ... }
}
```

Error responses:
```json
{
  "success": false,
  "message": "Error description",
  "errors": {
    "field_name": ["Validation error message"]
  }
}
```

---

## 3. Flow 1 — Deposit & Lock Funds

This is the core payment flow. The user enters an amount, selects a lock period, and pays via Stripe Payment Sheet (card, Apple Pay, or Google Pay).

### 3-Step Process

```
Step 1: POST /api/stripe/create-payment-intent  →  Get 4 credentials
Step 2: Present Stripe Payment Sheet (native UI) →  User pays
Step 3: POST /api/stripe/confirm-payment         →  Backend records payment + creates hold
```

### Step 1 — Create Payment Intent

**Endpoint:** `POST /api/stripe/create-payment-intent`  
**Auth:** Required (Bearer token)

**Request:**
```dart
final response = await dio.post('/stripe/create-payment-intent', data: {
  'amount': 50.00,              // Required. Minimum: 1.00
  'currency': 'usd',            // Required. Options: usd, eur, gbp, cad, aud, nzd, sgd, hkd, jpy, chf, dkk, nok, sek
  'hold_period_type': 'custom',  // Required. Must be "custom"
  'hold_start_at': '2026-05-12', // Required. YYYY-MM-DD. Must be today or later
  'hold_end_at': '2026-06-12',   // Required. YYYY-MM-DD. Must be after hold_start_at
  'title': 'My Savings Goal',    // Optional. Max 255 chars
});
```

**Success Response (201):**
```json
{
  "success": true,
  "message": "Payment intent created. Use client_secret to present Payment Sheet.",
  "data": {
    "payment_intent_id": "pi_3ABC123DEF456",
    "client_secret": "pi_3ABC123DEF456_secret_XYZ789",
    "customer_id": "cus_ABC123",
    "ephemeral_key": "ek_live_ABC123",
    "publishable_key": "pk_test_ABC123",
    "amount": 50.00,
    "currency": "usd"
  }
}
```

### Step 2 — Present Payment Sheet

```dart
// Initialize the Payment Sheet with credentials from Step 1
await Stripe.instance.initPaymentSheet(
  paymentSheetParameters: SetupPaymentSheetParameters(
    paymentIntentClientSecret: data['client_secret'],
    customerEphemeralKeySecret: data['ephemeral_key'],
    customerId: data['customer_id'],
    merchantDisplayName: 'Time Vault',

    // Apple Pay configuration
    applePay: const PaymentSheetApplePay(
      merchantCountryCode: 'US',
    ),

    // Google Pay configuration
    googlePay: const PaymentSheetGooglePay(
      merchantCountryCode: 'US',
      testEnv: true,  // Set to false in production!
    ),

    // Match app theme
    style: ThemeMode.system,

    // Appearance customization (optional)
    appearance: const PaymentSheetAppearance(
      colors: PaymentSheetAppearanceColors(
        primary: Color(0xFF4CAF50),
      ),
    ),
  ),
);

// Present the Payment Sheet to the user
// This shows: saved cards (if returning user), new card input, Apple Pay, Google Pay
// THROWS StripeException if user cancels or payment fails
await Stripe.instance.presentPaymentSheet();
```

**What the user sees:**

First-time user:
```
┌──────────────────────────────┐
│  Card: ____ ____ ____ ____   │
│  MM/YY    CVC               │
│                              │
│  ◉ Apple Pay                 │
│  ◉ Google Pay                │
│                              │
│  ☐ Save card for future use  │
│                              │
│      [ Pay $50.00 ]          │
└──────────────────────────────┘
```

Returning user (has saved cards):
```
┌──────────────────────────────┐
│  Saved payment methods:      │
│  ● Visa ****4242  12/28  ✓  │
│  ○ MC   ****5556  03/29     │
│                              │
│  + Add new card              │
│  ◉ Apple Pay                 │
│  ◉ Google Pay                │
│                              │
│      [ Pay $50.00 ]          │
└──────────────────────────────┘
```

### Step 3 — Confirm Payment

After `presentPaymentSheet()` succeeds (no exception thrown), confirm with the backend.

**Endpoint:** `POST /api/stripe/confirm-payment`  
**Auth:** Required

**Request:**
```dart
final confirmResponse = await dio.post('/stripe/confirm-payment', data: {
  'payment_intent_id': data['payment_intent_id'],  // From Step 1. Must start with "pi_"
});
```

**Success Response (200):**
```json
{
  "success": true,
  "message": "Payment confirmed and records created successfully.",
  "data": {
    "payment": {
      "id": 123,
      "payment_intent_id": "pi_3ABC123DEF456",
      "amount": 50.00,
      "currency": "usd",
      "status": "succeeded",
      "paid_at": "2026-05-12T10:30:45Z",
      "card_brand": "visa",
      "card_last4": "4242",
      "card_exp_month": 12,
      "card_exp_year": 2027,
      "card_funding": "credit",
      "card_country": "US",
      "payment_method_type": "card"
    },
    "hold": {
      "id": 456,
      "amount": 50.00,
      "remaining_amount": 50.00,
      "status": "holding",
      "hold_period_type": "custom",
      "hold_start_at": "2026-05-12T10:30:45Z",
      "hold_end_at": "2026-06-12T10:30:45Z",
      "hold_days": 31,
      "title": "My Savings Goal"
    }
  }
}
```

### Complete Deposit Function (Copy-Paste Ready)

```dart
import 'package:flutter_stripe/flutter_stripe.dart';

class PaymentService {
  final Dio _dio;

  PaymentService(this._dio);

  /// Deposit money and lock it for a hold period.
  ///
  /// Returns the payment and hold data on success.
  /// Throws [PaymentCancelledException] if user cancels.
  /// Throws [PaymentFailedException] on any other error.
  Future<Map<String, dynamic>> depositAndLock({
    required double amount,
    required String currency,
    required DateTime holdStartDate,
    required DateTime holdEndDate,
    String? title,
  }) async {
    // ── STEP 1: Create Payment Intent ──
    final intentResponse = await _dio.post(
      '/stripe/create-payment-intent',
      data: {
        'amount': amount,
        'currency': currency,
        'hold_period_type': 'custom',
        'hold_start_at': _formatDate(holdStartDate),
        'hold_end_at': _formatDate(holdEndDate),
        if (title != null) 'title': title,
      },
    );

    if (intentResponse.data['success'] != true) {
      throw PaymentFailedException(intentResponse.data['message'] ?? 'Failed to create payment');
    }

    final intentData = intentResponse.data['data'];
    final paymentIntentId = intentData['payment_intent_id'];

    // ── STEP 2: Initialize & Present Payment Sheet ──
    try {
      await Stripe.instance.initPaymentSheet(
        paymentSheetParameters: SetupPaymentSheetParameters(
          paymentIntentClientSecret: intentData['client_secret'],
          customerEphemeralKeySecret: intentData['ephemeral_key'],
          customerId: intentData['customer_id'],
          merchantDisplayName: 'Time Vault',
          applePay: const PaymentSheetApplePay(merchantCountryCode: 'US'),
          googlePay: const PaymentSheetGooglePay(
            merchantCountryCode: 'US',
            testEnv: true, // false in production
          ),
          style: ThemeMode.system,
        ),
      );

      await Stripe.instance.presentPaymentSheet();
    } on StripeException catch (e) {
      if (e.error.code == FailureCode.Canceled) {
        throw PaymentCancelledException();
      }
      throw PaymentFailedException(e.error.localizedMessage ?? 'Payment failed');
    }

    // ── STEP 3: Confirm Payment with Backend ──
    final confirmResponse = await _dio.post(
      '/stripe/confirm-payment',
      data: {'payment_intent_id': paymentIntentId},
    );

    if (confirmResponse.data['success'] != true) {
      throw PaymentFailedException(confirmResponse.data['message'] ?? 'Failed to confirm payment');
    }

    return confirmResponse.data['data'];
  }

  String _formatDate(DateTime date) {
    return '${date.year}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}';
  }
}

class PaymentCancelledException implements Exception {}
class PaymentFailedException implements Exception {
  final String message;
  PaymentFailedException(this.message);
}
```

---

## 4. Flow 2 — Add Bank Account

Users must add a bank account before they can withdraw. The first bank account automatically becomes the **primary** account. Adding the first bank account also silently creates a Stripe Connect account on the backend (the user never sees Stripe).

**Endpoint:** `POST /api/bank-account`  
**Auth:** Required

### Request (US Account)

```dart
final response = await dio.post('/bank-account', data: {
  'dob': '1990-05-15',              // Required. YYYY-MM-DD. Must be before today
  'account_number': '000123456789',  // Required. 5-34 characters
  'bank_name': 'Chase Bank',         // Required. Max 100 characters
  'routing_number': '110000000',     // Required for US. Exactly 9 digits
  'account_type': 'checking',        // Required. Options: savings, checking, current
  'country': 'US',                   // Required. 2-letter code
  'currency': 'usd',                // Required. 3-letter code
});
```

### Request (Non-US Account — Europe, etc.)

```dart
final response = await dio.post('/bank-account', data: {
  'dob': '1990-05-15',
  'account_number': 'DE89370400440532013000',
  'bank_name': 'Deutsche Bank',
  'iban': 'DE89370400440532013000',   // Required for non-US. 15-34 characters
  'account_type': 'checking',
  'country': 'DE',
  'currency': 'eur',
});
```

### Validation Rules

| Field | Type | Rules |
|-------|------|-------|
| `dob` | string | Required. Date format `YYYY-MM-DD`. Must be before today |
| `account_number` | string | Required. 5–34 characters |
| `bank_name` | string | Required. Max 100 characters |
| `routing_number` | string | Required if `country` is `US`. Exactly 9 digits |
| `iban` | string | Required if `country` is NOT `US`. 15–34 characters |
| `account_type` | string | Required. One of: `savings`, `checking`, `current` |
| `country` | string | Required. 2-letter code (see supported list below) |
| `currency` | string | Required. 3-letter code (see supported list below) |

### Supported Countries

`US`, `GB`, `CA`, `AU`, `DE`, `FR`, `IE`, `NL`, `AT`, `BE`, `ES`, `IT`, `PT`, `DK`, `FI`, `NO`, `SE`, `CH`, `NZ`, `SG`, `HK`, `JP`

### Supported Currencies

`usd`, `eur`, `gbp`, `cad`, `aud`, `nzd`, `sgd`, `hkd`, `jpy`, `chf`, `dkk`, `nok`, `sek`

### Success Response (201)

```json
{
  "success": true,
  "message": "Bank account added successfully.",
  "data": {
    "id": 789,
    "account_holder_name": "John Doe",
    "bank_name": "Chase Bank",
    "account_number": "****6789",
    "account_type": "checking",
    "country": "US",
    "currency": "USD",
    "is_primary": true,
    "created_at": "2026-05-12T10:30:45Z"
  }
}
```

### Error Responses

**Validation Error (422):**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "routing_number": ["The routing number must be 9 digits."],
    "account_number": ["The account number must be between 5 and 34 characters."]
  }
}
```

**Stripe Error (500):**
```json
{
  "success": false,
  "message": "Failed to setup bank account. Please try again."
}
```

### Complete Add Bank Account Function

```dart
class BankAccountService {
  final Dio _dio;

  BankAccountService(this._dio);

  /// Add a new bank account.
  /// First bank account is automatically set as primary.
  /// Silently creates Stripe Connect account if first bank account.
  Future<BankAccount> addBankAccount({
    required String dob,
    required String accountNumber,
    required String bankName,
    required String accountType,
    required String country,
    required String currency,
    String? routingNumber,  // Required for US
    String? iban,           // Required for non-US
  }) async {
    final response = await _dio.post('/bank-account', data: {
      'dob': dob,
      'account_number': accountNumber,
      'bank_name': bankName,
      'account_type': accountType,
      'country': country,
      'currency': currency,
      if (routingNumber != null) 'routing_number': routingNumber,
      if (iban != null) 'iban': iban,
    });

    if (response.data['success'] == true) {
      return BankAccount.fromJson(response.data['data']);
    }

    throw Exception(response.data['message'] ?? 'Failed to add bank account');
  }
}
```

### UI Implementation — Add Bank Account Screen

```dart
class AddBankAccountScreen extends StatefulWidget {
  const AddBankAccountScreen({super.key});

  @override
  State<AddBankAccountScreen> createState() => _AddBankAccountScreenState();
}

class _AddBankAccountScreenState extends State<AddBankAccountScreen> {
  final _formKey = GlobalKey<FormState>();
  final _dobController = TextEditingController();
  final _accountNumberController = TextEditingController();
  final _bankNameController = TextEditingController();
  final _routingNumberController = TextEditingController();
  final _ibanController = TextEditingController();

  String _selectedCountry = 'US';
  String _selectedCurrency = 'usd';
  String _selectedAccountType = 'checking';
  bool _isLoading = false;

  // Show routing number for US, IBAN for non-US
  bool get _isUS => _selectedCountry == 'US';

  // Country to currency mapping
  static const Map<String, String> countryCurrencyMap = {
    'US': 'usd', 'GB': 'gbp', 'CA': 'cad', 'AU': 'aud',
    'DE': 'eur', 'FR': 'eur', 'IE': 'eur', 'NL': 'eur',
    'AT': 'eur', 'BE': 'eur', 'ES': 'eur', 'IT': 'eur',
    'PT': 'eur', 'FI': 'eur', 'DK': 'dkk', 'NO': 'nok',
    'SE': 'sek', 'CH': 'chf', 'NZ': 'nzd', 'SG': 'sgd',
    'HK': 'hkd', 'JP': 'jpy',
  };

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _isLoading = true);

    try {
      final bankAccount = await context.read<BankAccountService>().addBankAccount(
        dob: _dobController.text,
        accountNumber: _accountNumberController.text,
        bankName: _bankNameController.text,
        accountType: _selectedAccountType,
        country: _selectedCountry,
        currency: _selectedCurrency,
        routingNumber: _isUS ? _routingNumberController.text : null,
        iban: !_isUS ? _ibanController.text : null,
      );

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Bank account added successfully')),
        );
        Navigator.pop(context, bankAccount);
      }
    } on DioException catch (e) {
      _handleDioError(e);
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Add Bank Account')),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            // Date of Birth
            TextFormField(
              controller: _dobController,
              decoration: const InputDecoration(
                labelText: 'Date of Birth',
                hintText: 'YYYY-MM-DD',
              ),
              readOnly: true,
              onTap: () async {
                final date = await showDatePicker(
                  context: context,
                  initialDate: DateTime(1990, 1, 1),
                  firstDate: DateTime(1900),
                  lastDate: DateTime.now().subtract(const Duration(days: 1)),
                );
                if (date != null) {
                  _dobController.text =
                      '${date.year}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}';
                }
              },
              validator: (v) => v == null || v.isEmpty ? 'Required' : null,
            ),
            const SizedBox(height: 16),

            // Country Picker
            DropdownButtonFormField<String>(
              value: _selectedCountry,
              decoration: const InputDecoration(labelText: 'Country'),
              items: countryCurrencyMap.keys.map((code) {
                return DropdownMenuItem(value: code, child: Text(code));
              }).toList(),
              onChanged: (value) {
                setState(() {
                  _selectedCountry = value!;
                  _selectedCurrency = countryCurrencyMap[value]!;
                });
              },
            ),
            const SizedBox(height: 16),

            // Bank Name
            TextFormField(
              controller: _bankNameController,
              decoration: const InputDecoration(labelText: 'Bank Name'),
              validator: (v) => v == null || v.isEmpty ? 'Required' : null,
            ),
            const SizedBox(height: 16),

            // Account Number
            TextFormField(
              controller: _accountNumberController,
              decoration: const InputDecoration(labelText: 'Account Number'),
              keyboardType: TextInputType.number,
              validator: (v) {
                if (v == null || v.isEmpty) return 'Required';
                if (v.length < 5 || v.length > 34) return 'Must be 5-34 characters';
                return null;
              },
            ),
            const SizedBox(height: 16),

            // Routing Number (US only)
            if (_isUS)
              TextFormField(
                controller: _routingNumberController,
                decoration: const InputDecoration(
                  labelText: 'Routing Number',
                  hintText: '9 digits',
                ),
                keyboardType: TextInputType.number,
                maxLength: 9,
                validator: (v) {
                  if (v == null || v.isEmpty) return 'Required for US accounts';
                  if (v.length != 9) return 'Must be exactly 9 digits';
                  return null;
                },
              ),

            // IBAN (non-US only)
            if (!_isUS)
              TextFormField(
                controller: _ibanController,
                decoration: const InputDecoration(
                  labelText: 'IBAN',
                  hintText: '15-34 characters',
                ),
                validator: (v) {
                  if (v == null || v.isEmpty) return 'Required for non-US accounts';
                  if (v.length < 15 || v.length > 34) return 'Must be 15-34 characters';
                  return null;
                },
              ),
            const SizedBox(height: 16),

            // Account Type
            DropdownButtonFormField<String>(
              value: _selectedAccountType,
              decoration: const InputDecoration(labelText: 'Account Type'),
              items: const [
                DropdownMenuItem(value: 'checking', child: Text('Checking')),
                DropdownMenuItem(value: 'savings', child: Text('Savings')),
                DropdownMenuItem(value: 'current', child: Text('Current')),
              ],
              onChanged: (v) => setState(() => _selectedAccountType = v!),
            ),
            const SizedBox(height: 16),

            // Currency (auto-selected based on country)
            TextFormField(
              initialValue: _selectedCurrency.toUpperCase(),
              decoration: const InputDecoration(labelText: 'Currency'),
              readOnly: true,
              enabled: false,
            ),
            const SizedBox(height: 32),

            // Submit Button
            ElevatedButton(
              onPressed: _isLoading ? null : _submit,
              child: _isLoading
                  ? const CircularProgressIndicator()
                  : const Text('Add Bank Account'),
            ),
          ],
        ),
      ),
    );
  }

  void _handleDioError(DioException e) {
    if (e.response?.statusCode == 422) {
      final errors = e.response?.data['errors'] as Map<String, dynamic>?;
      final firstError = errors?.values.first;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(firstError is List ? firstError.first : 'Validation error')),
      );
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.response?.data['message'] ?? 'Something went wrong')),
      );
    }
  }
}
```

---

## 5. Flow 3 — View Bank Accounts

**Endpoint:** `GET /api/bank-account`  
**Auth:** Required

**Request:**
```dart
final response = await dio.get('/bank-account');
```

**Success Response (200):**
```json
{
  "success": true,
  "data": {
    "has_bank_account": true,
    "total": 2,
    "bank_accounts": [
      {
        "id": 789,
        "account_holder_name": "John Doe",
        "bank_name": "Chase Bank",
        "account_number": "****6789",
        "account_type": "checking",
        "country": "US",
        "currency": "USD",
        "is_primary": true,
        "created_at": "2026-05-12T10:30:45Z"
      },
      {
        "id": 790,
        "account_holder_name": "John Doe",
        "bank_name": "Bank of America",
        "account_number": "****1234",
        "account_type": "savings",
        "country": "US",
        "currency": "USD",
        "is_primary": false,
        "created_at": "2026-05-13T08:15:00Z"
      }
    ]
  }
}
```

**No Bank Accounts (200):**
```json
{
  "success": true,
  "data": {
    "has_bank_account": false,
    "bank_accounts": []
  }
}
```

### Dart Code

```dart
Future<List<BankAccount>> getBankAccounts() async {
  final response = await _dio.get('/bank-account');
  if (response.data['success'] == true) {
    final list = response.data['data']['bank_accounts'] as List;
    return list.map((json) => BankAccount.fromJson(json)).toList();
  }
  return [];
}
```

### UI — Bank Account List

```dart
class BankAccountListScreen extends StatefulWidget {
  const BankAccountListScreen({super.key});

  @override
  State<BankAccountListScreen> createState() => _BankAccountListScreenState();
}

class _BankAccountListScreenState extends State<BankAccountListScreen> {
  List<BankAccount> _accounts = [];
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _loadAccounts();
  }

  Future<void> _loadAccounts() async {
    setState(() => _isLoading = true);
    try {
      _accounts = await context.read<BankAccountService>().getBankAccounts();
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Bank Accounts'),
        actions: [
          IconButton(
            icon: const Icon(Icons.add),
            onPressed: () async {
              final result = await Navigator.push(
                context,
                MaterialPageRoute(builder: (_) => const AddBankAccountScreen()),
              );
              if (result != null) _loadAccounts();
            },
          ),
        ],
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : _accounts.isEmpty
              ? _buildEmptyState()
              : _buildAccountList(),
    );
  }

  Widget _buildEmptyState() {
    return Center(
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          const Icon(Icons.account_balance, size: 64, color: Colors.grey),
          const SizedBox(height: 16),
          const Text('No bank accounts added'),
          const SizedBox(height: 16),
          ElevatedButton(
            onPressed: () async {
              final result = await Navigator.push(
                context,
                MaterialPageRoute(builder: (_) => const AddBankAccountScreen()),
              );
              if (result != null) _loadAccounts();
            },
            child: const Text('Add Bank Account'),
          ),
        ],
      ),
    );
  }

  Widget _buildAccountList() {
    return RefreshIndicator(
      onRefresh: _loadAccounts,
      child: ListView.builder(
        itemCount: _accounts.length,
        itemBuilder: (context, index) {
          final account = _accounts[index];
          return ListTile(
            leading: const Icon(Icons.account_balance),
            title: Text(account.bankName),
            subtitle: Text('${account.accountNumber} · ${account.accountType}'),
            trailing: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                if (account.isPrimary)
                  const Chip(label: Text('Primary'))
                else
                  TextButton(
                    onPressed: () => _setPrimary(account.id),
                    child: const Text('Set Primary'),
                  ),
                IconButton(
                  icon: const Icon(Icons.delete_outline, color: Colors.red),
                  onPressed: () => _deleteAccount(account.id),
                ),
              ],
            ),
          );
        },
      ),
    );
  }

  Future<void> _setPrimary(int id) async {
    try {
      await context.read<BankAccountService>().setPrimary(id);
      _loadAccounts();
    } on DioException catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.response?.data['message'] ?? 'Failed')),
      );
    }
  }

  Future<void> _deleteAccount(int id) async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Delete Bank Account'),
        content: const Text('Are you sure you want to remove this bank account?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
          TextButton(onPressed: () => Navigator.pop(context, true), child: const Text('Delete')),
        ],
      ),
    );

    if (confirm != true) return;

    try {
      await context.read<BankAccountService>().deleteBankAccount(id);
      _loadAccounts();
    } on DioException catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.response?.data['message'] ?? 'Failed to delete')),
      );
    }
  }
}
```

---

## 6. Flow 4 — Change Primary Bank Account

The primary bank account is the one used for withdrawals. Users can switch which account is primary.

**Endpoint:** `POST /api/bank-account/{id}/set-primary`  
**Auth:** Required  
**Request Body:** None

```dart
Future<void> setPrimary(int bankAccountId) async {
  final response = await _dio.post('/bank-account/$bankAccountId/set-primary');

  if (response.data['success'] != true) {
    throw Exception(response.data['message'] ?? 'Failed to set primary');
  }
}
```

**Success Response (200):**
```json
{
  "success": true,
  "message": "Bank account set as primary.",
  "data": {
    "id": 790,
    "bank_name": "Bank of America",
    "account_number": "****1234",
    "is_primary": true
  }
}
```

**Already Primary (200):**
```json
{
  "success": true,
  "message": "Bank account is already primary.",
  "data": {
    "id": 790,
    "bank_name": "Bank of America",
    "account_number": "****1234",
    "is_primary": true
  }
}
```

**Not Found (404):**
```json
{
  "success": false,
  "message": "Bank account not found."
}
```

---

## 7. Flow 5 — Delete Bank Account

**Endpoint:** `DELETE /api/bank-account/{id}`  
**Auth:** Required  
**Request Body:** None

```dart
Future<void> deleteBankAccount(int bankAccountId) async {
  final response = await _dio.delete('/bank-account/$bankAccountId');

  if (response.data['success'] != true) {
    throw Exception(response.data['message'] ?? 'Failed to delete');
  }
}
```

**Success (200):**
```json
{
  "success": false,
  "message": "Bank account removed successfully."
}
```

**Blocked — Pending Transfer:**
```json
{
  "success": false,
  "message": "Cannot delete bank while a withdrawal is pending."
}
```

**Important:** If the deleted account was the primary, the backend automatically promotes the oldest remaining account to primary.

---

## 8. Flow 6 — View Wallet & Hold Status

### Wallet Summary

**Endpoint:** `GET /api/payment-holds/summary`  
**Auth:** Required

```dart
Future<WalletSummary> getWalletSummary() async {
  final response = await _dio.get('/payment-holds/summary');
  return WalletSummary.fromJson(response.data['data']);
}
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "holding": {
      "count": 2,
      "total_amount": 100.00,
      "total_remaining": 100.00
    },
    "ready_for_transfer": {
      "count": 1,
      "total_amount": 50.00,
      "total_remaining": 50.00
    },
    "partial_transferred": {
      "count": 0,
      "total_amount": 0.00,
      "total_remaining": 0.00
    },
    "transferred": {
      "count": 2,
      "total_amount": 100.00,
      "total_remaining": 0.00
    },
    "abandoned": {
      "count": 0,
      "total_amount": 0.00
    }
  }
}
```

### Transaction History (Holds + Transfers)

**Endpoint:** `GET /api/payment-holds?per_page=15`  
**Auth:** Required

```dart
Future<TransactionHistoryResponse> getTransactionHistory({int page = 1, int perPage = 15}) async {
  final response = await _dio.get('/payment-holds', queryParameters: {
    'page': page,
    'per_page': perPage,  // Max: 100
  });
  return TransactionHistoryResponse.fromJson(response.data);
}
```

**Response (200):**
```json
{
  "success": true,
  "summary": {
    "total_checkout_amount": 150.00,
    "total_transferred_amount": 50.00,
    "pending_balance": 100.00,
    "total_transactions": 5
  },
  "transactions": [
    {
      "transaction_type": "checkout",
      "transaction_id": "CHK-456",
      "hold_id": 456,
      "amount": 50.00,
      "currency": "USD",
      "status": "succeeded",
      "date": "2026-05-12T10:30:45Z",
      "description": "Checkout payment received",
      "hold_duration": {
        "hold_days": 31,
        "hold_period_type": "custom",
        "hold_start_at": "2026-05-12T10:30:45Z",
        "hold_end_at": "2026-06-12T10:30:45Z",
        "days_elapsed": 5,
        "days_remaining": 26,
        "is_complete": false
      },
      "payment_details": {
        "payment_id": 123,
        "payment_intent_id": "pi_...",
        "hold_status": "holding",
        "can_request_payout": false
      }
    },
    {
      "transaction_type": "transfer",
      "transaction_id": "TRF-789",
      "hold_id": 123,
      "amount": 50.00,
      "currency": "USD",
      "status": "completed",
      "date": "2026-05-11T10:30:45Z",
      "description": "Transfer to Stripe Connect account",
      "transfer_details": {
        "transfer_id": 789,
        "stripe_transfer_id": "tr_...",
        "transfer_type": "user_requested",
        "transferred_at": "2026-05-11T10:30:45Z"
      }
    }
  ],
  "pagination": {
    "current_page": 1,
    "per_page": 15,
    "total": 5,
    "last_page": 1,
    "has_more_pages": false
  }
}
```

### Hold Status Reference

| Status | Meaning | Can Withdraw? | UI Display |
|--------|---------|---------------|------------|
| `holding` | Funds locked, hold period active | No | Show countdown timer |
| `ready_for_transfer` | Hold expired, ready to withdraw | Yes | Show "Withdraw" button |
| `partial_transferred` | Partially withdrawn | Yes (remaining) | Show remaining amount |
| `transferred` | Fully withdrawn | No | Show "Completed" badge |
| `abandoned` | Account deleted, funds forfeited | No | Show "Abandoned" badge |

### UX Tips for Wallet Screen

```dart
// Refresh wallet on app resume
@override
void didChangeAppLifecycleState(AppLifecycleState state) {
  if (state == AppLifecycleState.resumed) {
    _loadWalletSummary();  // Hold status might have changed via backend cron
  }
}

// Show countdown for active holds
String formatHoldCountdown(String holdEndAt) {
  final endDate = DateTime.parse(holdEndAt);
  final now = DateTime.now();
  final difference = endDate.difference(now);

  if (difference.isNegative) return 'Ready to withdraw';
  if (difference.inDays > 0) return '${difference.inDays} days remaining';
  if (difference.inHours > 0) return '${difference.inHours} hours remaining';
  return '${difference.inMinutes} minutes remaining';
}
```

---

## 9. Flow 7 — Withdraw Funds

Two withdrawal options: withdraw a specific amount (pulls from oldest eligible holds first), or request payout for a single hold.

### Option A — Withdraw Specific Amount (Bulk)

**Endpoint:** `POST /api/payment-holds/withdraw`  
**Auth:** Required

**Prerequisites:**
- User must have a primary bank account
- Must have eligible holds (expired hold period)

```dart
Future<WithdrawalResponse> withdraw(double amount) async {
  final response = await _dio.post('/payment-holds/withdraw', data: {
    'amount': amount,  // Required. Min: 0.50, Max: 999999.99
  });

  if (response.data['success'] == true) {
    return WithdrawalResponse.fromJson(response.data['data']);
  }

  throw Exception(response.data['message'] ?? 'Withdrawal failed');
}
```

**Success Response (200):**
```json
{
  "success": true,
  "message": "Withdrawal of $100.00 completed successfully.",
  "data": {
    "requested_amount": 100.00,
    "processed_amount": 100.00,
    "total_transfers": 2,
    "holds_processed": [
      { "hold_id": 456, "transfer_id": 789, "amount": 50.00 },
      { "hold_id": 457, "transfer_id": 790, "amount": 50.00 }
    ],
    "transfers": [
      {
        "hold_id": 456,
        "transfer_id": 789,
        "stripe_transfer_id": "tr_ABC123",
        "amount": 50.00,
        "currency": "USD",
        "status": "pending",
        "transferred_at": "2026-05-12T14:30:45Z"
      }
    ],
    "status": "completed",
    "all_completed": true,
    "summary": {
      "requested": 100.00,
      "processed": 100.00,
      "difference": 0.00
    }
  }
}
```

**Error — No Bank Account:**
```json
{
  "success": false,
  "message": "Bank account not found. Please add a bank account first."
}
```

**Error — No Eligible Holds:**
```json
{
  "success": false,
  "message": "No eligible holds available for withdrawal."
}
```

### Option B — Request Payout for Single Hold

**Endpoint:** `POST /api/payment-holds/{hold_id}/request-payout`  
**Auth:** Required  
**Request Body:** None

```dart
Future<PayoutResponse> requestPayout(int holdId) async {
  final response = await _dio.post('/payment-holds/$holdId/request-payout');

  if (response.data['success'] == true) {
    return PayoutResponse.fromJson(response.data['data']);
  }

  throw Exception(response.data['message'] ?? 'Payout request failed');
}
```

**Success Response (200):**
```json
{
  "success": true,
  "message": "Payout request submitted successfully.",
  "data": {
    "hold_id": 456,
    "transfer_id": 789,
    "stripe_transfer_id": "tr_ABC123",
    "amount": 50.00,
    "currency": "USD",
    "status": "pending",
    "transferred_at": "2026-05-12T14:30:45Z",
    "can_request_again": false
  }
}
```

**Error — Hold Period Not Complete:**
```json
{
  "success": false,
  "message": "Hold period not completed yet. Payout will be available after hold period ends.",
  "data": {
    "hold_end_at": "2026-06-12T10:30:45Z",
    "days_remaining": 31
  }
}
```

**Error — Already Requested:**
```json
{
  "success": false,
  "message": "Payout request already exists for this hold.",
  "data": {
    "transfer_id": 789,
    "status": "pending"
  }
}
```

### Complete Withdrawal Screen

```dart
class WithdrawScreen extends StatefulWidget {
  final double availableBalance;
  const WithdrawScreen({super.key, required this.availableBalance});

  @override
  State<WithdrawScreen> createState() => _WithdrawScreenState();
}

class _WithdrawScreenState extends State<WithdrawScreen> {
  final _amountController = TextEditingController();
  bool _isLoading = false;
  List<BankAccount> _bankAccounts = [];

  @override
  void initState() {
    super.initState();
    _checkBankAccount();
  }

  Future<void> _checkBankAccount() async {
    _bankAccounts = await context.read<BankAccountService>().getBankAccounts();
    if (_bankAccounts.isEmpty && mounted) {
      // No bank account — redirect to add one first
      final result = await Navigator.push(
        context,
        MaterialPageRoute(builder: (_) => const AddBankAccountScreen()),
      );
      if (result == null && mounted) {
        Navigator.pop(context); // User cancelled, go back
      } else {
        _checkBankAccount(); // Reload after adding
      }
    }
    setState(() {});
  }

  Future<void> _withdraw() async {
    final amount = double.tryParse(_amountController.text);
    if (amount == null || amount < 0.50) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Minimum withdrawal is \$0.50')),
      );
      return;
    }

    if (amount > widget.availableBalance) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Maximum available: \$${widget.availableBalance.toStringAsFixed(2)}')),
      );
      return;
    }

    setState(() => _isLoading = true);

    try {
      final result = await context.read<PaymentService>().withdraw(amount);

      if (mounted) {
        // Show success with transfer details
        showDialog(
          context: context,
          builder: (_) => AlertDialog(
            title: const Text('Withdrawal Submitted'),
            content: Text(
              'Amount: \$${result.processedAmount.toStringAsFixed(2)}\n'
              'Transfers: ${result.totalTransfers}\n'
              'Status: ${result.allCompleted ? "Completed" : "Processing"}',
            ),
            actions: [
              TextButton(
                onPressed: () {
                  Navigator.pop(context);
                  Navigator.pop(context, true); // Return to wallet
                },
                child: const Text('OK'),
              ),
            ],
          ),
        );
      }
    } on DioException catch (e) {
      final message = e.response?.data['message'] ?? 'Withdrawal failed';
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final primaryAccount = _bankAccounts.where((a) => a.isPrimary).firstOrNull;

    return Scaffold(
      appBar: AppBar(title: const Text('Withdraw Funds')),
      body: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Available balance
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  children: [
                    const Text('Available Balance', style: TextStyle(color: Colors.grey)),
                    const SizedBox(height: 8),
                    Text(
                      '\$${widget.availableBalance.toStringAsFixed(2)}',
                      style: const TextStyle(fontSize: 32, fontWeight: FontWeight.bold),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 24),

            // Withdrawal destination
            if (primaryAccount != null)
              Card(
                child: ListTile(
                  leading: const Icon(Icons.account_balance),
                  title: Text(primaryAccount.bankName),
                  subtitle: Text('${primaryAccount.accountNumber} (Primary)'),
                  trailing: TextButton(
                    onPressed: () async {
                      await Navigator.push(
                        context,
                        MaterialPageRoute(builder: (_) => const BankAccountListScreen()),
                      );
                      _checkBankAccount();
                    },
                    child: const Text('Change'),
                  ),
                ),
              ),
            const SizedBox(height: 24),

            // Amount input
            TextFormField(
              controller: _amountController,
              decoration: InputDecoration(
                labelText: 'Withdrawal Amount',
                prefixText: '\$ ',
                suffixIcon: TextButton(
                  onPressed: () {
                    _amountController.text = widget.availableBalance.toStringAsFixed(2);
                  },
                  child: const Text('MAX'),
                ),
              ),
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
            ),
            const SizedBox(height: 32),

            // Withdraw button
            ElevatedButton(
              onPressed: _isLoading ? null : _withdraw,
              style: ElevatedButton.styleFrom(
                padding: const EdgeInsets.symmetric(vertical: 16),
              ),
              child: _isLoading
                  ? const CircularProgressIndicator()
                  : const Text('Withdraw', style: TextStyle(fontSize: 18)),
            ),
          ],
        ),
      ),
    );
  }
}
```

---

## 10. Complete Data Models (Dart)

```dart
class BankAccount {
  final int id;
  final String accountHolderName;
  final String bankName;
  final String accountNumber;  // Masked: "****6789"
  final String accountType;
  final String country;
  final String currency;
  final bool isPrimary;
  final String createdAt;

  BankAccount({
    required this.id,
    required this.accountHolderName,
    required this.bankName,
    required this.accountNumber,
    required this.accountType,
    required this.country,
    required this.currency,
    required this.isPrimary,
    required this.createdAt,
  });

  factory BankAccount.fromJson(Map<String, dynamic> json) {
    return BankAccount(
      id: json['id'],
      accountHolderName: json['account_holder_name'] ?? '',
      bankName: json['bank_name'] ?? '',
      accountNumber: json['account_number'] ?? '',
      accountType: json['account_type'] ?? '',
      country: json['country'] ?? '',
      currency: json['currency'] ?? '',
      isPrimary: json['is_primary'] ?? false,
      createdAt: json['created_at'] ?? '',
    );
  }
}

class PaymentResult {
  final int paymentId;
  final double amount;
  final String currency;
  final String status;
  final String? cardBrand;
  final String? cardLast4;
  final String paymentMethodType;
  final String paidAt;

  PaymentResult({
    required this.paymentId,
    required this.amount,
    required this.currency,
    required this.status,
    this.cardBrand,
    this.cardLast4,
    required this.paymentMethodType,
    required this.paidAt,
  });

  factory PaymentResult.fromJson(Map<String, dynamic> json) {
    return PaymentResult(
      paymentId: json['id'],
      amount: (json['amount'] as num).toDouble(),
      currency: json['currency'] ?? 'usd',
      status: json['status'] ?? '',
      cardBrand: json['card_brand'],
      cardLast4: json['card_last4'],
      paymentMethodType: json['payment_method_type'] ?? 'card',
      paidAt: json['paid_at'] ?? '',
    );
  }
}

class HoldResult {
  final int holdId;
  final double amount;
  final double remainingAmount;
  final String status;
  final String holdStartAt;
  final String holdEndAt;
  final int holdDays;
  final String? title;

  HoldResult({
    required this.holdId,
    required this.amount,
    required this.remainingAmount,
    required this.status,
    required this.holdStartAt,
    required this.holdEndAt,
    required this.holdDays,
    this.title,
  });

  factory HoldResult.fromJson(Map<String, dynamic> json) {
    return HoldResult(
      holdId: json['id'],
      amount: (json['amount'] as num).toDouble(),
      remainingAmount: (json['remaining_amount'] as num?)?.toDouble() ?? (json['amount'] as num).toDouble(),
      status: json['status'] ?? '',
      holdStartAt: json['hold_start_at'] ?? '',
      holdEndAt: json['hold_end_at'] ?? '',
      holdDays: json['hold_days'] ?? 0,
      title: json['title'],
    );
  }

  bool get isComplete {
    final endDate = DateTime.tryParse(holdEndAt);
    if (endDate == null) return false;
    return DateTime.now().isAfter(endDate);
  }

  int get daysRemaining {
    final endDate = DateTime.tryParse(holdEndAt);
    if (endDate == null) return 0;
    final remaining = endDate.difference(DateTime.now()).inDays;
    return remaining > 0 ? remaining : 0;
  }

  bool get canWithdraw =>
      (status == 'ready_for_transfer' || status == 'partial_transferred') && remainingAmount > 0;
}

class WalletSummary {
  final HoldGroup holding;
  final HoldGroup readyForTransfer;
  final HoldGroup partialTransferred;
  final HoldGroup transferred;

  WalletSummary({
    required this.holding,
    required this.readyForTransfer,
    required this.partialTransferred,
    required this.transferred,
  });

  double get lockedAmount => holding.totalRemaining;
  double get availableAmount => readyForTransfer.totalRemaining + partialTransferred.totalRemaining;
  double get totalBalance => lockedAmount + availableAmount;

  factory WalletSummary.fromJson(Map<String, dynamic> json) {
    return WalletSummary(
      holding: HoldGroup.fromJson(json['holding'] ?? {}),
      readyForTransfer: HoldGroup.fromJson(json['ready_for_transfer'] ?? {}),
      partialTransferred: HoldGroup.fromJson(json['partial_transferred'] ?? {}),
      transferred: HoldGroup.fromJson(json['transferred'] ?? {}),
    );
  }
}

class HoldGroup {
  final int count;
  final double totalAmount;
  final double totalRemaining;

  HoldGroup({required this.count, required this.totalAmount, required this.totalRemaining});

  factory HoldGroup.fromJson(Map<String, dynamic> json) {
    return HoldGroup(
      count: json['count'] ?? 0,
      totalAmount: (json['total_amount'] as num?)?.toDouble() ?? 0.0,
      totalRemaining: (json['total_remaining'] as num?)?.toDouble() ?? 0.0,
    );
  }
}

class WithdrawalResponse {
  final double requestedAmount;
  final double processedAmount;
  final int totalTransfers;
  final bool allCompleted;
  final String status;

  WithdrawalResponse({
    required this.requestedAmount,
    required this.processedAmount,
    required this.totalTransfers,
    required this.allCompleted,
    required this.status,
  });

  factory WithdrawalResponse.fromJson(Map<String, dynamic> json) {
    return WithdrawalResponse(
      requestedAmount: (json['requested_amount'] as num?)?.toDouble() ?? 0.0,
      processedAmount: (json['processed_amount'] as num?)?.toDouble() ?? 0.0,
      totalTransfers: json['total_transfers'] ?? 0,
      allCompleted: json['all_completed'] ?? false,
      status: json['status'] ?? '',
    );
  }
}

class TransactionItem {
  final String transactionType;   // "checkout" or "transfer"
  final String transactionId;
  final int holdId;
  final double amount;
  final String currency;
  final String status;
  final String date;
  final String description;
  final Map<String, dynamic>? holdDuration;
  final Map<String, dynamic>? paymentDetails;
  final Map<String, dynamic>? transferDetails;

  TransactionItem({
    required this.transactionType,
    required this.transactionId,
    required this.holdId,
    required this.amount,
    required this.currency,
    required this.status,
    required this.date,
    required this.description,
    this.holdDuration,
    this.paymentDetails,
    this.transferDetails,
  });

  bool get isCheckout => transactionType == 'checkout';
  bool get isTransfer => transactionType == 'transfer';

  factory TransactionItem.fromJson(Map<String, dynamic> json) {
    return TransactionItem(
      transactionType: json['transaction_type'] ?? '',
      transactionId: json['transaction_id'] ?? '',
      holdId: json['hold_id'] ?? 0,
      amount: (json['amount'] as num?)?.toDouble() ?? 0.0,
      currency: json['currency'] ?? 'USD',
      status: json['status'] ?? '',
      date: json['date'] ?? '',
      description: json['description'] ?? '',
      holdDuration: json['hold_duration'],
      paymentDetails: json['payment_details'],
      transferDetails: json['transfer_details'],
    );
  }
}
```

---

## 11. Complete API Service (Dart)

```dart
class TimeVaultApiService {
  final Dio _dio;

  TimeVaultApiService(this._dio);

  // ─── PAYMENT (Deposit & Lock) ───

  /// Step 1: Create payment intent
  Future<Map<String, dynamic>> createPaymentIntent({
    required double amount,
    required String currency,
    required String holdStartAt,
    required String holdEndAt,
    String? title,
  }) async {
    final response = await _dio.post('/stripe/create-payment-intent', data: {
      'amount': amount,
      'currency': currency,
      'hold_period_type': 'custom',
      'hold_start_at': holdStartAt,
      'hold_end_at': holdEndAt,
      if (title != null) 'title': title,
    });
    _assertSuccess(response);
    return response.data['data'];
  }

  /// Step 3: Confirm payment after Payment Sheet success
  Future<Map<String, dynamic>> confirmPayment(String paymentIntentId) async {
    final response = await _dio.post('/stripe/confirm-payment', data: {
      'payment_intent_id': paymentIntentId,
    });
    _assertSuccess(response);
    return response.data['data'];
  }

  // ─── BANK ACCOUNTS ───

  Future<List<BankAccount>> getBankAccounts() async {
    final response = await _dio.get('/bank-account');
    if (response.data['success'] == true) {
      final list = response.data['data']['bank_accounts'] as List;
      return list.map((j) => BankAccount.fromJson(j)).toList();
    }
    return [];
  }

  Future<BankAccount> addBankAccount(Map<String, dynamic> data) async {
    final response = await _dio.post('/bank-account', data: data);
    _assertSuccess(response);
    return BankAccount.fromJson(response.data['data']);
  }

  Future<void> setPrimaryBankAccount(int id) async {
    final response = await _dio.post('/bank-account/$id/set-primary');
    _assertSuccess(response);
  }

  Future<void> deleteBankAccount(int id) async {
    final response = await _dio.delete('/bank-account/$id');
    _assertSuccess(response);
  }

  // ─── WALLET & HOLDS ───

  Future<WalletSummary> getWalletSummary() async {
    final response = await _dio.get('/payment-holds/summary');
    _assertSuccess(response);
    return WalletSummary.fromJson(response.data['data']);
  }

  Future<Map<String, dynamic>> getTransactionHistory({int page = 1, int perPage = 15}) async {
    final response = await _dio.get('/payment-holds', queryParameters: {
      'page': page,
      'per_page': perPage,
    });
    return response.data;
  }

  // ─── WITHDRAWAL ───

  Future<WithdrawalResponse> withdraw(double amount) async {
    final response = await _dio.post('/payment-holds/withdraw', data: {
      'amount': amount,
    });
    _assertSuccess(response);
    return WithdrawalResponse.fromJson(response.data['data']);
  }

  Future<Map<String, dynamic>> requestPayout(int holdId) async {
    final response = await _dio.post('/payment-holds/$holdId/request-payout');
    _assertSuccess(response);
    return response.data['data'];
  }

  // ─── TRANSACTION HISTORY ───

  Future<Map<String, dynamic>> getAllTransactions({int page = 1}) async {
    final response = await _dio.get('/transactions/all', queryParameters: {'page': page});
    return response.data;
  }

  Future<Map<String, dynamic>> getCheckoutTransactions({int page = 1}) async {
    final response = await _dio.get('/transactions/checkouts', queryParameters: {'page': page});
    return response.data;
  }

  Future<Map<String, dynamic>> getWithdrawTransactions({int page = 1}) async {
    final response = await _dio.get('/transactions/withdraws', queryParameters: {'page': page});
    return response.data;
  }

  Future<Map<String, dynamic>> getReadyForTransfer({int page = 1}) async {
    final response = await _dio.get('/transactions/ready-for-transfer', queryParameters: {'page': page});
    return response.data;
  }

  // ─── HELPER ───

  void _assertSuccess(Response response) {
    if (response.data['success'] != true) {
      throw ApiException(response.data['message'] ?? 'Request failed');
    }
  }
}

class ApiException implements Exception {
  final String message;
  ApiException(this.message);
  @override
  String toString() => message;
}
```

---

## 12. Complete Payment Provider (State Management)

```dart
import 'package:flutter/material.dart';

class PaymentProvider extends ChangeNotifier {
  final TimeVaultApiService _api;

  PaymentProvider(this._api);

  // ─── State ───
  WalletSummary? walletSummary;
  List<BankAccount> bankAccounts = [];
  List<TransactionItem> transactions = [];
  bool isLoading = false;
  String? errorMessage;

  // ─── Wallet ───

  Future<void> loadWallet() async {
    isLoading = true;
    errorMessage = null;
    notifyListeners();

    try {
      walletSummary = await _api.getWalletSummary();
    } catch (e) {
      errorMessage = e.toString();
    }

    isLoading = false;
    notifyListeners();
  }

  // ─── Bank Accounts ───

  Future<void> loadBankAccounts() async {
    try {
      bankAccounts = await _api.getBankAccounts();
      notifyListeners();
    } catch (e) {
      errorMessage = e.toString();
      notifyListeners();
    }
  }

  BankAccount? get primaryBankAccount =>
      bankAccounts.where((a) => a.isPrimary).firstOrNull;

  bool get hasBankAccount => bankAccounts.isNotEmpty;

  Future<BankAccount> addBankAccount(Map<String, dynamic> data) async {
    final account = await _api.addBankAccount(data);
    await loadBankAccounts();
    return account;
  }

  Future<void> setPrimary(int id) async {
    await _api.setPrimaryBankAccount(id);
    await loadBankAccounts();
  }

  Future<void> deleteBankAccount(int id) async {
    await _api.deleteBankAccount(id);
    await loadBankAccounts();
  }

  // ─── Deposit ───

  Future<Map<String, dynamic>> deposit({
    required double amount,
    required String currency,
    required DateTime holdStartDate,
    required DateTime holdEndDate,
    String? title,
  }) async {
    final formatDate = (DateTime d) =>
        '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

    // Step 1: Create intent
    final intentData = await _api.createPaymentIntent(
      amount: amount,
      currency: currency,
      holdStartAt: formatDate(holdStartDate),
      holdEndAt: formatDate(holdEndDate),
      title: title,
    );

    // Step 2: Show Payment Sheet
    await Stripe.instance.initPaymentSheet(
      paymentSheetParameters: SetupPaymentSheetParameters(
        paymentIntentClientSecret: intentData['client_secret'],
        customerEphemeralKeySecret: intentData['ephemeral_key'],
        customerId: intentData['customer_id'],
        merchantDisplayName: 'Time Vault',
        applePay: const PaymentSheetApplePay(merchantCountryCode: 'US'),
        googlePay: const PaymentSheetGooglePay(merchantCountryCode: 'US', testEnv: true),
      ),
    );
    await Stripe.instance.presentPaymentSheet();

    // Step 3: Confirm
    final result = await _api.confirmPayment(intentData['payment_intent_id']);

    // Refresh wallet after deposit
    await loadWallet();

    return result;
  }

  // ─── Withdraw ───

  Future<WithdrawalResponse> withdraw(double amount) async {
    final result = await _api.withdraw(amount);
    await loadWallet(); // Refresh after withdrawal
    return result;
  }

  Future<Map<String, dynamic>> requestPayout(int holdId) async {
    final result = await _api.requestPayout(holdId);
    await loadWallet();
    return result;
  }
}
```

---

## 13. Screen-by-Screen UI Implementation

### Screen Flow Map

```
┌─────────────────────────────────────────────┐
│                HOME / WALLET                 │
│                                              │
│  ┌────────────────────────────────────────┐  │
│  │  Locked:    $100.00  (2 holds)        │  │
│  │  Available: $50.00   (1 hold)         │  │
│  │  Total:     $150.00                    │  │
│  └────────────────────────────────────────┘  │
│                                              │
│  [+ Deposit]              [Withdraw]         │
│                                              │
│  Recent Transactions:                        │
│  ┌────────────────────────────────────────┐  │
│  │ ↓ $50.00  Checkout   May 12  holding  │  │
│  │ ↑ $50.00  Transfer   May 11  done     │  │
│  │ ↓ $100.00 Checkout   May 10  holding  │  │
│  └────────────────────────────────────────┘  │
│                                              │
│  [Bank Accounts]  [Settings]                 │
└──────────────┬───────────────┬───────────────┘
               │               │
     ┌─────────▼──────┐  ┌────▼──────────────┐
     │  DEPOSIT SCREEN │  │ WITHDRAW SCREEN   │
     │                 │  │                   │
     │ Amount: $____   │  │ Available: $50.00 │
     │ Start: ______   │  │ Amount: $____     │
     │ End:   ______   │  │                   │
     │ Title: ______   │  │ To: Chase ****6789│
     │                 │  │  [Change Bank]    │
     │ [Pay Now]       │  │                   │
     │     │           │  │ [Withdraw]        │
     │     ▼           │  └───────────────────┘
     │ Payment Sheet   │
     │ (Stripe native) │
     │     │           │
     │     ▼           │
     │ Success Screen  │
     └─────────────────┘

┌────────────────────────────────────┐
│         BANK ACCOUNTS              │
│                                    │
│  Chase Bank     ****6789  Primary  │
│  Bank of America ****1234 [Set Primary] [Delete]  │
│                                    │
│  [+ Add Bank Account]             │
│         │                          │
│         ▼                          │
│  ┌──────────────────────┐         │
│  │ ADD BANK ACCOUNT     │         │
│  │                      │         │
│  │ DOB:      ________   │         │
│  │ Country:  [US ▼]     │         │
│  │ Bank:     ________   │         │
│  │ Account:  ________   │         │
│  │ Routing:  ________   │ (US)    │
│  │ IBAN:     ________   │ (EU)    │
│  │ Type:     [Checking▼]│         │
│  │                      │         │
│  │ [Add Bank Account]   │         │
│  └──────────────────────┘         │
└────────────────────────────────────┘
```

---

## 14. Error Handling

### Global Error Handler

```dart
String handleApiError(DioException e) {
  switch (e.response?.statusCode) {
    case 401:
      // Token expired — redirect to login
      return 'Session expired. Please log in again.';

    case 403:
      return e.response?.data['message'] ?? 'Access denied.';

    case 404:
      return e.response?.data['message'] ?? 'Not found.';

    case 422:
      // Validation errors — extract first error
      final errors = e.response?.data['errors'] as Map<String, dynamic>?;
      if (errors != null && errors.isNotEmpty) {
        final firstField = errors.values.first;
        if (firstField is List && firstField.isNotEmpty) {
          return firstField.first.toString();
        }
      }
      return e.response?.data['message'] ?? 'Invalid input.';

    case 500:
      return e.response?.data['message'] ?? 'Server error. Please try again.';

    default:
      if (e.type == DioExceptionType.connectionTimeout ||
          e.type == DioExceptionType.receiveTimeout) {
        return 'Connection timed out. Check your internet.';
      }
      return 'Something went wrong. Please try again.';
  }
}
```

### Stripe Payment Sheet Errors

```dart
try {
  await Stripe.instance.presentPaymentSheet();
} on StripeException catch (e) {
  switch (e.error.code) {
    case FailureCode.Canceled:
      // User tapped X or swiped down — do nothing
      break;
    case FailureCode.Failed:
      showError('Payment failed: ${e.error.localizedMessage}');
      break;
    case FailureCode.Timeout:
      showError('Payment timed out. Please try again.');
      break;
    default:
      showError(e.error.localizedMessage ?? 'Payment error');
  }
}
```

---

## 15. Testing with Stripe Test Cards

Use these test card numbers in Stripe's test mode:

| Card Number | Brand | Result |
|-------------|-------|--------|
| `4242 4242 4242 4242` | Visa | Success |
| `5555 5555 5555 4444` | Mastercard | Success |
| `3782 822463 10005` | Amex | Success |
| `4000 0000 0000 9995` | Visa | Declined (insufficient funds) |
| `4000 0000 0000 0002` | Visa | Declined (generic) |
| `4000 0025 0000 3155` | Visa | Requires 3D Secure (auto-handled by Payment Sheet) |

**Test expiry:** Any future date (e.g., `12/28`)  
**Test CVC:** Any 3 digits (e.g., `123`)  
**Test ZIP:** Any 5 digits (e.g., `12345`)

### Test Bank Accounts (for withdrawal)

**US Test Account:**
```
Routing Number: 110000000
Account Number: 000123456789
```

---

## 16. Complete Flow Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                    TIME VAULT — COMPLETE FLOW                    │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌──────────────┐     ┌──────────────────┐     ┌────────────┐  │
│  │   LOGIN      │────►│   HOME / WALLET  │────►│  DEPOSIT   │  │
│  │              │     │                  │     │            │  │
│  │  Email+Pass  │     │  Locked: $100    │     │  Amount    │  │
│  │  Social Auth │     │  Ready:  $50     │     │  Dates     │  │
│  │  OTP Verify  │     │  Total:  $150    │     │  Title     │  │
│  └──────────────┘     └────────┬─────────┘     └─────┬──────┘  │
│                                │                     │         │
│                                │                     ▼         │
│                                │              ┌────────────┐   │
│                                │              │  STRIPE     │   │
│                                │              │  PAYMENT    │   │
│                                │              │  SHEET      │   │
│                                │              │             │   │
│                                │              │  Card       │   │
│                                │              │  Apple Pay  │   │
│                                │              │  Google Pay │   │
│                                │              │  Saved Cards│   │
│                                │              └─────┬───────┘  │
│                                │                    │          │
│                                │                    ▼          │
│                                │              ┌────────────┐   │
│                                │              │  CONFIRM    │   │
│                                │              │  PAYMENT    │   │
│                                │              │             │   │
│                                │              │  Payment ✓  │   │
│                                │              │  Hold created│  │
│                                │              │  Status:     │  │
│                                │              │  "holding"   │  │
│                                │              └─────┬───────┘  │
│                                │                    │          │
│                                ◄────────────────────┘          │
│                                │                               │
│                     ┌──────────▼──────────┐                    │
│                     │                     │                    │
│              ┌──────▼──────┐    ┌─────────▼────────┐           │
│              │  BANK ACCTS │    │    WITHDRAW       │           │
│              │             │    │                   │           │
│              │  Add Bank   │    │  Check bank acct  │           │
│              │  View All   │◄───│   └─ Add if none  │           │
│              │  Set Primary│    │                   │           │
│              │  Delete     │    │  Enter amount     │           │
│              └─────────────┘    │   or pick hold    │           │
│                                 │                   │           │
│                                 │  ┌─────────────┐  │           │
│                                 │  │ Option A:    │  │           │
│                                 │  │ Bulk withdraw│  │           │
│                                 │  │ POST /withdraw│ │           │
│                                 │  │ amount: $100 │  │           │
│                                 │  └──────────────┘  │           │
│                                 │  ┌─────────────┐  │           │
│                                 │  │ Option B:    │  │           │
│                                 │  │ Single payout│  │           │
│                                 │  │ POST /request│  │           │
│                                 │  │ -payout/{id} │  │           │
│                                 │  └──────────────┘  │           │
│                                 │                   │           │
│                                 │  Transfer created │           │
│                                 │  Status: pending  │           │
│                                 │  Email sent       │           │
│                                 └───────────────────┘           │
│                                                                 │
│  HOLD STATUS LIFECYCLE:                                         │
│  holding → [hold period expires] → ready_for_transfer           │
│            → [partial withdraw] → partial_transferred           │
│            → [full withdraw]    → transferred                   │
│                                                                 │
│  TRANSFER STATUS:                                               │
│  pending → processing → completed (or failed)                   │
│                                                                 │
│  AUTOMATED (Backend Cron — runs every 5 min):                   │
│  • Checks expired holds → marks as ready_for_transfer           │
│  • Verifies pending transfers → marks as completed/failed       │
│  • Sends email notifications for status changes                 │
│                                                                 │
│  UX TIPS:                                                       │
│  • Refresh wallet on app resume (hold status may have changed)  │
│  • Show countdown timer for active holds                        │
│  • Check bank account exists before showing withdraw screen     │
│  • Show pending state after withdrawal (not instant)            │
│  • Handle StripeException.Canceled gracefully (just dismiss)    │
│  • Format amounts based on currency code                        │
│  • All API dates are ISO 8601 — convert to local timezone       │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## Quick Reference — All API Endpoints Used

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `POST` | `/api/stripe/create-payment-intent` | Create payment (Step 1) |
| `POST` | `/api/stripe/confirm-payment` | Confirm payment (Step 3) |
| `POST` | `/api/bank-account` | Add bank account |
| `GET` | `/api/bank-account` | List bank accounts |
| `POST` | `/api/bank-account/{id}/set-primary` | Change primary bank |
| `DELETE` | `/api/bank-account/{id}` | Delete bank account |
| `GET` | `/api/payment-holds/summary` | Wallet summary |
| `GET` | `/api/payment-holds` | Transaction history |
| `POST` | `/api/payment-holds/withdraw` | Bulk withdrawal |
| `POST` | `/api/payment-holds/{id}/request-payout` | Single hold payout |
| `GET` | `/api/transactions/all` | All transactions |
| `GET` | `/api/transactions/checkouts` | Checkout history |
| `GET` | `/api/transactions/withdraws` | Withdrawal history |
| `GET` | `/api/transactions/ready-for-transfer` | Ready holds |
