# Postman API Testing Guide - Fintech App

## 🔧 Setup Postman

### Base URL
```
http://localhost:8000/api
```

### Environment Variables (Create in Postman)

Create a new environment with these variables:

| Variable | Initial Value | Current Value |
|----------|--------------|---------------|
| `base_url` | `http://localhost:8000/api` | `http://localhost:8000/api` |
| `token` | (empty) | (will be set after login) |
| `user_email` | `test@example.com` | (your test email) |
| `user_phone` | `1234567890` | (your test phone) |

---

## 📋 API Endpoints with Named Routes

### Public Authentication Routes

#### 1. Signup
**POST** `{{base_url}}/auth/signup`  
**Route Name**: `auth.signup`  
**Rate Limit**: 5 requests per minute

**Headers:**
```
Content-Type: application/json
```

**Body (JSON):**
```json
{
    "full_name": "John Doe",
    "email": "john@example.com",
    "phone": "1234567890",
    "password": "Secure@123",
    "password_confirmation": "Secure@123"
}
```

**Expected Response (201):**
```json
{
    "success": true,
    "message": "Registration successful. Please verify your OTP.",
    "data": {
        "user": {...},
        "otp_sent": true
    }
}
```

**Note**: Check email for OTP or check database `users.otp_code`

---

#### 2. Verify OTP
**POST** `{{base_url}}/auth/verify-otp`  
**Route Name**: `auth.verify-otp`  
**Rate Limit**: 5 requests per minute

**Body (JSON):**
```json
{
    "email": "john@example.com",
    "otp_code": "1234"
}
```

**Expected Response (200):**
```json
{
    "success": true,
    "message": "OTP verified successfully.",
    "data": {
        "user": {...},
        "token": "1|xxxxxxxxxxxx"
    }
}
```

**⚠️ Important**: Save the `token` to environment variable `{{token}}`

---

#### 3. Resend OTP
**POST** `{{base_url}}/auth/resend-otp`  
**Route Name**: `auth.resend-otp`  
**Rate Limit**: 3 requests per minute

**Body (JSON):**
```json
{
    "email": "john@example.com"
}
```

**Expected Response (200):**
```json
{
    "success": true,
    "message": "OTP code has been resent.",
    "data": {
        "otp_sent": true
    }
}
```

**⚠️ Security**: Only works for unverified users. If user is already verified, returns error.

---

#### 4. Login
**POST** `{{base_url}}/auth/login`  
**Route Name**: `auth.login`  
**Rate Limit**: 5 requests per minute

**Body (JSON) - Regular Login:**
```json
{
    "email": "john@example.com",
    "password": "Secure@123",
    "device_id": "device123",
    "device_type": "ios",
    "fcm_token": "fcm_token_here",
    "timezone": "UTC",
    "language": "en"
}
```

**Body (JSON) - Social Login:**
```json
{
    "provider": "google",
    "provider_token": "token_here",
    "provider_id": "google_user_id",
    "email": "user@gmail.com",
    "name": "User Name",
    "device_id": "device123",
    "device_type": "android"
}
```

**Expected Response (200):**
```json
{
    "success": true,
    "message": "Login successful.",
    "data": {
        "user": {...},
        "token": "1|xxxxxxxxxxxx"
    }
}
```

**⚠️ Security**: 
- Unverified users cannot login (returns error)
- Save token to `{{token}}` variable

---

#### 5. Forgot Password
**POST** `{{base_url}}/auth/forgot-password`  
**Route Name**: `auth.forgot-password`  
**Rate Limit**: 3 requests per minute

**Body (JSON):**
```json
{
    "email": "john@example.com"
}
```

**Expected Response (200):**
```json
{
    "success": true,
    "message": "OTP code has been sent to your email.",
    "data": {
        "otp_sent": true
    }
}
```

---

#### 6. Reset Password
**POST** `{{base_url}}/auth/reset-password`  
**Route Name**: `auth.reset-password`  
**Rate Limit**: 5 requests per minute

**Body (JSON):**
```json
{
    "email": "john@example.com",
    "otp_code": "1234",
    "password": "NewSecure@123",
    "password_confirmation": "NewSecure@123"
}
```

**Expected Response (200):**
```json
{
    "success": true,
    "message": "Password has been reset successfully."
}
```

---

### Protected Routes (Require Authentication)

**⚠️ All protected routes require this header:**
```
Authorization: Bearer {{token}}
```

