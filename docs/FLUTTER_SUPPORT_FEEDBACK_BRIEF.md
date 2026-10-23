# Flutter Brief — Support & Feedback (Time Vault)

Prepared 2026-09-22. The backend is live and verified; this is the complete contract.

---

## What to build

Two screens, each posting to one endpoint that is already live and tested: **Feedback** (star rating + comment) and **Support** (subject + message).

The backend does three things per submission, so the app does not need to: it stores the row, emails the internal inbox, and emails a confirmation back to the signed-in user. The app's only job is to collect the input, post it, and show the result.

Both endpoints are `POST`, both require the user's Sanctum bearer token, and both return the same envelope as the rest of the API. No new packages are needed.

---

## Connection basics

Base URL: `https://api.timevaultapp.co`

Both endpoints sit behind `auth:sanctum`, so they use the same bearer token the app already stores after login — no new auth work.

| Header | Value |
| --- | --- |
| `Authorization` | `Bearer <token>` |
| `Accept` | `application/json` |
| `Content-Type` | `application/json` |

**`Accept: application/json` is not optional.** Without it Laravel answers a failed validation with an HTML redirect instead of a 422 JSON body, and the parse will fail in a way that looks like a server bug.

Tokens do not expire on their own — `SANCTUM_TOKEN_EXPIRATION` is unset, so a token stays valid until the user logs out. Treat a 401 as "session gone", not "token aged out".

Rate limit: **10 requests per minute per user, per endpoint**. Exceeding it returns 429. A user tapping Submit twice will not hit it; a retry loop will.

---

## Feedback endpoint

`POST /api/profile/feedback`

| Field | Type | Rules |
| --- | --- | --- |
| `rating` | int | Required, 1–5 |
| `feedback` | string | Required, max 1000 characters |

Request:

```json
{
  "rating": 5,
  "feedback": "The hold timer is clear and payouts arrive on time."
}
```

Success, HTTP 200:

```json
{
  "success": true,
  "message": "Feedback submitted successfully.",
  "data": {
    "feedback": {
      "id": 12,
      "rating": 5,
      "feedback": "The hold timer is clear and payouts arrive on time.",
      "created_at": "2026-09-22T14:57:16-04:00"
    }
  }
}
```

The server trims leading and trailing whitespace from `feedback` before storing, so the string you get back may differ from the one you sent. Display the returned value, not the local one.

`created_at` is ISO-8601 in the **user's own timezone** (from their profile), not UTC. Parse it with `DateTime.parse` and it is already correct for display — do not apply another offset.

**One quirk to know about:** the endpoint also accepts the misspelled field `ratting` instead of `rating`, because older builds of the app send it. Send `rating`. The only place this leaks is the error response — see Error handling.

---

## Support endpoint

`POST /api/profile/support`

| Field | Type | Rules |
| --- | --- | --- |
| `subject` | string | Required, max 150 characters |
| `message` | string | Required, max 2000 characters |

Request:

```json
{
  "subject": "Withdrawal not received",
  "message": "I requested a withdrawal three days ago and my bank shows nothing."
}
```

Success, HTTP 200:

```json
{
  "success": true,
  "message": "Support request submitted successfully.",
  "data": {
    "support_request": {
      "id": 2,
      "subject": "Withdrawal not received",
      "message": "I requested a withdrawal three days ago and my bank shows nothing.",
      "created_at": "2026-09-22T14:49:00+00:00"
    }
  }
}
```

Both fields are trimmed server-side, and `created_at` follows the same timezone rule as feedback.

The returned `id` is the reference number the user sees in their confirmation email, shown there zero-padded to five digits (`00002`). Worth showing on the success screen so a user quoting it matches what support sees.

---

## Error handling

| Status | Meaning | What the app should do |
| --- | --- | --- |
| 422 | Validation failed | Show the message under the offending field, keep the form filled |
| 401 | Token missing or invalid | Send the user to login; do not show a form error |
| 429 | Rate limit (10/min) | "Too many attempts, try again in a minute"; keep the draft |
| 500 | Server error | Generic retry message; keep the draft |

A 422 body always has the same shape — `errors` is a map of field name to a list of strings:

```json
{
  "message": "The subject field is required.",
  "errors": {
    "subject": ["The subject field is required."]
  }
}
```

**Read `errors`, never `message`, when binding to form fields.** On the feedback endpoint a missing rating returns *two* keys because of the legacy `ratting` alias, and `message` gets a suffix that should never reach a user:

```json
{
  "message": "The rating field is required. (and 1 more error)",
  "errors": {
    "rating": ["The rating field is required."],
    "ratting": ["The rating field is required."]
  }
}
```

Bind `errors['rating']` and ignore `errors['ratting']` entirely. If you display `message` verbatim anywhere, the user reads "(and 1 more error)".

Never retry automatically on 422 — the input is wrong and will stay wrong. A single manual retry on 500 or a network timeout is fine.

---

## Dart implementation

Adapt the client to whatever HTTP layer the app already uses — the shapes are what matter.

