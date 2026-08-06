<?php

declare(strict_types=1);

use Thijssensoftware\SnagClient\Snag;

describe('the pseudonym', function () {
    it('is stable, so two reports from one person are recognisably one person', function () {
        Snag::resolveReporterUsing(fn (): int => 42);

        expect(Snag::reporterReference())->toBe(Snag::reporterReference());
    });

    it('never contains the identifier it came from', function () {
        Snag::resolveReporterUsing(fn (): string => 'jan@example.com');

        expect(Snag::reporterReference())->not->toContain('jan')
            ->and(Snag::reporterReference())->toMatch('/^[a-f0-9]{64}$/');
    });

    it('is 64 characters, which is what snag stores', function () {
        // reporter_ref is a fixed 64-char column and the ingest rules require exactly that
        // length, so a change here is a change to snag's schema.
        Snag::resolveReporterUsing(fn (): int => 1);

        expect(strlen(Snag::reporterReference()))->toBe(64);
    });

    it('differs between apps holding the same user', function () {
        // Each app salts with its own secret, so snag cannot line one app's reporters up
        // against another's and rebuild a person's activity across the estate.
        Snag::resolveReporterUsing(fn (): int => 42);
        $first = Snag::reporterReference();

        config()->set('snag-client.pseudonym_salt', 'a-different-app');

        expect(Snag::reporterReference())->not->toBe($first);
    });

    it('cannot be reversed by snag, which holds the ingest secret', function () {
        // This is the property the whole identity boundary rests on. snag must hold the ingest
        // secret to verify signatures. If the pseudonym were derived from that same secret,
        // snag could hash the integers upwards and recover a user id in seconds.
        Snag::resolveReporterUsing(fn (): int => 42);

        $whatSnagCouldCompute = hash_hmac('sha256', '42', 'ingest-secret');

        expect(Snag::reporterReference())->not->toBe($whatSnagCouldCompute);
    });

    it('falls back to the authenticated user, and to nobody when there is none', function () {
        // The sixteen apps all put the widget behind their own login, so the authenticated user
        // is the default and the resolver is the exception. With no one signed in there is no
        // reporter, and the widget does not render at all.
        Snag::forgetReporterResolver();

        expect(Snag::shouldRender())->toBeFalse();
    });
});

describe('the signature', function () {
    it('is what snag recomputes with the shared secret', function () {
        // Pinned against snag's own ReporterSignature: sha256 hmac over "ref|expiresAt" with
        // the ingest secret. The two live in different repositories, so if they drift apart
        // every report from this app is rejected as unsigned.
        $reference = str_repeat('a', 64);
        $expiresAt = 1785976420;

        expect(Snag::sign($reference, $expiresAt))
            ->toBe(hash_hmac('sha256', $reference.'|'.$expiresAt, 'ingest-secret'));
    });

    it('is 64 characters, which is what the ingest rules require', function () {
        expect(strlen(Snag::sign(str_repeat('a', 64), time())))->toBe(64);
    });

    it('expires, and says when', function () {
        Snag::resolveReporterUsing(fn (): int => 42);

        $expires = (int) Snag::attributes()['data-snag-expires'];

        expect($expires)->toBeGreaterThan(time())
            ->and($expires)->toBeLessThanOrEqual(time() + 3600);
    });

    it('refuses to issue a token that would outlive its usefulness', function () {
        // An app that set a ten-year ttl would have quietly turned a short-lived token into a
        // permanent credential.
        Snag::resolveReporterUsing(fn (): int => 42);
        config()->set('snag-client.ttl', 60 * 60 * 24 * 365);

        expect((int) Snag::attributes()['data-snag-expires'])
            ->toBeLessThanOrEqual(time() + 86400);
    });

    it('refuses a ttl so short the page would break before the reporter typed', function () {
        Snag::resolveReporterUsing(fn (): int => 42);
        config()->set('snag-client.ttl', 1);

        expect((int) Snag::attributes()['data-snag-expires'])
            ->toBeGreaterThanOrEqual(time() + 60);
    });
});

describe('the CSP source', function () {
    it('is the same origin the bundle is loaded from', function () {
        expect(Snag::cspSources())->toBe('https://snag.thijssensoftware.nl');
    });

    it('has no trailing slash to break a source expression', function () {
        config()->set('snag-client.url', 'https://snag.thijssensoftware.nl/');

        expect(Snag::cspSources())->toBe('https://snag.thijssensoftware.nl');
    });
});