---

#### 7. Logout
**POST** `{{base_url}}/auth/logout`  
**Route Name**: `auth.logout`

**Headers:**
```
Authorization: Bearer {{token}}
Content-Type: application/json
```

**Expected Response (200):**
```json
{
    "success": true,
    "message": "Logged out successfully."
}
```

---

#### 8. Get Profile
**GET** `{{base_url}}/profile`  
**Route Name**: `profile.show`

**Headers:**
```
Authorization: Bearer {{token}}
```

**Expected Response (200):**
```json
{
    "success": true,
    "data": {
        "user": {
            "id": 1,
            "role": "user",
            "full_name": "John Doe",
            "email": "john@example.com",
            ...
        }
    }
}
```

---

#### 9. Update Profile
**PUT** `{{base_url}}/profile/update`  
**Route Name**: `profile.update`

**Headers:**
```
Authorization: Bearer {{token}}
Content-Type: application/json
```

**Body (JSON):**
```json
{
    "full_name": "John Updated",
    "email": "johnnew@example.com",
    "phone": "9876543210",
    "profile": "profile_image_url"
}
```

---

#### 10. Update Language
**PUT** `{{base_url}}/profile/language`  
**Route Name**: `profile.language`

**Headers:**
```
Authorization: Bearer {{token}}
Content-Type: application/json
```

**Body (JSON):**
```json
{
    "language": "en"
}
```

**Available**: en, es, fr, de, it, pt, zh, ja, ko, ar, hi

---

#### 11. Update Timezone
**PUT** `{{base_url}}/profile/timezone`  
**Route Name**: `profile.timezone`

**Headers:**
```
Authorization: Bearer {{token}}
Content-Type: application/json
```

**Body (JSON):**
```json
{
    "timezone": "America/New_York"
}
```

---

#### 12. Register Device
**POST** `{{base_url}}/device/register`  
**Route Name**: `device.register`

**Headers:**
```
Authorization: Bearer {{token}}
Content-Type: application/json
```

**Body (JSON):**
```json
{
    "device_id": "device123",
    "device_type": "ios",
    "fcm_token": "fcm_token_here",
    "timezone": "UTC",
    "language": "en"
}
```

**Device Types**: ios, android, web

---

#### 13. Update Device Token
**PUT** `{{base_url}}/device/update-token`  
**Route Name**: `device.update-token`

**Headers:**
```
Authorization: Bearer {{token}}
Content-Type: application/json
```

**Body (JSON):**
```json
{
    "device_id": "device123",
    "fcm_token": "new_fcm_token_here"
}
```

---

#### 14. Get Notification Settings
**GET** `{{base_url}}/notification-settings`  
**Route Name**: `notification-settings.show`

**Headers:**
```
Authorization: Bearer {{token}}
```

**Expected Response (200):**
```json
{
    "success": true,
    "data": {
        "settings": {
            "id": 1,
            "user_id": 1,
            "password_alert": true,
            "transaction_alert": true,
            "push_notification_alert": true,
            "email_alert": true,
            "lock_alert": true,
            "unlock_alert": true
        }
    }
}
```

---

#### 15. Update Notification Settings
**PUT** `{{base_url}}/notification-settings/update`  
**Route Name**: `notification-settings.update`

**Headers:**
```
Authorization: Bearer {{token}}
Content-Type: application/json
```

**Body (JSON):**
```json
{
    "password_alert": false,
    "transaction_alert": true,
    "push_notification_alert": true,
    "email_alert": false,
    "lock_alert": true,
    "unlock_alert": true
}
```

---

#### 16. Create Payment Intent
**POST** `{{base_url}}/stripe/payment-intent`  
**Route Name**: `stripe.payment-intent`

**Headers:**
```
Authorization: Bearer {{token}}
Content-Type: application/json
```

**Body (JSON) - Basic Payment (No Hold):**
```json
{
    "amount": 100.00,
    "currency": "usd",
    "return_url": "https://yourapp.com/payment/success"
}
```

**Body (JSON) - Payment with Hold Period (1 Month):**
```json
{
    "amount": 100.00,
    "currency": "usd",
    "return_url": "https://yourapp.com/payment/success",
    "hold_period_type": "1_month"
}
```

**Body (JSON) - Payment with Hold Period (2 Months):**
```json
{
    "amount": 100.00,
    "currency": "usd",
    "return_url": "https://yourapp.com/payment/success",
    "hold_period_type": "2_months"
}
```

