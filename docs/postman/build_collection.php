<?php

/**
 * Generates the Time Vault Postman collection + environments from the real route table.
 * Run from the project root:  php <this file>
 */
const JSON_OPTS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

function url(string $path, array $query = []): array
{
    $clean = ltrim($path, '/');
    $segments = $clean === '' ? [] : explode('/', $clean);

    $enabled = array_filter($query, fn ($q) => empty($q['disabled']));
    $raw = '{{base_url}}/'.$clean;
    if ($enabled) {
        $raw .= '?'.implode('&', array_map(fn ($q) => $q['key'].'='.$q['value'], $enabled));
    }

    $u = ['raw' => $raw, 'host' => ['{{base_url}}'], 'path' => $segments];
    if ($query) {
        $u['query'] = array_values($query);
    }

    return $u;
}

function events(?string $test, ?string $pre): array
{
    $out = [];
    if ($pre !== null) {
        $out[] = ['listen' => 'prerequest', 'script' => ['type' => 'text/javascript', 'exec' => explode("\n", $pre)]];
    }
    if ($test !== null) {
        $out[] = ['listen' => 'test', 'script' => ['type' => 'text/javascript', 'exec' => explode("\n", $test)]];
    }

    return $out;
}

/**
 * @param  array|null  $body  Associative array -> raw JSON body. ['__form' => [...]] -> form-data.
 */
function item(string $name, string $method, array $url, ?array $body = null, string $desc = '', array $opts = []): array
{
    $headers = [['key' => 'Accept', 'value' => 'application/json']];
    $request = ['method' => $method, 'header' => &$headers, 'url' => $url];

    if (! empty($opts['public'])) {
        $request['auth'] = ['type' => 'noauth'];
    }

    foreach ($opts['headers'] ?? [] as $k => $v) {
        $headers[] = ['key' => $k, 'value' => $v];
    }

    if ($body !== null && isset($body['__form'])) {
        $fields = [];
        foreach ($body['__form'] as $key => $spec) {
            $field = ['key' => $key, 'type' => $spec['type'] ?? 'text'];
            if (($spec['type'] ?? 'text') === 'file') {
                $field['src'] = [];
            } else {
                $field['value'] = (string) $spec['value'];
            }
            if (! empty($spec['disabled'])) {
                $field['disabled'] = true;
            }
            if (! empty($spec['description'])) {
                $field['description'] = $spec['description'];
            }
            $fields[] = $field;
        }
        $request['body'] = ['mode' => 'formdata', 'formdata' => $fields];
    } elseif ($body !== null) {
        $headers[] = ['key' => 'Content-Type', 'value' => 'application/json'];
        $request['body'] = [
            'mode' => 'raw',
            'raw' => json_encode($body, JSON_OPTS),
            'options' => ['raw' => ['language' => 'json']],
        ];
    }

    if ($desc !== '') {
        $request['description'] = $desc;
    }

    unset($headers);

    $entry = ['name' => $name, 'request' => $request, 'response' => []];

    $ev = events($opts['test'] ?? null, $opts['pre'] ?? null);
    if ($ev) {
        $entry['event'] = $ev;
    }

    return $entry;
}

function folder(string $name, string $desc, array $items): array
{
    return ['name' => $name, 'description' => $desc, 'item' => $items];
}

// ---------------------------------------------------------------------------
// Reusable scripts
// ---------------------------------------------------------------------------

$saveToken = <<<'JS'
const body = pm.response.json();
const token = body && body.data ? body.data.token : null;

if (token) {
    pm.collectionVariables.set("token", token);
    console.log("Saved token to collection variable {{token}}");
} else {
    console.log("No token in response - check the message field:", body && body.message);
}

pm.test("Request succeeded", function () {
    pm.expect(pm.response.code).to.be.oneOf([200, 201]);
});
JS;

$holdDates = <<<'JS'
// Keeps hold_start_at valid forever: start = now, end = now + 1 day.
// The API rejects a start date before today (in the user's timezone).
const pad = (n) => String(n).padStart(2, "0");
const fmt = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;

const start = new Date();
const end = new Date(start.getTime() + 24 * 60 * 60 * 1000);

pm.collectionVariables.set("hold_start_at", fmt(start));
pm.collectionVariables.set("hold_end_at", fmt(end));
JS;

$savePaymentIntent = <<<'JS'
const body = pm.response.json();

if (body && body.data && body.data.payment_intent_id) {
    pm.collectionVariables.set("payment_intent_id", body.data.payment_intent_id);
    pm.collectionVariables.set("client_secret", body.data.client_secret);
    console.log("Saved payment_intent_id:", body.data.payment_intent_id);
}

