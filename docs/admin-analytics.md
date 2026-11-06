# Admin → App Analytics

The **App Analytics** page in the admin panel (`/admin/analytics`, sidebar item under Dashboard) shows how the TimeVault mobile app is used. The data comes from two places:

- **User behaviour, from Microsoft Clarity** (project **TimeVault Wallet**, `z8isutv2au`):
  - sessions, average daily users, screens per session, active time;
  - rage taps, dead taps, quick backs and app errors;
  - popular screens, devices, OS and countries, plus a daily trend.
- **Business events, from Firebase Analytics / GA4** (Firebase project `time-vault-cbd41`):
  - active and new users, and sessions;
  - money locked, withdrawn and earned;
  - four funnels: lock, withdraw, subscribe, add bank;
  - all 23 app events in their 6 groups;
  - top screens and platforms, and an optional "why things fail" table;
  - a **Right now** strip: active users, events and platforms in the last 30 minutes, from GA4's realtime API. It has no processing delay and refreshes every minute.

Each source reports its own status (`ok`, `no_data`, `not_configured` or `error`). One source that is down or not configured never blanks the other. Until a source is set up, the page shows what to configure.

Recordings and heatmaps can't be exported from Clarity. The page links to the Clarity dashboard for those.

No Composer or npm packages were added. GA4 signs in with a JWT that PHP's `ext-openssl` signs, and Chart.js loads from jsDelivr with a pinned version and an SRI hash.

## Files

| File | What it is |
|---|---|
| `config/timevault_analytics.php` | Settings (reads `.env`), the event groups, funnels and money events |
| `app/Services/Analytics/ClarityClient.php` | Clarity Data Export API client, with the daily call budget |
| `app/Services/Analytics/ClarityReport.php` | Parses Clarity payloads (field names are tolerant, see below) |
| `app/Services/Analytics/Ga4Client.php` | GA4 Data API `runReport`, service-account JWT |
| `app/Services/Analytics/FirebaseReport.php` | The GA4 queries behind the Firebase section |
| `app/Services/Analytics/AnalyticsDashboard.php` | Builds the whole page as one array (also served as JSON) |
| `app/Models/ClaritySnapshot.php`, migration `2026_11_05_000001_create_clarity_snapshots_table` | Daily Clarity history, stored raw |
| `app/Console/Commands/SyncClarityInsights.php` | `php artisan analytics:sync-clarity [--force] [--show]` |
| `app/Http/Controllers/Admin/AnalyticsDashboardController.php` | `GET /admin/analytics` (page), `GET /admin/analytics.json` |
| `resources/views/admin/analytics/` | The page (admin layout) and its partial (CSS scoped under `.tva`) |
| `routes/web.php` | Both routes, inside the existing `admin` middleware group |
| `routes/console.php` | Cron 5: `analytics:sync-clarity` daily at 00:15 UTC |
| `tests/Feature/Admin/AdminAnalyticsTest.php` | 15 tests; all external HTTP is faked |

Access is the same as every other admin page. The `admin` middleware (`App\Http\Middleware\AdminMiddleware`) needs a signed-in user with `role = admin`; anyone else is redirected to `/admin/login`.

## 1. Microsoft Clarity

1. In Clarity, open **TimeVault Wallet** and go to **Settings → Data Export → Generate new API token**. Only project admins can do this.
2. Put it in the server's `.env` as `CLARITY_API_TOKEN=…`, then run `php artisan config:cache`.
3. Run the first sync by hand. This uses 4 of today's 10 calls:
   ```bash
   php artisan analytics:sync-clarity --show
   ```

After that, **Cron 5** runs it every day at 00:15 UTC. It stores the previous UTC day, because Clarity's `numOfDays=1` means "the last 24 hours". History starts the day the sync first runs, because **Clarity's API only returns the last 3 days**.

**Limits.** Clarity allows 10 calls per project per UTC day.
- The sync makes 4 calls: overall, OS, Device and Country/Region.
- It skips any dimension it already stored that day.
- A built-in budget (`daily_call_budget = 8`) stops further calls, so a loop or a retry storm can't use up the quota.
- On HTTP 429 the sync stops at once.
- `--force` fetches everything again (4 more calls).

**Check the field names once.** Microsoft documents only the Traffic fields (`totalSessionCount`, `totalBotSessionCount`, `distantUserCount`, `PagesPerSessionPercentage`).
- `ClarityReport.php` matches the other metrics against likely spellings, ignoring case and punctuation.
- If a card shows "–" while Clarity's dashboard has a number, look for the real field name in the `--show` output and add it to that metric's candidate list.
- Snapshots are stored raw, so the fix also corrects days already stored.

