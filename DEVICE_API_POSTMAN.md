# Device API - Postman Testing Guide

## 🔧 Base URL
```
http://localhost:8000/api
```

## 🔒 Authentication Required
All device endpoints require authentication token:
```
Authorization: Bearer {{token}}
```

---

## 📱 API Endpoints

### 1. Register Device
**POST** `{{base_url}}/device/register`  
**Route Name**: `device.register`

**Headers:**
```
Authorization: Bearer {{token}}
Content-Type: application/json
Accept: application/json
```

**Body (JSON):**
```json
{
    "device_id": "device_unique_id_123",
    "device_type": "ios",
    "fcm_token": "fcm_token_here_optional",
    "timezone": "America/New_York",
    "language": "en"
}
```

**Device Types:**
- `ios` - iOS devices
- `android` - Android devices
- `web` - Web browsers

**Required Fields:**
- `device_id` (required)
- `device_type` (required: ios, android, or web)

**Optional Fields:**
- `fcm_token` (for push notifications)
- `timezone` (default: user's current timezone)
- `language` (default: user's current language)

**Expected Response (200):**
```json
{
    "success": true,
    "message": "Device registered successfully.",
    "data": {
        "user": {
            "id": 1,
            "device_id": "device_unique_id_123",
            "device_type": "ios",
            "fcm_token": "fcm_token_here_optional",
            "timezone": "America/New_York",
            "language": "en",
            "last_active_at": "2026-01-12T18:00:00.000000Z",
            ...
        }
    }
}
```

---

### 2. Update Device Token
**PUT** `{{base_url}}/device/update-token`  
**Route Name**: `device.update-token`

**Headers:**
```
Authorization: Bearer {{token}}
Content-Type: application/json
Accept: application/json
```

**Body (JSON):**
```json
{
    "device_id": "device_unique_id_123",
    "fcm_token": "new_fcm_token_here"
}
```

**Required Fields:**
- `device_id` (must match registered device)
- `fcm_token` (new FCM token)

**Expected Response (200):**
```json
{
    "success": true,
    "message": "Device token updated successfully.",
    "data": {
        "user": {
            "id": 1,
            "device_id": "device_unique_id_123",
            "fcm_token": "new_fcm_token_here",
            "last_active_at": "2026-01-12T18:00:00.000000Z",
            ...
        }
    }
}
```

**Error Response (400) - Device ID Mismatch:**
```json
{
    "success": false,
    "message": "Device ID mismatch."
}
```

---

## 🧪 Testing Scenarios

### Test 1: Register iOS Device
```json
{
    "device_id": "ios_device_12345",
    "device_type": "ios",
    "fcm_token": "ios_fcm_token_abc123",
    "timezone": "America/Los_Angeles",
    "language": "en"
}
```

### Test 2: Register Android Device
```json
{
    "device_id": "android_device_67890",
    "device_type": "android",
    "fcm_token": "android_fcm_token_xyz789",
    "timezone": "Asia/Kolkata",
    "language": "hi"
}
```

### Test 3: Register Web Device
```json
{
    "device_id": "web_browser_abc123",
    "device_type": "web",
    "timezone": "UTC",
    "language": "en"
}
```

### Test 4: Update FCM Token
```json
{
    "device_id": "ios_device_12345",
    "fcm_token": "updated_fcm_token_new123"
}
```

---

## ⚠️ Validation Rules

### Register Device:
- `device_id`: Required, string, max 255 characters
- `device_type`: Required, must be: `ios`, `android`, or `web`
- `fcm_token`: Optional, string, max 500 characters
- `timezone`: Optional, string, max 100 characters
- `language`: Optional, string, max 10 characters

### Update Token:
- `device_id`: Required, string, max 255 characters (must match registered device)
- `fcm_token`: Required, string, max 500 characters

---

## 🔍 Error Responses

### 400 Bad Request - Missing Required Field
```json
{
    "message": "The device id field is required.",
    "errors": {
        "device_id": ["The device id field is required."]
    }
}
```

### 400 Bad Request - Invalid Device Type
```json
{
    "message": "The selected device type is invalid.",
    "errors": {
        "device_type": ["The selected device type is invalid."]
    }
}
```

### 400 Bad Request - Device ID Mismatch
```json
{
    "success": false,
    "message": "Device ID mismatch."
}
```

### 401 Unauthorized - Missing Token
```json
{
    "message": "Unauthenticated."
}
```

---

## 📝 Postman Collection Setup

### Environment Variables:
```
base_url = http://localhost:8000/api
token = 1|xxxxxxxxxxxx (from login)
```

### Request 1: Register Device
- **Method**: POST
- **URL**: `{{base_url}}/device/register`
- **Headers**: 
  - `Authorization: Bearer {{token}}`
  - `Content-Type: application/json`
- **Body**: JSON (as shown above)

### Request 2: Update Device Token
- **Method**: PUT
- **URL**: `{{base_url}}/device/update-token`
- **Headers**: 
  - `Authorization: Bearer {{token}}`
  - `Content-Type: application/json`
- **Body**: JSON (as shown above)

---

## ✅ Testing Checklist

- [ ] Register iOS device with all fields
- [ ] Register Android device with all fields
- [ ] Register Web device (without FCM token)
- [ ] Register device with only required fields
- [ ] Update FCM token with correct device_id
- [ ] Try update token with wrong device_id (should fail)
- [ ] Try register without device_id (should fail)
- [ ] Try register with invalid device_type (should fail)
- [ ] Check last_active_at is updated on both endpoints

---

## 🎯 Use Cases

1. **App Installation**: User installs app → Register device
2. **Token Refresh**: FCM token expires → Update device token
3. **Multi-Device**: User logs in on new device → Register new device
4. **Timezone Change**: User changes timezone → Update via register (or profile)

---

**Note**: Both endpoints update `last_active_at` automatically to track user activity.

