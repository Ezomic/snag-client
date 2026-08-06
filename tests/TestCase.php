<?php

declare(strict_types=1);

namespace Thijssensoftware\SnagClient\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Thijssensoftware\RequestId\RequestIdServiceProvider;
use Thijssensoftware\SnagClient\Snag;
use Thijssensoftware\SnagClient\SnagClientServiceProvider;

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
        ]);
    }
}
