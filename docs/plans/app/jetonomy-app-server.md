# jetonomy-app-server — Laravel Build Platform

## Overview

`app.jetonomy.com` — Laravel application that manages customer app configurations, triggers builds via GitHub Actions + Expo EAS, and serves download links. Hosted on DigitalOcean droplet.

## Tech Stack

- **Framework:** Laravel 11
- **Frontend:** Blade + Tailwind CSS + Alpine.js (simple, no SPA overhead)
- **Database:** MySQL 8
- **Storage:** S3-compatible (DigitalOcean Spaces or AWS S3)
- **Queue:** Redis + Laravel Horizon (for build jobs)
- **Build:** GitHub Actions + Expo EAS
- **Hosting:** DigitalOcean Droplet (Ubuntu 24.04)
- **Deploy:** GitHub Actions → SSH deploy
- **Repo:** Private `jetonomy-app-server`

## Database Schema

### Migration: customers
```php
Schema::create('customers', function (Blueprint $table) {
    $table->id();
    $table->string('license_key')->unique();
    $table->string('site_url');
    $table->string('email');
    $table->string('plan'); // starter, growth, agency, lifetime, app-only
    $table->string('status')->default('active'); // active, expired, suspended
    $table->unsignedBigInteger('edd_customer_id')->nullable();
    $table->timestamp('license_verified_at')->nullable();
    $table->timestamps();
});
```

### Migration: app_configs
```php
Schema::create('app_configs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
    $table->string('app_name');
    $table->string('accent_color', 7)->default('#3B82F6');
    $table->string('icon_path')->nullable(); // S3 path
    $table->string('splash_path')->nullable();
    $table->string('login_bg_path')->nullable();
    $table->boolean('dark_mode_default')->default(false);
    $table->string('site_url_hardcoded')->nullable(); // For white-label builds
    $table->json('extra_config')->nullable(); // Future extensibility
    $table->timestamps();
});
```

### Migration: builds
```php
Schema::create('builds', function (Blueprint $table) {
    $table->id();
    $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
    $table->string('platform'); // android, ios
    $table->string('status')->default('pending'); // pending, building, ready, failed
    $table->string('version')->default('1.0.0');
    $table->string('download_url')->nullable(); // S3 presigned URL
    $table->string('github_run_id')->nullable();
    $table->text('error_log')->nullable();
    $table->timestamp('triggered_at')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();
});
```

## Routes

### Web Routes (Customer Dashboard)
```php
// Auth
Route::get('/login', [AuthController::class, 'showLogin']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout']);

// Dashboard (auth required)
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/config', [AppConfigController::class, 'edit']);
    Route::post('/dashboard/config', [AppConfigController::class, 'update']);
    Route::get('/dashboard/builds', [BuildController::class, 'index']);
    Route::post('/dashboard/builds', [BuildController::class, 'trigger']);
    Route::get('/dashboard/builds/{build}', [BuildController::class, 'show']);
});
```

### API Routes (From Pro Plugin + GitHub Webhooks)
```php
// From Jetonomy Pro plugin
Route::prefix('api')->group(function () {
    Route::post('/app/register', [ApiController::class, 'register']);
    Route::get('/app/status', [ApiController::class, 'status']);
    Route::post('/app/build', [ApiController::class, 'triggerBuild']);
    Route::get('/app/build/{build}', [ApiController::class, 'buildStatus']);

    // Build config for GitHub Actions to fetch
    Route::get('/app/build-config/{customer}', [ApiController::class, 'buildConfig']);
});

// GitHub Actions webhook
Route::post('/webhooks/github/build-complete', [WebhookController::class, 'buildComplete']);
```

## Controllers

### AuthController
```
showLogin()  → License key form
login()      → Validate against EDD API, create session
logout()     → Clear session
```

**EDD License Validation:**
```php
$response = Http::get('https://store.wbcomdesigns.com', [
    'edd_action' => 'check_license',
    'license'    => $licenseKey,
    'item_name'  => 'Jetonomy Pro',
]);

// Response: { license: "valid", expires: "2027-01-01", customer_email: "..." }
```

