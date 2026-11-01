<?php

declare(strict_types=1);

namespace Thijssensoftware\SnagClient\Context;

/**
 * The bearer snag presents when it asks for a request's context.
 *
 * It is derived from the ingest secret both sides already hold, so there is no third secret to
 * provision, but it is not the ingest secret and cannot be turned into a reporter signature: the
 * signing key is itself an HMAC of a fixed label, and the signed message names the app, the
 * request id and an expiry. A token that leaks (a proxy log, say) opens one request id for two
 * minutes, on one app.
 */
final class ContextToken
{
    public const VERSION = 'v1';

    public const MAX_LIFETIME = 300;

    public const GRACE = 60;

    public static function bearer(string $appKey, string $secret, string $requestId, int $expiresAt): string
    {
        return self::VERSION.'.'.$expiresAt.'.'.self::signature($appKey, $secret, $requestId, $expiresAt);
    }

    public static function verify(
        ?string $bearer,
        string $appKey,
        string $secret,
        string $requestId,
        ?int $now = null,
    ): bool {
        if ($bearer === null || preg_match('/^v1\.(\d{1,12})\.([0-9a-f]{64})$/D', $bearer, $parts) !== 1) {
            return false;
        }

        $now ??= time();
        $expiresAt = (int) $parts[1];

        if ($expiresAt <= $now - self::GRACE || $expiresAt > $now + self::MAX_LIFETIME) {
            return false;
        }

        return hash_equals(self::signature($appKey, $secret, $requestId, $expiresAt), $parts[2]);
    }

    /**
     * The id is lowercased inside the signature because the same ULID is written in capitals
     * by one library and lowercase by another, and the two sides must not disagree about it.
     */
    private static function signature(string $appKey, string $secret, string $requestId, int $expiresAt): string
    {
        $signingKey = hash_hmac('sha256', 'snag-context-'.self::VERSION, $secret);

        return hash_hmac('sha256', implode('|', [self::VERSION, $appKey, strtolower($requestId), $expiresAt]), $signingKey);
    }
}