**Body (JSON) - Payment with Hold Period (6 Months):**
```json
{
    "amount": 100.00,
    "currency": "usd",
    "return_url": "https://yourapp.com/payment/success",
    "hold_period_type": "6_months"
}
```

**Body (JSON) - Payment with Hold Period (1 Year):**
```json
{
    "amount": 100.00,
    "currency": "usd",
    "return_url": "https://yourapp.com/payment/success",
    "hold_period_type": "1_year"
}
```

**Body (JSON) - Payment with Custom Hold Period:**
```json
{
    "amount": 100.00,
    "currency": "usd",
    "return_url": "https://yourapp.com/payment/success",
    "hold_period_type": "custom",
    "hold_start_at": "2026-01-15",
    "hold_end_at": "2026-03-15",
    "hold_days": 60
}
```

**⚠️ Date Format Notes:**
- Use format: `YYYY-MM-DD` (e.g., "2026-01-15")
- `hold_start_at` must be today or a future date
- `hold_end_at` must be after `hold_start_at`
- No minimum hold period restriction - users can set any duration

**Expected Response (200):**
```json
{
    "success": true,
    "data": {
        "payment_intent_id": "pi_xxxxxxxxxxxxx",
        "client_secret": "pi_xxxxxxxxxxxxx_secret_xxxxxxxxxxxxx",
        "hold_period": {
            "type": "1_month",
            "start_at": "2026-01-08T12:00:00Z",
            "end_at": "2026-02-08T12:00:00Z",
            "days": 30
        }
    }
}
```

**⚠️ Important Notes:**
- Minimum amount: $1.00
- Supported currencies: USD, EUR, GBP (check config)
- Hold period types: `1_month`, `2_months`, `6_months`, `1_year`, `custom`
- Custom hold period requires `hold_start_at` and `hold_end_at` (no minimum restriction)
- If hold period is provided, data is stored in both `payments` and `payment_holds` tables
- If no hold period, only `payments` table is used

**Database Verification:**
After successful request, check:
1. `payments` table - Should have new record with:
   - `user_id` = authenticated user ID
   - `payment_intent_id` = Stripe PaymentIntent ID
   - `amount` = payment amount
   - `currency` = currency code
   - `status` = 'pending'

2. `payment_holds` table (if hold_period_type provided) - Should have new record with:
   - `payment_id` = ID from payments table
   - `user_id` = authenticated user ID
   - `amount` = payment amount
   - `hold_start_at` = start date
   - `hold_end_at` = end date
   - `hold_days` = number of days
   - `hold_period_type` = selected type
   - `status` = 'holding'

---

## 🔒 Testing Security Features

### 1. Test Rate Limiting

**Test Signup Rate Limit:**
1. Send 5 signup requests quickly → All should succeed
2. Send 6th request → Should get `429 Too Many Requests`
3. Wait 1 minute → Should work again

**Test Resend OTP Rate Limit:**
1. Send 3 resend-otp requests → All should succeed
2. Send 4th request → Should get `429 Too Many Requests`

### 2. Test Password Strength

**Weak Password (Should Fail):**
```json
{
    "password": "password",
    "password_confirmation": "password"
}
```
**Error**: "Password must contain at least one uppercase letter..."

**Strong Password (Should Pass):**
```json
{
    "password": "Secure@123",
    "password_confirmation": "Secure@123"
}
```

### 3. Test OTP Verification

**Test Unverified User Login:**
1. Signup → User created (is_verified = false)
2. Try login without OTP verify → Should fail with "Please verify your account with OTP first"

**Test Wrong OTP:**
```json
{
    "email": "john@example.com",
    "otp_code": "9999"
}
```
**Error**: "Invalid OTP code."

**Test Expired OTP:**
- Wait 5+ minutes after OTP sent
- Try verify → Should fail with "OTP code has expired"

### 4. Test Token Security

**Test Without Token:**
- Remove `Authorization` header
- Call protected route → Should get `401 Unauthorized`

**Test Invalid Token:**
```
Authorization: Bearer invalid_token_here
```
**Error**: `401 Unauthorized`

**Test Expired Token:**
- Use old token (after 24 hours)
- Should get `401 Unauthorized`

### 5. Test Input Sanitization

**Note**: Input sanitization middleware is currently disabled. Will be enabled later.

