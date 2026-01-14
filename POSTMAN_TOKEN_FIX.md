# Fix "Unauthenticated" Error in Postman

## 🔍 Common Issues & Solutions

### Issue 1: Token Format Incorrect

**❌ Wrong:**
```
Authorization: Bearer{{token}}
```
or
```
Authorization: {{token}}
```

**✅ Correct:**
```
Authorization: Bearer {{token}}
```

**Important**: There must be a **space** between "Bearer" and the token!

---

### Issue 2: Token Not Saved Properly

**Check in Postman:**
1. Go to **Environment** (top right)
2. Check if `token` variable exists
3. Check if token value is correct (should start with number like `1|...`)

**Example Token Format:**
```
1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

---

### Issue 3: Using Wrong Header Name

**❌ Wrong:**
- `Auth: Bearer {{token}}`
- `Token: {{token}}`

**✅ Correct:**
- `Authorization: Bearer {{token}}`

---

## 📝 Step-by-Step Fix

### Step 1: Login and Get Token

1. **POST** `http://localhost:8000/api/auth/login`
2. **Body (JSON):**
```json
{
    "email": "your@email.com",
    "password": "YourPassword@123"
}
```
3. **Response** should have:
```json
{
    "success": true,
    "data": {
        "token": "1|xxxxxxxxxxxx"
    }
}
```

### Step 2: Save Token to Environment

**Option A: Manual**
1. Copy the token from response
2. Go to Environment → Edit
3. Set `token` variable = `1|xxxxxxxxxxxx` (paste token)

**Option B: Auto-save (Recommended)**

Add this to **Login** request's **Tests** tab:

```javascript
if (pm.response.code === 200) {
    var jsonData = pm.response.json();
    if (jsonData.success && jsonData.data && jsonData.data.token) {
        pm.environment.set("token", jsonData.data.token);
        console.log("✅ Token saved:", jsonData.data.token);
    } else {
        console.log("❌ Token not found in response");
    }
}
```

### Step 3: Use Token in Get Profile

1. **GET** `http://localhost:8000/api/profile`
2. **Headers Tab:**
   - Key: `Authorization`
   - Value: `Bearer {{token}}`
   
   **Important**: Type exactly `Bearer {{token}}` (with space!)

3. **Send Request**

---

## 🧪 Test Token Manually

### Test 1: Check Token Format

In Postman, create a test request:

**GET** `http://localhost:8000/api/profile`

**Headers:**
```
Authorization: Bearer 1|your_actual_token_here
```

Replace `1|your_actual_token_here` with your actual token from login response.

### Test 2: Check Environment Variable

In Postman Console (bottom), type:
```javascript
pm.environment.get("token")
```

Should show your token.

---

## 🔧 Troubleshooting

### Problem: Still Getting "Unauthenticated"

**Solution 1: Check Token in Database**
```sql
SELECT * FROM personal_access_tokens ORDER BY created_at DESC LIMIT 1;
```

**Solution 2: Check Token Expiry**
- Tokens expire after 24 hours
- Try login again to get new token

**Solution 3: Clear Postman Cache**
- Close and reopen Postman
- Clear environment variables and set again

**Solution 4: Check Server Logs**
```bash
tail -f storage/logs/laravel.log
```

### Problem: Token Saved But Not Working

**Check:**
1. Token has no extra spaces
2. Token starts with number (e.g., `1|...`)
3. Using `Authorization` header (not `Auth`)
4. Using `Bearer ` prefix with space

---

## ✅ Correct Postman Setup

### Environment Variables:
```
base_url = http://localhost:8000/api
token = 1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

### Get Profile Request:
- **Method**: GET
- **URL**: `{{base_url}}/profile`
- **Headers**:
  - `Authorization`: `Bearer {{token}}`
  - `Accept`: `application/json`
  - `Content-Type`: `application/json`

---

## 🎯 Quick Checklist

- [ ] Login successful and got token
- [ ] Token saved to environment variable `{{token}}`
- [ ] Using `Authorization` header (not `Auth`)
- [ ] Using `Bearer {{token}}` (with space between Bearer and token)
- [ ] Token format: `1|xxxxxxxxxxxx` (starts with number)
- [ ] Token not expired (less than 24 hours old)
- [ ] Server running on `http://localhost:8000`

---

## 📞 Still Not Working?

1. **Check Laravel Logs:**
   ```bash
   tail -f storage/logs/laravel.log
   ```

2. **Test with cURL:**
   ```bash
   curl -X GET http://localhost:8000/api/profile \
     -H "Authorization: Bearer YOUR_TOKEN_HERE" \
     -H "Accept: application/json"
   ```

3. **Verify Token in Database:**
   ```sql
   SELECT * FROM personal_access_tokens WHERE tokenable_id = YOUR_USER_ID;
   ```

---

**Most Common Issue**: Missing space between "Bearer" and token!

**Correct**: `Bearer {{token}}`  
**Wrong**: `Bearer{{token}}` or `{{token}}`


