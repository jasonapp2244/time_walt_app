# Flutter Integration: Bank Account KYC Fields

## What Changed on Backend

The `POST /api/bank-account` endpoint now requires **6 new fields** for Stripe KYC verification. Without these fields, `payouts_enabled` stays `false` and users can never withdraw money.

---

## API Endpoint

**`POST /api/bank-account`** (Auth: Bearer token required)

---

## Full Request Body (Updated)

```json
{
  "dob": "1990-05-15",
  "account_number": "000123456789",
  "bank_name": "Chase",
  "routing_number": "110000000",
  "account_type": "checking",
  "country": "US",
  "currency": "usd",
  "address_line1": "123 Main St",
  "city": "San Francisco",
  "state": "CA",
  "postal_code": "94105",
  "phone": "+14155551234",
  "ssn_last_4": "1234"
}
```

---

## NEW Fields (6 added)

| Field | Type | Required | Rules | Notes |
|---|---|---|---|---|
| `address_line1` | string | Always | max 200 chars | Street address (e.g., "123 Main St") |
| `city` | string | Always | max 100 chars | City name |
| `state` | string | Always | max 100 chars | State/province code (e.g., "CA", "NY") |
| `postal_code` | string | Always | max 20 chars | ZIP/postal code (e.g., "94105") |
| `phone` | string | Always | max 20 chars | Phone with country code (e.g., "+14155551234") |
| `ssn_last_4` | string | US only | exactly 4 digits | Last 4 of SSN — **required when `country=US`** |
| `id_number` | string | Non-US only | max 30 chars | National ID — **required when `country != US`** |

---

## EXISTING Fields (No Changes)

| Field | Type | Required | Rules |
|---|---|---|---|
| `dob` | string | Always | date format, must be before today |
| `account_number` | string | Always | 5-34 chars |
| `bank_name` | string | Always | max 100 chars |
| `routing_number` | string | US only | exactly 9 digits |
| `iban` | string | Non-US only | 15-34 chars |
| `account_type` | string | Always | `savings`, `checking`, or `current` |
| `country` | string | Always | 2-letter code: US, GB, CA, AU, DE, FR, etc. |
| `currency` | string | Always | 3-letter code: usd, eur, gbp, cad, etc. |

---

## Validation Error Responses

```json
{
  "message": "The address line1 field is required.",
  "errors": {
    "address_line1": ["The address line1 field is required."],
    "ssn_last_4": ["Last 4 digits of SSN are required for US accounts."],
    "id_number": ["National ID number is required for non-US accounts."]
  }
}
```

---

## Flutter UI Changes Needed

### 1. Add New Form Fields to Bank Account Screen

```dart
// Address Section
TextFormField(label: "Street Address")    // -> address_line1
TextFormField(label: "City")              // -> city
TextFormField(label: "State")             // -> state (dropdown for US)
TextFormField(label: "ZIP / Postal Code") // -> postal_code
TextFormField(label: "Phone Number")      // -> phone (with country code picker)

// Identity Section (conditional)
if (country == "US")
  TextFormField(label: "SSN Last 4 Digits")  // -> ssn_last_4 (masked input, 4 digits)
else
  TextFormField(label: "National ID Number")  // -> id_number
```

### 2. Update the API Request Model

```dart
final Map<String, dynamic> body = {
  // ...existing fields...
  'address_line1': addressLine1,
  'city': city,
  'state': state,
  'postal_code': postalCode,
  'phone': phone,          // format: "+14155551234"
  if (country == 'US')
    'ssn_last_4': ssnLast4
  else
    'id_number': idNumber,
};
```

### 3. UX Recommendations

- **SSN field**: Use `obscureText: true`, `maxLength: 4`, `keyboardType: TextInputType.number`
- **Phone field**: Use a country code picker (`intl_phone_number_input` package) to ensure `+` prefix
- **State field**: For US, use a dropdown with 50 state codes. For others, a free text input
- **Show a security note**: "This information is sent directly to Stripe for identity verification and is never stored on our servers" (the sensitive fields like `ssn_last_4` and `id_number` are only forwarded to Stripe, not saved in DB)

---

## Success Response (No Changes)

```json
{
  "success": true,
  "message": "Bank account added successfully.",
  "data": {
    "id": 1,
    "account_holder_name": "John Doe",
    "bank_name": "Chase",
    "account_number": "****6789",
    "account_type": "checking",
    "country": "US",
    "currency": "USD",
    "is_primary": true,
    "created_at": "2026-05-12T10:30:00+00:00"
  }
}
```

---

## Test Stripe Values (Test Mode)

| Field | Test Value |
|---|---|
| `ssn_last_4` | `0000` |
| `id_number` | `000000000` |
| `account_number` | `000123456789` |
| `routing_number` | `110000000` |

---

## Why These Fields Are Needed

Stripe is a regulated financial platform. They are legally required to verify the identity of anyone receiving payouts. These fields satisfy Stripe's KYC (Know Your Customer) requirements:

| Field | Why Stripe Needs It |
|---|---|
| `address_line1` | Identity verification against government records + tax reporting (1099 in US) |
| `city` | Part of address verification — must match official records |
| `state` | US tax jurisdiction + state-level compliance + 1099-K reporting |
| `postal_code` | Address Verification System (AVS) cross-check with banking databases |
| `phone` | SMS verification + fraud detection + regulatory contact requirement |
| `ssn_last_4` (US) | US law — Stripe matches against IRS/SSA records. Without it, no payouts |
| `id_number` (non-US) | Same as SSN but for other countries (SIN in Canada, NIN in UK, TFN in Australia) |

### Without These Fields

```
User adds bank account -> Stripe creates Connect account
-> KYC incomplete -> payouts_enabled: false
-> Withdrawal request -> TRANSFER FAILS
```

### With These Fields

```
User adds bank account + address + phone + SSN
-> Stripe verifies identity instantly (most US accounts)
-> payouts_enabled: true
-> Withdrawals work immediately
```

---

## Backend Files Changed

| File | What Changed |
|---|---|
| `app/Http/Requests/Bank/StoreBankAccountRequest.php` | Added 7 new validation rules + 3 custom error messages |
| `app/Http/Controllers/Api/BankAccountController.php` | Forwards `phone`, `address`, `ssn_last_4`/`id_number` to Stripe Connect account creation |

---

## Summary of Backend Changes

| What | Where | Why |
|---|---|---|
| 7 new validation rules | `StoreBankAccountRequest.php` | Validate new fields before hitting Stripe |
| 3 custom error messages | `StoreBankAccountRequest.php` | Clear messages for SSN/ID requirements |
| `phone` forwarded to Stripe | `BankAccountController.php` -> `individual.phone` | Stripe KYC requirement |
| `address` block added | `BankAccountController.php` -> `individual.address` | Stripe identity verification |
| `ssn_last_4` sent for US | `BankAccountController.php` -> `individual.ssn_last_4` | US federal law (IRS matching) |
| `id_number` sent for non-US | `BankAccountController.php` -> `individual.id_number` | Non-US identity verification |
| `array_filter()` wrapper | `BankAccountController.php` | Strips null values so Stripe doesn't receive `ssn_last_4: null` for non-US accounts |