pm.test("Payment intent created", function () {
    pm.response.to.have.status(200);
});
JS;

$saveBankId = <<<'JS'
const body = pm.response.json();
const bank = body && body.data ? (body.data.bank_account || body.data) : null;

if (bank && bank.id) {
    pm.collectionVariables.set("bank_account_id", bank.id);
    console.log("Saved bank_account_id:", bank.id);
}
JS;

$saveHoldId = <<<'JS'
const body = pm.response.json();
const rows = body && body.data ? (body.data.data || body.data.transactions || body.data) : null;

if (Array.isArray(rows) && rows.length) {
    const id = rows[0].hold_id || rows[0].id;
    if (id) {
        pm.collectionVariables.set("hold_id", id);
        console.log("Saved hold_id:", id);
    }
}
JS;

$collectionTest = <<<'JS'
// Runs after every request in this collection.
pm.test("No server error (5xx)", function () {
    pm.expect(pm.response.code, "HTTP status").to.be.below(500);
});

// Surfaces Laravel's validation errors in the Postman console instead of
// making you dig through the response body.
if (pm.response.code === 422) {
    try {
        const errors = pm.response.json().errors || {};
        Object.keys(errors).forEach((field) => console.log("422 " + field + ": " + errors[field][0]));
    } catch (e) { /* non-JSON body */ }
}
JS;

// ---------------------------------------------------------------------------
// Folders
// ---------------------------------------------------------------------------

$items = [];

$items[] = folder('00. Start here', <<<'TXT'
Read this first.

1. Import this collection AND one of the environment files, then pick the environment
   in the top-right selector.
2. Run "1.2 Signup" -> "1.3 Verify OTP" (or "1.5 Login" with an existing account).
   The token is saved automatically into the {{token}} collection variable; every
   protected request picks it up. You never paste a token by hand.
3. Everything else can be run in any order.

Variables worth knowing: {{base_url}} (API root, ends in /api), {{host}} (no /api,
used only by the health check), {{token}}, {{email}}, {{password}}, {{otp_code}},
{{hold_id}}, {{bank_account_id}}, {{payment_intent_id}}.

Auth is set once on the collection (Bearer {{token}}). Public endpoints override it
with "No Auth", so a stale token cannot break signup or login.
TXT, [
    item('0.1 Health check', 'GET', [
        'raw' => '{{host}}/up',
        'host' => ['{{host}}'],
        'path' => ['up'],
    ], null, 'Laravel health endpoint. Lives at the domain root, NOT under /api. Returns 200 and an HTML page when the app boots and the database is reachable.', [
        'public' => true,
        'test' => "pm.test(\"App is up\", function () {\n    pm.response.to.have.status(200);\n});",
    ]),
    item('0.2 Privacy policy (public)', 'GET', url('privacy-policy'), null, 'The active privacy policy for the in-app viewer. No auth. Rate limit 60/min.', ['public' => true]),
]);

