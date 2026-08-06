<?php

declare(strict_types=1);

namespace Thijssensoftware\SnagClient;

use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Thijssensoftware\RequestId\RequestIdContext;

/**
 * Everything the host app has to decide before the widget can be trusted.
 *
 * The widget itself is served by snag and is the same bundle everywhere. What it cannot do is
 * vouch for who is holding it: only the host app knows that, and only the host app is allowed
 * to know it. So the pseudonym and the signature over it are computed here, server-side, on
 * every page render.
 */
class Snag
{
    /**
     * @var Closure(): (string|int|null)|null
     */
    private static ?Closure $reporterResolver = null;

    /**
     * Override how the reporter is identified.
     *
     * The default is the authenticated user's id, which is right for the sixteen apps this was
     * written for, since all of them put the widget behind their own login. An app with a
     * different notion of a reporter (a session for an anonymous visitor, a customer rather
     * than a staff member) says so here rather than forking the package.
     *
     * @param  Closure(): (string|int|null)  $resolver
     */
    public static function resolveReporterUsing(Closure $resolver): void
    {
        self::$reporterResolver = $resolver;
    }

    public static function forgetReporterResolver(): void
    {
        self::$reporterResolver = null;
    }

    /**
     * Whether there is anything to render.
     *
     * Deliberately strict. A half-configured widget that posts unsigned reports, or one that
     * renders for a visitor nobody can identify, is worse than no widget: it produces reports
     * that cannot be triaged and cannot be answered.
     */
    public static function shouldRender(): bool
    {
        return Config::get('snag-client.enabled') === true
            && is_string(Config::get('snag-client.url'))
            && Config::get('snag-client.url') !== ''
            && is_string(Config::get('snag-client.key'))
            && Config::get('snag-client.key') !== ''
            && self::secret() !== ''
            && self::salt() !== ''
            && self::reporterIdentifier() !== null;
    }

    /**
     * The attributes the widget bundle reads off its own script tag.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        $reference = self::reporterReference();
        $expiresAt = time() + self::ttl();

        $attributes = [
            'src' => self::url().'/widget.js',
            'data-snag-key' => (string) Config::get('snag-client.key'),
            'data-snag-reporter' => $reference,
            'data-snag-signature' => self::sign($reference, $expiresAt),
            'data-snag-expires' => (string) $expiresAt,
            'data-snag-request-id' => app(RequestIdContext::class)->current(),
            'data-snag-locale' => self::locale(),
        ];

        $release = Config::get('snag-client.release');

        if (is_string($release) && $release !== '') {
            $attributes['data-snag-release'] = $release;
        }

        return $attributes;
    }

    /**
     * An opaque, stable pseudonym for the reporter.
     *
     * Hashed with a salt snag has never seen, which is the part that matters. snag necessarily
     * holds the ingest secret in order to verify the signature below; if this used that same
     * secret, snag could hash the integers upwards and recover a user id in seconds. The whole
     * identity boundary rests on these being two different secrets.
     */
    public static function reporterReference(): string
    {
        return hash_hmac('sha256', (string) self::reporterIdentifier(), self::salt());
    }

    /**
     * Proves this app vouched for that pseudonym until that moment.
     *
     * snag recomputes this with the shared ingest secret. It cannot recompute the pseudonym
     * itself, and does not need to.
     */
    public static function sign(string $reference, int $expiresAt): string
    {
        return hash_hmac('sha256', $reference.'|'.$expiresAt, self::secret());
    }

    /**
     * The origin an app's Content-Security-Policy has to allow, in script-src and connect-src.
     *
     * Returned rather than documented as a string, so a policy is built from the same config
     * value the widget is loaded from and the two cannot drift apart.
     */
    public static function cspSources(): string
    {
        return self::url();
    }

    private static function reporterIdentifier(): string|int|null
    {
        if (self::$reporterResolver instanceof Closure) {
            return (self::$reporterResolver)();
        }

        return Auth::id();
    }

    private static function url(): string
    {
        return rtrim((string) Config::get('snag-client.url'), '/');
    }

    private static function secret(): string
    {
        return (string) Config::get('snag-client.secret');
    }

    private static function salt(): string
    {
        return (string) Config::get('snag-client.pseudonym_salt');
    }

    private static function locale(): string
    {
        $locale = Config::get('snag-client.locale');

        return is_string($locale) && $locale !== '' ? $locale : app()->getLocale();
    }

    /**
     * Clamped, because snag rejects a signature that has expired and a host app that issued a
     * ten-year one would have quietly turned a short-lived token into a permanent credential.
     */
    private static function ttl(): int
    {
        $ttl = (int) Config::get('snag-client.ttl', 3600);

        return max(60, min($ttl, 86400));
    }
}
