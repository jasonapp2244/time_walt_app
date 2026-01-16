# Stripe Payment & Transfer Flow Documentation

## Overview
Yeh document Stripe Connect payment system ka complete flow explain karta hai jo Flutter mobile app developer ke liye helpful hoga.

## ⚠️ Laravel Control & Responsibility

**Laravel FULLY controls hold/unlock/transfer logic:**

### ✅ Laravel Fully Controls:

1. **Hold Logic:**
   - Payment hold Laravel DB me store hota hai (`payment_holds` table)
   - Hold period Laravel me manage hota hai (user custom ya default)
   - Hold status Laravel me track hota hai (`holding`, `ready_for_transfer`, `transferred`)
   - **No Stripe hold/authorization** - Pure Laravel DB logic

2. **Unlock/Ready Logic:**
   - Laravel cron job check karta hai `hold_end_at <= NOW()`
   - Laravel automatically status update karta hai `holding` → `ready_for_transfer`
   - **Fully Laravel controlled** - No Stripe dependency

3. **Transfer Trigger:**
   - Laravel cron job automatically transfer create karta hai
   - Ya admin manually Laravel API se transfer trigger karta hai
   - **Fully Laravel controlled** - Laravel decides when to transfer

4. **Transfer Execution:**
   - Laravel Stripe Transfer API call karta hai
   - Laravel transfer record create karta hai (`transfers` table)
   - Laravel transfer status track karta hai
   - **Laravel controls** - Stripe API use karta hai but logic Laravel me hai

### ❌ Laravel Does NOT Control:

1. **Payment Processing:**
   - Stripe handles payment processing
   - Stripe handles card authorization/capture

2. **Payout to Bank:**
   - Stripe automatically payout karta hai user ke bank account me
   - Laravel can only track payout status via webhooks

### Summary:
- **Hold:** 100% Laravel (DB me)
- **Unlock/Ready:** 100% Laravel (cron job)
- **Transfer Trigger:** 100% Laravel (cron/admin)
- **Transfer Execution:** Laravel (Stripe API call, but Laravel controls)
- **Payout:** Stripe (automatic, Laravel tracks only)

## Database Tables

### 1. stripe_connect_accounts
- User ka Stripe Express account store karta hai
- Bank details optional hai (baad me add kar sakta hai)
- Status: `pending`, `verified`, `restricted`

### 2. payments
- User ke card payments store karta hai
- Payment admin ke Stripe balance me jata hai
- Status: `pending`, `succeeded`, `failed`, `canceled`

### 3. payment_holds
- Payment amount ko hold karta hai (Laravel DB me)
- Hold period configurable hai (default: 30 days)
- Status: `holding`, `ready_for_transfer`, `transferred`

### 4. transfers
- Admin se user ke Stripe Connect account me transfer
- Transfer type: `manual` (admin click) ya `cron` (automatic)
- Status: `pending`, `completed`, `failed`, `canceled`

---

## Complete Payment Flow

### Step 1: User Stripe Connect Account Creation
**API Endpoint:** `POST /api/stripe/connect/create`

- User ko Express account create karna hoga
- Payment se pehle ya payment flow me
- Bank details optional hai (baad me add kar sakta hai)
- Response: `connect_account_id`, `onboarding_url` (if needed)

**Flutter Integration:**
```dart
// Create Stripe Connect account
POST /api/stripe/connect/create
Headers: Authorization: Bearer {token}
Response: {
  "connect_account_id": "acct_xxx",
  "onboarding_url": "https://connect.stripe.com/..." // optional
}
```

---

### Step 2: Payment Processing
**API Endpoint:** `POST /api/stripe/payment-intent`

- User card se payment karega
- Money admin ke Stripe balance me jayega
- Stripe fees admin pay karega
- User ka full amount intact rahega
- **User custom hold period set kar sakta hai** (minimum 1 month)

**⚠️ IMPORTANT: Payment Flow Sequence**

