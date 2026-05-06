# Time Vault — VPS Deployment Guide (Hostinger CloudPanel)

## Context
Deploy the Time Vault Laravel 12 app from branch `stripe-sheet-and-admin-panel` to Hostinger VPS with CloudPanel.

**Server Details:**
- VPS User: `devonlinetestserver-timevaultapp`
- Site Path: `/home/devonlinetestserver-timevaultapp/htdocs/timevaultapp.devonlinetestserver.com`
- Domain: `timevaultapp.devonlinetestserver.com`
- Git repo is NOT cloned yet on the server

**Requirements:** PHP 8.2+, MySQL, Composer, Supervisor, Cron (Node.js NOT needed — admin panel uses plain Bootstrap)

> **IMPORTANT — Encryption Warning:**
> This app uses field-level encryption (`email`, `phone`, `full_name` in the User model) tied to `APP_KEY`.
> - **NEVER** regenerate `APP_KEY` after data has been seeded/created — it will corrupt all encrypted data and cause `DecryptException: The MAC is invalid`.
> - If you must change `APP_KEY`, you MUST truncate all tables and re-seed (see Troubleshooting section below).
> - Always generate `APP_KEY` ONCE **before** running migrations and seeders.

---

## Step-by-Step Commands (run on VPS terminal)

### Step 1: Clone the Repository

```bash
cd /home/devonlinetestserver-timevaultapp/htdocs

# Remove the empty default directory
rm -rf timevaultapp.devonlinetestserver.com

# Clone your repo (public — no token needed)
git clone https://github.com/jasonapp2244/time_walt_app.git timevaultapp.devonlinetestserver.com

cd timevaultapp.devonlinetestserver.com

# Switch to the correct branch
git checkout stripe-sheet-and-admin-panel
```

---

### Step 2: Install Composer Dependencies

```bash
cd /home/devonlinetestserver-timevaultapp/htdocs/timevaultapp.devonlinetestserver.com

composer install --no-dev --optimize-autoloader --no-interaction
```

---

### Step 3: Create `.env` File

```bash
cp .env.example .env
nano .env
```

Update these values:

```env
APP_NAME="Time Vault"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://timevaultapp.devonlinetestserver.com

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_cloudpanel_db_name
DB_USERNAME=your_cloudpanel_db_user
DB_PASSWORD=your_cloudpanel_db_password

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_DOMAIN=timevaultapp.devonlinetestserver.com
SESSION_SECURE_COOKIE=true

QUEUE_CONNECTION=database
CACHE_STORE=database

STRIPE_KEY=sk_test_xxxx
STRIPE_SECRET=sk_test_xxxx
STRIPE_PUBLISHABLE_KEY=pk_test_xxxx
STRIPE_WEBHOOK_SECRET=whsec_xxxx
STRIPE_AUTO_TRANSFER=false
STRIPE_PLATFORM_URL=https://timevaultapp.devonlinetestserver.com

ADMIN_PANEL_EMAIL=admin@timevault.com
ADMIN_PANEL_PASSWORD=YourSecurePassword123

MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your_email@gmail.com
MAIL_PASSWORD=your_app_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@timevaultapp.devonlinetestserver.com
MAIL_FROM_NAME="Time Vault"
```

Save: `Ctrl+O` → Enter → `Ctrl+X`

---

### Step 4: Generate App Key, Run Migrations & Seed

> **CRITICAL:** Generate key FIRST, then migrate, then seed. This order ensures all encrypted data uses the same APP_KEY.

```bash
php artisan key:generate
```

```bash
php artisan migrate --force
```

```bash
php artisan db:seed --force
```

---

### Step 5: Set Permissions & Storage Link

```bash
cd /home/devonlinetestserver-timevaultapp/htdocs/timevaultapp.devonlinetestserver.com

chmod -R 775 storage bootstrap/cache
chown -R devonlinetestserver-timevaultapp:devonlinetestserver-timevaultapp storage bootstrap/cache

php artisan storage:link
```

