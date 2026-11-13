<?php

namespace App\Providers;

use App\Services\Cdn\ProxyRing;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }

        // One ring per request, shared by everything that signs a link in it. Scoped, not a
        // singleton: it memoizes rows `nodes:probe` rewrites every minute, and a singleton would
        // pin that memo past the request — php-fpm would not notice, Octane and the queue worker
        // would serve routing from a fleet that has since changed.
        $this->app->scoped(ProxyRing::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Request::macro('project', function () {
            $project = $this->attributes->get('resolved_project');
            abort_if(! $project, 400, 'Project context required. Send X-Project-Ulid header.');

            return $project;
        });

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }
}
