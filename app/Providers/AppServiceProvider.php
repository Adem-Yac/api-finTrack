<?php

namespace App\Providers;

use App\Models\AppNotification;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->runningOnVercel()) {
            $this->configureVercelFilesystem();
        }
    }

    public function boot(): void
    {
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(8)->by((string) $request->ip());
        });

        Route::bind('notification', fn (string $value) => AppNotification::query()->findOrFail($value));

        if (! $this->runningOnVercel()) {
            return;
        }

        URL::forceScheme('https');

        if (! $this->app->runningInConsole()) {
            $this->bootstrapSqliteIfNeeded();
        }
    }

    private function runningOnVercel(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        return getenv('VERCEL') === '1';
    }

    private function configureVercelFilesystem(): void
    {
        $storage = '/tmp/storage';
        foreach ([
            $storage.'/framework/cache/data',
            $storage.'/framework/sessions',
            $storage.'/framework/views',
            $storage.'/logs',
            $storage.'/app',
        ] as $dir) {
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        $this->app->useStoragePath($storage);
    }

    private function bootstrapSqliteIfNeeded(): void
    {
        if (config('database.default') !== 'sqlite') {
            return;
        }

        $sqliteTarget = (string) config('database.connections.sqlite.database');
        if ($sqliteTarget === '' || $sqliteTarget === ':memory:') {
            return;
        }

        if (! file_exists($sqliteTarget)) {
            $seed = database_path('vercel-seed.sqlite');
            if (file_exists($seed)) {
                copy($seed, $sqliteTarget);
            } else {
                touch($sqliteTarget);
            }
        }

        try {
            if (! Schema::hasTable('users')) {
                Artisan::call('migrate', ['--force' => true]);
                Artisan::call('db:seed', ['--force' => true]);
            }
        } catch (Throwable) {
            // Cold start without a writable database must not take the API down.
        }
    }
}