**Step 2A: Hold Period Selection (BEFORE Payment Intent)**
- Flutter app me **pehle** hold period selection screen show kare
- User hold period select kare (1 month, 2 months, 6 months, 1 year, ya custom dates)
- Custom option me date pickers se start/end date select kare
- Validation: Minimum 30 days required

**Step 2B: Create Payment Intent (WITH Hold Period Data)**
- Hold period data ke saath payment intent create kare
- **Hold period data payment intent create karte waqt hi send karni hai**
- Backend hold period data store karega payment intent ke saath

**Step 2C: Stripe Checkout / Card Collection**
- Stripe SDK use kare card details collect karne ke liye
- **Card details collection me hold period data nahi jayega** (already sent in Step 2B)
- Stripe checkout/card form me sirf card details collect honge

**Step 2D: Payment Confirmation**
- Payment confirm hone ke baad webhook trigger hoga
- Webhook me backend stored hold period data use karega

**Flutter Integration - Complete Flow:**
```dart
// ============================================
// STEP 1: Show Hold Period Selection UI
// ============================================
class PaymentScreen extends StatefulWidget {
  @override
  _PaymentScreenState createState() => _PaymentScreenState();
}

class _PaymentScreenState extends State<PaymentScreen> {
  String? selectedHoldPeriod; // '1_month', '2_months', etc.
  DateTime? customStartDate;
  DateTime? customEndDate;
  
  // User selects hold period FIRST
  void onHoldPeriodSelected(String period) {
    setState(() {
      selectedHoldPeriod = period;
      if (period == 'custom') {
        // Show date pickers for custom dates
        showCustomDatePicker();
      }
    });
  }
  
  // ============================================
  // STEP 2: Create Payment Intent WITH Hold Period
  // ============================================
  Future<void> createPaymentIntent() async {
    // Prepare hold period data
    Map<String, dynamic> holdPeriodData = {};
    
    if (selectedHoldPeriod == 'custom') {
      // Validate custom dates
      if (customStartDate == null || customEndDate == null) {
        showError('Please select start and end dates');
        return;
      }
      
      final days = customEndDate!.difference(customStartDate!).inDays;
      if (days < 30) {
        showError('Minimum 30 days required');
        return;
      }
      
      holdPeriodData = {
        'hold_period_type': 'custom',
        'hold_start_at': customStartDate!.toIso8601String(),
        'hold_end_at': customEndDate!.toIso8601String(),
        'hold_days': days,
      };
    } else {
      // Predefined periods
      final days = getDaysForPeriod(selectedHoldPeriod!);
      final start = DateTime.now();
      final end = start.add(Duration(days: days));
      
      holdPeriodData = {
        'hold_period_type': selectedHoldPeriod,
        'hold_start_at': start.toIso8601String(),
        'hold_end_at': end.toIso8601String(),
        'hold_days': days,
      };
    }
    
    // Create Payment Intent API Call
    // ⚠️ Hold period data yahan send kare (payment intent create se PEHLE)
    final response = await http.post(
      Uri.parse('$baseUrl/api/stripe/payment-intent'),
      headers: {
        'Authorization': 'Bearer $token',
        'Content-Type': 'application/json',
      },
      body: jsonEncode({
        'amount': amountInCents,
        'currency': 'usd',
        // Hold period data yahan send kare
        ...holdPeriodData,
      }),
    );
    
    final data = jsonDecode(response.body);
    final clientSecret = data['data']['client_secret'];
    final paymentIntentId = data['data']['payment_intent_id'];
    
    // Backend me hold period data store ho chuka hai payment intent ke saath
    // Ab Stripe checkout/card collection kare
    
    // ============================================
    // STEP 3: Stripe Checkout / Card Collection
    // ============================================
    // Card details collection - hold period data yahan nahi jayega
    await handleStripePayment(clientSecret);
  }
  
  // ============================================
  // STEP 4: Stripe Payment Confirmation
  // ============================================
  Future<void> handleStripePayment(String clientSecret) async {
    // Use Stripe SDK for card collection
    // Example with flutter_stripe package:
    
    try {
      // Initialize Stripe
      await Stripe.instance.initPaymentSheet(
        paymentSheetParameters: SetupPaymentSheetParameters(
          paymentIntentClientSecret: clientSecret,
          merchantDisplayName: 'Your App Name',
        ),
      );
      
      // Display payment sheet (card details collection)
      await Stripe.instance.presentPaymentSheet();
      
      // Payment successful
      // Webhook automatically trigger hoga
      // Backend me stored hold period data use hoga
      
      showSuccess('Payment successful!');
    } catch (e) {
      showError('Payment failed: $e');
    }
  }
  
  int getDaysForPeriod(String period) {
    switch (period) {
      case '1_month': return 30;
      case '2_months': return 60;
      case '6_months': return 180;
      case '1_year': return 365;
      default: return 30;
    }
  }
}
```