$items[] = folder('01. Auth (public)', 'Unauthenticated endpoints. Rate limits are per IP and tight: signup/login/verify/reset 5/min, resend-otp and forgot-password 3/min, check-email 10/min. A 429 here means you hit that ceiling, not that the credentials were wrong.', [
    item('1.1 Check email', 'POST', url('auth/check-email'), [
        'email' => '{{email}}',
    ], "Tells the app whether an address is already registered before showing the signup form.\n\nRules: email required, valid, max 255.\nReturns success:true with an `exists` boolean - it is never a 404.", ['public' => true]),

    item('1.2 Signup', 'POST', url('auth/signup'), [
        'full_name' => 'Test User',
        'email' => '{{email}}',
        'phone' => '+12025550147',
        'password' => '{{password}}',
        'password_confirmation' => '{{password}}',
    ], <<<'TXT'
Creates an unverified account and emails a 4-digit OTP.

Rules:
  full_name             required, max 255
  email                 required, valid, max 255, unique (checked via blind index)
  phone                 optional, max 20, unique
  password              required, 8-128, confirmed, and must contain a lowercase
                        letter, an uppercase letter, a digit and a symbol
  password_confirmation required, must match password

Social signup: send provider (google|apple|facebook) and provider_id instead.

The account cannot log in until "1.3 Verify OTP" succeeds. Unverified accounts are
hard-deleted after 24 hours by the users:cleanup-unverified command.
TXT, ['public' => true, 'test' => $saveToken]),

    item('1.3 Verify OTP', 'POST', url('auth/verify-otp'), [
        'email' => '{{email}}',
        'otp_code' => '{{otp_code}}',
    ], "Confirms the signup OTP and returns the bearer token.\n\nRules: email (or phone) required, otp_code required and exactly 4 characters.\nThe code is in the signup email. On a local box with MAIL_MAILER=log, read it from storage/logs/laravel.log.", ['public' => true, 'test' => $saveToken]),

    item('1.4 Resend OTP', 'POST', url('auth/resend-otp'), [
        'email' => '{{email}}',
    ], 'Issues a fresh OTP. Rate limit 3/min - the tightest in the API.', ['public' => true]),

    item('1.5 Login', 'POST', url('auth/login'), [
        'email' => '{{email}}',
        'password' => '{{password}}',
        'device_id' => 'postman-device-001',
        'device_type' => 'android',
        'fcm_token' => '',
        'timezone' => 'America/New_York',
        'language' => 'en',
    ], <<<'TXT'
Email/password login. Saves data.token into {{token}} automatically.

Rules:
  email        required without phone, valid email
  phone        required without email
  password     required (omit only for social login)
  otp_code     optional, exactly 4 chars - only when the account has 2FA pending
  device_type  optional, one of ios|android|web
  timezone     optional - stored on the user and used to render every date the API
               returns, so send the device timezone

If the response has data.token = null, read the message: the account needs OTP
verification first.
TXT, ['public' => true, 'test' => $saveToken]),

    item('1.6 Login (social)', 'POST', url('auth/login'), [
        'email' => '{{email}}',
        'provider' => 'google',
        'provider_id' => '104729384756102938475',
        'provider_token' => 'ya29.a0AfB_byC...',
    ], "Google/Apple/Facebook login. When provider and provider_id are both present the\npassword becomes optional and provider_token becomes required.\n\nprovider must be one of: google, apple, facebook.", ['public' => true, 'test' => $saveToken]),

    item('1.7 Forgot password', 'POST', url('auth/forgot-password'), [
        'email' => '{{email}}',
    ], 'Emails a 4-digit reset OTP. Send either email or phone. Rate limit 3/min.', ['public' => true]),

    item('1.8 Reset password', 'POST', url('auth/reset-password'), [
        'email' => '{{email}}',
        'otp_code' => '{{otp_code}}',
        'password' => 'NewPass@123',
        'password_confirmation' => 'NewPass@123',
    ], "Completes the reset with the OTP from 1.7.\n\nRules: otp_code required and exactly 4 chars; password 8-128, confirmed, and must\ncontain lower + upper + digit + symbol. Same policy as signup.", ['public' => true]),
]);

$items[] = folder('02. Auth (token required)', 'Both need a valid bearer token.', [
    item('2.1 Logout', 'POST', url('auth/logout'), null, 'Revokes the current access token only - other devices stay signed in. No body.', [
        'test' => "pm.collectionVariables.set(\"token\", \"\");\nconsole.log(\"Cleared {{token}}\");",
    ]),

    item('2.2 Delete account', 'POST', url('auth/delete-account'), null, <<<'TXT'
DESTRUCTIVE. Do not fire this against an account you still need.

What it does, in one transaction: sweeps any remaining balance to the admin account,
marks every payment hold and transfer as abandoned, revokes all tokens, then
anonymises the row (email, phone and name are overwritten, status becomes 'deleted').
The user's email address is freed for re-registration.

The account cannot be recovered afterwards. Rate limit 5 per hour.
TXT),
]);