**Test XSS Prevention (When Enabled):**
```json
{
    "full_name": "<script>alert('xss')</script>John"
}
```
**Result**: Script tags will be removed when middleware enabled

### 6. Test Security Headers

**Note**: Security headers middleware is currently disabled. Will be enabled later.

**When Enabled**, check Response Headers:
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `X-XSS-Protection: 1; mode=block`
- `Strict-Transport-Security: max-age=31536000`

---

## 📝 Postman Collection Structure

### Recommended Folder Structure:

```
Time Walt App API
├── Authentication (Public)
│   ├── Signup
│   ├── Verify OTP
│   ├── Resend OTP
│   ├── Login
│   ├── Forgot Password
│   └── Reset Password
├── Authentication (Protected)
│   └── Logout
├── Profile
│   ├── Get Profile
│   ├── Update Profile
│   ├── Update Language
│   └── Update Timezone
├── Device
│   ├── Register Device
│   └── Update Device Token
└── Notification Settings
    ├── Get Settings
    └── Update Settings
```

---

## 🧪 Complete Testing Flow

### Flow 1: New User Registration
1. **Signup** → Get user (not verified)
2. Check email/database for OTP
3. **Verify OTP** → Get token, save to `{{token}}`
4. **Get Profile** → Use token
5. **Update Profile** → Use token
6. **Logout** → Use token

### Flow 2: Existing User Login
1. **Login** → Get token, save to `{{token}}`
2. **Get Profile** → Use token
3. **Register Device** → Use token
4. **Get Notification Settings** → Use token
5. **Update Notification Settings** → Use token

### Flow 3: Password Reset
1. **Forgot Password** → OTP sent
2. Check email for OTP
3. **Reset Password** → Password updated
4. **Login** with new password → Get token

### Flow 4: Security Testing
1. Try login without verification → Should fail
2. Try weak password → Should fail
3. Try rate limit → Should fail after limit
4. Try without token → Should fail
5. Try invalid token → Should fail

---

## 🔍 Postman Pre-request Scripts

### Auto-save Token After Login

Add this to Login request's **Tests** tab:

```javascript
if (pm.response.code === 200) {
    var jsonData = pm.response.json();
    if (jsonData.data && jsonData.data.token) {
        pm.environment.set("token", jsonData.data.token);
        console.log("Token saved:", jsonData.data.token);
    }
}
```

---

## 📊 Response Status Codes

| Code | Meaning |
|------|---------|
| 200 | Success |
| 201 | Created (Signup) |
| 400 | Bad Request (Validation Error) |
| 401 | Unauthorized (Invalid Token/Credentials) |
| 403 | Forbidden (Account Not Active/Not Verified) |
| 404 | Not Found |
| 429 | Too Many Requests (Rate Limit) |
| 500 | Server Error |

---

## ⚠️ Important Notes

1. **Token Management**: Always save token after login/verify-otp
2. **Rate Limits**: Don't exceed limits or you'll be blocked (5/min auth, 3/min resend/forgot)
3. **OTP Expiry**: OTP expires in 5 minutes
4. **Password Requirements**: Must meet all criteria (A-Z, a-z, 0-9, special char)
5. **Verification Required**: Unverified users cannot login
6. **Security Headers**: Currently disabled - will be enabled later (see SECURITY_ENABLE_LATER.md)
7. **API Logging**: Currently disabled - will be enabled later
8. **Input Sanitization**: Currently disabled - will be enabled later

---

## 🚀 Quick Start

1. **Start Server**:
   ```bash
   php artisan serve
   ```

2. **Set Environment**:
   - Create Postman environment
   - Set `base_url` = `http://localhost:8000/api`

3. **Test Signup**:
   - Use strong password: `Secure@123`
   - Save OTP from email/database

4. **Verify OTP**:
   - Use OTP code
   - Token auto-saved (if using pre-request script)

5. **Test Protected Routes**:
   - Token automatically used from environment

---

## 📱 Testing Checklist

- [ ] Signup with strong password
- [ ] Verify OTP
- [ ] Login with credentials
- [ ] Test rate limiting
- [ ] Test weak password rejection
- [ ] Test unverified user login rejection
- [ ] Test token authentication
- [ ] Test all protected routes
- [ ] Test security headers
- [ ] Test input sanitization
- [ ] Test logout
- [ ] Test password reset flow

---

**Happy Testing! 🎉**

