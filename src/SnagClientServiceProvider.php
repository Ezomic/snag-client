<?php

declare(strict_types=1);

namespace Thijssensoftware\SnagClient;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Thijssensoftware\SnagClient\Context\ContextRecorder;
use Thijssensoftware\SnagClient\Context\RequestStats;
use Thijssensoftware\SnagClient\Http\ContextController;

class SnagClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/snag-client.php', 'snag-client');

        $this->app->scoped(RequestStats::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/snag-client.php' => config_path('snag-client.php'),
        ], 'snag-client-config');

        $this->registerDirective();
        $this->registerContext();
    }

    /**
     * Global middleware and a route of the package's own, because the request a report is about
     * is as often an API call as a page, and neither belongs to a group the host app controls.
     * The route sits outside every group on purpose: no session, no cookies, no CSRF, only a
     * bearer.
     */
    private function registerContext(): void
    {
        Event::listen(QueryExecuted::class, static function (): void {
            app(RequestStats::class)->queries++;
        });

        RateLimiter::for('snag-client-context', static fn (Request $request): Limit => Limit::perMinute(60)->by((string) $request->ip()));

        $this->app->booted(function (): void {
            $kernel = $this->app->make(HttpKernel::class);

            if ($kernel instanceof FoundationHttpKernel) {
                $kernel->pushMiddleware(ContextRecorder::class);
            }
        });

        Route::get('_snag/context/{requestId?}', ContextController::class)
            ->where('requestId', '.*')
            ->middleware('throttle:snag-client-context')
            ->name('snag-client.context');
    }

    /**
     * One directive for both halves of the estate.
     *
     * A Blade directive rather than a Vue component or an npm package because the sixteen apps
     * are a mix of Blade with Alpine and Inertia with Vue, and both kinds render a Blade layout.
     * This is the one place that reaches all of them.
     */
    private function registerDirective(): void
    {
        Blade::directive('snag', fn (): string => '<?php echo '.Widget::class.'::render(); ?>');
    }
}
