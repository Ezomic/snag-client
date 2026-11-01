<?php

declare(strict_types=1);

namespace Thijssensoftware\SnagClient\Context;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Request context lives in the host app's own cache and nowhere else, under a ttl, so it
 * expires by itself and there is nothing long term to protect, back up or purge.
 */
final class ContextStore
{
    /**
     * Only the ids the request-id package can produce, lowercased so the key is one key.
     */
    public static function normalize(string $requestId): ?string
    {
        if (strlen($requestId) > 36 || ! (Str::isUlid($requestId) || Str::isUuid($requestId))) {
            return null;
        }

        return strtolower($requestId);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function put(string $requestId, array $context): void
    {
        // add(), not put(): a client can send any valid id in X-Request-Id, and it must not be
        // able to overwrite what was recorded for someone else's request.
        $this->cache()->add($this->key($requestId), $context, ContextSettings::TTL);
    }

    /**
     * @return array<mixed>|null
     */
    public function get(string $requestId): ?array
    {
        $stored = $this->cache()->get($this->key($requestId));

        return is_array($stored) ? $stored : null;
    }

    private function key(string $requestId): string
    {
        return 'snag-client:context:'.ContextSettings::key().':'.strtolower($requestId);
    }

    private function cache(): Repository
    {
        return Cache::store(ContextSettings::store());
    }
}
