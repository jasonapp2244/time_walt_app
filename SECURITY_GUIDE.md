# Fintech App Security Guide

## 🔒 Security Features Implemented

### 1. **Route Security**

#### Named Routes
All routes have been given descriptive names for better security and maintainability:
- `auth.signup`, `auth.login`, `auth.verify-otp`, etc.
- `profile.show`, `profile.update`, etc.
- `device.register`, `device.update-token`
- `notification-settings.show`, `notification-settings.update`

#### Rate Limiting
- **Authentication Routes**: 5 attempts per minute
- **Resend OTP**: 3 attempts per minute (prevent abuse)
- **Forgot Password**: 3 attempts per minute
- **API Routes**: 60 requests per minute (default)

### 2. **Password Security**

#### Strong Password Requirements
- Minimum 8 characters
- Maximum 128 characters
- At least one uppercase letter (A-Z)
- At least one lowercase letter (a-z)
- At least one number (0-9)
- At least one special character: `@$!%*?&#^()_+-=[]{};:,.<>/`

#### Password Hashing
- All passwords are hashed using `bcrypt`
- Never stored in plain text

### 3. **Authentication Security**

#### OTP Verification
- 4-digit OTP code
- Expires in 5 minutes
- Required for account verification
- Unverified users cannot login
- Resend OTP limited to 3 times per minute

#### Token Security
- Sanctum tokens with expiration (24 hours default)
- Token-based authentication
- Automatic token expiration
- Logout invalidates tokens

#### Account Verification Flow
1. User signs up → `is_verified = false`
2. OTP sent via email
3. User must verify OTP → `is_verified = true`
4. Only verified users can login

### 4. **API Security Headers**

All API responses include:
- `X-Content-Type-Options: nosniff` - Prevents MIME type sniffing
- `X-Frame-Options: DENY` - Prevents clickjacking
- `X-XSS-Protection: 1; mode=block` - XSS protection
- `Strict-Transport-Security` - Forces HTTPS
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy` - Restricts browser features
- Server information removed

### 5. **Input Sanitization**

- All inputs are sanitized automatically
- Null bytes removed
- Script tags removed
- Whitespace trimmed
- XSS prevention

### 6. **API Request Logging**

All API requests are logged with:
- Method and URL
- IP address
- User agent
- User ID (if authenticated)
- Response status
- Duration
- Timestamp

**Log Location**: `storage/logs/api.log`

### 7. **Security Logging**

Security events logged separately:
- Failed login attempts
- OTP verification failures
- Password reset attempts
- Suspicious activities

**Log Location**: `storage/logs/security.log`

### 8. **CORS Configuration**

- Configured for API security
- Only allowed origins can access API
- Credentials support for authenticated requests

### 9. **Database Security**

- Password reset tokens in users table (not separate)
- OTP codes expire after 5 minutes
- Sensitive fields hidden in API responses
- SQL injection prevention (Eloquent ORM)

### 10. **Middleware Stack**

Applied in order:
1. **SecurityHeaders** - Adds security headers
2. **SanitizeInput** - Sanitizes all inputs
3. **LogApiRequests** - Logs API requests
4. **auth:sanctum** - Authentication check
5. **throttle** - Rate limiting

---

## 🛡️ Security Best Practices

### Environment Variables

**Never commit these to Git:**
- `APP_KEY`
- `DB_PASSWORD`
- `MAIL_PASSWORD`
- `SANCTUM_TOKEN_PREFIX`
- Any API keys or secrets

### Production Checklist

- [ ] Set `APP_ENV=production`
- [ ] Set `APP_DEBUG=false`
- [ ] Use HTTPS only
- [ ] Configure proper CORS origins
- [ ] Set strong database passwords
- [ ] Enable rate limiting
- [ ] Set up monitoring and alerts
- [ ] Regular security audits
- [ ] Keep dependencies updated
- [ ] Use environment-specific configs

### Token Management

- Tokens expire after 24 hours (configurable)
- Users should logout when done
- Implement token refresh if needed
- Monitor token usage

### OTP Security

- OTP expires in 5 minutes
- Only 3 resend attempts per minute
- OTP cleared after verification
- Email delivery for OTP

### Rate Limiting

**Current Limits:**
- Signup: 5/minute
- Login: 5/minute
- Verify OTP: 5/minute
- Resend OTP: 3/minute
- Forgot Password: 3/minute
- Reset Password: 5/minute
- API: 60/minute

**To Adjust**: Edit `routes/api.php` throttle values

---

## 🔍 Security Monitoring

### Log Files to Monitor

1. **API Logs**: `storage/logs/api.log`
   - All API requests
   - Response times
   - User activities

2. **Security Logs**: `storage/logs/security.log`
   - Failed authentication attempts
   - Suspicious activities
   - Security events

3. **Application Logs**: `storage/logs/laravel.log`
   - General application errors
   - Exceptions

### What to Monitor

- Multiple failed login attempts from same IP
- Unusual API request patterns
- High number of OTP resend requests
- Token usage anomalies
- Unauthorized access attempts

---

## 🚨 Security Incident Response

### If Security Breach Suspected

1. **Immediately**:
   - Review security logs
   - Check for unauthorized access
   - Identify affected users

2. **Actions**:
   - Force password reset for affected users
   - Revoke all active tokens
   - Increase rate limiting temporarily
   - Notify affected users

3. **Prevention**:
   - Update security measures
   - Review and fix vulnerabilities
   - Update dependencies
   - Conduct security audit

---

## 📋 Security Testing

### Test Scenarios

1. **Password Strength**
   - Try weak passwords → Should fail
   - Try strong passwords → Should pass

2. **Rate Limiting**
   - Make 6 login attempts in 1 minute → 6th should fail
   - Wait 1 minute → Should work again

3. **OTP Verification**
   - Try login without OTP verification → Should fail
   - Try wrong OTP → Should fail
   - Try expired OTP → Should fail

4. **Token Security**
   - Use expired token → Should fail
   - Use invalid token → Should fail
   - Logout → Token should be invalidated

5. **Input Sanitization**
   - Try XSS in inputs → Should be sanitized
   - Try SQL injection → Should be prevented

---

## 🔐 Additional Recommendations

### For Production

1. **Enable HTTPS**: Use SSL/TLS certificates
2. **Firewall**: Configure server firewall
3. **WAF**: Consider Web Application Firewall
4. **DDoS Protection**: Use Cloudflare or similar
5. **Backup**: Regular database backups
6. **Monitoring**: Set up application monitoring
7. **Alerts**: Configure security alerts
8. **Penetration Testing**: Regular security audits
9. **Compliance**: Follow PCI DSS, GDPR, etc.
10. **Encryption**: Encrypt sensitive data at rest

### Code Security

- Never log passwords or tokens
- Validate all inputs
- Use prepared statements (Eloquent does this)
- Escape output data
- Keep dependencies updated
- Regular code reviews
- Security headers always enabled

---

## 📞 Security Contact

For security issues, contact your security team immediately.

**Remember**: Security is an ongoing process, not a one-time setup!

