# Postman Quick Reference - After Security Updates

## 🔄 What Changed After Security?

### ✅ New Features:
1. **Named Routes** - All routes now have names
2. **Rate Limiting** - Requests are limited per minute
3. **Security Headers** - Added to all responses
4. **Input Sanitization** - Automatic XSS prevention
5. **API Logging** - All requests logged
6. **Token Expiration** - Tokens expire after 24 hours

### ⚠️ Important Changes:
- **Unverified users CANNOT login** (must verify OTP first)
- **Rate limits enforced** (5/min for most, 3/min for resend/forgot)
- **Strong password required** (uppercase, lowercase, number, special char)
- **Security headers** in all responses

---

## 🚀 Quick Start Testing

### Step 1: Setup Postman Environment

Create environment variables:
- `base_url` = `http://localhost:8000/api`
- `token` = (empty, will be set after login)

### Step 2: Test Signup

**POST** `{{base_url}}/auth/signup`

```json
{
    "full_name": "Test User",
    "email": "test@example.com",
    "phone": "1234567890",
    "password": "Secure@123",
    "password_confirmation": "Secure@123"
}
```

**⚠️ Password must have**: Uppercase, Lowercase, Number, Special Char

### Step 3: Get OTP

Check database:
```sql
SELECT otp_code FROM users WHERE email = 'test@example.com';
```

Or check email inbox.

### Step 4: Verify OTP

**POST** `{{base_url}}/auth/verify-otp`

```json
{
    "email": "test@example.com",
    "otp_code": "1234"
}
```

**Save token** from response to `{{token}}` variable.

### Step 5: Test Protected Route

**GET** `{{base_url}}/profile`

**Headers:**
```
Authorization: Bearer {{token}}
```

---

## 📋 All Endpoints (Quick List)

### Public Routes (No Token)

| Method | Endpoint | Rate Limit |
|--------|----------|------------|
| POST | `/auth/signup` | 5/min |
| POST | `/auth/verify-otp` | 5/min |
| POST | `/auth/resend-otp` | 3/min |
| POST | `/auth/login` | 5/min |
| POST | `/auth/forgot-password` | 3/min |
| POST | `/auth/reset-password` | 5/min |

### Protected Routes (Need Token)

**Header Required**: `Authorization: Bearer {{token}}`

| Method | Endpoint | Route Name |
|--------|----------|------------|
| POST | `/auth/logout` | auth.logout |
| GET | `/profile` | profile.show |
| PUT | `/profile/update` | profile.update |
| PUT | `/profile/language` | profile.language |
| PUT | `/profile/timezone` | profile.timezone |
| POST | `/device/register` | device.register |
| PUT | `/device/update-token` | device.update-token |
| GET | `/notification-settings` | notification-settings.show |
| PUT | `/notification-settings/update` | notification-settings.update |

---

## 🔒 Security Testing Checklist

### Test These Scenarios:

- [ ] **Weak Password** → Should fail validation
- [ ] **Rate Limit** → 6th request in 1 min should fail (429)
- [ ] **Login Without Verification** → Should fail (403)
- [ ] **Wrong OTP** → Should fail (400)
- [ ] **Expired OTP** → Should fail (400)
- [ ] **No Token** → Protected route should fail (401)
- [ ] **Invalid Token** → Should fail (401)
- [ ] **XSS Input** → Should be sanitized
- [ ] **Security Headers** → Check response headers

---

## 📝 Postman Tests Script

Add this to **Login** request's **Tests** tab to auto-save token:

```javascript
if (pm.response.code === 200) {
    var jsonData = pm.response.json();
    if (jsonData.data && jsonData.data.token) {
        pm.environment.set("token", jsonData.data.token);
        console.log("✅ Token saved to environment");
    }
}
```

---

## 🎯 Common Issues & Solutions

### Issue: "429 Too Many Requests"
**Solution**: Wait 1 minute, rate limit resets

### Issue: "Please verify your account with OTP first"
**Solution**: User must verify OTP before login

### Issue: "Invalid credentials"
**Solution**: Check email/password or verify account first

### Issue: "401 Unauthorized"
**Solution**: Check if token is valid and in Authorization header

### Issue: Password validation fails
**Solution**: Password must have: A-Z, a-z, 0-9, special char

---

## 📊 Response Format

**Success:**
```json
{
    "success": true,
    "message": "Success message",
    "data": {...}
}
```

**Error:**
```json
{
    "success": false,
    "message": "Error message"
}
```

**Rate Limit Error (429):**
```json
{
    "message": "Too Many Attempts."
}
```

---

## 🔍 Check Security Headers

After any request, check **Headers** tab in Postman:

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `X-XSS-Protection: 1; mode=block`
- `Strict-Transport-Security: max-age=31536000`

---

## 📖 Full Documentation

See `POSTMAN_TESTING_GUIDE.md` for complete details.

---

**Ready to test! 🚀**

