# yt-dlp Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a Docker-ready internal dashboard for downloading YouTube videos via yt-dlp, generating square Plex-compatible thumbnails, tracking download history with metrics, and exporting to Unraid.

**Architecture:** Single Laravel 12 app with Inertia/Vue 3 SPA. Queued downloads processed by Laravel Horizon workers reading from Redis. 5-second polling for active queue status. Login-only auth with artisan-created admin user.

**Tech Stack:** PHP 8.4, Laravel 12, Inertia.js, Vue 3, Tailwind CSS v4, Laravel Horizon, Redis, MySQL 8, Docker Compose, yt-dlp, ffmpeg, Pest PHP

**Spec:** `docs/superpowers/specs/2026-05-06-yt-dlp-dashboard-design.md`

---

## File Map

| File | Purpose |
|---|---|
| `Dockerfile` | PHP 8.4 + nginx + ffmpeg + yt-dlp; single image for app + horizon |
| `docker-compose.yml` | app / horizon / redis / mysql |
| `docker/nginx/default.conf` | nginx site config (PHP-FPM upstream) |
| `docker/entrypoint.sh` | starts PHP-FPM + nginx for `app` service |
| `docker/horizon-entrypoint.sh` | runs `php artisan horizon` for `horizon` service |
| `.env.example` | all required env vars documented |
| `app/Enums/DownloadStatus.php` | `pending/processing/completed/failed` backed enum |
| `app/Models/Download.php` | Eloquent model with casts |
| `database/migrations/xxxx_create_downloads_table.php` | full schema including `thumbnail_url` |
| `database/factories/DownloadFactory.php` | includes `completed()` state |
| `app/Services/YtDlpService.php` | `getMetadata()` + `download()` via Process facade |
| `app/Services/ThumbnailService.php` | `generate()` — HTTP fetch + ffmpeg square crop |
| `app/Services/ExportService.php` | `export()` — rsync via Process facade |
| `app/Jobs/ProcessDownload.php` | queue job: yt-dlp download + thumbnail generation |
| `app/Http/Controllers/Auth/LoginController.php` | show / store / destroy |
| `app/Http/Controllers/VideoController.php` | index / preview / store / queue |
| `app/Http/Controllers/DownloadController.php` | index / destroy |
| `app/Http/Controllers/ExportController.php` | store |
| `app/Console/Commands/CreateAdmin.php` | `admin:create {email} {password}` |
| `routes/web.php` | all routes |
| `resources/views/app.blade.php` | Inertia root template |
| `resources/js/app.js` | Vue + Inertia bootstrap |
| `resources/js/Layouts/AppLayout.vue` | shared nav + logout |
| `resources/js/Pages/Login.vue` | email/password login form |
| `resources/js/Pages/Dashboard.vue` | URL input, preview card, active queue table |
| `resources/js/Pages/History.vue` | download history table with export/delete actions |
| `tests/Feature/Auth/LoginTest.php` | login flow |
| `tests/Feature/CreateAdminCommandTest.php` | artisan command |
| `tests/Feature/VideoControllerTest.php` | preview, submit, duplicate validation, queue poll |
| `tests/Feature/ProcessDownloadJobTest.php` | job success + failure |
| `tests/Feature/DownloadControllerTest.php` | history, delete |
| `tests/Feature/ExportControllerTest.php` | export trigger |
| `tests/Unit/YtDlpServiceTest.php` | getMetadata + download |
| `tests/Unit/ThumbnailServiceTest.php` | generate |
| `tests/Unit/ExportServiceTest.php` | rsync command construction |

---

### Task 1: Scaffold Laravel 12

**Files:**
- Create: all Laravel scaffold files

- [ ] **Step 1: Create Laravel 12 project in the current directory**

```bash
composer create-project laravel/laravel:^12.0 . --prefer-dist
```

Expected: `artisan`, `app/`, `routes/`, `resources/`, `composer.json` all present.

- [ ] **Step 2: Verify default tests pass**

```bash
php artisan test
```

Expected: All example tests pass.

- [ ] **Step 3: Commit scaffold**

```bash
git add -A
git commit -m "chore: scaffold Laravel 12"
```

---

### Task 2: Install PHP dependencies

**Files:**
- Modify: `composer.json`
- Create: `app/Http/Middleware/HandleInertiaRequests.php` (published)
- Create: `config/horizon.php` (published)

- [ ] **Step 1: Install Inertia server-side adapter**

```bash
composer require inertiajs/inertia-laravel
```

- [ ] **Step 2: Publish Inertia middleware**

```bash
php artisan inertia:middleware
```

Expected: `app/Http/Middleware/HandleInertiaRequests.php` created.

- [ ] **Step 3: Register Inertia middleware in `bootstrap/app.php`**

Open `bootstrap/app.php`. Inside `->withMiddleware(function (Middleware $middleware) {` add:

```php
$middleware->web(append: [
    \App\Http\Middleware\HandleInertiaRequests::class,
]);
```

- [ ] **Step 4: Install Laravel Horizon**

```bash
composer require laravel/horizon
php artisan horizon:install
```

Expected: `config/horizon.php` and `public/vendor/horizon/` created.

- [ ] **Step 5: Install Predis**

```bash
composer require predis/predis
```

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "chore: install inertia, horizon, predis"
```

---

### Task 3: Install JS dependencies and configure Vite/Tailwind/Vue

**Files:**
- Modify: `package.json`, `vite.config.js`, `resources/css/app.css`, `resources/js/app.js`
- Create: `resources/views/app.blade.php`

- [ ] **Step 1: Install Vue 3, Inertia Vue adapter, and Vite plugin**

```bash
npm install vue @inertiajs/vue3 @vitejs/plugin-vue
```

- [ ] **Step 2: Install Tailwind CSS v4**

```bash
npm install -D tailwindcss @tailwindcss/vite
```

- [ ] **Step 3: Replace `vite.config.js`**

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        tailwindcss(),
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
});
```

- [ ] **Step 4: Replace `resources/css/app.css`**

```css
@import "tailwindcss";
```

- [ ] **Step 5: Replace `resources/js/app.js`**

```js
import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

createInertiaApp({
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
```

- [ ] **Step 6: Create `resources/views/app.blade.php`**

```html
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>yt-dlp Dashboard</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead
    </head>
    <body class="bg-gray-50 text-gray-900">
        @inertia
    </body>
</html>
```

- [ ] **Step 7: Delete the default `resources/views/welcome.blade.php`**

```bash
rm resources/views/welcome.blade.php
```

- [ ] **Step 8: Verify build succeeds**

```bash
npm run build
```