## 2. Firebase Analytics (GA4)

**Prerequisite:** as of 2026-11-05, Google Analytics is **not linked** to Firebase project `time-vault-cbd41` (`analyticsDetails` returned 404). Until it is, the app's Firebase events aren't collected anywhere.

1. **Link it.** Firebase console → `time-vault-cbd41` → ⚙ Project settings → **Integrations** → **Google Analytics** → **Enable**. Then create or choose a GA4 property.
   - Optional: re-download `google-services.json` and `GoogleService-Info.plist` for the app afterwards.
2. **Property ID.** On the same Integrations card, open the linked property, or go to GA4 → Admin → Property details. Copy the numeric **Property ID** into `GA4_PROPERTY_ID`.
3. **Enable the API.** In Google Cloud console, project `time-vault-cbd41`, go to APIs & Services → Library and enable **Google Analytics Data API**.
4. **Service account.** This backend has no existing Google service account (push notifications don't use one), so create a new read-only one:
   - IAM & Admin → Service accounts → **Create**, for example `admin-analytics`. It needs **no** Cloud roles.
   - On that account: Keys → Add key → **JSON**.
5. **Read access.** In GA4, go to Admin → **Property access management** → **+** → add the service account's email with the **Viewer** role.
6. **Key file on the server.** It must sit outside `public/`. `storage/app/private/` is already gitignored.
   ```bash
   mv admin-analytics-*.json storage/app/private/ga4-service-account.json
   chmod 600 storage/app/private/ga4-service-account.json
   ```
   Leave `GA4_CREDENTIALS_PATH` empty to use that default path, or set it to an absolute path. Then run `php artisan config:cache`.

GA4 results are cached for `GA4_CACHE_MINUTES` (default 60). GA4 needs a few hours to process events, so today's numbers fill in during the day.

**Optional: "why things fail" table.**
1. In GA4, go to Admin → **Custom definitions** → Create custom dimension: name `Failure reason`, scope **Event**, event parameter `reason`.
2. Set `GA4_REASON_DIMENSION=true`.

The dimension only collects data from the moment it's registered.

## 3. Settings (`.env`)

| Key | Default | Meaning |
|---|---|---|
| `CLARITY_API_TOKEN` | empty | Clarity Data Export token. Secret. |
| `CLARITY_PROJECT_ID` | `z8isutv2au` | Used for the dashboard link |
| `GA4_PROPERTY_ID` | empty | Numeric GA4 property ID |
| `GA4_CREDENTIALS_PATH` | empty = `storage/app/private/ga4-service-account.json` | Service-account JSON key. Secret file. |
| `GA4_CACHE_MINUTES` | `60` | GA4 result cache |
| `GA4_REASON_DIMENSION` | `false` | Turns on the "why things fail" table |

Tokens and keys are only read from `.env` or the key file. They are never logged, never sent to the browser, and never cached: the GA4 access token lives in memory for one request.

## 4. How to read the numbers

- **The money cards** (Locked, Withdrawn, Subscription revenue) add up the `value` the app sends with `lock_created`, `withdraw_completed` and `purchase`, in USD.
  - They're for spotting trends, **not for accounting**: an event is lost if the phone is offline or the app is killed before it's sent.
  - Real totals come from this backend's database (Dashboard, Payments, Transfers).
- **Funnels** are ratios of event counts in the selected range. For example, 60% on "Lock created" means 6 locks created for every 10 started.
- **Clarity "Avg daily users"** is the average of each day's distinct users. One person active on 3 days counts on each of those days.
- **Clarity rates and times** over a range are averages weighted by sessions, so a busy day counts more than a quiet one.
- **GA4 processing delay:** GA4 needs a few hours, so today's numbers fill in during the day.
- **Right now vs the cards:** the strip reads raw events from the last 30 minutes, and the cards read processed data. Events appear in the strip within a minute or two, but in the cards only hours later.
  - Gold chips are the app's own tracked events; grey chips are Firebase's automatic ones (`screen_view`, `session_start`, `user_engagement`).
  - If only grey chips ever appear, the app isn't logging its own events under the names in `config/timevault_analytics.php`.

## 5. Production

- `./deploy.sh --branch main` runs the migration that creates `clarity_snapshots`. It takes a `mysqldump` before migrating.
- The scheduler cron (`* * * * * … artisan schedule:run`) is already installed by `deploy/setup-background-jobs.sh`. Check that Cron 5 is listed:
  ```bash
  php artisan schedule:list | grep analytics:sync-clarity
  ```
- None of the mobile app's API routes changed.
