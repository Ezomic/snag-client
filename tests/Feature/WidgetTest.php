<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\Process\ExecutableFinder;
use Thijssensoftware\SnagClient\Snag;
use Thijssensoftware\SnagClient\Widget;

function withReporter(string|int|null $id = 42): void
{
    Snag::resolveReporterUsing(fn (): string|int|null => $id);
}

describe('what the widget renders', function () {
    it('prints a deferred script tag pointing at snag', function () {
        withReporter();

        $html = (string) Widget::render();

        expect($html)->toContain('<script defer ')
            ->and($html)->toContain('src="https://snag.thijssensoftware.nl/widget.js"')
            ->and($html)->toContain('data-snag-key="billr"');
    });

    it('carries everything the bundle needs to boot', function () {
        // The widget reads these off its own script tag and refuses to start without the first
        // four, so a missing one is a silent no-widget rather than an error anyone would see.
        withReporter();

        $html = (string) Widget::render();

        foreach (['key', 'reporter', 'signature', 'expires'] as $attribute) {
            expect($html)->toContain('data-snag-'.$attribute.'="');
        }
    });

    it('stamps the request id, which is the whole join to flare', function () {
        withReporter();

        expect((string) Widget::render())->toContain('data-snag-request-id="');
    });

    it('stamps the release, so a regression can be tied to a deploy', function () {
        withReporter();

        expect((string) Widget::render())->toContain('data-snag-release="a1b2c3d"');
    });

    it('omits the release rather than printing an empty one', function () {
        withReporter();
        config()->set('snag-client.release', null);

        expect((string) Widget::render())->not->toContain('data-snag-release');
    });

    it('follows the app locale when none is configured', function () {
        withReporter();
        app()->setLocale('nl');

        expect((string) Widget::render())->toContain('data-snag-locale="nl"');
    });

    it('prefers an explicit locale over the app one', function () {
        withReporter();
        app()->setLocale('nl');
        config()->set('snag-client.locale', 'en');

        expect((string) Widget::render())->toContain('data-snag-locale="en"');
    });

    it('escapes attribute values so config cannot break out of the tag', function () {
        withReporter();
        config()->set('snag-client.release', 'a" onload="steal()');

        $html = (string) Widget::render();

        expect($html)->not->toContain('onload="steal()')
            ->and($html)->toContain('&quot;');
    });

    it('is reachable as @snag from a layout', function () {
        withReporter();

        expect(Blade::render('@snag'))->toContain('data-snag-key="billr"');
    });
});

describe('when it refuses to render', function () {
    it('renders nothing at all rather than a broken tag', function () {
        withReporter();
        config()->set('snag-client.enabled', false);

        expect((string) Widget::render())->toBe('');
    });

    it('stays silent for a visitor nobody can identify', function () {
        // A report from an unidentifiable reporter cannot be triaged, deduplicated by reporter,
        // or answered. Better no button than a button that files those.
        withReporter(null);

        expect((string) Widget::render())->toBe('');
    });

    it('stays silent when the app has not been given its identity', function () {
        withReporter();
        config()->set('snag-client.key', null);

        expect((string) Widget::render())->toBe('');
    });

    it('stays silent without an ingest secret, rather than sending unsigned reports', function () {
        withReporter();
        config()->set('snag-client.secret', null);

        expect((string) Widget::render())->toBe('');
    });

    it('stays silent without a pseudonym salt, rather than inventing one', function () {
        // A default salt would be the same in every app, so one app's pseudonyms would match
        // another's, and snag would be able to correlate a person across apps.
        withReporter();
        config()->set('snag-client.pseudonym_salt', null);

        expect((string) Widget::render())->toBe('');
    });

});

/**
 * Runs the shim the way a browser would, against a window that only knows about listeners, and
 * returns what the script printed.
 */
