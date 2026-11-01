<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Thijssensoftware\SnagClient\Context\ContextStore;
use Thijssensoftware\SnagClient\Snag;

describe('recording a request', function () {
    it('keeps the route, action, user and query count against the request id', function () {
        $user = new User;
        $user->id = 42;

        $id = (string) Str::ulid();
        $this->actingAs($user)->withHeaders(['X-Request-Id' => $id])->get('/orders/7')->assertOk();

        $stored = app(ContextStore::class)->get($id);

        expect($stored)->toBeArray()
            ->and($stored['route'])->toBe('orders.show')
            ->and($stored['uri'])->toBe('orders/{order}')
            ->and($stored['action'])->toBe('Thijssensoftware\\SnagClient\\Tests\\Support\\OrdersController@show')
            ->and($stored['method'])->toBe('GET')
            ->and($stored['status'])->toBe(200)
            ->and($stored['query_count'])->toBe(2)
            ->and($stored['user_ref'])->toBe(Snag::reporterReference());
    });

    it('stores the route pattern and never the url with its parameters', function () {
        $id = (string) Str::ulid();
        $this->withHeaders(['X-Request-Id' => $id])->get('/orders/secret-token-123?reset=abc')->assertOk();

        expect(json_encode(app(ContextStore::class)->get($id)))
            ->not->toContain('secret-token-123')
            ->not->toContain('reset');
    });

    it('records no user for a guest', function () {
        $id = (string) Str::ulid();
        $this->withHeaders(['X-Request-Id' => $id])->get('/plain')->assertOk();

        expect(app(ContextStore::class)->get($id)['user_ref'])->toBeNull();
    });

    it('never falls back to a raw id when there is no salt to pseudonymise with', function () {
        config()->set('snag-client.pseudonym_salt', '');
        $user = new User;
        $user->id = 42;

        $id = (string) Str::ulid();
        $this->actingAs($user)->withHeaders(['X-Request-Id' => $id])->get('/plain');

        expect(app(ContextStore::class)->get($id)['user_ref'])->toBeNull();
    });

    it('expires on its own after fifteen minutes', function () {
        $id = (string) Str::ulid();
        $this->withHeaders(['X-Request-Id' => $id])->get('/plain');

        $this->travel(14)->minutes();
        expect(app(ContextStore::class)->get($id))->not->toBeNull();

        $this->travel(2)->minutes();
        expect(app(ContextStore::class)->get($id))->toBeNull();
    });

    it('keeps the first thing recorded for an id, so a replayed id cannot overwrite it', function () {
        $id = (string) Str::ulid();
        $this->withHeaders(['X-Request-Id' => $id])->get('/orders/1');
        $this->withHeaders(['X-Request-Id' => $id])->get('/plain');

        expect(app(ContextStore::class)->get($id)['route'])->toBe('orders.show');
    });

    it('counts queries per request, not across them', function () {
        $first = (string) Str::ulid();
        $second = (string) Str::ulid();
        $this->withHeaders(['X-Request-Id' => $first])->get('/orders/1');
        $this->withHeaders(['X-Request-Id' => $second])->get('/plain');

        expect(app(ContextStore::class)->get($second)['query_count'])->toBe(0);
    });

    it('records nothing while the package is switched off', function () {
        config()->set('snag-client.enabled', false);
        $id = (string) Str::ulid();
        $this->withHeaders(['X-Request-Id' => $id])->get('/plain');

        expect(app(ContextStore::class)->get($id))->toBeNull();
    });

    it('records nothing when context is switched off on its own', function () {
        config()->set('snag-client.context.enabled', false);
        $id = (string) Str::ulid();
        $this->withHeaders(['X-Request-Id' => $id])->get('/plain');

        expect(app(ContextStore::class)->get($id))->toBeNull();
    });

    it('records nothing without the secret the endpoint would be guarded by', function () {
        config()->set('snag-client.secret', '');
        $id = (string) Str::ulid();
        $this->withHeaders(['X-Request-Id' => $id])->get('/plain');

        expect(app(ContextStore::class)->get($id))->toBeNull();
    });

    it('does not record its own endpoint being read', function () {
        $id = (string) Str::ulid();
        $this->withHeaders(['X-Request-Id' => $id])->get('/_snag/context/'.$id);

        expect(app(ContextStore::class)->get($id))->toBeNull();
    });

    it('does not break the response when recording fails', function () {
        Snag::resolveReporterUsing(function (): never {
            throw new RuntimeException('resolver blew up');
        });
        $user = new User;
        $user->id = 1;

        $this->withoutExceptionHandling()->actingAs($user)->get('/plain')->assertOk();
    });

    it('uses the cache store the app picked', function () {
        config()->set('cache.stores.snag-array', ['driver' => 'array']);
        config()->set('snag-client.context.store', 'snag-array');
        $id = (string) Str::ulid();
        $this->withHeaders(['X-Request-Id' => $id])->get('/plain');

        expect(Cache::store('snag-array')->get('snag-client:context:billr:'.strtolower($id)))->toBeArray()
            ->and(Cache::store('array')->get('snag-client:context:billr:'.strtolower($id)))->toBeNull();
    });
});
