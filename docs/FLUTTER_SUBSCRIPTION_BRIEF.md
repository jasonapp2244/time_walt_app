# Flutter Brief — Free Tier & Subscriptions (Time Vault)

Prepared 2026-10-07. The backend is built and tested locally (not deployed yet). This is the complete contract for Android and iOS; both platforms use the same code.

---

## What to build

1. **One Dio interceptor** that turns HTTP 402 `subscription_required` into the plans screen. No existing screen changes.
2. **Subscription Plans screen**: plan cards, Subscribe button, the Stripe Payment Sheet the app already uses for deposits.
3. **After payment:** call `/confirm`, then automatically retry the request that was blocked.
4. **Small additions:** "1 free lock left" hints on the lock and withdraw screens, and a **My Subscription** screen (plan, renewal date, Cancel, Manage payment method).

The free allowance is **2 locks + 2 withdrawals per account, for life**. The admin can change both numbers, and can switch the withdrawal limit off entirely. Never hard-code the numbers; read them from `/api/subscription/status`.

No new packages: `flutter_stripe` (Payment Sheet) and an in-app browser (`url_launcher` with `LaunchMode.inAppBrowserView`) are enough.

---

## Connection basics

Same as every other endpoint: base URL `https://api.timevaultapp.co`, Sanctum bearer token, and **`Accept: application/json` on every request**. Without it, a validation error comes back as an HTML redirect.

---

## 1. The 402 that opens the plans screen

These three existing endpoints can now answer **HTTP 402**:

| Endpoint | `action` |
| --- | --- |
| `POST /api/stripe/create-payment-intent` | `lock` |
| `POST /api/payment-holds/withdraw` | `withdraw` |
| `POST /api/payment-holds/{hold_id}/request-payout` | `withdraw` |

```json
{
  "success": false,
  "code": "subscription_required",
  "action": "lock",
  "used": 2,
  "limit": 2,
  "message": "You have used your 2 free locks. Subscribe to continue."
}
```

The 402 is returned **before** any card is charged or any money moves. Retrying the exact same request after subscribing is always safe.

`confirm-payment` is **never** blocked: once the card is charged, the lock always completes.

Interceptor sketch:

```dart
onError: (e, handler) async {
  final r = e.response;
  if (r?.statusCode == 402 && r?.data is Map && r!.data['code'] == 'subscription_required') {
    final subscribed = await navigator.push<bool>(MaterialPageRoute(
      builder: (_) => SubscriptionPlansScreen(action: r.data['action'], message: r.data['message']),
    ));
    if (subscribed == true) {
      return handler.resolve(await dio.fetch(e.requestOptions)); // replay the blocked request
    }
  }
  handler.next(e);
}
```

For a lock, the replayed `create-payment-intent` returns the deposit's Payment Sheet keys. Continue the normal deposit flow from there.

---

## 2. Plans

`GET /api/subscription/plans`

```json
{
  "success": true,
  "message": "Subscription plans retrieved successfully.",
  "data": {
    "plans": [
      {
        "id": 1,
        "name": "Monthly",
        "description": "No commitments. Cancel anytime.",
        "features": ["Unlimited time locks", "Unlimited withdrawals", "Renews automatically", "No commitments. Cancel anytime."],
        "price": 4.99,
        "currency": "USD",
        "interval": "month"
      },
      {
        "id": 2,
        "name": "Yearly",
        "description": "No commitments. Cancel anytime.",
        "features": ["Unlimited time locks", "Unlimited withdrawals", "Renews automatically", "No commitments. Cancel anytime."],
        "price": 49.99,
        "currency": "USD",
        "interval": "year"
      }
    ]
  }
}
```