**Backend Storage:**
- Backend payment intent create karte waqt hold period data store karega
- Storage options:
  1. Payment Intent metadata me store kare (recommended)
  2. Ya temporary table/cache me store kare payment_intent_id ke saath
  3. Webhook me payment_intent_id se hold period data retrieve kare

**Webhook Handling:**
```php
// payment_intent.succeeded webhook me
$paymentIntentId = $event->data->object->id;
$holdPeriodData = getHoldPeriodFromMetadata($paymentIntentId); // Retrieve stored data
// Create payment_holds with user's custom period
```

**Hold Period Options (Flutter UI me show kare):**
- `1_month` - 30 days (default)
- `2_months` - 60 days
- `6_months` - 180 days
- `1_year` - 365 days
- `custom` - User manually start/end date select kare (minimum 30 days)

**Backend Validation:**
- Minimum hold period: 30 days
- If `hold_end_at` provided, must be >= `hold_start_at` + 30 days
- If `hold_days` provided, must be >= 30
- If `hold_start_at` not provided, use payment success time

**Webhook:** `payment_intent.succeeded` → Payment record update + Payment Hold create with user's custom period

---

### Step 3: Payment Hold (Laravel DB)
**Automatic:** Payment success ke baad automatically create hota hai

- Amount Laravel DB me hold hota hai
- **Hold period: User custom set karega** (minimum 30 days / 1 month)
- Status: `holding`
- `hold_start_at`: User custom date ya payment success time (if not provided)
- `hold_end_at`: User custom end date (must be >= hold_start_at + 30 days)
- `hold_days`: Calculated days between start and end
- `hold_period_type`: User selected option (1_month, 2_months, 6_months, 1_year, custom)

**Flutter App Responsibility:**
- ✅ Payment intent create karte waqt hold period options show kare
- ✅ User ko dropdown/selection UI provide kare (1 month, 2 months, 6 months, 1 year, custom)
- ✅ Custom option me date picker show kare (start date aur end date)
- ✅ Minimum 30 days validation frontend me bhi check kare
- ✅ Selected hold period ko API request me send kare

**Backend Responsibility:**
- ✅ Hold period validation (minimum 30 days)
- ✅ Payment hold record create kare with user's custom dates
- ✅ If dates not provided, use default (30 days from payment time)

---

### Step 4: Cron Job - Automatic Transfer Check
**Backend Cron Job:** Daily/Hourly run hota hai

**Logic:**
```php
// Cron job query
payment_holds WHERE 
  status = 'holding' 
  AND hold_end_at <= NOW()
```

**Action:**
1. Status update: `holding` → `ready_for_transfer`
2. **Automatically Transfer create kare** (transfer_type = 'cron')
3. Stripe Transfer API call kare
4. Transfer record create kare with status `pending`

**Important:** 
- ✅ Cron job automatically transfer trigger karega
- ✅ Admin manual bhi transfer kar sakta hai (transfer_type = 'manual')
- ✅ Dono cases me Stripe Transfer create hoga

---

### Step 5: Bank Details (Optional - Anytime)
**API Endpoint:** `POST /api/stripe/connect/onboarding-link`

- User bank details add kar sakta hai
- Adding bank details se auto transfer nahi hoga
- Transfer already ho chuka hai to payout ready ho jayega

