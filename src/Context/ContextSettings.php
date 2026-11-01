<?php

declare(strict_types=1);

namespace Thijssensoftware\SnagClient\Context;

use Illuminate\Support\Facades\Config;

final class ContextSettings
{
    public const TTL = 900;

    public static function active(): bool
    {
        return Config::get('snag-client.enabled') === true
            && Config::get('snag-client.context.enabled', true) !== false
            && self::key() !== ''
            && self::secret() !== '';
    }

    public static function key(): string
    {
        $key = Config::get('snag-client.key');

        return is_string($key) ? $key : '';
    }

    public static function secret(): string
    {
        $secret = Config::get('snag-client.secret');

        return is_string($secret) ? $secret : '';
    }

    public static function store(): ?string
    {
        $store = Config::get('snag-client.context.store');

        return is_string($store) && $store !== '' ? $store : null;
    }
}
