# Postman API Testing Guide - Module 1

## Base URL
```
http://localhost:8000/api
```

## Authentication
Protected routes require Bearer Token in header:
```
Authorization: Bearer {token}
```

---

## 1. Authentication APIs (Public)

### 1.1 Signup
**POST** `/auth/signup`

**Body (JSON):**
```json
{
    "full_name": "John Doe",
    "email": "john@example.com",
    "phone": "1234567890",
    "password": "password123",
    "password_confirmation": "password123"
}
```

**Response:**
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

---

### 1.2 Verify OTP
**POST** `/auth/verify-otp`

**Body (JSON):**
```json
{
    "email": "john@example.com",
    "otp_code": "1234"
}
```
OR
```json
{
    "phone": "1234567890",
    "otp_code": "1234"
}
```

**Response:**
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

**Note:** Save the `token` for protected routes!

---

### 1.3 Resend OTP
**POST** `/auth/resend-otp`

**Body (JSON):**
```json
{
    "email": "john@example.com"
}
```
OR
```json
{
    "phone": "1234567890"
}
```

---

### 1.4 Login
**POST** `/auth/login`

**Body (JSON) - Regular Login:**
```json
{
    "email": "john@example.com",
    "password": "password123",
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

**Response:**
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

---

### 1.5 Forgot Password
**POST** `/auth/forgot-password`

**Body (JSON):**
```json
{
    "email": "john@example.com"
}
```

---

### 1.6 Reset Password
**POST** `/auth/reset-password`

**Body (JSON):**
```json
{
    "email": "john@example.com",
    "otp_code": "1234",
    "password": "newpassword123",
    "password_confirmation": "newpassword123"
}
```

---

## 2. Protected APIs (Require Bearer Token)

### 2.1 Logout
**POST** `/auth/logout`

**Headers:**
```
Authorization: Bearer {token}
```

---

### 2.2 Get Profile
**GET** `/profile`

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "user": {
            "id": 1,
            "role": "user",
            "full_name": "John Doe",
            "email": "john@example.com",
            "phone": "1234567890",
            ...
        }
    }
}
```

---

### 2.3 Update Profile
**PUT** `/profile/update`

**Headers:**
```
Authorization: Bearer {token}
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

### 2.4 Update Language
**PUT** `/profile/language`

**Headers:**
```
Authorization: Bearer {token}
```

**Body (JSON):**
```json
{
    "language": "en"
}
```

**Available languages:** en, es, fr, de, it, pt, zh, ja, ko, ar, hi

---

### 2.5 Update Timezone
**PUT** `/profile/timezone`

**Headers:**
```
Authorization: Bearer {token}
```

**Body (JSON):**
```json
{
    "timezone": "America/New_York"
}
```

---

### 2.6 Register Device
**POST** `/device/register`

**Headers:**
```
Authorization: Bearer {token}
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

**Device Types:** ios, android, web

---

### 2.7 Update Device Token
**PUT** `/device/update-token`

**Headers:**
```
Authorization: Bearer {token}
```

**Body (JSON):**
```json
{
    "device_id": "device123",
    "fcm_token": "new_fcm_token_here"
}
```

---

### 2.8 Get Notification Settings
**GET** `/notification-settings`

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
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

### 2.9 Update Notification Settings
**PUT** `/notification-settings/update`

**Headers:**
```
Authorization: Bearer {token}
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

## Testing Flow

1. **Signup** → Get user (not verified)
2. **Verify OTP** → Get token (check email for OTP)
3. **Login** → Get token
4. **Get Profile** → Use token
5. **Update Profile** → Use token
6. **Register Device** → Use token
7. **Get Notification Settings** → Use token
8. **Update Notification Settings** → Use token
9. **Logout** → Use token

---

## Common Response Format

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

---

## Notes

- OTP code is 4 digits (sent via email)
- OTP expires in 5 minutes
- Token is valid until logout
- All protected routes require `Authorization: Bearer {token}` header
- Device fields are stored in users table
- Notification settings are in separate table

