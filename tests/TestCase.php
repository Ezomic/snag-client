<?php

declare(strict_types=1);

namespace Thijssensoftware\SnagClient\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as Orchestra;
use Thijssensoftware\RequestId\RequestIdServiceProvider;
use Thijssensoftware\SnagClient\Snag;
use Thijssensoftware\SnagClient\SnagClientServiceProvider;
use Thijssensoftware\SnagClient\Tests\Support\OrdersController;

abstract class TestCase extends Orchestra
{
    protected function tearDown(): void
    {
        Snag::forgetReporterResolver();

        parent::tearDown();
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [RequestIdServiceProvider::class, SnagClientServiceProvider::class];
    }

    /**
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('/orders/{order}', [OrdersController::class, 'show'])->name('orders.show');

        $router->get('/plain', fn (): string => 'ok');
    }

    /**
     * A fully configured app, since almost every test is about what happens once it is.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('snag-client', [
            'enabled' => true,
            'url' => 'https://snag.thijssensoftware.nl',
            'key' => 'billr',
            'secret' => 'ingest-secret',
            'pseudonym_salt' => 'salt-snag-never-sees',
            'ttl' => 3600,
            'release' => 'a1b2c3d',
            'locale' => null,
            'context' => [
                'enabled' => true,
                'store' => null,
            ],
        ]);
    }
}