### AppConfigController
```
edit()   → Show branding form with current config
update() → Validate, upload assets to S3, save config
```

### BuildController
```
index()   → List all builds for customer
trigger() → Dispatch GitHub Actions workflow, create build record
show()    → Build detail with status + download link
```

### ApiController (for Pro Plugin)
```
register()    → Create/update customer + config from wp-admin
status()      → Return latest build info for wp-admin display
triggerBuild() → Same as BuildController::trigger but via API
buildConfig() → Return JSON config for GitHub Actions to consume
```

## Build Pipeline Detail

### 1. Customer triggers build
```php
// BuildController::trigger()
$build = Build::create([
    'customer_id' => $customer->id,
    'platform'    => $request->platform,
    'status'      => 'pending',
    'version'     => '1.0.0',
    'triggered_at' => now(),
]);

TriggerAppBuild::dispatch($build);
```

### 2. Laravel job calls GitHub Actions
```php
// Jobs/TriggerAppBuild.php
class TriggerAppBuild implements ShouldQueue
{
    public function handle()
    {
        $response = Http::withToken(config('services.github.token'))
            ->post('https://api.github.com/repos/vapvarun/jetonomy-app/actions/workflows/build.yml/dispatches', [
                'ref' => 'main',
                'inputs' => [
                    'build_id'   => (string) $this->build->id,
                    'platform'   => $this->build->platform,
                    'config_url' => route('api.build-config', $this->build->customer_id),
                ],
            ]);

        $this->build->update(['status' => 'building']);
    }
}
```

### 3. GitHub Actions workflow
```yaml
# .github/workflows/build.yml (in jetonomy-app repo)
name: Build App
on:
  workflow_dispatch:
    inputs:
      build_id:
        required: true
      platform:
        required: true
      config_url:
        required: true

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: 20

      - name: Install dependencies
        run: npm ci

      - name: Fetch customer config
        run: |
          curl -s ${{ inputs.config_url }} > customer-config.json

      - name: Inject branding
        run: node scripts/inject-branding.js customer-config.json

      - name: Setup EAS
        uses: expo/expo-github-action@v8
        with:
          eas-version: latest
          token: ${{ secrets.EXPO_TOKEN }}

      - name: Build
        run: eas build --platform ${{ inputs.platform }} --non-interactive --no-wait

      - name: Notify server
        run: |
          curl -X POST https://app.jetonomy.com/webhooks/github/build-complete \
            -H "Content-Type: application/json" \
            -d '{"build_id": "${{ inputs.build_id }}", "status": "ready"}'
```

### 4. Webhook receives completion
```php
// WebhookController::buildComplete()
$build = Build::findOrFail($request->build_id);
$build->update([
    'status'       => $request->status, // ready or failed
    'download_url' => $request->download_url,
    'completed_at' => now(),
]);

// Notify customer via email
Mail::to($build->customer->email)->send(new AppBuildReady($build));
```

## Dashboard UI

### Login Page
```
┌─────────────────────────────────────┐
│                                     │
│       🏗️ Jetonomy App Builder       │
│                                     │
│   Enter your Pro license key        │
│   ┌───────────────────────────┐     │
│   │ XXXX-XXXX-XXXX-XXXX      │     │
│   └───────────────────────────┘     │
│                                     │
│   ┌───────────────────────────┐     │
│   │        Sign In            │     │
│   └───────────────────────────┘     │
│                                     │
│   Don't have Pro? Get it →          │
│                                     │
└─────────────────────────────────────┘
```