Expected: `public/build/` created with manifest.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "chore: configure Vue 3, Inertia, Tailwind v4"
```

---

### Task 4: Docker infrastructure

**Files:**
- Create: `Dockerfile`, `docker-compose.yml`, `docker/nginx/default.conf`, `docker/entrypoint.sh`, `docker/horizon-entrypoint.sh`, `.dockerignore`
- Modify: `.env.example`

- [ ] **Step 1: Create `Dockerfile`**

```dockerfile
FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
    nginx \
    ffmpeg \
    python3 \
    curl \
    zip \
    unzip \
    git \
    nodejs \
    npm \
    && rm -rf /var/lib/apt/lists/*

RUN curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp \
    -o /usr/local/bin/yt-dlp && chmod a+rx /usr/local/bin/yt-dlp

RUN docker-php-ext-install pdo_mysql pcntl zip
RUN pecl install redis && docker-php-ext-enable redis

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-autoloader --no-dev

COPY . .
RUN composer dump-autoload --optimize

RUN npm ci && npm run build

COPY docker/nginx/default.conf /etc/nginx/sites-available/default

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/entrypoint.sh /entrypoint.sh
COPY docker/horizon-entrypoint.sh /horizon-entrypoint.sh
RUN chmod +x /entrypoint.sh /horizon-entrypoint.sh

EXPOSE 80
CMD ["/entrypoint.sh"]
```

- [ ] **Step 2: Create `docker/nginx/default.conf`**

```nginx
server {
    listen 80;
    server_name _;
    root /var/www/html/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

- [ ] **Step 3: Create `docker/entrypoint.sh`**

```bash
#!/bin/sh
set -e
php artisan migrate --force
php-fpm -D
exec nginx -g 'daemon off;'
```

- [ ] **Step 4: Create `docker/horizon-entrypoint.sh`**

```bash
#!/bin/sh
set -e
exec php artisan horizon
```

- [ ] **Step 5: Create `docker-compose.yml`**

```yaml
services:
  app:
    build: .
    ports:
      - "80:80"
    env_file: .env
    volumes:
      - ./storage/app/private/downloads:/var/www/html/storage/app/private/downloads
    depends_on:
      mysql:
        condition: service_healthy
      redis:
        condition: service_started

  horizon:
    build: .
    command: /horizon-entrypoint.sh
    env_file: .env
    volumes:
      - ./storage/app/private/downloads:/var/www/html/storage/app/private/downloads
    depends_on:
      - redis
      - mysql

  redis:
    image: redis:alpine
    volumes:
      - redis_data:/data

  mysql:
    image: mysql:8
    environment:
      MYSQL_DATABASE: ytdlp
      MYSQL_USER: ytdlp
      MYSQL_PASSWORD: secret
      MYSQL_ROOT_PASSWORD: rootsecret
    volumes:
      - mysql_data:/var/lib/mysql
    ports:
      - "3306:3306"
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 5s
      timeout: 5s
      retries: 10

volumes:
  redis_data:
  mysql_data:
```

- [ ] **Step 6: Create `.dockerignore`**

```
node_modules
.git
.env
storage/app/private/downloads
vendor
```

- [ ] **Step 7: Append required variables to `.env.example`**

Add these lines to the existing `.env.example`:

```env
# Queue (required)
QUEUE_CONNECTION=redis

# Redis (Docker service name)
REDIS_HOST=redis
REDIS_PORT=6379

# Database (Docker service name)
DB_HOST=mysql
DB_DATABASE=ytdlp
DB_USERNAME=ytdlp
DB_PASSWORD=secret

# Export / rsync
RSYNC_HOST=
RSYNC_USER=
RSYNC_DEST_PATH=
RSYNC_SSH_KEY_PATH=
```

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "chore: add Docker infrastructure"
```

---

### Task 5: DownloadStatus enum + Download model + migration + factory

**Files:**
- Create: `app/Enums/DownloadStatus.php`
- Create: `app/Models/Download.php`
- Create: `database/migrations/xxxx_create_downloads_table.php`
- Create: `database/factories/DownloadFactory.php`
- Create: `tests/Feature/DownloadModelTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/Feature/DownloadModelTest.php`:

```php
<?php

use App\Enums\DownloadStatus;
use App\Models\Download;

it('creates a download with pending status', function () {
    $download = Download::factory()->create([
        'youtube_url' => 'https://youtube.com/watch?v=abc123',
        'title' => 'Test Video',
        'channel' => 'Test Channel',
        'duration_seconds' => 120,
        'status' => DownloadStatus::Pending,
    ]);

    expect($download->status)->toBe(DownloadStatus::Pending)
        ->and($download->file_path)->toBeNull()
        ->and($download->exported_at)->toBeNull();
});

it('casts status as DownloadStatus enum', function () {
    $download = Download::factory()->create(['status' => DownloadStatus::Completed]);

    expect(Download::find($download->id)->status)->toBe(DownloadStatus::Completed);
});
```

- [ ] **Step 2: Run to verify failure**

```bash
php artisan test tests/Feature/DownloadModelTest.php
```

Expected: FAIL — class `DownloadStatus` not found.

- [ ] **Step 3: Create `app/Enums/DownloadStatus.php`**

```php
<?php

namespace App\Enums;

enum DownloadStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case Completed  = 'completed';
    case Failed     = 'failed';
}
```

- [ ] **Step 4: Generate and write migration**

```bash
php artisan make:migration create_downloads_table
```

Replace the generated `up()` method:

```php
public function up(): void
{
    Schema::create('downloads', function (Blueprint $table) {
        $table->id();
        $table->string('youtube_url');
        $table->string('title');
        $table->string('channel');
        $table->integer('duration_seconds');
        $table->string('thumbnail_url');
        $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
        $table->string('file_path')->nullable();
        $table->string('thumbnail_path')->nullable();
        $table->bigInteger('file_size_bytes')->unsigned()->nullable();
        $table->bigInteger('download_speed_bps')->unsigned()->nullable();
        $table->timestamp('started_at')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->timestamp('exported_at')->nullable();
        $table->text('error_message')->nullable();
        $table->timestamps();
    });
}
```

- [ ] **Step 5: Create `app/Models/Download.php`**

```php
<?php

namespace App\Models;

use App\Enums\DownloadStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Download extends Model
{
    use HasFactory;

    protected $fillable = [
        'youtube_url',
        'title',
        'channel',
        'duration_seconds',
        'thumbnail_url',
        'status',
        'file_path',
        'thumbnail_path',
        'file_size_bytes',
        'download_speed_bps',
        'started_at',
        'completed_at',
        'exported_at',
        'error_message',
    ];

    protected $casts = [
        'status'       => DownloadStatus::class,
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
        'exported_at'  => 'datetime',
    ];
}
```

- [ ] **Step 6: Create `database/factories/DownloadFactory.php`**

```php
<?php

namespace Database\Factories;

use App\Enums\DownloadStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

class DownloadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'youtube_url'       => 'https://youtube.com/watch?v=' . $this->faker->regexify('[A-Za-z0-9_-]{11}'),
            'title'             => $this->faker->sentence(4),
            'channel'           => $this->faker->company(),
            'duration_seconds'  => $this->faker->numberBetween(60, 3600),
            'thumbnail_url'     => 'https://i.ytimg.com/vi/abc/maxresdefault.jpg',
            'status'            => DownloadStatus::Pending,
            'file_path'         => null,
            'thumbnail_path'    => null,
            'file_size_bytes'   => null,
            'download_speed_bps' => null,
            'started_at'        => null,
            'completed_at'      => null,
            'exported_at'       => null,
            'error_message'     => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status'             => DownloadStatus::Completed,
            'file_path'          => 'downloads/1/video.mp4',
            'thumbnail_path'     => 'downloads/1/thumbnail.jpg',
            'file_size_bytes'    => 104857600,
            'download_speed_bps' => 1048576,
            'started_at'         => now()->subMinutes(2),
            'completed_at'       => now(),
        ]);
    }
}
```

- [ ] **Step 7: Run tests**

```bash
php artisan test tests/Feature/DownloadModelTest.php
```

Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: add Download model, enum, migration, factory"
```

---

### Task 6: Auth — login controller

**Files:**
- Create: `app/Http/Controllers/Auth/LoginController.php`
- Create: `tests/Feature/Auth/LoginTest.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/Auth/LoginTest.php`:

```php
<?php

use App\Models\User;

it('renders login page', function () {
    $this->get('/login')->assertStatus(200);
});

it('redirects authenticated user away from login', function () {
    $this->actingAs(User::factory()->create())->get('/login')->assertRedirect('/');
});

it('authenticates valid credentials', function () {
    $user = User::factory()->create([
        'email'    => 'admin@example.com',
        'password' => bcrypt('secret'),
    ]);

    $this->post('/login', ['email' => 'admin@example.com', 'password' => 'secret'])
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
});

it('rejects invalid credentials', function () {
    User::factory()->create(['email' => 'admin@example.com']);

    $this->post('/login', ['email' => 'admin@example.com', 'password' => 'wrong'])
        ->assertSessionHasErrors('email');
});

it('logs out authenticated user', function () {
    $this->actingAs(User::factory()->create())
        ->post('/logout')
        ->assertRedirect('/login');

    $this->assertGuest();
});

it('redirects unauthenticated user from dashboard to login', function () {
    $this->get('/')->assertRedirect('/login');
});
```

- [ ] **Step 2: Run to verify failure**

```bash
php artisan test tests/Feature/Auth/LoginTest.php
```

Expected: FAIL — routes not defined.

- [ ] **Step 3: Create `app/Http/Controllers/Auth/LoginController.php`**

```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function show(): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect('/');
        }

        return Inertia::render('Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (!Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'Invalid credentials.']);
        }

        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
```

- [ ] **Step 4: Write `routes/web.php`** (full file — will be extended in later tasks)

```php
<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\VideoController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', [VideoController::class, 'index'])->name('dashboard');
    Route::post('/videos/preview', [VideoController::class, 'preview']);
    Route::post('/videos', [VideoController::class, 'store']);
    Route::get('/api/queue', [VideoController::class, 'queue']);

    Route::get('/history', [DownloadController::class, 'index'])->name('history');
    Route::get('/downloads/{download}/thumbnail', [DownloadController::class, 'thumbnail']);
    Route::delete('/downloads/{download}', [DownloadController::class, 'destroy']);

    Route::post('/downloads/{download}/export', [ExportController::class, 'store']);
});
```

- [ ] **Step 5: Run tests**

```bash
php artisan test tests/Feature/Auth/LoginTest.php
```

Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add login/logout auth"
```

---

### Task 7: Admin artisan command

**Files:**
- Create: `app/Console/Commands/CreateAdmin.php`
- Create: `tests/Feature/CreateAdminCommandTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/CreateAdminCommandTest.php`:

```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates admin user', function () {
    $this->artisan('admin:create admin@example.com password123')
        ->assertSuccessful()
        ->expectsOutput('Admin user created: admin@example.com');

    $user = User::where('email', 'admin@example.com')->first();

    expect($user)->not->toBeNull()
        ->and(Hash::check('password123', $user->password))->toBeTrue()
        ->and($user->name)->toBe('Admin');
});

it('updates password when user already exists', function () {
    User::factory()->create(['email' => 'admin@example.com']);

    $this->artisan('admin:create admin@example.com newpassword')
        ->assertSuccessful()
        ->expectsOutput('Admin user updated: admin@example.com');

    expect(Hash::check('newpassword', User::where('email', 'admin@example.com')->value('password')))->toBeTrue();
});
```

- [ ] **Step 2: Run to verify failure**

```bash
php artisan test tests/Feature/CreateAdminCommandTest.php
```

Expected: FAIL — command not found.

- [ ] **Step 3: Create `app/Console/Commands/CreateAdmin.php`**

```php
<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdmin extends Command
{
    protected $signature   = 'admin:create {email} {password}';
    protected $description = 'Create or update the admin user';

    public function handle(): int
    {
        $email    = $this->argument('email');
        $password = $this->argument('password');
        $user     = User::where('email', $email)->first();

        if ($user) {
            $user->update(['password' => Hash::make($password)]);
            $this->info("Admin user updated: {$email}");
        } else {
            User::create([
                'name'     => 'Admin',
                'email'    => $email,
                'password' => Hash::make($password),
            ]);
            $this->info("Admin user created: {$email}");
        }

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test tests/Feature/CreateAdminCommandTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: add admin:create artisan command"
```

---

### Task 8: YtDlpService

**Files:**
- Create: `app/Services/YtDlpService.php`
- Create: `tests/Unit/YtDlpServiceTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/YtDlpServiceTest.php`:

```php
<?php

use App\Services\YtDlpService;
use Illuminate\Support\Facades\Process;

it('returns structured metadata from yt-dlp', function () {
    Process::fake([
        '*yt-dlp*' => Process::result(
            output: json_encode([
                'title'     => 'Test Video',
                'uploader'  => 'Test Channel',
                'duration'  => 245,
                'thumbnail' => 'https://i.ytimg.com/vi/abc/maxresdefault.jpg',
            ]),
            exitCode: 0
        ),
    ]);

    $metadata = (new YtDlpService())->getMetadata('https://youtube.com/watch?v=abc123');

    expect($metadata['title'])->toBe('Test Video')
        ->and($metadata['channel'])->toBe('Test Channel')
        ->and($metadata['duration'])->toBe(245)
        ->and($metadata['thumbnail'])->toBe('https://i.ytimg.com/vi/abc/maxresdefault.jpg');
});

it('throws RuntimeException when yt-dlp fails', function () {
    Process::fake([
        '*yt-dlp*' => Process::result(
            output: '',
            errorOutput: 'ERROR: Video unavailable',
            exitCode: 1
        ),
    ]);

    expect(fn () => (new YtDlpService())->getMetadata('https://youtube.com/watch?v=bad'))
        ->toThrow(RuntimeException::class, 'ERROR: Video unavailable');
});

it('downloads video and returns file path', function () {
    Process::fake([
        '*yt-dlp*' => Process::result(output: '', exitCode: 0),
    ]);

    $outputDir = sys_get_temp_dir() . '/ytdlp-test-' . uniqid();
    mkdir($outputDir, 0755, true);
    file_put_contents($outputDir . '/video.mp4', 'fake');

    $path = (new YtDlpService())->download('https://youtube.com/watch?v=abc123', $outputDir);

    expect($path)->toBe($outputDir . '/video.mp4');

    unlink($outputDir . '/video.mp4');
    rmdir($outputDir);
});

it('throws RuntimeException when download fails', function () {
    Process::fake([
        '*yt-dlp*' => Process::result(output: '', errorOutput: 'Download failed', exitCode: 1),
    ]);

    $outputDir = sys_get_temp_dir() . '/ytdlp-test-' . uniqid();
    mkdir($outputDir, 0755, true);

    expect(fn () => (new YtDlpService())->download('https://youtube.com/watch?v=bad', $outputDir))
        ->toThrow(RuntimeException::class, 'Download failed');

    rmdir($outputDir);
});
```

- [ ] **Step 2: Run to verify failure**

```bash
php artisan test tests/Unit/YtDlpServiceTest.php
```

Expected: FAIL — `YtDlpService` not found.

- [ ] **Step 3: Create `app/Services/YtDlpService.php`**

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class YtDlpService
{
    public function getMetadata(string $url): array
    {
        $result = Process::run([
            'yt-dlp', '--dump-json', '--no-playlist', $url,
        ]);

        if (!$result->successful()) {
            throw new RuntimeException($result->errorOutput() ?: 'yt-dlp failed');
        }

        $data = json_decode($result->output(), true);

        return [
            'title'     => $data['title'] ?? 'Unknown',
            'channel'   => $data['uploader'] ?? $data['channel'] ?? 'Unknown',
            'duration'  => (int) ($data['duration'] ?? 0),
            'thumbnail' => $data['thumbnail'] ?? '',
        ];
    }

    public function download(string $url, string $outputDir): string
    {
        $result = Process::run([
            'yt-dlp',
            '-f', 'bv*[ext=mp4]+ba[ext=m4a]/b[ext=mp4]/best',
            '--merge-output-format', 'mp4',
            '-o', $outputDir . '/video.%(ext)s',
            '--no-playlist',
            $url,
        ]);

        if (!$result->successful()) {
            throw new RuntimeException($result->errorOutput() ?: 'yt-dlp download failed');
        }

        return $outputDir . '/video.mp4';
    }
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test tests/Unit/YtDlpServiceTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: add YtDlpService"
```

---

### Task 9: ThumbnailService

**Files:**
- Create: `app/Services/ThumbnailService.php`
- Create: `tests/Unit/ThumbnailServiceTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/ThumbnailServiceTest.php`:

```php
<?php

use App\Services\ThumbnailService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

it('downloads thumbnail and crops to square', function () {
    Http::fake([
        'https://i.ytimg.com/*' => Http::response(str_repeat('x', 512), 200),
    ]);
    Process::fake([
        '*ffmpeg*' => Process::result(output: '', exitCode: 0),
    ]);

    $outputDir = sys_get_temp_dir() . '/thumb-test-' . uniqid();
    mkdir($outputDir, 0755, true);

    $path = (new ThumbnailService())->generate(
        'https://i.ytimg.com/vi/abc/maxresdefault.jpg',
        $outputDir
    );

    expect($path)->toBe($outputDir . '/thumbnail.jpg');

    if (file_exists($path)) unlink($path);
    rmdir($outputDir);
});

it('throws RuntimeException when ffmpeg fails', function () {
    Http::fake([
        'https://i.ytimg.com/*' => Http::response(str_repeat('x', 512), 200),
    ]);
    Process::fake([
        '*ffmpeg*' => Process::result(output: '', errorOutput: 'Invalid data', exitCode: 1),
    ]);

    $outputDir = sys_get_temp_dir() . '/thumb-test-' . uniqid();
    mkdir($outputDir, 0755, true);

    expect(fn () => (new ThumbnailService())->generate(
        'https://i.ytimg.com/vi/abc/maxresdefault.jpg',
        $outputDir
    ))->toThrow(RuntimeException::class);

    if (is_dir($outputDir)) rmdir($outputDir);
});
```

- [ ] **Step 2: Run to verify failure**

```bash
php artisan test tests/Unit/ThumbnailServiceTest.php
```

Expected: FAIL — `ThumbnailService` not found.

- [ ] **Step 3: Create `app/Services/ThumbnailService.php`**

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class ThumbnailService
{
    public function generate(string $thumbnailUrl, string $outputDir): string
    {
        $rawPath    = $outputDir . '/thumbnail_raw.jpg';
        $outputPath = $outputDir . '/thumbnail.jpg';

        $response = Http::get($thumbnailUrl);
        if (!$response->successful()) {
            throw new RuntimeException('Failed to download thumbnail');
        }
        file_put_contents($rawPath, $response->body());

        // Center-crop to square: take min(width, height) on each axis
        $result = Process::run([
            'ffmpeg', '-i', $rawPath,
            '-vf', "crop='min(iw,ih)':'min(iw,ih)'",
            '-y', $outputPath,
        ]);

        if (file_exists($rawPath)) {
            unlink($rawPath);
        }

        if (!$result->successful()) {
            throw new RuntimeException('ffmpeg crop failed: ' . $result->errorOutput());
        }

        return $outputPath;
    }
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test tests/Unit/ThumbnailServiceTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: add ThumbnailService"
```

---

### Task 10: VideoController

**Files:**
- Create: `app/Http/Controllers/VideoController.php`
- Create: `tests/Feature/VideoControllerTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/VideoControllerTest.php`:

```php
<?php

use App\Enums\DownloadStatus;
use App\Jobs\ProcessDownload;
use App\Models\Download;
use App\Models\User;
use App\Services\YtDlpService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders dashboard page', function () {
    $this->get('/')->assertStatus(200);
});

it('returns video preview metadata', function () {
    $mock = Mockery::mock(YtDlpService::class);
    $mock->shouldReceive('getMetadata')
        ->with('https://youtube.com/watch?v=abc123')
        ->andReturn([
            'title'     => 'My Video',
            'channel'   => 'My Channel',
            'duration'  => 300,
            'thumbnail' => 'https://i.ytimg.com/vi/abc/default.jpg',
        ]);
    app()->instance(YtDlpService::class, $mock);

    $this->postJson('/videos/preview', ['url' => 'https://youtube.com/watch?v=abc123'])
        ->assertOk()
        ->assertJson(['title' => 'My Video', 'channel' => 'My Channel', 'duration' => 300]);
});

it('returns 422 for invalid preview url', function () {
    $this->postJson('/videos/preview', ['url' => 'not-a-url'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');
});

it('creates download record and dispatches job', function () {
    Queue::fake();

    $mock = Mockery::mock(YtDlpService::class);
    $mock->shouldReceive('getMetadata')->andReturn([
        'title' => 'My Video', 'channel' => 'My Channel',
        'duration' => 300, 'thumbnail' => 'https://i.ytimg.com/vi/abc/default.jpg',
    ]);
    app()->instance(YtDlpService::class, $mock);

    $this->post('/videos', ['url' => 'https://youtube.com/watch?v=abc123'])
        ->assertRedirect('/');

    Queue::assertPushed(ProcessDownload::class);
    $this->assertDatabaseHas('downloads', [
        'youtube_url' => 'https://youtube.com/watch?v=abc123',
        'status'      => DownloadStatus::Pending->value,
    ]);
});

it('blocks submit when video is already queued', function () {
    Download::factory()->create([
        'youtube_url' => 'https://youtube.com/watch?v=abc123',
        'status'      => DownloadStatus::Processing,
    ]);

    $this->postJson('/videos', ['url' => 'https://youtube.com/watch?v=abc123'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');
});

it('returns 409 when video already completed', function () {
    Download::factory()->completed()->create([
        'youtube_url' => 'https://youtube.com/watch?v=abc123',
    ]);

    $this->postJson('/videos', ['url' => 'https://youtube.com/watch?v=abc123'])
        ->assertStatus(409)
        ->assertJson(['already_downloaded' => true]);
});

it('allows force re-download of completed video', function () {
    Queue::fake();

    Download::factory()->completed()->create([
        'youtube_url' => 'https://youtube.com/watch?v=abc123',
    ]);

    $mock = Mockery::mock(YtDlpService::class);
    $mock->shouldReceive('getMetadata')->andReturn([
        'title' => 'My Video', 'channel' => 'My Channel',
        'duration' => 300, 'thumbnail' => 'https://i.ytimg.com/vi/abc/default.jpg',
    ]);
    app()->instance(YtDlpService::class, $mock);

    $this->post('/videos', ['url' => 'https://youtube.com/watch?v=abc123', 'force' => true])
        ->assertRedirect('/');

    Queue::assertPushed(ProcessDownload::class);
});

it('returns only pending and processing downloads for queue poll', function () {
    Download::factory()->create(['status' => DownloadStatus::Pending]);
    Download::factory()->create(['status' => DownloadStatus::Processing]);
    Download::factory()->completed()->create();

    $this->getJson('/api/queue')
        ->assertOk()
        ->assertJsonCount(2);
});
```

- [ ] **Step 2: Run to verify failure**

```bash
php artisan test tests/Feature/VideoControllerTest.php
```

Expected: FAIL — controller not found.

- [ ] **Step 3: Create stub `app/Jobs/ProcessDownload.php`** (full implementation in Task 11)

```bash
php artisan make:job ProcessDownload
```

- [ ] **Step 4: Create `app/Http/Controllers/VideoController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Enums\DownloadStatus;
use App\Jobs\ProcessDownload;
use App\Models\Download;
use App\Services\YtDlpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VideoController extends Controller
{
    public function __construct(private YtDlpService $ytDlp) {}

    public function index(): Response
    {
        return Inertia::render('Dashboard');
    }

    public function preview(Request $request): JsonResponse
    {
        $request->validate(['url' => ['required', 'url']]);

        return response()->json($this->ytDlp->getMetadata($request->url));
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate(['url' => ['required', 'url']]);

        $url      = $request->url;
        $existing = Download::where('youtube_url', $url)->latest()->first();

        if ($existing) {
            if (in_array($existing->status, [DownloadStatus::Pending, DownloadStatus::Processing])) {
                return response()->json(
                    ['errors' => ['url' => ['This video is already in the queue.']]],
                    422
                );
            }

            if ($existing->status === DownloadStatus::Completed && !$request->boolean('force')) {
                return response()->json(['already_downloaded' => true], 409);
            }
        }

        $metadata = $this->ytDlp->getMetadata($url);

        $download = Download::create([
            'youtube_url'      => $url,
            'title'            => $metadata['title'],
            'channel'          => $metadata['channel'],
            'duration_seconds' => $metadata['duration'],
            'thumbnail_url'    => $metadata['thumbnail'],
            'status'           => DownloadStatus::Pending,
        ]);

        ProcessDownload::dispatch($download);

        return redirect('/');
    }

    public function queue(): JsonResponse
    {
        return response()->json(
            Download::whereIn('status', [DownloadStatus::Pending, DownloadStatus::Processing])
                ->orderBy('created_at')
                ->get(['id', 'title', 'channel', 'duration_seconds', 'status', 'created_at'])
        );
    }
}
```

- [ ] **Step 5: Run tests**

```bash
php artisan test tests/Feature/VideoControllerTest.php
```

Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add VideoController"
```

---

### Task 11: ProcessDownload job

**Files:**
- Modify: `app/Jobs/ProcessDownload.php`
- Create: `tests/Feature/ProcessDownloadJobTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/ProcessDownloadJobTest.php`:

```php
<?php

use App\Enums\DownloadStatus;
use App\Jobs\ProcessDownload;
use App\Models\Download;
use App\Services\ThumbnailService;
use App\Services\YtDlpService;

it('processes download successfully and updates model', function () {
    $download = Download::factory()->create([
        'youtube_url'   => 'https://youtube.com/watch?v=abc123',
        'thumbnail_url' => 'https://i.ytimg.com/vi/abc/default.jpg',
        'status'        => DownloadStatus::Pending,
    ]);

    $ytDlp = Mockery::mock(YtDlpService::class);
    $ytDlp->shouldReceive('download')->once()->andReturnUsing(function ($url, $dir) {
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $path = $dir . '/video.mp4';
        file_put_contents($path, str_repeat('x', 1024 * 1024));
        return $path;
    });

    $thumbnail = Mockery::mock(ThumbnailService::class);
    $thumbnail->shouldReceive('generate')->once()->andReturnUsing(function ($thumbnailUrl, $dir) {
        $path = $dir . '/thumbnail.jpg';
        file_put_contents($path, 'img');
        return $path;
    });

    app()->instance(YtDlpService::class, $ytDlp);
    app()->instance(ThumbnailService::class, $thumbnail);

    (new ProcessDownload($download))->handle($ytDlp, $thumbnail);

    $download->refresh();

    expect($download->status)->toBe(DownloadStatus::Completed)
        ->and($download->file_path)->not->toBeNull()
        ->and($download->thumbnail_path)->not->toBeNull()
        ->and($download->file_size_bytes)->toBe(1024 * 1024)
        ->and($download->download_speed_bps)->toBeGreaterThan(0)
        ->and($download->started_at)->not->toBeNull()
        ->and($download->completed_at)->not->toBeNull();
});

it('marks download as failed when yt-dlp throws', function () {
    $download = Download::factory()->create(['status' => DownloadStatus::Pending]);

    $ytDlp = Mockery::mock(YtDlpService::class);
    $ytDlp->shouldReceive('download')
        ->andThrow(new RuntimeException('Video unavailable'));

    $thumbnail = Mockery::mock(ThumbnailService::class);

    app()->instance(YtDlpService::class, $ytDlp);
    app()->instance(ThumbnailService::class, $thumbnail);

    expect(fn () => (new ProcessDownload($download))->handle($ytDlp, $thumbnail))
        ->toThrow(RuntimeException::class);

    $download->refresh();

    expect($download->status)->toBe(DownloadStatus::Failed)
        ->and($download->error_message)->toBe('Video unavailable');
});
```

- [ ] **Step 2: Run to verify failure**

```bash
php artisan test tests/Feature/ProcessDownloadJobTest.php
```

Expected: FAIL — job `handle()` is a stub.

- [ ] **Step 3: Replace `app/Jobs/ProcessDownload.php`**

```php
<?php

namespace App\Jobs;

use App\Enums\DownloadStatus;
use App\Models\Download;
use App\Services\ThumbnailService;
use App\Services\YtDlpService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessDownload implements ShouldQueue
{
    use Queueable;

    public function __construct(public Download $download) {}

    public function handle(YtDlpService $ytDlp, ThumbnailService $thumbnail): void
    {
        $this->download->update([
            'status'     => DownloadStatus::Processing,
            'started_at' => now(),
        ]);

        try {
            $outputDir = storage_path('app/private/downloads/' . $this->download->id);

            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            $filePath      = $ytDlp->download($this->download->youtube_url, $outputDir);
            $thumbnailPath = $thumbnail->generate($this->download->thumbnail_url, $outputDir);

            $fileSize    = filesize($filePath);
            $completedAt = now();
            $elapsed     = max(1, $completedAt->diffInSeconds($this->download->started_at));

            $this->download->update([
                'status'             => DownloadStatus::Completed,
                'file_path'          => 'downloads/' . $this->download->id . '/video.mp4',
                'thumbnail_path'     => 'downloads/' . $this->download->id . '/thumbnail.jpg',
                'file_size_bytes'    => $fileSize,
                'download_speed_bps' => (int) ($fileSize / $elapsed),
                'completed_at'       => $completedAt,
            ]);
        } catch (Throwable $e) {
            $this->download->update([
                'status'        => DownloadStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test tests/Feature/ProcessDownloadJobTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: implement ProcessDownload job"
```

---

### Task 12: ExportService

**Files:**
- Create: `app/Services/ExportService.php`
- Create: `tests/Unit/ExportServiceTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/ExportServiceTest.php`:

```php
<?php

use App\Enums\DownloadStatus;
use App\Models\Download;
use App\Services\ExportService;
use Illuminate\Support\Facades\Process;

it('runs rsync and sets exported_at on success', function () {
    Process::fake(['*rsync*' => Process::result(output: '', exitCode: 0)]);

    config([
        'export.rsync_host'     => 'unraid.local',
        'export.rsync_user'     => 'root',
        'export.rsync_dest'     => '/mnt/media/ytdlp',
        'export.rsync_key_path' => '/run/secrets/id_rsa',
    ]);

    $download = Download::factory()->completed()->create();

    (new ExportService())->export($download);

    $download->refresh();

    expect($download->exported_at)->not->toBeNull();

    Process::assertRan(fn ($process) => str_contains($process->command(), 'rsync'));
});

it('throws RuntimeException when rsync fails', function () {
    Process::fake(['*rsync*' => Process::result(output: '', errorOutput: 'Connection refused', exitCode: 255)]);

    config([
        'export.rsync_host'     => 'unraid.local',
        'export.rsync_user'     => 'root',
        'export.rsync_dest'     => '/mnt/media/ytdlp',
        'export.rsync_key_path' => '/run/secrets/id_rsa',
    ]);

    $download = Download::factory()->completed()->create();

    expect(fn () => (new ExportService())->export($download))
        ->toThrow(RuntimeException::class, 'Connection refused');
});
```

- [ ] **Step 2: Run to verify failure**

```bash
php artisan test tests/Unit/ExportServiceTest.php
```

Expected: FAIL — `ExportService` not found.

- [ ] **Step 3: Create `config/export.php`**

```php
<?php

return [
    'rsync_host'     => env('RSYNC_HOST', ''),
    'rsync_user'     => env('RSYNC_USER', ''),
    'rsync_dest'     => env('RSYNC_DEST_PATH', ''),
    'rsync_key_path' => env('RSYNC_SSH_KEY_PATH', ''),
];
```

- [ ] **Step 4: Create `app/Services/ExportService.php`**

```php
<?php

namespace App\Services;

use App\Models\Download;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class ExportService
{
    public function export(Download $download): void
    {
        $host    = config('export.rsync_host');
        $user    = config('export.rsync_user');
        $dest    = config('export.rsync_dest');
        $keyPath = config('export.rsync_key_path');

        $localDir = storage_path('app/private/downloads/' . $download->id) . '/';

        $result = Process::run([
            'rsync', '-avz',
            '-e', "ssh -i {$keyPath} -o StrictHostKeyChecking=no",
            $localDir,
            "{$user}@{$host}:{$dest}",
        ]);

        if (!$result->successful()) {
            throw new RuntimeException($result->errorOutput() ?: 'rsync failed');
        }

        $download->update(['exported_at' => now()]);
    }
}
```

- [ ] **Step 5: Run tests**

```bash
php artisan test tests/Unit/ExportServiceTest.php
```

Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add ExportService"
```

---

### Task 13: DownloadController

**Files:**
- Create: `app/Http/Controllers/DownloadController.php`
- Create: `tests/Feature/DownloadControllerTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/DownloadControllerTest.php`:

```php
<?php

use App\Enums\DownloadStatus;
use App\Models\Download;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders history page with downloads', function () {
    Download::factory()->count(3)->create();

    $this->get('/history')->assertStatus(200);
});

it('deletes download record and removes directory from disk', function () {
    $download = Download::factory()->completed()->create();

    $dir = storage_path('app/private/downloads/' . $download->id);
    mkdir($dir, 0755, true);
    file_put_contents($dir . '/video.mp4', 'fake');

    $this->delete("/downloads/{$download->id}")->assertRedirect('/history');

    $this->assertDatabaseMissing('downloads', ['id' => $download->id]);
    expect(is_dir($dir))->toBeFalse();
});

it('serves thumbnail from private storage', function () {
    $download = Download::factory()->completed()->create([
        'thumbnail_path' => 'downloads/' . 1 . '/thumbnail.jpg',
    ]);

    $dir = storage_path('app/private/downloads/' . $download->id);
    mkdir($dir, 0755, true);
    file_put_contents($dir . '/thumbnail.jpg', 'fake-img');

    $this->get("/downloads/{$download->id}/thumbnail")->assertOk();

    // Cleanup
    unlink($dir . '/thumbnail.jpg');
    rmdir($dir);
});

it('redirects unauthenticated user from history', function () {
    auth()->logout();
    $this->get('/history')->assertRedirect('/login');
});
```

- [ ] **Step 2: Run to verify failure**

```bash
php artisan test tests/Feature/DownloadControllerTest.php
```

Expected: FAIL — controller not found.

- [ ] **Step 3: Create `app/Http/Controllers/DownloadController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Download;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class DownloadController extends Controller
{
    public function index(): Response
    {
        $downloads = Download::orderByDesc('created_at')
            ->get([
                'id', 'title', 'channel', 'duration_seconds', 'thumbnail_path',
                'status', 'file_size_bytes', 'download_speed_bps',
                'completed_at', 'exported_at', 'error_message', 'created_at',
            ]);

        return Inertia::render('History', ['downloads' => $downloads]);
    }

    public function thumbnail(Download $download): HttpResponse
    {
        $path = storage_path('app/private/downloads/' . $download->id . '/thumbnail.jpg');
        abort_unless(file_exists($path), 404);

        return response()->file($path, ['Content-Type' => 'image/jpeg']);
    }

    public function destroy(Download $download): RedirectResponse
    {
        $dir = storage_path('app/private/downloads/' . $download->id);

        if (is_dir($dir)) {
            // Remove all files then the directory
            array_map('unlink', glob($dir . '/*'));
            rmdir($dir);
        }

        $download->delete();

        return redirect('/history');
    }
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test tests/Feature/DownloadControllerTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: add DownloadController"
```

---

### Task 14: ExportController

**Files:**
- Create: `app/Http/Controllers/ExportController.php`
- Create: `tests/Feature/ExportControllerTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/ExportControllerTest.php`:

```php
<?php

use App\Enums\DownloadStatus;
use App\Models\Download;
use App\Models\User;
use App\Services\ExportService;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('triggers export for completed download', function () {
    $download = Download::factory()->completed()->create();

    $mock = Mockery::mock(ExportService::class);
    $mock->shouldReceive('export')->once()->with(Mockery::on(
        fn ($d) => $d->id === $download->id
    ));
    app()->instance(ExportService::class, $mock);

    $this->post("/downloads/{$download->id}/export")->assertRedirect('/history');
});

it('returns 409 if download already exported', function () {
    $download = Download::factory()->completed()->create([
        'exported_at' => now(),
    ]);

    $this->postJson("/downloads/{$download->id}/export")->assertStatus(409);
});

it('returns 422 if download not yet completed', function () {
    $download = Download::factory()->create(['status' => DownloadStatus::Processing]);

    $this->postJson("/downloads/{$download->id}/export")->assertStatus(422);
});
```

- [ ] **Step 2: Run to verify failure**

```bash
php artisan test tests/Feature/ExportControllerTest.php
```

Expected: FAIL — controller not found.

- [ ] **Step 3: Create `app/Http/Controllers/ExportController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Enums\DownloadStatus;
use App\Models\Download;
use App\Services\ExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class ExportController extends Controller
{
    public function __construct(private ExportService $exporter) {}

    public function store(Download $download): JsonResponse|RedirectResponse
    {
        if ($download->exported_at) {
            return response()->json(['message' => 'Already exported.'], 409);
        }

        if ($download->status !== DownloadStatus::Completed) {
            return response()->json(['message' => 'Download not complete.'], 422);
        }

        $this->exporter->export($download);

        return redirect('/history');
    }
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test tests/Feature/ExportControllerTest.php
```

Expected: PASS

- [ ] **Step 5: Run the full test suite**

```bash
php artisan test
```

Expected: All tests pass.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add ExportController"
```

---

### Task 15: Login.vue

**Files:**
- Create: `resources/js/Pages/Login.vue`

- [ ] **Step 1: Create `resources/js/Pages/Login.vue`**

```vue
<script setup>
import { useForm } from '@inertiajs/vue3'

const form = useForm({
  email: '',
  password: '',
})

function submit() {
  form.post('/login')
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-100">
    <div class="w-full max-w-sm bg-white rounded-lg shadow p-8">
      <h1 class="text-xl font-semibold text-gray-800 mb-6">yt-dlp Dashboard</h1>

      <form @submit.prevent="submit" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
          <input
            v-model="form.email"
            type="email"
            autocomplete="email"
            class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
          <p v-if="form.errors.email" class="text-red-600 text-xs mt-1">{{ form.errors.email }}</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
          <input
            v-model="form.password"
            type="password"
            autocomplete="current-password"
            class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <button
          type="submit"
          :disabled="form.processing"
          class="w-full bg-blue-600 text-white rounded px-4 py-2 text-sm font-medium hover:bg-blue-700 disabled:opacity-50"
        >
          Sign in
        </button>
      </form>
    </div>
  </div>
</template>
```

- [ ] **Step 2: Verify build**

```bash
npm run build
```

Expected: No errors.

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "feat: add Login page"
```

---

### Task 16: AppLayout.vue

**Files:**
- Create: `resources/js/Layouts/AppLayout.vue`

- [ ] **Step 1: Create `resources/js/Layouts/AppLayout.vue`**

```vue
<script setup>
import { Link } from '@inertiajs/vue3'
</script>

<template>
  <div class="min-h-screen bg-gray-50">
    <nav class="bg-white border-b border-gray-200 px-6 py-3 flex items-center justify-between">
      <div class="flex items-center gap-6">
        <span class="font-semibold text-gray-800">yt-dlp Dashboard</span>
        <Link href="/" class="text-sm text-gray-600 hover:text-gray-900">Dashboard</Link>
        <Link href="/history" class="text-sm text-gray-600 hover:text-gray-900">History</Link>
      </div>
      <Link
        href="/logout"
        method="post"
        as="button"
        class="text-sm text-gray-500 hover:text-gray-800"
      >
        Sign out
      </Link>
    </nav>

    <main class="max-w-5xl mx-auto px-6 py-8">
      <slot />
    </main>
  </div>
</template>
```

- [ ] **Step 2: Verify build**

```bash
npm run build
```

Expected: No errors.

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "feat: add AppLayout"
```

---

### Task 17: Dashboard.vue

**Files:**
- Create: `resources/js/Pages/Dashboard.vue`

- [ ] **Step 1: Create `resources/js/Pages/Dashboard.vue`**

```vue
<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const url = ref('')
const preview = ref(null)
const previewError = ref('')
const isLoadingPreview = ref(false)
const isSubmitting = ref(false)
const submitError = ref('')
const showForceConfirm = ref(false)
const queue = ref([])

async function fetchPreview() {
  if (!url.value) return
  previewError.value = ''
  preview.value = null
  isLoadingPreview.value = true

  try {
    const res = await fetch('/videos/preview', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '' },
      body: JSON.stringify({ url: url.value }),
    })
    const data = await res.json()
    if (!res.ok) {
      previewError.value = data.errors?.url?.[0] ?? 'Failed to fetch preview.'
    } else {
      preview.value = data
    }
  } catch {
    previewError.value = 'Network error.'
  } finally {
    isLoadingPreview.value = false
  }
}

async function addToQueue(force = false) {
  isSubmitting.value = true
  submitError.value = ''

  try {
    const res = await fetch('/videos', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
        'X-Inertia': 'true',
      },
      body: JSON.stringify({ url: url.value, force }),
    })

    if (res.status === 409) {
      showForceConfirm.value = true
    } else if (!res.ok) {
      const data = await res.json()
      submitError.value = data.errors?.url?.[0] ?? 'Could not add to queue.'
    } else {
      url.value = ''
      preview.value = null
      showForceConfirm.value = false
      await pollQueue()
    }
  } finally {
    isSubmitting.value = false
  }
}

async function pollQueue() {
  const res = await fetch('/api/queue')
  if (res.ok) queue.value = await res.json()
}

function formatDuration(seconds) {
  const m = Math.floor(seconds / 60)
  const s = seconds % 60
  return `${m}:${String(s).padStart(2, '0')}`
}

let interval
onMounted(() => {
  pollQueue()
  interval = setInterval(pollQueue, 5000)
})
onUnmounted(() => clearInterval(interval))
</script>

<template>
  <AppLayout>
    <!-- URL Input -->
    <div class="bg-white rounded-lg border border-gray-200 p-6 mb-6">
      <h2 class="text-lg font-medium text-gray-800 mb-4">Add Video</h2>
      <div class="flex gap-2">
        <input
          v-model="url"
          type="url"
          placeholder="https://youtube.com/watch?v=..."
          class="flex-1 border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          @keydown.enter="fetchPreview"
        />
        <button
          @click="fetchPreview"
          :disabled="isLoadingPreview || !url"
          class="bg-gray-800 text-white rounded px-4 py-2 text-sm font-medium hover:bg-gray-900 disabled:opacity-50"
        >
          {{ isLoadingPreview ? 'Loading…' : 'Preview' }}
        </button>
      </div>
      <p v-if="previewError" class="text-red-600 text-sm mt-2">{{ previewError }}</p>
    </div>

    <!-- Preview Card -->
    <div v-if="preview" class="bg-white rounded-lg border border-gray-200 p-6 mb-6 flex gap-4">
      <img :src="preview.thumbnail" alt="thumbnail" class="w-32 h-32 object-cover rounded" />
      <div class="flex-1">
        <h3 class="font-medium text-gray-900">{{ preview.title }}</h3>
        <p class="text-sm text-gray-600 mt-1">{{ preview.channel }}</p>
        <p class="text-sm text-gray-500 mt-1">{{ formatDuration(preview.duration) }}</p>
        <p v-if="submitError" class="text-red-600 text-sm mt-2">{{ submitError }}</p>
        <button
          @click="addToQueue(false)"
          :disabled="isSubmitting"
          class="mt-3 bg-blue-600 text-white rounded px-4 py-2 text-sm font-medium hover:bg-blue-700 disabled:opacity-50"
        >
          Add to Queue
        </button>
      </div>
    </div>

    <!-- Force Confirm Modal -->
    <div v-if="showForceConfirm" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
      <div class="bg-white rounded-lg p-6 w-full max-w-sm shadow-xl">
        <h3 class="font-medium text-gray-900 mb-2">Already downloaded</h3>
        <p class="text-sm text-gray-600 mb-4">This video has already been downloaded. Download again?</p>
        <div class="flex gap-2 justify-end">
          <button @click="showForceConfirm = false" class="px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded">Cancel</button>
          <button @click="addToQueue(true)" class="px-4 py-2 text-sm bg-blue-600 text-white rounded hover:bg-blue-700">Download Again</button>
        </div>
      </div>
    </div>

    <!-- Active Queue -->
    <div class="bg-white rounded-lg border border-gray-200">
      <div class="px-6 py-4 border-b border-gray-100">
        <h2 class="text-lg font-medium text-gray-800">Queue</h2>
      </div>
      <div v-if="queue.length === 0" class="px-6 py-8 text-center text-sm text-gray-400">
        Queue is empty.
      </div>
      <table v-else class="w-full text-sm">
        <thead class="bg-gray-50 text-left text-xs text-gray-500 uppercase tracking-wide">
          <tr>
            <th class="px-6 py-3">Title</th>
            <th class="px-6 py-3">Channel</th>
            <th class="px-6 py-3">Duration</th>
            <th class="px-6 py-3">Status</th>
            <th class="px-6 py-3">Added</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="item in queue" :key="item.id">
            <td class="px-6 py-4 font-medium text-gray-900 max-w-xs truncate">{{ item.title }}</td>
            <td class="px-6 py-4 text-gray-600">{{ item.channel }}</td>
            <td class="px-6 py-4 text-gray-500">{{ formatDuration(item.duration_seconds) }}</td>
            <td class="px-6 py-4">
              <span
                class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                :class="{
                  'bg-yellow-100 text-yellow-800': item.status === 'pending',
                  'bg-blue-100 text-blue-800': item.status === 'processing',
                }"
              >{{ item.status }}</span>
            </td>
            <td class="px-6 py-4 text-gray-400">{{ new Date(item.created_at).toLocaleString() }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </AppLayout>
</template>
```

- [ ] **Step 2: Add CSRF token meta tag to `resources/views/app.blade.php`**

In the `<head>` section, after the charset meta tag, add:

```html
<meta name="csrf-token" content="{{ csrf_token() }}">
```

- [ ] **Step 3: Verify build**

```bash
npm run build
```

Expected: No errors.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "feat: add Dashboard page"
```

---

### Task 18: History.vue

**Files:**
- Create: `resources/js/Pages/History.vue`

- [ ] **Step 1: Create `resources/js/Pages/History.vue`**

```vue
<script setup>
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
  downloads: Array,
})

function formatBytes(bytes) {
  if (!bytes) return '—'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

function formatSpeed(bps) {
  if (!bps) return '—'
  return (bps / (1024 * 1024)).toFixed(2) + ' MB/s'
}

function formatDuration(seconds) {
  const m = Math.floor(seconds / 60)
  const s = seconds % 60
  return `${m}:${String(s).padStart(2, '0')}`
}

function formatDate(dt) {
  if (!dt) return '—'
  return new Date(dt).toLocaleString()
}

function deleteDownload(id) {
  if (!confirm('Delete this download? Files will be removed.')) return
  router.delete(`/downloads/${id}`)
}

function exportDownload(id) {
  router.post(`/downloads/${id}/export`)
}
</script>

<template>
  <AppLayout>
    <div class="bg-white rounded-lg border border-gray-200">
      <div class="px-6 py-4 border-b border-gray-100">
        <h2 class="text-lg font-medium text-gray-800">Download History</h2>
      </div>

      <div v-if="downloads.length === 0" class="px-6 py-8 text-center text-sm text-gray-400">
        No downloads yet.
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-gray-50 text-left text-xs text-gray-500 uppercase tracking-wide">
            <tr>
              <th class="px-4 py-3">Thumbnail</th>
              <th class="px-4 py-3">Title</th>
              <th class="px-4 py-3">Channel</th>
              <th class="px-4 py-3">Duration</th>
              <th class="px-4 py-3">Size</th>
              <th class="px-4 py-3">Speed</th>
              <th class="px-4 py-3">Downloaded</th>
              <th class="px-4 py-3">Status</th>
              <th class="px-4 py-3">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="dl in downloads" :key="dl.id">
              <td class="px-4 py-3">
                <img
                  v-if="dl.thumbnail_path"
                  :src="`/downloads/${dl.id}/thumbnail`"
                  class="w-12 h-12 object-cover rounded"
                  alt=""
                />
                <div v-else class="w-12 h-12 bg-gray-100 rounded" />
              </td>
              <td class="px-4 py-3 font-medium text-gray-900 max-w-xs truncate">{{ dl.title }}</td>
              <td class="px-4 py-3 text-gray-600">{{ dl.channel }}</td>
              <td class="px-4 py-3 text-gray-500">{{ formatDuration(dl.duration_seconds) }}</td>
              <td class="px-4 py-3 text-gray-500">{{ formatBytes(dl.file_size_bytes) }}</td>
              <td class="px-4 py-3 text-gray-500">{{ formatSpeed(dl.download_speed_bps) }}</td>
              <td class="px-4 py-3 text-gray-400 whitespace-nowrap">{{ formatDate(dl.completed_at) }}</td>
              <td class="px-4 py-3">
                <span
                  class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                  :class="{
                    'bg-yellow-100 text-yellow-800': dl.status === 'pending',
                    'bg-blue-100 text-blue-800': dl.status === 'processing',
                    'bg-green-100 text-green-800': dl.status === 'completed',
                    'bg-red-100 text-red-800': dl.status === 'failed',
                  }"
                >{{ dl.status }}</span>
              </td>
              <td class="px-4 py-3 whitespace-nowrap">
                <button
                  @click="exportDownload(dl.id)"
                  :disabled="!!dl.exported_at || dl.status !== 'completed'"
                  class="text-xs text-blue-600 hover:text-blue-800 disabled:text-gray-300 disabled:cursor-not-allowed mr-3"
                >
                  {{ dl.exported_at ? 'Exported' : 'Export' }}
                </button>
                <button
                  @click="deleteDownload(dl.id)"
                  class="text-xs text-red-600 hover:text-red-800"
                >
                  Delete
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppLayout>
</template>
```

- [ ] **Step 2: Verify build**

```bash
npm run build
```

Expected: No errors.

- [ ] **Step 4: Run full test suite**

```bash
php artisan test
```

Expected: All tests pass.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: add History page"
```

> **Note on thumbnails:** Served via authenticated `GET /downloads/{id}/thumbnail` (streams from private storage). History.vue uses `/downloads/${dl.id}/thumbnail` as the image src — no public symlink needed.

---

### Task 19: Configure Horizon and queue settings

**Files:**
- Modify: `config/horizon.php`
- Modify: `.env` / `.env.example`

- [ ] **Step 1: Set queue driver to redis in `.env.example`** (already done in Task 4 — verify it's present)

```bash
grep "QUEUE_CONNECTION=redis" .env.example
```

Expected: line found.

- [ ] **Step 2: Configure Horizon environments in `config/horizon.php`**

Find the `'environments'` key and replace its value with:

```php
'environments' => [
    'production' => [
        'supervisor-1' => [
            'maxProcesses' => 5,
            'balanceMaxShift' => 1,
            'balanceCooldown' => 3,
        ],
    ],
    'local' => [
        'supervisor-1' => [
            'maxProcesses' => 3,
        ],
    ],
],
```

- [ ] **Step 3: Verify Horizon dashboard route is accessible** — Horizon ships with a `/horizon` route protected by the `HorizonServiceProvider`. In production it restricts to specific emails. For this internal tool, add the admin email to the `gate()` in `app/Providers/AppServiceProvider.php`:

```php
use Laravel\Horizon\Horizon;

public function boot(): void
{
    Horizon::auth(function ($request) {
        return auth()->check();
    });
}
```

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore: configure Horizon for production"
```

---

### Task 20: Final verification

- [ ] **Step 1: Run complete test suite**

```bash
php artisan test
```

Expected: All tests pass.

- [ ] **Step 2: Build frontend assets**

```bash
npm run build
```

Expected: No errors.

- [ ] **Step 3: Verify Docker build** (requires Docker installed)

```bash
docker compose build
```

Expected: Image builds successfully.

- [ ] **Step 4: Smoke test Docker stack**

```bash
docker compose up -d
```

Wait ~15 seconds for MySQL to be healthy, then:

```bash
docker compose exec app php artisan admin:create admin@example.com secret
```

Open `http://localhost` in a browser. Verify:
- Redirected to `/login`
- Login with `admin@example.com` / `secret` → lands on Dashboard
- Paste a YouTube URL → Preview card appears
- Add to Queue → row appears in active queue table
- Check History page → completed download appears with metrics

- [ ] **Step 5: Final commit**

```bash
git add -A
git commit -m "chore: final verification and cleanup"
```

---

## Setup Instructions (for `README.md`)

Add these instructions to a `README.md` in the project root:

```markdown
## Setup

1. Copy `.env.example` to `.env` and fill in:
   - `APP_KEY` — generate with `php artisan key:generate --show`
   - `RSYNC_HOST`, `RSYNC_USER`, `RSYNC_DEST_PATH`, `RSYNC_SSH_KEY_PATH` (for export)

2. Build and start:
   ```bash
   docker compose up -d --build
   ```

3. Create admin user:
   ```bash
   docker compose exec app php artisan admin:create your@email.com yourpassword
   ```

4. Open http://localhost
```
