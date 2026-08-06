<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Off switch
    |--------------------------------------------------------------------------
    |
    | The host app's own switch, independent of snag's. snag has a kill switch
    | of its own that works without redeploying anything, which is the one to
    | reach for in an incident; this one is for an app that has simply not been
    | rolled out yet, or a local environment that should not be filing reports.
    |
    */

    'enabled' => env('SNAG_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Where snag lives
    |--------------------------------------------------------------------------
    |
    | The origin the widget bundle is served from, and the origin it uploads to.
    | Deliberately one value: a widget that could be told to upload somewhere
    | other than where it came from would be a useful thing for an attacker who
    | got a foothold in a layout file.
    |
    */

    'url' => env('SNAG_URL'),

    /*
    |--------------------------------------------------------------------------
    | This app's identity to snag
    |--------------------------------------------------------------------------
    |
    | The key names the app; the secret proves a report really came from it.
    | snag holds the same secret, so it can verify the signature below without
    | this app having to send anything else about the reporter.
    |
    */

    'key' => env('SNAG_KEY'),

    'secret' => env('SNAG_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Reporter pseudonym salt
    |--------------------------------------------------------------------------
    |
    | The reporter's identifier is hashed with this before it leaves the app, so
    | snag stores a pseudonym and never a user id or an email address.
    |
    | It is a separate secret from the ingest secret above, and that separation
    | is the whole point rather than an accident. snag necessarily holds the
    | ingest secret in order to verify signatures. If the pseudonym were derived
    | with that same secret, snag could take a pseudonym, hash the integers one
    | upwards and recover the user id in seconds. With a salt snag has never
    | seen, it cannot.
    |
    | Never send this to snag, and treat rotating it as resetting every
    | reporter's identity: their past reports stop being recognised as theirs.
    |
    */

    'pseudonym_salt' => env('SNAG_PSEUDONYM_SALT'),

    /*
    |--------------------------------------------------------------------------
    | Signature lifetime
    |--------------------------------------------------------------------------
    |
    | How long a signature issued on a page render stays valid. Short, because
    | the app re-issues one on every render anyway, and a leaked one should stop
    | working quickly. It has to be at or under snag's own reporter_token_ttl or
    | snag will reject reports from a page that was left open.
    |
    */

    'ttl' => env('SNAG_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Release
    |--------------------------------------------------------------------------
    |
    | Stamped on every report so a regression can be tied to a deploy. Usually
    | the commit sha the app was built from.
    |
    */

    'release' => env('SNAG_RELEASE'),

    /*
    |--------------------------------------------------------------------------
    | Locale
    |--------------------------------------------------------------------------
    |
    | The language the reporter is offered the form in. Null follows the app's
    | own locale, which is right for an app that already knows who is reading.
    |
    */

    'locale' => env('SNAG_LOCALE'),

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | An app that sets a CSP has to allow snag's origin in two places, because
    | the bundle is fetched from there and uploads back to there:
    |
    |     script-src  https://snag.thijssensoftware.nl
    |     connect-src https://snag.thijssensoftware.nl
    |
    | connect-src has to be named explicitly. Widening default-src instead would
    | loosen every other fetch type at the same time.
    |
    | Snag::cspSources() returns the origin so a policy can be built from config
    | rather than from a string pasted into sixteen layouts.
    |
    | One thing that is easy to miss: the widget's panel carries its styling as
    | an inline <style> inside its shadow root, and a shadow root is not outside
    | the policy. An app that tightens style-src to drop 'unsafe-inline' gets a
    | working but completely unstyled panel. Of the sixteen apps only
    | ob-weekregistratie sets a policy at all today, and it already allows
    | 'unsafe-inline' there.
    |
    */

];