**Flutter Integration:**
```dart
// Get onboarding link for bank details
POST /api/stripe/connect/onboarding-link
Response: {
  "onboarding_url": "https://connect.stripe.com/..."
}

// Open URL in WebView or browser
```

**Webhook:** `account.updated` → Update `payouts_enabled` and `status` in stripe_connect_accounts

---

### Step 6: Stripe Transfer (Admin/Cron Triggered)
**API Endpoint (Admin):** `POST /api/admin/transfer/{hold_id}`

**Two Ways:**
1. **Cron Job (Automatic):** 
   - Hold period khatam hone par automatically transfer create
   - `transfer_type = 'cron'`
   
2. **Admin Manual:**
   - Admin manually transfer trigger kare
   - `transfer_type = 'manual'`
   - `admin_id` field me admin user ID store hoga

**Transfer Details:**
- Exact amount transfer (no platform fee)
- Destination: User ka Stripe Connect Account
- Stripe Transfer API use karega

**Webhook:** 
- `transfer.created` → transfers.status = `completed`
- `transfer.failed` → transfers.status = `failed`

---

### Step 7: Stripe Payout (Automatic)
**Stripe Automatic Process:**

- Stripe automatically user ke bank account me payout karega
- **Condition:** Bank details added and verified honi chahiye
- **If bank missing:** Transfer pending rahega, payout nahi hoga

**No Action Required** - Stripe automatically handle karega

---

## API Endpoints Summary

### User Endpoints
1. `POST /api/stripe/connect/create` - Create Stripe Connect account
2. `POST /api/stripe/connect/onboarding-link` - Get bank onboarding link
3. `POST /api/stripe/payment-intent` - Create payment intent (with custom hold period)

### Admin Endpoints
4. `POST /api/admin/transfer/{hold_id}` - Manually trigger transfer

### Webhook Endpoint
5. `POST /api/stripe/webhook` - Handle Stripe events

---

## Webhook Events Handling

### payment_intent.succeeded
- Update `payments.status = 'succeeded'`
- Create `payment_holds` record with user's custom hold period
- Set `hold_start_at` (user custom or payment time)
- Set `hold_end_at` (user custom, minimum 30 days from start)
- Set `hold_days` (calculated)
- Set `hold_period_type` (user selected)

### payment_intent.failed
- Update `payments.status = 'failed'`
- Store `failure_reason`

### account.updated
- Update `stripe_connect_accounts.payouts_enabled`
- Update `stripe_connect_accounts.status`

### transfer.created
- Update `transfers.status = 'completed'`
- Update `payment_holds.status = 'transferred'`

### transfer.failed
- Update `transfers.status = 'failed'`
- Store `failure_reason`

---

## Cron Job Details

### Job: Check Payment Holds
**Schedule:** Daily (recommended) or Hourly

**Query:**
```sql
SELECT * FROM payment_holds 
WHERE status = 'holding' 
AND hold_end_at <= NOW()
```

**Actions:**
1. Update `payment_holds.status = 'ready_for_transfer'`
2. Create Stripe Transfer via API
3. Create `transfers` record with:
   - `transfer_type = 'cron'`
   - `status = 'pending'`
   - `admin_id = NULL`

---

## Status Flow Diagram

```
Payment Flow:
payment.succeeded → payment_holds.created (status: holding)
                    ↓
              [Hold Period: User Custom (minimum 30 days)]
                    ↓
         Cron Job Check (hold_end_at <= now)
                    ↓
    payment_holds.status = 'ready_for_transfer'
                    ↓
    Transfer Created (transfer_type: 'cron' or 'manual')
                    ↓
    transfers.status = 'pending' → Stripe API Call
                    ↓
    transfers.status = 'completed' (via webhook)
                    ↓
    payment_holds.status = 'transferred'
                    ↓
    Stripe Automatic Payout (if bank verified)
```

---

## Flutter Developer Implementation Guide

### ⚠️ CRITICAL: Payment Flow Sequence