- Show them in the order given. `interval` is `month` or `year`.
- An empty list means the admin has not published a plan yet. Show "Plans are not available right now" rather than a blank screen.
- **The client's plans (2026-10-23):** Monthly **$4.99/month** and Yearly **$49.99/year**, both renewing automatically until cancelled. They are created on the server by `php artisan db:seed --class=SubscriptionPlanSeeder`, so the list above is what the app receives, monthly first.
- **Client requirement (2026-10-13, wording 2026-10-23):** the plans screen (the paywall after the 2 free locks or the 2 free withdrawals) must show **"No commitments. Cancel anytime."** Place it near the price / Subscribe button, visible without scrolling. It is fixed app copy (it is also each plan's `description`), so show it even while the plans are loading.
- Under each price, show that it renews: **"Renews automatically at $4.99/month until cancelled."** / **"Renews automatically at $49.99/year until cancelled."** Build it from `price`, `currency` and `interval`. The app stores require auto-renewing subscriptions to say so before the user pays.
  - The line is accurate because there is no minimum term and the user can cancel at any time from My Subscription (§5).
  - Cancelling stops the next renewal; access continues to `current_period_end`; the current period is **not** refunded. Any extra paywall wording must not promise a refund.

---

## 3. Subscribe → Payment Sheet → confirm

### 3a. Start

`POST /api/subscription/subscribe` with body `{ "plan_id": 1 }`

Success, HTTP 200:

```json
{
  "success": true,
  "message": "Subscription created. Complete the payment to activate it.",
  "data": {
    "subscription_id": 12,
    "status": "incomplete",
    "client_secret": "pi_..._secret_...",
    "customer_id": "cus_...",
    "ephemeral_key": "ek_test_...",
    "publishable_key": "pk_test_...",
    "amount": 9.99,
    "currency": "usd",
    "plan": { "id": 1, "name": "Premium", "...": "..." }
  }
}
```

These are the **same keys as `create-payment-intent`**, so reuse the deposit Payment Sheet code:

```dart
await Stripe.instance.initPaymentSheet(paymentSheetParameters: SetupPaymentSheetParameters(
  paymentIntentClientSecret: data['client_secret'],
  customerId: data['customer_id'],
  customerEphemeralKeySecret: data['ephemeral_key'],
  merchantDisplayName: 'Time Vault',
  applePay: const PaymentSheetApplePay(merchantCountryCode: 'US'),
  googlePay: const PaymentSheetGooglePay(merchantCountryCode: 'US', testEnv: kDebugMode),
  returnURL: depositReturnUrl, // reuse the exact returnURL the deposit Payment Sheet passes (needed for 3-D Secure)
));
await Stripe.instance.presentPaymentSheet();
```

| Status | Meaning | Show |
| --- | --- | --- |
| 409 | Already subscribed | Close the plans screen and return `true` (retry) |
| 422 | Plan hidden or removed | "This plan is no longer available", reload the plans |
| 500 | Stripe unavailable | "Please try again later" |

If the user taps Subscribe twice, the second call returns the same unpaid subscription, so no duplicate charge is possible.

### 3b. Confirm (instant activation)

Right after `presentPaymentSheet()` completes without an exception:

`POST /api/subscription/confirm` (no body)

The server reads the subscription back from Stripe, so access starts now without waiting for the webhook. It returns the same `data` as `/status` (section 4):

- `data.is_subscribed == true` → pop the plans screen with `true`. The interceptor replays the blocked request.
- `data.is_subscribed == false` (message "Payment is still processing…") → poll `GET /api/subscription/status` every 2 s for up to ~10 s, then show "Payment received, activating…" and let the user retry.

If the user cancels the Payment Sheet (`StripeException` with `FailureCode.Canceled`), just stay on the plans screen. Nothing was charged.

---

## 4. Status and free allowance

`GET /api/subscription/status`

```json
{
  "success": true,
  "message": "Subscription status retrieved successfully.",
  "data": {
    "is_subscribed": false,
    "subscription": null,
    "locks_used": 1,
    "lock_limit": 2,
    "locks_left": 1,
    "withdrawals_used": 0,
    "withdraw_limit": 2,
    "withdrawals_left": 2
  }
}
```

When subscribed:

```json
"subscription": {
  "id": 12,
  "status": "active",
  "is_active": true,
  "plan": { "id": 1, "name": "Premium", "price": 9.99, "currency": "USD", "interval": "month", "...": "..." },
  "current_period_end": "2026-11-07T10:00:00+00:00",
  "cancel_at_period_end": false
}
```

- **`*_left: null` means unlimited**: the user is subscribed, or the admin switched that limit off. Hide the hint in that case.
- Use `locks_left` / `withdrawals_left` for the "1 free lock left" hint. When it is `0`, you may open the plans screen straight away instead of waiting for the 402.
- `current_period_end` is in the user's saved timezone.
- `status` can be `past_due` while Stripe retries a failed renewal. The user still has access; you may show "Payment problem — update your card".
- `subscription` is `null` whenever nothing gives access (never subscribed, payment abandoned, or ended). Gate on `is_subscribed`; a non-null `subscription` is always `active` or `past_due`.

---

## 5. My Subscription screen

| Action | Call | Notes |
| --- | --- | --- |
| Cancel | `POST /api/subscription/cancel` | Stops renewal. Access continues until `current_period_end`; show "Ends on {date}". 404 if nothing is active. |
| Manage payment method | `POST /api/subscription/portal` | Returns `data.url`; open it in an in-app browser. The user can change their card and see invoices there. Refresh `/status` when they come back. |
| Undo a cancel | `POST /api/subscription/portal` | While `cancel_at_period_end` is true, `/subscribe` answers 409, so show "Renew" that opens the portal, where the user can renew the plan. After it ends, Subscribe works again. |

---

## 6. Renewals (nothing to build)

- Stripe charges the saved card automatically every month or year.
- If a renewal fails, or the bank asks for 3-D Secure, the **server emails the user a link to pay**.
- Stripe retries failed renewals for a while. If every retry fails, the subscription ends, and the next lock or withdrawal past the free allowance gets the 402 again.

---

## 7. Test checklist (Stripe test mode)

1. New user: do 2 locks, check that the 3rd opens the plans screen, that it lists Monthly $4.99 and Yearly $49.99, and that it shows "No commitments. Cancel anytime." and the "Renews automatically" line without scrolling. Do the same with 2 withdrawals.
2. Subscribe with `4242 4242 4242 4242` and check that the 3rd lock continues straight into its deposit Payment Sheet.
3. Subscribe with `4000 0025 0000 3155` (3-D Secure). The authentication sheet appears and returns to the app.
4. Subscribe with `4000 0000 0000 9995` (declined). An error shows, you stay on the plans screen and nothing is active.
5. Cancel, then check that the status shows "Ends on …" and locks still work until then.
6. Check Apple Pay on an iOS device and Google Pay on an Android device.

---

## Note for the iOS release

Apple's App Store rule 3.1.1 asks that subscriptions which unlock app features use Apple's in-app purchase. This build sells the subscription through Stripe, so App Review may reject the iOS build. If that happens, the backend is already prepared for an Apple / RevenueCat path (the `subscriptions` table has a `provider` column); everything else in this brief stays the same.