$items[] = folder('03. Profile', 'Everything under /api/profile. All token-protected.', [
    item('3.1 Get profile', 'GET', url('profile'), null, 'Returns the signed-in user: name, email, phone, profile image URL, timezone, language, verification state.'),

    item('3.2 Update profile', 'POST', url('profile/update'), ['__form' => [
        'full_name' => ['value' => 'Test User', 'description' => 'optional, max 255'],
        'phone' => ['value' => '+12025550147', 'description' => 'optional, max 20, unique'],
        'timezone' => ['value' => 'America/New_York', 'description' => 'optional, max 100'],
        'language' => ['value' => 'en', 'description' => 'optional, max 10'],
        'profile_image' => ['type' => 'file', 'description' => 'optional - jpeg/jpg/png/gif, max 2 MB', 'disabled' => true],
    ]], <<<'TXT'
POST, not PUT - the route uses POST so multipart file uploads work.

Send as form-data (already configured). Every field is optional; send only what
changed. Tick the profile_image row and pick a file to test the upload; leave it
unticked otherwise.

profile_image: image only, jpeg/jpg/png/gif, max 2048 KB.
TXT),

    item('3.3 Change password', 'POST', url('profile/change-password'), [
        'current_password' => '{{password}}',
        'new_password' => 'NewPass@123',
        'new_password_confirmation' => 'NewPass@123',
    ], "Rules: current_password required; new_password 8-128, confirmed, and must contain\nlower + upper + digit + symbol. Rate limit 5/min.", []),

    item('3.4 Update language', 'POST', url('profile/language'), [
        'language' => 'en',
    ], 'Allowed values: en, es, fr, de, it, pt, zh, ja, ko, ar, hi. Anything else is a 422.'),

    item('3.5 Update timezone', 'POST', url('profile/timezone'), [
        'timezone' => 'America/New_York',
    ], "Must be a real IANA identifier (America/New_York, Europe/London, Asia/Karachi).\nAbbreviations like EST are rejected.\n\nThis matters: the stored timezone decides how hold dates are interpreted on create\nand how every date in the API is rendered back."),

    item('3.6 Submit feedback', 'POST', url('profile/feedback'), [
        'rating' => 5,
        'feedback' => 'The hold timer is clear and payouts arrive on time.',
    ], <<<'TXT'
Stores the feedback, emails ADMIN_EMAIL, and emails a confirmation to the user.

Rules: rating required, integer 1-5. feedback required, max 1000 chars.
Whitespace is trimmed server-side, so the stored text can differ from what you sent.

Quirk: the endpoint also accepts the misspelled key `ratting` for old app builds.
Send `rating`. On a validation failure BOTH keys come back in `errors`, and the
top-level `message` carries a "(and 1 more error)" suffix - bind to errors.rating
and ignore the rest.

Rate limit 10/min. No email arrives if the queue worker is not running.
TXT),

    item('3.7 Submit support request', 'POST', url('profile/support'), [
        'subject' => 'Withdrawal not received',
        'message' => 'I requested a withdrawal three days ago and my bank shows nothing.',
    ], <<<'TXT'
Stores the request, emails SUPPORT_EMAIL, and emails a confirmation to the user.
Visible to staff at /admin/support.

Rules: subject required, max 150. message required, max 2000. Both trimmed.

The returned data.support_request.id is the reference number the user sees in their
confirmation email, zero-padded to five digits (id 2 -> #00002).

Rate limit 10/min.
TXT),
]);

$items[] = folder('04. Notification settings', 'Per-user alert toggles.', [
    item('4.1 Get settings', 'GET', url('notification-settings'), null, 'Returns the six boolean toggles for the signed-in user.'),
    item('4.2 Update settings', 'POST', url('notification-settings/update'), [
        'password_alert' => true,
        'transaction_alert' => true,
        'push_notification_alert' => true,
        'email_alert' => true,
        'lock_alert' => true,
        'unlock_alert' => true,
    ], "Every field is `sometimes|boolean` - send only the toggles you are changing and the\nrest keep their current value."),
]);

$items[] = folder('05. App config', 'Static URLs the app reads at startup.', [
    item('5.1 Refer-a-friend + store URLs', 'GET', url('refer-friend-url'), null, 'Returns refer_friend_url, android_app_url and ios_app_url, all from config/env. Empty values mean the keys are unset on the server.'),
]);

$items[] = folder('06. Stripe payments', <<<'TXT'
Payment Sheet flow. The order is fixed:

  create-payment-intent -> present the Payment Sheet on the device ->
  confirm-payment -> a PaymentHold row exists

From Postman you can only run step 1 and step 3; the card is collected by the Stripe
SDK on the device. To test step 3 without a device, confirm the intent with a test
card through the Stripe CLI or dashboard first, then call confirm-payment.

Use test keys. A 200 from Stripe is not proof a charge settled - check the hold.

create-payment-intent is gated by the free tier (folder 11): once the user has used
their free locks and has no subscription it returns 402 code=subscription_required
before any card is touched. confirm-payment is never gated.
TXT, [
    item('6.1 Create payment intent', 'POST', url('stripe/create-payment-intent'), [
        'amount' => 25,
        'currency' => 'usd',
        'title' => 'Vacation fund',
        'hold_period_type' => 'custom',
        'hold_start_at' => '{{hold_start_at}}',
        'hold_end_at' => '{{hold_end_at}}',
        'hold_hours' => 0,
        'hold_minutes' => 0,
    ], <<<'TXT'
Creates the Stripe Customer, EphemeralKey and PaymentIntent in one call and returns
everything the Payment Sheet needs: payment_intent_id, client_secret, customer_id,
ephemeral_key, publishable_key.

Rules:
  amount           required, numeric, min 1
  currency         required, 3 chars, must be in services.stripe.supported_currencies
  title            optional, max 255
  hold_period_type required, currently only "custom"
  hold_start_at    required, date, not before today IN THE USER'S TIMEZONE
  hold_end_at      required, date, after hold_start_at
  hold_hours       optional, 0-23     hold_minutes optional, 0-59

The pre-request script rewrites hold_start_at/hold_end_at to now and now+1 day, so
this request never goes stale. Format: "YYYY-MM-DD HH:MM:SS".

payment_intent_id and client_secret are saved to collection variables on success.
TXT, ['pre' => $holdDates, 'test' => $savePaymentIntent]),

    item('6.2 Confirm payment', 'POST', url('stripe/confirm-payment'), [
        'payment_intent_id' => '{{payment_intent_id}}',
    ], <<<'TXT'
Call this after the Payment Sheet succeeds. Writes the Payment row, the PaymentHold
row and the saved card details (brand, last4, expiry, funding, country).

Rules: payment_intent_id required, must start with "pi_".

Idempotent - calling it twice returns "Payment already recorded." rather than
duplicating the hold.

400 "not completed yet" means the intent was never confirmed on the device.
403 means the intent belongs to a different user.
TXT),

    item('6.3 Stripe webhook (reference only)', 'POST', url('stripe/webhook'), [
        'id' => 'evt_test_webhook',
        'object' => 'event',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'pi_xxx', 'object' => 'payment_intent', 'amount' => 2500, 'currency' => 'usd', 'status' => 'succeeded']],
    ], <<<'TXT'
Stripe calls this, you normally do not. No auth, no CSRF.

Sending it from Postman returns a signature error: the handler verifies the
Stripe-Signature header against STRIPE_WEBHOOK_SECRET, and Postman cannot forge it.
That rejection is the endpoint working correctly.

To exercise it for real:
    stripe listen --forward-to https://api.timevaultapp.co/api/stripe/webhook
    stripe trigger payment_intent.succeeded

Kept here so the route table in Postman matches the one in the code.
TXT, ['public' => true, 'headers' => ['Stripe-Signature' => 't=0,v1=placeholder_will_be_rejected']]),
]);

