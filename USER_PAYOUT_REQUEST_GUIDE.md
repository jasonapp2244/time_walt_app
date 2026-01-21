# User Payout Request Guide - Roman Urdu

## Complete Flow:

```
Payment Hold Created (Status: holding)
    ↓
Hold Period Complete (hold_end_at pass ho jaye)
    ↓
User Payout Request API Call Karta Hai
    ↓
Hold Status: holding → ready_for_transfer (automatic)
    ↓
Stripe Transfer Create Hota Hai
    ↓
Webhook: transfer.created
    ↓
Transfer Status: pending → completed
    ↓
User Ko Payout Mil Gaya ✅
```

---

## API 1: Get User's Payment Holds

### Endpoint: `GET /api/payment-holds`

**Purpose:** User apne sabhi payment holds dekh sakta hai.

**Authentication:** ✅ Required (User token)

**Headers:**
```
Authorization: Bearer {user_token}
Accept: application/json
```

**Response (200):**
```json
{
    "success": true,
    "message": "Payment holds retrieved successfully.",
    "data": [
        {
            "id": 1,
            "amount": 50.00,
            "status": "holding",
            "hold_start_at": "2026-01-15T10:00:00Z",
            "hold_end_at": "2026-02-14T10:00:00Z",
            "hold_days": 30,
            "hold_period_type": "1_month",
            "ready_at": null,
            "transferred_at": null,
            "can_request_payout": false,
            "payment": {
                "id": 10,
                "payment_intent_id": "pi_xxxxx",
                "status": "succeeded"
            },
            "transfer": null
        },
        {
            "id": 2,
            "amount": 100.00,
            "status": "ready_for_transfer",
            "hold_start_at": "2026-01-01T10:00:00Z",
            "hold_end_at": "2026-01-31T10:00:00Z",
            "hold_days": 30,
            "hold_period_type": "1_month",
            "ready_at": "2026-02-01T10:00:00Z",
            "transferred_at": null,
            "can_request_payout": true,
            "payment": {
                "id": 11,
                "payment_intent_id": "pi_yyyyy",
                "status": "succeeded"
            },
            "transfer": null
        }
    ]
}
```

**Important Fields:**
- `can_request_payout`: `true` agar hold period complete ho gaya aur transfer nahi hua
- `status`: `holding`, `ready_for_transfer`, ya `transferred`
- `hold_end_at`: Hold period end date

---

## API 2: Request Payout

### Endpoint: `POST /api/payment-holds/{hold_id}/request-payout`

**Purpose:** User payout request karta hai agar hold `ready_for_transfer` hai.

**Authentication:** ✅ Required (User token)

**Headers:**
```
Content-Type: application/json
Authorization: Bearer {user_token}
Accept: application/json
```

**Request Body:**
```json
{}
```
(Empty body - hold_id URL parameter se aata hai)

**Success Response (200):**
```json
{
    "success": true,
    "message": "Payout request submitted successfully. Transfer will be processed shortly.",
    "data": {
        "transfer_id": 1,
        "stripe_transfer_id": "tr_xxxxx",
        "status": "pending",
        "amount": 50.00,
        "currency": "usd"
    }
}
```

**Error Responses:**

**400 - Hold Period Not Complete:**
```json
{
    "success": false,
    "message": "Hold period not completed yet. Payout will be available after hold period ends.",
    "data": {
        "hold_end_at": "2026-02-14T10:00:00Z",
        "days_remaining": 15
    }
}
```

**400 - Already Transferred:**
```json
{
    "success": false,
    "message": "Payout already completed for this hold."
}
```

**400 - Transfer Already Exists:**
```json
{
    "success": false,
    "message": "Payout request already exists for this hold.",
    "data": {
        "transfer_id": 1,
        "status": "pending"
    }
}
```

**404 - Hold Not Found:**
```json
{
    "success": false,
    "message": "Payment hold not found."
}
```

---

## Complete Example Flow:

### Step 1: User Login

**API:** `POST /api/auth/login`

**Response:**
```json
{
    "data": {
        "token": "1|user_token_here"
    }
}
```

### Step 2: Get Payment Holds

**API:** `GET /api/payment-holds`

**Headers:**
```
Authorization: Bearer 1|user_token_here
```

**Response:**
```json
{
    "data": [
        {
            "id": 1,
            "amount": 50.00,
            "status": "ready_for_transfer",
            "hold_end_at": "2026-01-31T10:00:00Z",
            "can_request_payout": true
        }
    ]
}
```

