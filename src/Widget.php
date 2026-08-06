<?php

declare(strict_types=1);

namespace Thijssensoftware\SnagClient;

use Illuminate\Support\HtmlString;

/**
 * The script tag itself.
 *
 * Kept apart from Snag so the decision of what to say and the business of writing HTML are not
 * the same class, and so the markup can be asserted without a rendered Blade view.
 */
class Widget
{
    public static function render(): HtmlString
    {
        if (! Snag::shouldRender()) {
            // Silently nothing. A host app that has not been rolled out yet, or a page nobody
            // is signed in to, should look exactly as it did before the package was installed.
            return new HtmlString('');
        }

        return new HtmlString('<script defer '.self::attributes().'></script>');
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