$items[] = folder('07. Bank accounts', <<<'TXT'
Multiple banks per user; the first one added becomes primary automatically. Adding
the first bank silently creates the user's Stripe Custom Connect account, so the
first POST is slower than the rest.

Stripe does not allow editing bank details - to change an account you delete it and
add a new one.
TXT, [
    item('7.1 Add bank account (US)', 'POST', url('bank-account'), [
        'dob' => '1990-05-14',
        'account_number' => '000123456789',
        'bank_name' => 'Chase',
        'routing_number' => '110000000',
        'account_type' => 'checking',
        'country' => 'US',
        'currency' => 'usd',
        'address_line1' => '1600 Pennsylvania Ave NW',
        'city' => 'Washington',
        'state' => 'DC',
        'postal_code' => '20500',
        'phone' => '+12025550147',
        'ssn_last_4' => '0000',
    ], <<<'TXT'
Rules:
  dob             required, a real date, before today
  account_number  required, 5-34 chars
  bank_name       required, max 100
  routing_number  REQUIRED when country is US, exactly 9 digits
  iban            REQUIRED when country is NOT US, 15-34 chars
  account_type    required, one of savings|checking|current
  country         required, 2 letters, one of US GB CA AU DE FR IE NL AT BE ES IT
                  PT DK FI NO SE CH NZ SG HK JP
  currency        required, 3 letters, one of usd eur gbp cad aud nzd sgd hkd jpy
                  chf dkk nok sek
  address_line1, city, state, postal_code, phone   all required
  ssn_last_4      REQUIRED when country is US, exactly 4 digits
  id_number       optional, max 30

The values above are Stripe's US test bank numbers - safe to send against test keys.
TXT, ['test' => $saveBankId]),

    item('7.2 Add bank account (IBAN / non-US)', 'POST', url('bank-account'), [
        'dob' => '1990-05-14',
        'account_number' => 'GB82WEST12345698765432',
        'iban' => 'GB82WEST12345698765432',
        'bank_name' => 'Barclays',
        'account_type' => 'checking',
        'country' => 'GB',
        'currency' => 'gbp',
        'address_line1' => '1 Churchill Place',
        'city' => 'London',
        'state' => 'Greater London',
        'postal_code' => 'E14 5HP',
        'phone' => '+442071234567',
    ], "Non-US variant: iban replaces routing_number, and ssn_last_4 is not required.\nSame endpoint, same rules - see 7.1.", ['test' => $saveBankId]),

    item('7.3 List bank accounts', 'GET', url('bank-account'), null, 'All banks for the signed-in user. Account numbers come back masked (****6789); the full number is never returned.', ['test' => $saveBankId]),

    item('7.4 Set primary', 'POST', url('bank-account/{{bank_account_id}}/set-primary'), null, "Makes this bank the payout destination and syncs Stripe's default_for_currency.\nWithout that sync the money would go to the old bank even though the app shows the new one."),

    item('7.5 Delete bank account', 'DELETE', url('bank-account/{{bank_account_id}}'), null, "Removes the bank. If the deleted one was primary, the next remaining bank is promoted\nand Stripe's default is updated to match."),
]);

