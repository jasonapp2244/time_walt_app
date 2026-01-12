# Profile Image Upload Fix Guide

## 🔧 Postman Setup for Form-Data with Image

### Step-by-Step Instructions:

1. **Method**: `PUT`
2. **URL**: `http://localhost:8000/api/profile/update`
3. **Headers**:
   ```
   Authorization: Bearer {{token}}
   Accept: application/json
   ```
   ⚠️ **IMPORTANT**: Do NOT add `Content-Type` header manually - Postman will set it automatically for form-data!

4. **Body Tab**:
   - Select: **`form-data`** (NOT raw, NOT x-www-form-urlencoded)
   
5. **Add Fields**:
   - `full_name` (Type: **Text**): `John Updated`
   - `phone` (Type: **Text**): `9876543210`
   - `profile_image` (Type: **File**): [Click and select image file]

### ⚠️ Common Mistakes:

1. **Wrong Body Type**:
   - ❌ Using `raw` with JSON
   - ❌ Using `x-www-form-urlencoded`
   - ✅ Use `form-data` only

2. **Wrong Field Type**:
   - ❌ `profile_image` as Text
   - ✅ `profile_image` as **File**

3. **Content-Type Header**:
   - ❌ Manually adding `Content-Type: multipart/form-data`
   - ✅ Let Postman set it automatically

4. **File Size**:
   - ⚠️ Max file size: 2MB
   - ⚠️ Allowed formats: jpeg, jpg, png, gif

---

## 🧪 Testing Steps

### Test 1: Image Only
1. Body: `form-data`
2. Add only: `profile_image` (File)
3. Select image file
4. Send request

### Test 2: Name + Phone + Image
1. Body: `form-data`
2. Add:
   - `full_name` (Text): `Test Name`
   - `phone` (Text): `1234567890`
   - `profile_image` (File): Select image
3. Send request

---

## 🔍 Debugging

If you get "No data provided to update", check the response `debug_info`:

```json
{
    "success": false,
    "message": "...",
    "debug_info": {
        "has_file_profile_image": true/false,
        "has_profile_image_key": true/false,
        "all_files_keys": ["profile_image"],
        "content_type": "multipart/form-data; boundary=..."
    }
}
```

### What to Check:

1. **`has_file_profile_image`**: Should be `true` if file is detected
2. **`all_files_keys`**: Should contain `"profile_image"` if file is sent
3. **`content_type`**: Should start with `multipart/form-data`

---

## ✅ Correct Postman Setup Screenshot Guide

### Body Tab:
```
┌─────────────────────────────────────┐
│ Body                                │
├─────────────────────────────────────┤
│ ○ none  ○ form-data  ○ x-www...    │ ← Select form-data
│ ○ raw   ○ binary     ○ GraphQL     │
├─────────────────────────────────────┤
│ Key          │ Value │ Type         │
├──────────────┼───────┼──────────────┤
│ full_name    │ Test  │ Text    [✓]  │
│ phone        │ 123   │ Text    [✓]  │
│ profile_image│ [file]│ File    [✓]  │ ← Must be File type!
└─────────────────────────────────────┘
```

---

## 🚨 Troubleshooting

### Issue: File not detected

**Solution 1**: Check field name is exactly `profile_image` (case-sensitive)

**Solution 2**: Make sure Type is set to **File** (not Text)

**Solution 3**: Try without other fields first (just image)

**Solution 4**: Check file size (must be < 2MB)

**Solution 5**: Check file format (jpeg, jpg, png, gif only)

---

## 📝 Example cURL (for reference)

```bash
curl -X PUT http://localhost:8000/api/profile/update \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -F "full_name=John Updated" \
  -F "phone=9876543210" \
  -F "profile_image=@/path/to/image.jpg"
```

---

## ✅ Success Response

```json
{
    "success": true,
    "message": "Profile updated successfully.",
    "data": {
        "user": {
            "id": 1,
            "full_name": "John Updated",
            "phone": "9876543210",
            "profile": "http://localhost:8000/storage/profiles/xyz.jpg",
            ...
        }
    }
}
```

---

**Remember**: Always use `form-data` body type and set `profile_image` field type to **File** in Postman!