### Dashboard
```
┌─────────────────────────────────────────────┐
│  Jetonomy App Builder          [Logout]     │
│─────────────────────────────────────────────│
│                                             │
│  Your App: Course Academy Forum             │
│  Site: courseacademy.com                     │
│  License: Pro Growth (active)               │
│                                             │
│  ┌──────────────┐  ┌──────────────┐         │
│  │   Android     │  │     iOS      │         │
│  │   ✅ Ready    │  │   ✅ Ready   │         │
│  │  [Download]   │  │  [Download]  │         │
│  │  Built: 3/30  │  │  Built: 3/30 │         │
│  └──────────────┘  └──────────────┘         │
│                                             │
│  [⚙️ Edit Branding]  [🔄 Rebuild]           │
│                                             │
│  Build History                              │
│  ┌─────────────────────────────────────┐    │
│  │ v1.0 │ Android │ Ready  │ Mar 30   │    │
│  │ v1.0 │ iOS     │ Ready  │ Mar 30   │    │
│  └─────────────────────────────────────┘    │
│                                             │
└─────────────────────────────────────────────┘
```

### Branding Editor
```
┌─────────────────────────────────────────────┐
│  ← App Branding                             │
│─────────────────────────────────────────────│
│                                             │
│  App Name                                   │
│  ┌───────────────────────────────┐          │
│  │ Course Academy Forum          │          │
│  └───────────────────────────────┘          │
│                                             │
│  Accent Color    [████████] #3B82F6         │
│                                             │
│  App Icon (1024x1024)                       │
│  ┌─────────┐                                │
│  │  [img]  │  [Upload]                      │
│  └─────────┘                                │
│                                             │
│  Splash Screen                              │
│  ┌─────────┐                                │
│  │  [img]  │  [Upload]                      │
│  └─────────┘                                │
│                                             │
│  Dark Mode Default  [Toggle]                │
│                                             │
│  Preview:                                   │
│  ┌──────────┐                               │
│  │ [Phone   │                               │
│  │  mockup  │                               │
│  │  with    │                               │
│  │  colors] │                               │
│  └──────────┘                               │
│                                             │
│  [Save & Build]                             │
│                                             │
└─────────────────────────────────────────────┘
```

## Server Setup (DigitalOcean Droplet)

```
Ubuntu 24.04
├── Nginx (reverse proxy + SSL via Let's Encrypt)
├── PHP 8.3 + FPM
├── MySQL 8
├── Redis
├── Laravel Horizon (queue worker)
├── Supervisor (keeps Horizon running)
└── Certbot (auto-renew SSL)
```

**Deploy:** GitHub Actions → SSH → `git pull && composer install && php artisan migrate && php artisan horizon:terminate`

## Environment Variables

```env
APP_URL=https://app.jetonomy.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=jetonomy_app
DB_USERNAME=jetonomy
DB_PASSWORD=xxx

AWS_ACCESS_KEY_ID=xxx
AWS_SECRET_ACCESS_KEY=xxx
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=jetonomy-app-assets

GITHUB_TOKEN=ghp_xxx  # For triggering Actions
GITHUB_REPO=vapvarun/jetonomy-app

EDD_STORE_URL=https://store.wbcomdesigns.com
EDD_ITEM_NAME=Jetonomy Pro

MAIL_MAILER=smtp
MAIL_HOST=smtp.postmarkapp.com
```

## Development Phases

### Phase 1 — MVP (Week 1)
- [ ] Laravel project scaffold
- [ ] Customer model + migration
- [ ] EDD license validation
- [ ] Login / logout
- [ ] Dashboard page (static)

### Phase 2 — Branding (Week 2)
- [ ] AppConfig model + migration
- [ ] Branding form (name, icon, colors)
- [ ] S3 upload for assets
- [ ] Build config API endpoint

### Phase 3 — Build Pipeline (Week 3)
- [ ] Build model + migration
- [ ] GitHub Actions workflow in jetonomy-app repo
- [ ] Trigger build from dashboard
- [ ] Webhook for build completion
- [ ] Download links

### Phase 4 — Polish (Week 4)
- [ ] Email notifications (build ready, build failed)
- [ ] Build history page
- [ ] Rate limiting (2 builds/day)
- [ ] API endpoints for Pro plugin integration
- [ ] Documentation page for app store submission
- [ ] Deploy to droplet