**Yeh sequence follow karna MANDATORY hai:**

1. **Step 1: Hold Period Selection (FIRST)**
   - User ko hold period selection screen show kare
   - User option select kare (1 month, 2 months, 6 months, 1 year, ya custom)
   - Custom me dates select kare
   - Validation check kare (minimum 30 days)

2. **Step 2: Create Payment Intent (WITH Hold Period)**
   - Hold period data ke saath payment intent API call kare
   - **Hold period data yahan hi send kare** (payment intent create se PEHLE)
   - Backend hold period data store karega (metadata ya temporary storage me)

3. **Step 3: Stripe Checkout / Card Collection**
   - Stripe SDK use kare card details collect karne ke liye
   - **Card collection me hold period data nahi jayega** (already sent in Step 2)
   - Stripe checkout form me sirf card details collect honge

4. **Step 4: Payment Confirmation**
   - Payment confirm hone ke baad webhook trigger hoga
   - Backend stored hold period data use karega payment hold create karne ke liye

**Key Point:** Hold period data payment intent create karte waqt hi send karni hai, Stripe checkout/card collection se PEHLE.

---

### What Flutter App Needs to Do:

#### 1. Payment Intent Creation with Hold Period Selection

**UI Components Required:**
- Hold Period Selection Dropdown/Radio Buttons
- Custom Date Pickers (if custom option selected)
- Validation Messages

**Implementation Steps:**

```dart
// Step 1: Show Hold Period Options
class HoldPeriodSelection extends StatefulWidget {
  @override
  _HoldPeriodSelectionState createState() => _HoldPeriodSelectionState();
}

class _HoldPeriodSelectionState extends State<HoldPeriodSelection> {
  String? selectedPeriod; // '1_month', '2_months', '6_months', '1_year', 'custom'
  DateTime? startDate;
  DateTime? endDate;
  
  final Map<String, int> periodDays = {
    '1_month': 30,
    '2_months': 60,
    '6_months': 180,
    '1_year': 365,
  };
  
  // Step 2: Validate Custom Dates
  bool validateCustomDates() {
    if (selectedPeriod == 'custom') {
      if (startDate == null || endDate == null) {
        return false;
      }
      final days = endDate!.difference(startDate!).inDays;
      if (days < 30) {
        // Show error: Minimum 30 days required
        return false;
      }
    }
    return true;
  }
  
  // Step 3: Prepare API Request
  Map<String, dynamic> getHoldPeriodData() {
    if (selectedPeriod == 'custom') {
      return {
        'hold_period_type': 'custom',
        'hold_start_at': startDate!.toIso8601String(),
        'hold_end_at': endDate!.toIso8601String(),
        'hold_days': endDate!.difference(startDate!).inDays,
      };
    } else {
      final days = periodDays[selectedPeriod] ?? 30;
      final start = DateTime.now();
      final end = start.add(Duration(days: days));
      return {
        'hold_period_type': selectedPeriod,
        'hold_start_at': start.toIso8601String(),
        'hold_end_at': end.toIso8601String(),
        'hold_days': days,
      };
    }
  }
  
  // Step 4: Create Payment Intent API Call (WITH Hold Period Data)
  Future<void> createPaymentIntent() async {
    if (!validateCustomDates()) {
      // Show error
      return;
    }
    
    final holdPeriod = getHoldPeriodData();
    
    // ⚠️ Hold period data yahan send kare (payment intent create se PEHLE)
    final response = await http.post(
      Uri.parse('$baseUrl/api/stripe/payment-intent'),
      headers: {
        'Authorization': 'Bearer $token',
        'Content-Type': 'application/json',
      },
      body: jsonEncode({
        'amount': amountInCents,
        'currency': 'usd',
        ...holdPeriod, // Add hold period data
      }),
    );
    
    final data = jsonDecode(response.body);
    final clientSecret = data['data']['client_secret'];
    final paymentIntentId = data['data']['payment_intent_id'];
    
    // ✅ Backend me hold period data store ho chuka hai payment intent ke saath
    // Ab Stripe checkout/card collection kare
    
    // Step 5: Stripe Checkout / Card Collection
    // ⚠️ Card collection me hold period data nahi jayega (already sent above)
    await handleStripeCheckout(clientSecret);
  }
  
  // Step 6: Stripe Payment Confirmation
  Future<void> handleStripeCheckout(String clientSecret) async {
    // Use Stripe SDK for card collection
    // Example with flutter_stripe package:
    
    try {
      await Stripe.instance.initPaymentSheet(
        paymentSheetParameters: SetupPaymentSheetParameters(
          paymentIntentClientSecret: clientSecret,
          merchantDisplayName: 'Your App Name',
        ),
      );
      
      // Display payment sheet (card details collection)
      await Stripe.instance.presentPaymentSheet();
      
      // ✅ Payment successful
      // ✅ Webhook automatically trigger hoga
      // ✅ Backend me stored hold period data use hoga payment hold create karne ke liye
      
      showSuccess('Payment successful!');
    } catch (e) {
      showError('Payment failed: $e');
    }
  }
}
```