$items[] = folder('08. Payment holds', 'Funds locked until maturity, then withdrawable. Statuses: holding -> ready_for_transfer -> transferred (or partial_transferred, abandoned, canceled). A cron marks matured holds every 5 minutes.', [
    item('8.1 List holds', 'GET', url('payment-holds', [
        ['key' => 'per_page', 'value' => '15', 'description' => 'optional, clamped to 1-100, default 15'],
        ['key' => 'page', 'value' => '1', 'description' => 'optional', 'disabled' => true],
    ]), null, 'Paginated holds, newest first, with the related payment and transfers. Saves the first hold id into {{hold_id}}.', ['test' => $saveHoldId]),

    item('8.2 Balance summary', 'GET', url('payment-holds/summary'), null, 'Totals for the wallet screen: amount held, amount ready for transfer, amount already withdrawn.'),

    item('8.3 Request payout (one hold)', 'POST', url('payment-holds/{{hold_id}}/request-payout'), null, "Withdraws a single matured hold in full. No body - the hold id comes from the URL.\n\n404 means the hold does not exist or belongs to another user; the API never reveals\nwhich."),

    item('8.4 Withdraw an amount', 'POST', url('payment-holds/withdraw'), [
        'amount' => 10.00,
    ], <<<'TXT'
The normal withdrawal path. Takes an amount, not a hold id, and spreads it across
every eligible hold oldest-first, creating one Transfer per hold touched.

Rules: amount required, numeric, min 0.50, max 999999.99.

A partly-consumed hold becomes partial_transferred. remaining_amount is deducted
here for both completed and pending transfers; if a pending transfer later fails,
verify:pending-transfers restores it.

Money goes to the primary bank account, so set that first (7.4).

Gated by the free tier (folder 11) like 8.3: 402 code=subscription_required once the
free withdrawals are used, unless the admin switched the withdrawal limit off. A
withdrawal that sends no money does not use one up.
TXT),
]);

$items[] = folder('09. Transaction history', 'Five read-only views over the same data. Every item carries hold_start, hold_end, total_days, days_remaining, hours_remaining and minutes_remaining; withdraw rows have those set to null.', [
    item('9.1 All (with summary)', 'GET', url('transactions/all', [
        ['key' => 'per_page', 'value' => '15', 'description' => 'optional, 1-100'],
        ['key' => 'page', 'value' => '1', 'disabled' => true],
    ]), null, 'Everything plus a summary block: checkout, withdraw, hold_amount, ready_for_transfer, transferred. This is what the history screen loads first.'),

    item('9.2 Checkouts', 'GET', url('transactions/checkouts', [
        ['key' => 'per_page', 'value' => '15'],
        ['key' => 'page', 'value' => '1', 'disabled' => true],
    ]), null, 'Money in. Paginated.'),

    item('9.3 Withdrawals', 'GET', url('transactions/withdraws', [
        ['key' => 'per_page', 'value' => '15'],
        ['key' => 'page', 'value' => '1', 'disabled' => true],
    ]), null, 'Money out, grouped by withdrawal request - one row per request even when it spanned several holds.'),

    item('9.4 Hold amounts (locked)', 'GET', url('transactions/hold-amounts', [
        ['key' => 'per_page', 'value' => '15'],
        ['key' => 'page', 'value' => '1', 'disabled' => true],
    ]), null, 'Holds still inside their lock period.'),

    item('9.5 Ready for transfer', 'GET', url('transactions/ready-for-transfer', [
        ['key' => 'per_page', 'value' => '15'],
        ['key' => 'page', 'value' => '1', 'disabled' => true],
    ]), null, 'Matured holds the user can withdraw right now.'),
]);

