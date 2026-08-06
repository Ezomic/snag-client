<?php

declare(strict_types=1);

namespace Thijssensoftware\SnagClient;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class SnagClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/snag-client.php', 'snag-client');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/snag-client.php' => config_path('snag-client.php'),
        ], 'snag-client-config');

        $this->registerDirective();
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
