# Payment Holds Summary API Guide

## New API: Get Payment Holds Summary

### Endpoint: `GET /api/payment-holds/summary`

**Purpose:** User ki total locked payment, transferred payment, aur other statistics dikhata hai.

**Authentication:** ✅ Required (User token)

---

## Request:

**Method:** `GET`  
**URL:** `/api/payment-holds/summary`

**Headers:**
```
Authorization: Bearer {user_token}
Accept: application/json
```

**Body:** (No body required)

---

## Response:

**Success Response (200):**
```json
{
    "success": true,
    "message": "Payment holds summary retrieved successfully.",
    "data": {
        "summary": {
            "total_amount": 500.00,
            "total_locked": 200.00,
            "total_ready_for_transfer": 150.00,
            "total_transferred": 150.00,
            "available_for_payout": 150.00,
            "upcoming_payouts": 200.00
        },
        "counts": {
            "total": 5,
            "locked": 2,
            "ready_for_transfer": 1,
            "transferred": 2,
            "available_for_payout": 1
        }
    }
}
```

---

## Response Fields Explanation:

### Summary (Amounts):

| Field | Description | Example |
|-------|-------------|---------|
| `total_amount` | Sabhi holds ka total amount | 500.00 |
| `total_locked` | Abhi locked holds ka amount (status: `holding`) | 200.00 |
| `total_ready_for_transfer` | Ready for transfer holds ka amount | 150.00 |
| `total_transferred` | Already transferred holds ka amount | 150.00 |
| `available_for_payout` | User payout request kar sakta hai (ready + period complete) | 150.00 |
| `upcoming_payouts` | Future mein available honge (period abhi complete nahi) | 200.00 |

### Counts (Numbers):

| Field | Description | Example |
|-------|-------------|---------|
| `total` | Total holds count | 5 |
| `locked` | Locked holds count (status: `holding`) | 2 |
| `ready_for_transfer` | Ready for transfer count | 1 |
| `transferred` | Transferred holds count | 2 |
| `available_for_payout` | Available for payout count | 1 |

---

## Use Cases:

### 1. Dashboard Summary:
```javascript
// Frontend dashboard mein summary show karo
const summary = await fetch('/api/payment-holds/summary', {
    headers: { 'Authorization': `Bearer ${token}` }
});

// Display:
// Total Locked: $200.00
// Available for Payout: $150.00
// Total Transferred: $150.00
```

### 2. Quick Overview:
- User ko quickly pata chal jata hai kitna locked hai
- Kitna available hai payout ke liye
- Kitna already transferred ho gaya

---

## Example Scenarios:

### Scenario 1: User Has Multiple Holds

**Database:**
- Hold 1: $50 (status: `holding`, period not complete)
- Hold 2: $100 (status: `holding`, period complete)
- Hold 3: $75 (status: `ready_for_transfer`)
- Hold 4: $150 (status: `transferred`)

**Response:**
```json
{
    "summary": {
        "total_amount": 375.00,
        "total_locked": 150.00,        // Hold 1 + Hold 2
        "total_ready_for_transfer": 75.00,  // Hold 3
        "total_transferred": 150.00,   // Hold 4
        "available_for_payout": 175.00, // Hold 2 + Hold 3
        "upcoming_payouts": 50.00      // Hold 1
    },
    "counts": {
        "total": 4,
        "locked": 2,
        "ready_for_transfer": 1,
        "transferred": 1,
        "available_for_payout": 2
    }
}
```

---

## Postman Testing:

**Request:**
```
GET http://localhost/time_walt_app/public/api/payment-holds/summary
Authorization: Bearer {user_token}
```

**Expected Response:**
```json
{
    "success": true,
    "data": {
        "summary": {
            "total_locked": 200.00,
            "total_transferred": 150.00,
            "available_for_payout": 150.00
        }
    }
}
```

---

## Frontend Usage Example:

```javascript
// React/Vue/etc. mein use karo
const getPaymentSummary = async () => {
    const response = await fetch('/api/payment-holds/summary', {
        headers: {
            'Authorization': `Bearer ${token}`
        }
    });
    
    const data = await response.json();
    
    // Display in UI
    console.log('Locked:', data.data.summary.total_locked);
    console.log('Transferred:', data.data.summary.total_transferred);
    console.log('Available:', data.data.summary.available_for_payout);
};
```

---

## Complete API List:

| API | Method | Purpose |
|-----|--------|---------|
| Get Holds List | `GET /api/payment-holds` | All holds with details |
| Get Summary | `GET /api/payment-holds/summary` | Summary/statistics |
| Request Payout | `POST /api/payment-holds/{hold_id}/request-payout` | Request payout |

---

**End of Guide**

Ab user apni summary dekh sakta hai! 🎉