### Step 3: Request Payout

**API:** `POST /api/payment-holds/1/request-payout`

**Headers:**
```
Authorization: Bearer 1|user_token_here
```

**Response:**
```json
{
    "success": true,
    "message": "Payout request submitted successfully.",
    "data": {
        "transfer_id": 1,
        "stripe_transfer_id": "tr_xxxxx",
        "status": "pending"
    }
}
```

### Step 4: Webhook Automatic

Stripe automatically `transfer.created` webhook send karega.

### Step 5: Verify

**API:** `GET /api/payment-holds`

**Response:**
```json
{
    "data": [
        {
            "id": 1,
            "status": "transferred",
            "transferred_at": "2026-02-01T10:05:00Z",
            "transfer": {
                "id": 1,
                "status": "completed"
            }
        }
    ]
}
```

---

## When Can User Request Payout?

**User payout request kar sakta hai agar:**

1. ✅ Hold period complete ho gaya (`hold_end_at` <= current time)
2. ✅ Hold status `holding` ya `ready_for_transfer` hai
3. ✅ Transfer pehle se create nahi hua
4. ✅ Hold already transferred nahi hai

**User payout request nahi kar sakta agar:**

1. ❌ Hold period abhi complete nahi hua (`hold_end_at` > current time)
2. ❌ Hold already transferred hai (`status = 'transferred'`)
3. ❌ Transfer already exists

---

## Automatic Status Update:

Jab user payout request karta hai:

1. **Agar hold status `holding` hai:**
   - Status automatically update: `holding` → `ready_for_transfer`
   - `ready_at` timestamp set hota hai

2. **Transfer create hota hai:**
   - Stripe transfer create hota hai
   - Database mein transfer record create hota hai
   - Status: `pending`

3. **Webhook automatic:**
   - Stripe `transfer.created` webhook send karta hai
   - Transfer status: `pending` → `completed`
   - Hold status: `ready_for_transfer` → `transferred`

---

## Postman Testing:

### Request 1: Get Payment Holds

```
GET http://localhost/time_walt_app/public/api/payment-holds
Authorization: Bearer {user_token}
```

### Request 2: Request Payout

```
POST http://localhost/time_walt_app/public/api/payment-holds/1/request-payout
Authorization: Bearer {user_token}
Content-Type: application/json
```

**Body:** (Empty)

---

## Database Queries:

### Check User's Holds:
```sql
SELECT * FROM payment_holds 
WHERE user_id = 1
ORDER BY created_at DESC;
```

### Check Ready For Transfer:
```sql
SELECT * FROM payment_holds 
WHERE user_id = 1
AND status IN ('holding', 'ready_for_transfer')
AND (hold_end_at <= NOW() OR status = 'ready_for_transfer')
AND id NOT IN (SELECT hold_id FROM transfers);
```

### Check Transfer Status:
```sql
SELECT ph.*, t.status as transfer_status, t.stripe_transfer_id
FROM payment_holds ph
LEFT JOIN transfers t ON t.hold_id = ph.id
WHERE ph.user_id = 1;
```

---

## Important Points:

1. **User Apna Hold Hi Request Kar Sakta Hai:**
   - User sirf apne holds ke liye payout request kar sakta hai
   - Other users ke holds access nahi kar sakta

2. **Hold Period Complete Hona Chahiye:**
   - `hold_end_at` <= current time
   - Ya status already `ready_for_transfer` hona chahiye

3. **Automatic Transfer:**
   - User request karte hi transfer create ho jata hai
   - Admin approval ki zaroorat nahi (kyunki hold period complete ho gaya)

4. **Webhook Automatic:**
   - Transfer create hone ke baad Stripe webhook automatically aayega
   - Status automatically update ho jayega

---

## Comparison: Admin vs User Transfer

| Feature | Admin Transfer | User Payout Request |
|---------|---------------|---------------------|
| **Who Can Call** | Admin only | User (own holds) |
| **When** | Anytime | Only when hold period complete |
| **Approval** | Not needed | Not needed (automatic) |
| **Status Check** | Admin decides | System checks automatically |
| **Use Case** | Manual admin action | User self-service |

---

**End of Guide**

Ab user apna payout request kar sakta hai jab hold period complete ho jaye! 🎉
