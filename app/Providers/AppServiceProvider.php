<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

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
        if ($this->runningOnVercel()) {
            URL::forceScheme('https');
        }
    }

    private function runningOnVercel(): bool
    {
        return (bool) (getenv('VERCEL') ?: ($_ENV['VERCEL'] ?? false));
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

        if (env('DB_CONNECTION', 'sqlite') !== 'sqlite') {
            return;
        }

        $sqliteTarget = env('DB_DATABASE', '/tmp/database.sqlite');
        if (! file_exists($sqliteTarget)) {
            $seed = database_path('vercel-seed.sqlite');
            if (file_exists($seed)) {
                copy($seed, $sqliteTarget);
            } else {
                touch($sqliteTarget);
            }
        }
    }
}
