# Stripe Test Mode - Adding Funds Guide

## Issue: Insufficient Funds Error

Agar aapko yeh error aa raha hai:
```
"Insufficient available funds in your Stripe account"
```

Yeh Stripe **Test Mode** mein common issue hai. Transfer create karne ke liye Stripe account mein available balance chahiye.

---

## Solution: Test Mode Mein Funds Add Karein

### Method 1: Test Card Se Charge Create Karein (Recommended)

**Test Card:** `4000000000000077`

**Postman Request:**
```
POST https://api.stripe.com/v1/charges
Headers:
  Authorization: Bearer sk_test_xxxxx (Your Stripe Secret Key)
  Content-Type: application/x-www-form-urlencoded

Body (form-data):
  amount: 10000 (in cents = $100.00)
  currency: usd
  source: 4000000000000077
  description: Test funds for payout
```

**cURL Command:**
```bash
curl https://api.stripe.com/v1/charges \
  -u sk_test_xxxxx: \
  -d amount=10000 \
  -d currency=usd \
  -d source=4000000000000077 \
  -d description="Test funds"
```

**Response:**
```json
{
  "id": "ch_xxxxx",
  "amount": 10000,
  "currency": "usd",
  "status": "succeeded",
  ...
}
```

---

### Method 2: Stripe Dashboard Se Manually

1. **Stripe Dashboard** → **Payments** → **Create Payment**
2. Test card use karein: `4000000000000077`
3. Amount enter karein (e.g., $100.00)
4. Payment create karein

---

### Method 3: Payment Intent Se Charge Create Karein

**Postman Request:**
```
POST /api/stripe/test-payment
Authorization: Bearer {your_token}

Body:
{
    "amount": 100.00,
    "currency": "usd"
}
```

Yeh payment intent create karega aur confirm karega, jisse funds account mein add ho jayenge.

---

## Verify Funds

**Stripe Dashboard:**
- **Balance** → **Available Balance** check karein
- Transfer create karne ke liye sufficient balance hona chahiye

**API Check:**
```
GET https://api.stripe.com/v1/balance
Authorization: Bearer sk_test_xxxxx
```

---

## Important Notes:

1. **Test Mode Only**: Yeh sab test mode mein hai
2. **Real Funds Nahi**: Test mode mein real money nahi use hoti
3. **Transfer Amount**: Transfer amount se zyada balance hona chahiye
4. **Connect Account**: User ke paas verified Stripe Connect account hona chahiye

---

## Common Test Cards:

- **Success Card**: `4242424242424242`
- **Add Funds Card**: `4000000000000077` (Directly adds to available balance)
- **Decline Card**: `4000000000000002`

---

## After Adding Funds:

1. Funds add hone ke baad payout request dobara try karein
2. Transfer successfully create ho jayega
3. Webhook se transfer status update hoga

---

**Happy Testing! 🚀**
