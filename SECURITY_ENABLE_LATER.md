# Security Features - Enable Later (When App is Complete)

## 📝 Current Status

✅ **Enabled Now:**
- Basic rate limiting on routes (5/min for auth, 3/min for resend/forgot)
- Named routes (for better organization)
- Strong password validation
- OTP verification mandatory
- Token authentication

❌ **Disabled Now (Enable Later):**
- Security Headers middleware
- Input Sanitization middleware
- API Request Logging middleware
- Global API rate limiting

---

## 🚀 How to Enable Security Features Later

### Step 1: Enable Security Middleware

**File**: `bootstrap/app.php`

Uncomment these lines:

```php
->withMiddleware(function (Middleware $middleware): void {
    // Enable Security Headers, Input Sanitization, and Logging
    $middleware->api(prepend: [
        \App\Http\Middleware\SecurityHeaders::class,
        \App\Http\Middleware\SanitizeInput::class,
        \App\Http\Middleware\LogApiRequests::class,
    ]);

    // Enable Global API Rate Limiting
    $middleware->throttleApi();
    
    // Enable Trust Proxies (for production behind load balancer)
    $middleware->trustProxies(at: '*');
})
```

### Step 2: Verify Middleware Files Exist

These files are already created and ready:
- ✅ `app/Http/Middleware/SecurityHeaders.php`
- ✅ `app/Http/Middleware/SanitizeInput.php`
- ✅ `app/Http/Middleware/LogApiRequests.php`

### Step 3: Verify Log Channels

**File**: `config/logging.php`

Log channels are already configured:
- ✅ `api` channel (30 days retention)
- ✅ `security` channel (90 days retention)

### Step 4: Test After Enabling

1. Check response headers (should have security headers)
2. Test input sanitization (XSS attempts should be blocked)
3. Check logs in `storage/logs/api.log`
4. Test rate limiting

---

## 🔒 What Each Middleware Does

### 1. SecurityHeaders
- Adds security headers to all responses
- Prevents XSS, clickjacking, MIME sniffing
- Enforces HTTPS

### 2. SanitizeInput
- Removes null bytes
- Strips script tags
- Prevents XSS attacks
- Trims whitespace

### 3. LogApiRequests
- Logs all API requests
- Tracks IP, user agent, duration
- Helps with security monitoring
- Useful for debugging

---

## 📋 Pre-Production Checklist

Before enabling security features:

- [ ] All features tested and working
- [ ] All APIs functional
- [ ] Error handling tested
- [ ] Database migrations run
- [ ] Environment variables set
- [ ] HTTPS configured (for production)
- [ ] CORS configured properly
- [ ] Rate limits tested

---

## ⚙️ Configuration Options

### Rate Limiting

**Current**: Basic rate limiting on individual routes
**After Enable**: Global API rate limiting (60/min) + route-specific limits

**To Adjust**: Edit `routes/api.php` throttle values

### Token Expiration

**Current**: 24 hours (configurable in `config/sanctum.php`)

**To Change**: 
```php
'expiration' => env('SANCTUM_TOKEN_EXPIRATION', 60 * 24), // minutes
```

### Log Retention

**Current**: 
- API logs: 30 days
- Security logs: 90 days

**To Change**: Edit `config/logging.php`

---

## 🎯 When to Enable

### Recommended Timeline:

1. **Development Phase** (Now):
   - Keep disabled for easier testing
   - Focus on functionality

2. **Testing Phase**:
   - Enable gradually
   - Test each middleware separately

3. **Staging Phase**:
   - Enable all security features
   - Test thoroughly

4. **Production**:
   - All security enabled
   - Monitor logs
   - Regular security audits

---

## 🔧 Quick Enable Commands

When ready, just uncomment in `bootstrap/app.php`:

```bash
# No commands needed - just edit bootstrap/app.php
# Uncomment the middleware lines
```

---

## 📊 Benefits of Enabling

### Security Headers:
- Protects against XSS attacks
- Prevents clickjacking
- Enforces HTTPS
- Hides server information

### Input Sanitization:
- Prevents XSS in inputs
- Removes malicious scripts
- Cleans user data automatically

### API Logging:
- Track all API requests
- Monitor suspicious activities
- Debug issues easily
- Security audit trail

---

## ⚠️ Important Notes

1. **Performance**: Logging adds minimal overhead
2. **Storage**: Logs can grow - monitor disk space
3. **Privacy**: Logs contain user data - handle carefully
4. **Testing**: Test thoroughly after enabling
5. **Monitoring**: Set up log rotation and monitoring

---

## 🚨 If Issues After Enabling

### Issue: Requests blocked unexpectedly
**Solution**: Check rate limiting settings, adjust if needed

### Issue: Logs growing too large
**Solution**: Configure log rotation, reduce retention days

### Issue: Headers causing CORS issues
**Solution**: Configure CORS properly in `config/cors.php`

### Issue: Input sanitization too aggressive
**Solution**: Adjust `SanitizeInput` middleware rules

---

## 📖 Related Documentation

- `SECURITY_GUIDE.md` - Complete security guide
- `POSTMAN_TESTING_GUIDE.md` - API testing guide
- `POSTMAN_QUICK_REFERENCE.md` - Quick reference

---

**Remember**: Security is important, but enable when app is stable! 🔒