---

### Step 6: Laravel Production Optimizations

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize
```

---

### Step 7: Configure CloudPanel Root Directory

In CloudPanel → Site → **Settings** → **Root Directory**, set to:

```
/home/devonlinetestserver-timevaultapp/htdocs/timevaultapp.devonlinetestserver.com/public
```

> **IMPORTANT:** The root directory MUST end with `/public`. Without this, Laravel will not work.

---

### Step 8: Configure Nginx (CloudPanel Vhost Editor)

In CloudPanel → Site → **Vhost Editor**, use this configuration:

```nginx
server {
  listen 80;
  listen [::]:80;
  listen 443 quic;
  listen 443 ssl;
  listen [::]:443 quic;
  listen [::]:443 ssl;
  http2 on;
  http3 off;
  {{ssl_certificate_key}}
  {{ssl_certificate}}
  server_name timevaultapp.devonlinetestserver.com;
  {{root}}

  {{nginx_access_log}}
  {{nginx_error_log}}

  if ($scheme != "https") {
    rewrite ^ https://$host$request_uri permanent;
  }

  location ~ /.well-known {
    auth_basic off;
    allow all;
  }

  {{settings}}

  location / {
    {{varnish_proxy_pass}}
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_hide_header X-Varnish;
    proxy_redirect off;
    proxy_max_temp_file_size 0;
    proxy_connect_timeout      720;
    proxy_send_timeout         720;
    proxy_read_timeout         720;
    proxy_buffer_size          128k;
    proxy_buffers              4 256k;
    proxy_busy_buffers_size    256k;
    proxy_temp_file_write_size 256k;
  }

  location ~* ^.+\.(css|js|jpg|jpeg|gif|png|ico|gz|svg|svgz|ttf|otf|woff|woff2|eot|mp4|ogg|ogv|webm|webp|zip|swf|map|mjs)$ {
    add_header Access-Control-Allow-Origin "*";
    add_header alt-svc 'h3=":443"; ma=86400';
    expires max;
    access_log off;
  }

  location /storage/ {
    alias /home/devonlinetestserver-timevaultapp/htdocs/timevaultapp.devonlinetestserver.com/storage/app/public/;
    autoindex off;
  }

  location ~ /\.(ht|svn|git) {
    deny all;
  }

  location ~* \.(env|log|md)$ {
    deny all;
  }

  if (-f $request_filename) {
    break;
  }
}

server {
  listen 8080;
  listen [::]:8080;
  server_name timevaultapp.devonlinetestserver.com;
  {{root}}

  include /etc/nginx/global_settings;

  try_files $uri $uri/ /index.php?$args;
  index index.php index.html;

  location ~ \.php$ {
    include fastcgi_params;
    fastcgi_intercept_errors on;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    try_files $uri =404;
    fastcgi_read_timeout 3600;
    fastcgi_send_timeout 3600;
    fastcgi_param HTTPS "on";
    fastcgi_param SERVER_PORT 443;
    fastcgi_pass 127.0.0.1:{{php_fpm_port}};
    fastcgi_param PHP_VALUE "{{php_settings}}";
  }

  if (-f $request_filename) {
    break;
  }
}
```

---

### Step 9: SSL Certificate (CloudPanel)

In CloudPanel → Site → **SSL/TLS** → **New Let's Encrypt Certificate** → Create

---

### Step 10: Setup Cron (Laravel Scheduler)

```bash
crontab -e
```

Add this line:

```cron
* * * * * cd /home/devonlinetestserver-timevaultapp/htdocs/timevaultapp.devonlinetestserver.com && php artisan schedule:run >> /dev/null 2>&1
```

This triggers 3 scheduled commands:
- `check:payment-holds` — every 5 min
- `verify:pending-transfers` — every 5 min
- `users:cleanup-unverified` — daily at 00:00 ET

---

### Step 11: Setup Queue Worker (Supervisor)

```bash
apt-get install -y supervisor

