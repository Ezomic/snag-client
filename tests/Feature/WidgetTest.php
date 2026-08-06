<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
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

        expect($html)->toStartWith('<script defer ')
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