$items[] = folder('10. Admin API', 'One endpoint. It sits behind auth:sanctum like everything else, so the token must belong to an admin user.', [
    item('10.1 Execute transfer for a hold', 'POST', url('admin/transfer/{{hold_id}}'), null, "Pushes a matured hold to the user's bank through Stripe. No body - the hold id is the\nroute parameter.\n\nThis moves real money on live keys. Check the hold status and the destination bank\nbefore firing it."),
]);

$savePlanId = <<<'JS'
const body = pm.response.json();
const plans = body && body.data ? body.data.plans : null;

if (Array.isArray(plans) && plans.length) {
    pm.collectionVariables.set("plan_id", plans[0].id);
    console.log("Saved plan_id:", plans[0].id);
}
JS;

$items[] = folder('11. Subscription', <<<'TXT'
Free tier + paid plans. A user gets 2 free locks (6.1) and 2 free withdrawals (8.3 / 8.4);
both numbers are set in the admin panel (Free Tier). The next attempt returns:

  HTTP 402 {"success":false,"code":"subscription_required","action":"lock"|"withdraw",
            "used":2,"limit":2,"message":"..."}

The app opens the plans screen on that 402, then:

  11.3 subscribe -> present the Payment Sheet with the returned keys ->
  11.4 confirm   -> is_subscribed=true -> retry the request that got the 402

Like 6.x, the card step needs the device (or the Stripe CLI). Subscriptions renew
automatically in Stripe; renewal results arrive by webhook.
TXT, [
    item('11.1 List plans', 'GET', url('subscription/plans'), null, 'Plans the admin has made visible and synced to Stripe, in display order. Saves the first id into {{plan_id}}.', ['test' => $savePlanId]),

    item('11.2 Status and free allowance', 'GET', url('subscription/status'), null, "is_subscribed, the current subscription (plan, status, current_period_end,\ncancel_at_period_end) and the free counters: locks_used / lock_limit / locks_left and\nwithdrawals_used / withdraw_limit / withdrawals_left. A null *_left means unlimited\n(subscribed, or that limit is switched off)."),

    item('11.3 Subscribe', 'POST', url('subscription/subscribe'), [
        'plan_id' => '{{plan_id}}',
    ], <<<'TXT'
Starts a Stripe subscription and returns the same keys as 6.1 (client_secret,
customer_id, ephemeral_key, publishable_key), so the app reuses its Payment Sheet code.

Nothing is active yet - the first invoice must be paid in the Payment Sheet.
Calling it again within 23 h for the same plan returns the same unpaid subscription.

409 = already subscribed. 422 = plan hidden, deleted or not synced.
TXT, ['test' => "const b = pm.response.json();\nif (b.data && b.data.client_secret) { pm.collectionVariables.set('client_secret', b.data.client_secret); }"]),

    item('11.4 Confirm after payment', 'POST', url('subscription/confirm'), null, "Call right after the Payment Sheet succeeds. The server reads the subscription back\nfrom Stripe, so access starts now instead of when the webhook arrives. If the\nmessage says the payment is still processing, poll 11.2 for a few seconds.\n\n404 = the user never started a subscription."),

    item('11.5 Cancel at period end', 'POST', url('subscription/cancel'), null, 'Stops renewal. The user keeps access until current_period_end. 404 = no active subscription.'),

    item('11.6 Manage payment method', 'POST', url('subscription/portal'), null, 'Returns data.url, a short-lived Stripe Customer Portal link to change the card or see invoices. Open it in an in-app browser.'),
]);

// ---------------------------------------------------------------------------
// Collection
// ---------------------------------------------------------------------------