nano /etc/supervisor/conf.d/timevault-worker.conf
```

Paste:

```ini
[program:timevault-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /home/devonlinetestserver-timevaultapp/htdocs/timevaultapp.devonlinetestserver.com/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=devonlinetestserver-timevaultapp
numprocs=1
redirect_stderr=true
stdout_logfile=/home/devonlinetestserver-timevaultapp/htdocs/timevaultapp.devonlinetestserver.com/storage/logs/worker.log
stopwaitsecs=3600
```

Start it:

```bash
supervisorctl reread
supervisorctl update
supervisorctl start timevault-worker:*
supervisorctl status
```

---

### Step 12: Configure Stripe Webhook

1. Stripe Dashboard → Developers → Webhooks → **Add endpoint**
2. URL: `https://timevaultapp.devonlinetestserver.com/api/stripe/webhook`
3. Events: `payment_intent.succeeded`, `payment_intent.payment_failed`, `payment_intent.canceled`, `checkout.session.completed`, `account.updated`, `transfer.created`, `transfer.failed`, `transfer.canceled`
4. Copy signing secret → update `STRIPE_WEBHOOK_SECRET` in `.env`
5. Re-cache: `php artisan config:cache`

---

### Step 13: Test Everything

```bash
# Health check
curl https://timevaultapp.devonlinetestserver.com/up

# API test
curl https://timevaultapp.devonlinetestserver.com/api/privacy-policy

# Admin panel — open browser
# https://timevaultapp.devonlinetestserver.com/admin/login

# Check cron
php artisan schedule:list

# Check queue
supervisorctl status timevault-worker:*

# Check logs
tail -f storage/logs/laravel.log
```

---

### Future Deployments (git pull)

```bash
cd /home/devonlinetestserver-timevaultapp/htdocs/timevaultapp.devonlinetestserver.com

git pull origin stripe-sheet-and-admin-panel
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
supervisorctl restart timevault-worker:*
```

---

## Troubleshooting

### "DecryptException: The MAC is invalid" (500 Error)

This happens when encrypted data in the database was created with a different `APP_KEY`. Fix by truncating all tables and re-seeding:

```bash
cd /home/devonlinetestserver-timevaultapp/htdocs/timevaultapp.devonlinetestserver.com
```

**Step 1:** Truncate all tables (disables foreign key checks):

```bash
php artisan tinker --execute="DB::statement('SET FOREIGN_KEY_CHECKS=0'); DB::table('transfers')->truncate(); DB::table('payment_holds')->truncate(); DB::table('payments')->truncate(); DB::table('sessions')->truncate(); DB::table('users')->truncate(); DB::statement('SET FOREIGN_KEY_CHECKS=1'); echo 'Done';"
```

**Step 2:** Re-seed and rebuild caches:

```bash
php artisan db:seed --force && php artisan config:cache && php artisan route:cache && php artisan view:cache
```

**Step 3:** Clear browser cookies for the domain (or use incognito window)

### Stale Session Cookie (500 Error after login)

If the 500 only happens after login, the browser has an old encrypted session cookie:

```bash
php artisan tinker --execute="DB::table('sessions')->truncate(); echo 'Sessions cleared';"
```

Then clear browser cookies and try again in incognito.

---

## Verification Checklist

- [ ] `curl /up` returns 200
- [ ] `curl /api/privacy-policy` returns JSON
- [ ] Admin login page loads at `/admin/login`
- [ ] Admin can login with seeded credentials
- [ ] `supervisorctl status` shows RUNNING
- [ ] `crontab -l` shows scheduler entry
- [ ] SSL certificate is active (HTTPS works)
- [ ] `.env` file is NOT accessible via browser
- [ ] Stripe webhook endpoint responds (check Stripe dashboard)