```dart
class ValidationException implements Exception {
  ValidationException(this.errors, this.message);

  /// Field name -> messages, straight from the API's `errors` map.
  final Map<String, List<String>> errors;
  final String message;

  /// First message for a field, or null. Feed this to the TextFormField.
  String? forField(String field) => errors[field]?.first;
}

class SupportRequest {
  SupportRequest({required this.id, required this.subject, required this.message, required this.createdAt});

  final int id;
  final String subject;
  final String message;
  final DateTime createdAt;

  /// Reference number as it appears in the user's confirmation email.
  String get reference => id.toString().padLeft(5, '0');

  factory SupportRequest.fromJson(Map<String, dynamic> json) => SupportRequest(
        id: json['id'] as int,
        subject: json['subject'] as String,
        message: json['message'] as String,
        createdAt: DateTime.parse(json['created_at'] as String),
      );
}

class FeedbackEntry {
  FeedbackEntry({required this.id, required this.rating, required this.message, required this.createdAt});

  final int id;
  final int rating;
  final String message;
  final DateTime createdAt;

  factory FeedbackEntry.fromJson(Map<String, dynamic> json) => FeedbackEntry(
        id: json['id'] as int,
        rating: json['rating'] as int,
        // Note: the response field is named `feedback`, not `message`.
        message: json['feedback'] as String,
        createdAt: DateTime.parse(json['created_at'] as String),
      );
}
```

The client, with the error branches every call needs:

```dart
class SupportApi {
  SupportApi(this._client, this._token);

  static const _base = 'https://api.timevaultapp.co';
  final http.Client _client;
  final String _token;

  Map<String, String> get _headers => {
        'Authorization': 'Bearer $_token',
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      };

  Future<SupportRequest> submitSupport({required String subject, required String message}) async {
    final json = await _post('/api/profile/support', {'subject': subject, 'message': message});
    return SupportRequest.fromJson(json['data']['support_request'] as Map<String, dynamic>);
  }

  Future<FeedbackEntry> submitFeedback({required int rating, required String feedback}) async {
    final json = await _post('/api/profile/feedback', {'rating': rating, 'feedback': feedback});
    return FeedbackEntry.fromJson(json['data']['feedback'] as Map<String, dynamic>);
  }

  Future<Map<String, dynamic>> _post(String path, Map<String, dynamic> body) async {
    final response = await _client
        .post(Uri.parse('$_base$path'), headers: _headers, body: jsonEncode(body))
        .timeout(const Duration(seconds: 30));

    final decoded = jsonDecode(response.body) as Map<String, dynamic>;

    switch (response.statusCode) {
      case 200:
        return decoded;
      case 422:
        final raw = (decoded['errors'] as Map<String, dynamic>? ?? {});
        final errors = raw.map((key, value) =>
            MapEntry(key, (value as List).cast<String>()));
        throw ValidationException(errors, decoded['message'] as String? ?? 'Please check the form.');
      case 401:
        throw UnauthorizedException();
      case 429:
        throw RateLimitedException();
      default:
        throw ApiException(decoded['message'] as String? ?? 'Something went wrong.');
    }
  }
}
```

On the feedback screen, bind the error with `e.forField('rating')` and `e.forField('feedback')`. On support, `e.forField('subject')` and `e.forField('message')`.

---

## Screens

Both reachable from Profile / Settings, both for signed-in users only.

**Feedback screen**

- A 1–5 star selector, nothing preselected. Submit stays disabled until a star is chosen.
- A multiline comment field, max 1000 characters, with a live counter (`847 / 1000`).
- Enforce both limits client-side so the user never round-trips to learn the text is too long.

**Support screen**

- Subject: single line, max 150, counter.
- Message: multiline, max 2000, counter.
- Both required.

**States both screens need**

| State | Behaviour |
| --- | --- |
| Idle | Submit disabled until the form is valid |
| Submitting | Spinner in the button, form disabled, no second tap possible |
| Success | Confirmation, form cleared, screen dismissed or a done state |
| Validation error | Message under the field, other input preserved |
| Network or server error | Retry affordance, **draft preserved** |

The last row is the one that gets skipped. Someone who types 1800 characters about a missing withdrawal and loses it to a dropped connection does not retype it — they leave.

On success, tell the user an email is on the way, because one is: the backend sends a confirmation to their account address within seconds. For support, include the reference number (`#00002`) so it matches the email. Wording like "Thanks — we've emailed you a copy and our team will reply to this address" sets the right expectation.

No inbox, no history list, no push notification: neither endpoint has a GET counterpart, and replies come by email. Do not build a list view against these endpoints.

---

## Done when

- [ ] Feedback submits with a rating and comment, returns 200, and the success state shows
- [ ] Support submits with subject and message, returns 200, and the reference number is shown
- [ ] Submitting with no rating selected is impossible in the UI
- [ ] A 1001-character comment and a 2001-character message are blocked client-side
- [ ] A 422 from the server puts its message under the right field and keeps the rest of the form
- [ ] `errors['ratting']` is ignored; no user ever sees the text "(and 1 more error)"
- [ ] A 401 routes to login rather than showing a form error
- [ ] Airplane mode during submit keeps the draft and offers a retry
- [ ] Double-tapping Submit sends exactly one request
- [ ] `Accept: application/json` is set on both calls
- [ ] The user receives the confirmation email after each submission

That last one is the real end-to-end check: submit from the app, then confirm the email arrives at the account address. If the row appears in the admin panel but no email arrives, the problem is server-side (queue worker), not in the app — report it rather than working around it.

**Open question for whoever wires this up:** the two screens' entry points in Profile / Settings are not specified here. Place them where the existing navigation makes sense and flag it in review.