$collection = [
    'info' => [
        '_postman_id' => '9f1c4d2a-7b3e-4a58-9c10-timevault0001',
        'name' => 'Time Vault - Complete API',
        'description' => <<<'TXT'
Every route in routes/api.php, generated from the code on 2026-09-22.

## Setup (60 seconds)

1. Import this file and one environment file (Production or Local).
2. Select the environment in the top-right dropdown.
3. Open "01. Auth (public)" and run Signup -> Verify OTP, or Login if the account
   already exists. The bearer token is captured automatically.
4. Run anything else.

## How auth works here

The collection sets `Authorization: Bearer {{token}}` once, at the top level. Public
requests (signup, login, privacy policy, webhook, health) override it with No Auth,
so a stale token can never break them. Login, signup and verify-otp write the token
back into the collection variable - you never copy-paste it.

Tokens do not expire on their own: SANCTUM_TOKEN_EXPIRATION is unset, so a token is
valid until logout or account deletion. A 401 means the token was revoked, not aged.

## Things that will bite you

- `Accept: application/json` is set on every request and must stay. Without it
  Laravel answers a validation failure with an HTML redirect instead of 422 JSON.
- Rate limits are real: 5/min on signup, login, verify-otp and reset-password, 3/min
  on resend-otp and forgot-password, 10/min on feedback and support. A 429 is the
  limiter, not bad input.
- Emails (OTP, feedback, support) go through a database queue. No queue worker
  running means no email and no error - check `php artisan queue:work`.
- Dates come back in the USER'S timezone, taken from their profile, not UTC.
- Stripe endpoints need test keys and a real device for the Payment Sheet step.

Rate limit on protected routes overall: 1000/min per user.
TXT,
        'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
    ],
    'auth' => [
        'type' => 'bearer',
        'bearer' => [['key' => 'token', 'value' => '{{token}}', 'type' => 'string']],
    ],
    'event' => [
        ['listen' => 'test', 'script' => ['type' => 'text/javascript', 'exec' => explode("\n", $collectionTest)]],
    ],
    'item' => $items,
    'variable' => [
        ['key' => 'host', 'value' => 'https://api.timevaultapp.co', 'type' => 'string'],
        ['key' => 'base_url', 'value' => 'https://api.timevaultapp.co/api', 'type' => 'string'],
        ['key' => 'token', 'value' => '', 'type' => 'string'],
        ['key' => 'email', 'value' => 'tauseefchoohan0401@gmail.com', 'type' => 'string'],
        ['key' => 'password', 'value' => 'Test@1234', 'type' => 'string'],
        ['key' => 'otp_code', 'value' => '', 'type' => 'string'],
        ['key' => 'hold_id', 'value' => '1', 'type' => 'string'],
        ['key' => 'bank_account_id', 'value' => '1', 'type' => 'string'],
        ['key' => 'payment_intent_id', 'value' => '', 'type' => 'string'],
        ['key' => 'client_secret', 'value' => '', 'type' => 'string'],
        ['key' => 'hold_start_at', 'value' => '', 'type' => 'string'],
        ['key' => 'hold_end_at', 'value' => '', 'type' => 'string'],
        ['key' => 'plan_id', 'value' => '1', 'type' => 'string'],
    ],
];

// ---------------------------------------------------------------------------
// Environments
// ---------------------------------------------------------------------------

function environment(string $id, string $name, string $host): array
{
    $values = [
        ['key' => 'host', 'value' => $host, 'enabled' => true],
        ['key' => 'base_url', 'value' => $host.'/api', 'enabled' => true],
        ['key' => 'token', 'value' => '', 'enabled' => true],
        ['key' => 'email', 'value' => 'tauseefchoohan0401@gmail.com', 'enabled' => true],
        ['key' => 'password', 'value' => 'Test@1234', 'enabled' => true],
        ['key' => 'otp_code', 'value' => '', 'enabled' => true],
        ['key' => 'hold_id', 'value' => '1', 'enabled' => true],
        ['key' => 'bank_account_id', 'value' => '1', 'enabled' => true],
        ['key' => 'payment_intent_id', 'value' => '', 'enabled' => true],
        ['key' => 'plan_id', 'value' => '1', 'enabled' => true],
    ];

    return [
        'id' => $id,
        'name' => $name,
        'values' => array_map(fn ($v) => $v + ['type' => 'default'], $values),
        '_postman_variable_scope' => 'environment',
        '_postman_exported_at' => '2026-09-22T00:00:00.000Z',
    ];
}

$files = [
    'TimeVault_Complete_Postman_Collection.json' => $collection,
    'TimeVault_Postman_Environment.Production.json' => environment('a1b2c3d4-0001-4000-8000-timevaultprod', 'Time Vault - Production', 'https://api.timevaultapp.co'),
    'TimeVault_Postman_Environment.Local.json' => environment('a1b2c3d4-0002-4000-8000-timevaultlocl', 'Time Vault - Local', 'http://127.0.0.1:8000'),
];

foreach ($files as $name => $payload) {
    $json = json_encode($payload, JSON_OPTS);
    if ($json === false) {
        fwrite(STDERR, "ENCODE FAILED for $name: ".json_last_error_msg()."\n");
        exit(1);
    }
    file_put_contents($name, $json."\n");
    printf("%-48s %6d bytes\n", $name, strlen($json));
}

// Sanity: count requests
$count = 0;
array_walk_recursive($collection['item'], function ($v, $k) use (&$count) {
    if ($k === 'method') {
        $count++;
    }
});
echo "Requests: $count\n";