function runShim(string $afterwards, int $times = 1): string
{
    preg_match('/<script[^>]*>(.*?)<\/script>/s', (string) Widget::render(), $match);
    $shim = str_repeat($match[1]."\n", $times);

    $script = <<<JS
        const listeners = { error: [], unhandledrejection: [] };
        const window = {
            addEventListener: (type, fn) => listeners[type].push(fn),
            removeEventListener: (type, fn) => { listeners[type] = listeners[type].filter((f) => f !== fn); },
        };
        const fire = (type, event) => listeners[type].forEach((fn) => fn(event));
        {$shim}
        {$afterwards}
        JS;

    return trim(Process::run(['node', '-e', $script])->throw()->output());
}

describe('the pre-boot shim', function () {
    it('prints the shim ahead of the loader, so it listens before the host app runs', function () {
        // The loader is deferred and comes from another origin, so the host app's own bundle
        // can throw before any collector exists. An inline script runs while the page parses.
        withReporter();

        $html = (string) Widget::render();

        expect($html)->toStartWith('<script>')
            ->and($html)->toContain('__snagEarly')
            ->and(strpos($html, '__snagEarly'))->toBeLessThan(strpos($html, '<script defer '));
    });

    it('carries the CSP nonce when the app has one', function () {
        withReporter();
        Vite::useCspNonce('n0nce');

        expect((string) Widget::render())->toStartWith('<script nonce="n0nce">');
    });

    it('prints no nonce when the app has none', function () {
        withReporter();

        expect((string) Widget::render())->not->toContain('nonce=');
    });

    it('escapes the nonce so it cannot break out of the tag', function () {
        withReporter();
        Vite::useCspNonce('a" onload="steal()');

        $html = (string) Widget::render();

        expect($html)->not->toContain('onload="steal()')
            ->and($html)->toContain('&quot;');
    });

    it('buffers uncaught errors and rejections until the widget takes them over', function () {
        withReporter();

        $out = runShim(<<<'JS'
            fire('error', { error: new Error('mount failed'), message: 'Uncaught Error: mount failed' });
            fire('error', { error: null, message: 'Script error.' });
            fire('unhandledrejection', { reason: 'no route' });
            const early = window.__snagEarly;
            console.log(JSON.stringify(early.events.map((e) => [e.type, String(e.value), typeof e.at])));
            JS);

        expect(json_decode($out, true))->toBe([
            ['error', 'Error: mount failed', 'number'],
            ['error', 'Script error.', 'number'],
            ['rejection', 'no route', 'number'],
        ]);
    })->skip(fn (): bool => (new ExecutableFinder)->find('node') === null, 'node is not installed');

    it('stops listening once the widget says it has taken over', function () {
        withReporter();

        $out = runShim(<<<'JS'
            window.__snagEarly.stop();
            fire('error', { error: new Error('after boot'), message: 'after boot' });
            console.log(JSON.stringify([window.__snagEarly.events.length, listeners.error.length, listeners.unhandledrejection.length]));
            JS);

        expect(json_decode($out, true))->toBe([0, 0, 0]);
    })->skip(fn (): bool => (new ExecutableFinder)->find('node') === null, 'node is not installed');

    it('keeps the first fifty, so a widget that never drains it cannot grow it without bound', function () {
        withReporter();

        $out = runShim(<<<'JS'
            for (let i = 0; i < 60; i++) fire('error', { error: null, message: 'error ' + i });
            const events = window.__snagEarly.events;
            console.log(JSON.stringify([events.length, events[49].value]));
            JS);

        expect(json_decode($out, true))->toBe([50, 'error 49']);
    })->skip(fn (): bool => (new ExecutableFinder)->find('node') === null, 'node is not installed');

    it('leaves the first shim in charge when a page prints two', function () {
        withReporter();

        // Two @snag directives in one layout, or a layout and a partial that both carry one.
        $out = runShim(<<<'JS'
            console.log(JSON.stringify([listeners.error.length, listeners.unhandledrejection.length]));
            JS, times: 2);

        expect(json_decode($out, true))->toBe([1, 1]);
    })->skip(fn (): bool => (new ExecutableFinder)->find('node') === null, 'node is not installed');
});
