<?php

declare(strict_types=1);

namespace Thijssensoftware\SnagClient;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\HtmlString;

/**
 * The script tag itself.
 *
 * Kept apart from Snag so the decision of what to say and the business of writing HTML are not
 * the same class, and so the markup can be asserted without a rendered Blade view.
 */
class Widget
{
    /**
     * Keeps what goes wrong before the widget bundle arrives.
     *
     * The bundle loads deferred and from snag's origin, so an error the host app throws while it
     * boots (a mount that fails, a module that throws on import) happens before any collector
     * exists, and those are often the ones a report is about. This runs inline while the page is
     * still parsing and keeps the first fifty uncaught errors and rejections on
     * window.__snagEarly. The widget takes them over at boot and calls stop(), so nothing is
     * recorded twice. A widget from before the shim never drains it, which the cap keeps harmless.
     */
    private const SHIM = <<<'JS'
        (function (w) {
            if (w.__snagEarly) return;
            var events = [];
            function keep(type, value) { if (events.length < 50) events.push({ type: type, value: value, at: Date.now() }); }
            function onError(e) { keep('error', e.error != null ? e.error : e.message); }
            function onRejection(e) { keep('rejection', e.reason); }
            w.addEventListener('error', onError);
            w.addEventListener('unhandledrejection', onRejection);
            w.__snagEarly = { events: events, stop: function () { w.removeEventListener('error', onError); w.removeEventListener('unhandledrejection', onRejection); } };
        })(window);
        JS;

    public static function render(): HtmlString
    {
        if (! Snag::shouldRender()) {
            // Silently nothing. A host app that has not been rolled out yet, or a page nobody
            // is signed in to, should look exactly as it did before the package was installed.
            return new HtmlString('');
        }

        return new HtmlString(self::shim().'<script defer '.self::attributes().'></script>');
    }

    /**
     * Carries the app's CSP nonce when it has one, since a policy without 'unsafe-inline' refuses
     * an inline script that does not. Without a nonce such a policy blocks the shim and the
     * widget still works; it only misses what broke before it booted.
     */
    private static function shim(): string
    {
        $nonce = Vite::cspNonce();

        $attribute = is_string($nonce) && $nonce !== ''
            ? ' nonce="'.htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8').'"'
            : '';

        return '<script'.$attribute.'>'.self::SHIM.'</script>';
    }

    private static function attributes(): string
    {
        $parts = [];

        foreach (Snag::attributes() as $name => $value) {
            // Every value here is either ours or derived from a hash, but the release string and
            // the locale come from an app's own config, and escaping the lot is cheaper than
            // reasoning about which of them could ever contain a quote.
            $parts[] = $name.'="'.htmlspecialchars($value, ENT_QUOTES, 'UTF-8').'"';
        }

        return implode(' ', $parts);
    }
}
