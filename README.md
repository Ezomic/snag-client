# thijssensoftware/snag-client

Prints the [snag](https://github.com/Ezomic/snag) reporter widget into a host app.

The widget bundle itself is served by snag and is identical everywhere. What it cannot do is
vouch for who is holding it: only the host app knows that, and only the host app is allowed to
know it. This package computes the reporter pseudonym and the signature over it, server-side, on
every page render.

## Install

```bash
composer require thijssensoftware/snag-client
```

Add the repository first, since it is not on Packagist:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/Ezomic/snag-client.git" }
    ]
}
```

## Configure

```dotenv
SNAG_ENABLED=true
SNAG_URL=https://snag.thijssensoftware.nl
SNAG_KEY=billr
SNAG_SECRET=            # the ingest secret snag holds for this app
SNAG_PSEUDONYM_SALT=    # this app's own, never given to snag
SNAG_RELEASE=           # usually the deployed commit sha
```

`SNAG_ENABLED` ships as `false`. Installing the package does not start sending reports.

### The two secrets are two secrets on purpose

`SNAG_SECRET` is shared with snag, which needs it to verify that a report really came from this
app.

`SNAG_PSEUDONYM_SALT` is not shared with anyone. The reporter's identifier is hashed with it
before it leaves, so snag stores a pseudonym rather than a user id or an email address.

If both used the same secret, the boundary would be decorative: snag holds that secret, so it
could hash the integers upwards and recover a user id in seconds. Keep them separate, and treat
rotating the salt as resetting every reporter's identity.

## Use

```blade
@snag
```

Anywhere in the layout, usually just before `</body>`. It works the same in a Blade app and an
Inertia one, because both render a Blade layout.

It prints two tags: a short inline script, then the deferred loader. The widget bundle arrives
after the host app's own scripts have run, so the inline one keeps the uncaught errors and promise
rejections thrown before it boots (up to fifty) and the widget adds them to the report's console.
It catches whatever runs after the directive, which includes every deferred and module script,
wherever `@snag` sits; put it in `<head>` to also catch inline scripts in the body.

It renders nothing at all when the app is not configured, is switched off, or has nobody signed
in. A report from a reporter nobody can identify cannot be triaged, deduplicated or answered, so
no button is better than a button that files those.

### A different notion of a reporter

The default is the authenticated user's id. An app whose reporters are not users says so:

```php
Snag::resolveReporterUsing(fn () => session()->getId());
```

## Content Security Policy

An app that sets a CSP has to allow snag's origin in two places, because the bundle is fetched
from there and uploads back to there:

```
script-src  'self' https://snag.thijssensoftware.nl
connect-src 'self' https://snag.thijssensoftware.nl
```

`connect-src` has to be named explicitly. Widening `default-src` instead loosens every other
fetch type at the same time.

The inline script that keeps early errors needs no `'unsafe-inline'` when the app uses Vite's
nonce (`Vite::useCspNonce()`): it carries the same nonce as the app's own tags. A policy without
either blocks it, which costs only the errors thrown before the widget booted.

Build it from config rather than pasting the origin into sixteen layouts:

```php
'script-src' => ["'self'", Snag::cspSources()],
```

One thing that is easy to miss: the widget's panel carries its styling as an inline `<style>`
inside its shadow root, and a shadow root is not outside the policy. An app that tightens
`style-src` to drop `'unsafe-inline'` gets a working but completely unstyled panel.

## Excluding things from capture

snag serializes the DOM to replay it. Two attributes control what it sees, and both are read by
the widget rather than by this package:

| Attribute | Effect |
|---|---|
| `data-snag-exclude` | The element and its subtree never enter the snapshot |
| `data-snag-redact` | Text is masked whatever the app's text policy |
| `data-snag-reveal` | Under `mask_all`, opts a subtree back into being readable |