**Validation Rules (Flutter Side):**
- ✅ Minimum 30 days required
- ✅ End date must be >= Start date + 30 days
- ✅ If custom not selected, use predefined periods
- ✅ Show clear error messages

**API Request Format:**
```dart
{
  "amount": 10000,
  "currency": "usd",
  "hold_period_type": "2_months", // or "custom"
  "hold_start_at": "2026-01-08T10:00:00Z", // ISO 8601 format
  "hold_end_at": "2026-03-08T10:00:00Z", // ISO 8601 format
  "hold_days": 60 // Calculated days
}
```

#### 2. Display Hold Period Information

**Show in Payment Details Screen:**
- Hold start date
- Hold end date
- Remaining days
- Hold status

#### 3. No Action Required (Backend Handles):
- ❌ Webhook handling (Backend handles)
- ❌ Cron job execution (Backend handles)
- ❌ Transfer creation (Backend handles via cron)
- ❌ Stripe payout (Stripe handles automatically)

---

### 📋 Quick Reference: Complete Payment Flow

```
┌─────────────────────────────────────────────────────────────┐
│ FLUTTER APP FLOW                                             │
└─────────────────────────────────────────────────────────────┘

1. User selects hold period (UI Screen)
   ├─ Option: 1 month, 2 months, 6 months, 1 year
   └─ OR Custom: Select start date + end date (min 30 days)

2. Create Payment Intent API Call
   ├─ Send: amount, currency, hold_period_type, hold_start_at, hold_end_at
   ├─ Backend stores hold period data with payment intent
   └─ Receive: payment_intent_id, client_secret

3. Stripe Checkout / Card Collection
   ├─ Use Stripe SDK with client_secret
   ├─ User enters card details (Stripe form)
   └─ NO hold period data sent here (already sent in step 2)

4. Payment Confirmation
   ├─ Stripe processes payment
   ├─ Webhook triggers automatically
   └─ Backend uses stored hold period data to create payment hold

┌─────────────────────────────────────────────────────────────┐
│ BACKEND FLOW                                                 │
└─────────────────────────────────────────────────────────────┘

1. Payment Intent Created
   └─ Store hold period data (metadata or temp storage)

2. Webhook: payment_intent.succeeded
   ├─ Retrieve stored hold period data
   ├─ Create payment record
   └─ Create payment_holds with user's custom dates

3. Cron Job (Daily/Hourly)
   ├─ Check: hold_end_at <= NOW()
   └─ Auto create transfer (transfer_type = 'cron')

4. Stripe Transfer & Payout
   ├─ Transfer to user's Stripe Connect account
   └─ Stripe auto payout to user's bank (if verified)
```

**Key Takeaway:**
- ✅ Hold period data payment intent create karte waqt hi send kare
- ✅ Stripe checkout me sirf card details collect honge
- ✅ Backend hold period data store karega aur webhook me use karega

---

## Important Notes for Flutter Developer

1. **Transfer Trigger:**
   - `transfer_type` field se pata chalega ki transfer manual hai ya cron se
   - `'cron'` = Automatic (hold period khatam hone par)
   - `'manual'` = Admin manually trigger kiya

2. **Hold Period (User Custom):**
   - **User payment karte waqt hold period select karega**
   - **Minimum: 30 days (1 month)** - Required
   - **Options:**
     - `1_month` - 30 days (default if not selected)
     - `2_months` - 60 days
     - `6_months` - 180 days
     - `1_year` - 365 days
     - `custom` - User manually start/end date select kare
   - **Flutter UI me dropdown/selection show kare:**
     ```dart
     // Hold Period Selection UI
     DropdownButton(
       items: [
         '1 Month (30 days)',
         '2 Months (60 days)',
         '6 Months (180 days)',
         '1 Year (365 days)',
         'Custom (Select dates)'
       ]
     )
     ```
   - **Custom option me:**
     - Start date picker (default: today/payment time)
     - End date picker (minimum: start date + 30 days)
     - Validation: End date >= Start date + 30 days
   - **API Request me send kare:**
     ```dart
     {
       "hold_period_type": "2_months", // or "custom"
       "hold_start_at": "2026-01-08T10:00:00Z", // optional for custom
       "hold_end_at": "2026-03-08T10:00:00Z", // optional for custom
       "hold_days": 60 // optional, calculated if custom
     }
     ```
   - Hold period khatam hone par automatically transfer ho jayega (cron job)

3. **Bank Details:**
   - Optional hai initially
   - User baad me add kar sakta hai
   - Bank details ke bina transfer ho sakta hai, lekin payout nahi hoga

4. **Payment Status Tracking:**
   - Flutter app me payment status track karne ke liye:
     - `payments.status` - Payment status
     - `payment_holds.status` - Hold status
     - `transfers.status` - Transfer status

5. **Webhook Verification:**
   - Backend me Stripe webhook signature verification hoga
   - Flutter app ko direct webhook handle karne ki zarurat nahi

---

## Security & Compliance

✅ No long card authorization hold  
✅ No internal wallet  
✅ No auto transfer without admin/cron  
✅ No sensitive bank/identity data stored  
✅ All sensitive operations via Stripe API  
✅ Webhook signature verification  

---

## Example API Responses

### Create Payment Intent
```json
{
  "success": true,
  "data": {
    "payment_intent_id": "pi_xxx",
    "client_secret": "pi_xxx_secret_xxx",
    "amount": 10000,
    "currency": "usd",
    "hold_period": {
      "type": "2_months",
      "start_at": "2026-01-08T10:00:00Z",
      "end_at": "2026-03-08T10:00:00Z",
      "days": 60
    }
  }
}
```

### Create Payment Intent (Custom Dates)
```json
// Request
{
  "amount": 10000,
  "currency": "usd",
  "hold_period_type": "custom",
  "hold_start_at": "2026-01-15T10:00:00Z",
  "hold_end_at": "2026-04-15T10:00:00Z",
  "hold_days": 90
}

// Response
{
  "success": true,
  "data": {
    "payment_intent_id": "pi_xxx",
    "client_secret": "pi_xxx_secret_xxx",
    "amount": 10000,
    "currency": "usd",
    "hold_period": {
      "type": "custom",
      "start_at": "2026-01-15T10:00:00Z",
      "end_at": "2026-04-15T10:00:00Z",
      "days": 90
    }
  }
}
```

### Payment Status
```json
{
  "success": true,
  "data": {
    "payment": {
      "id": 1,
      "payment_intent_id": "pi_xxx",
      "amount": 10000,
      "status": "succeeded",
      "paid_at": "2026-01-08T10:00:00Z"
    },
    "hold": {
      "id": 1,
      "amount": 10000,
      "hold_start_at": "2026-01-08T10:00:00Z",
      "hold_end_at": "2026-02-07T10:00:00Z",
      "status": "holding"
    }
  }
}
```

---

**Last Updated:** 2026-01-08  
**For:** Flutter Mobile App Integration
