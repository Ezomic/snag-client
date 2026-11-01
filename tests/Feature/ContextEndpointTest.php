<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Thijssensoftware\SnagClient\Context\ContextStore;
use Thijssensoftware\SnagClient\Context\ContextToken;

function recordContext(?string $id = null, array $payload = []): string
{
    $id ??= (string) Str::ulid();

    app(ContextStore::class)->put($id, [
        'route' => 'orders.show',
        'uri' => 'orders/{order}',
        'action' => 'App\\Http\\Controllers\\OrderController@show',
        'method' => 'GET',
        'status' => 200,
        'user_ref' => str_repeat('a', 64),
        'query_count' => 12,
        'recorded_at' => 1_700_000_000,
        ...$payload,
    ]);

    return $id;
}

function bearerFor(string $id, array $overrides = []): array
{
    $options = [
        'key' => 'billr',
        'secret' => 'ingest-secret',
        'expires' => time() + 120,
        ...$overrides,
    ];

    return ['Authorization' => 'Bearer '.ContextToken::bearer($options['key'], $options['secret'], $id, $options['expires'])];
}

function assertIndistinguishable404(TestResponse $response): void
{
    $response->assertNotFound();
    expect($response->getContent())->toBe('{"message":"Not Found"}');
}

describe('answering snag', function () {
    it('returns the recorded context to a caller holding a valid token', function () {
        $id = recordContext();

        $this->getJson('/_snag/context/'.$id, bearerFor($id))
            ->assertOk()
            ->assertExactJson([
                'route' => 'orders.show',
                'uri' => 'orders/{order}',
                'action' => 'App\\Http\\Controllers\\OrderController@show',
                'method' => 'GET',
                'status' => 200,
                'user_ref' => str_repeat('a', 64),
                'query_count' => 12,
                'recorded_at' => 1_700_000_000,
            ])
            ->assertHeader('Cache-Control', 'no-store, private');
    });

    it('works for the request that was just recorded, end to end', function () {
        $id = (string) Str::ulid();
        $this->withHeaders(['X-Request-Id' => $id])->get('/orders/3')->assertOk();

        $this->getJson('/_snag/context/'.$id, bearerFor($id))
            ->assertOk()
            ->assertJsonPath('route', 'orders.show')
            ->assertJsonPath('query_count', 2);
    });

    it('accepts an uppercase ulid and finds the lowercase record', function () {
        $id = recordContext();

        $this->getJson('/_snag/context/'.strtoupper($id), bearerFor($id))->assertOk();
    });

    it('accepts a uuid, which is the other id the request-id package produces', function () {
        $id = recordContext((string) Str::uuid());

        $this->getJson('/_snag/context/'.$id, bearerFor($id))->assertOk();
    });
});

describe('refusing everyone else', function () {
    it('answers an unknown id exactly as it answers a bad token', function () {
        $known = recordContext();
        $unknown = (string) Str::ulid();

        $badToken = $this->getJson('/_snag/context/'.$known, ['Authorization' => 'Bearer nope']);
        $unknownId = $this->getJson('/_snag/context/'.$unknown, bearerFor($unknown));

        assertIndistinguishable404($badToken);
        assertIndistinguishable404($unknownId);
        expect($badToken->headers->all('content-type'))->toBe($unknownId->headers->all('content-type'));
    });

    it('answers a request with no token at all the same way', function () {
        $id = recordContext();

        assertIndistinguishable404($this->getJson('/_snag/context/'.$id));
    });

    it('rejects every shape of malformed bearer', function (string $header) {
        $id = recordContext();

        assertIndistinguishable404($this->getJson('/_snag/context/'.$id, ['Authorization' => $header]));
    })->with([
        'empty bearer' => 'Bearer ',
        'basic scheme' => 'Basic dXNlcjpwYXNz',
        'bare token' => 'v1.9999999999.'.str_repeat('a', 64),
        'wrong version' => 'Bearer v2.9999999999.'.str_repeat('a', 64),
        'short signature' => 'Bearer v1.9999999999.abc',
        'long signature' => 'Bearer v1.9999999999.'.str_repeat('a', 4096),
        'non numeric expiry' => 'Bearer v1.soon.'.str_repeat('a', 64),
        'uppercase hex' => 'Bearer v1.9999999999.'.str_repeat('A', 64),
        'extra segment' => 'Bearer v1.9999999999.'.str_repeat('a', 64).'.x',
        'unicode' => 'Bearer v1.9999999999.'.'é'.str_repeat('a', 63),
    ]);

    it('rejects a token that has expired', function () {
        $id = recordContext();

        assertIndistinguishable404($this->getJson('/_snag/context/'.$id, bearerFor($id, ['expires' => time() - 120])));
    });

    it('allows a minute of clock skew either way', function () {
        $id = recordContext();

        $this->getJson('/_snag/context/'.$id, bearerFor($id, ['expires' => time() - 30]))->assertOk();
    });

    it('rejects a token with an expiry too far ahead to be one snag minted', function () {
        $id = recordContext();

        assertIndistinguishable404($this->getJson('/_snag/context/'.$id, bearerFor($id, ['expires' => time() + 86400])));
    });

    it('rejects a token signed with another app\'s secret', function () {
        $id = recordContext();

        assertIndistinguishable404($this->getJson('/_snag/context/'.$id, bearerFor($id, ['secret' => 'some-other-apps-secret'])));
    });

    it('rejects a token minted for another app key even with this app\'s secret', function () {
        $id = recordContext();

        assertIndistinguishable404($this->getJson('/_snag/context/'.$id, bearerFor($id, ['key' => 'zero'])));
    });

    it('rejects a token minted for a different request id', function () {
        $mine = recordContext();
        $other = recordContext();

        assertIndistinguishable404($this->getJson('/_snag/context/'.$mine, bearerFor($other)));
    });

    it('does not accept the ingest secret itself as a bearer', function () {
        $id = recordContext();

        assertIndistinguishable404($this->getJson('/_snag/context/'.$id, ['Authorization' => 'Bearer ingest-secret']));
    });

    it('does not accept a reporter signature as a bearer', function () {
        $id = recordContext();
        $expires = time() + 120;
        $signature = hash_hmac('sha256', $id.'|'.$expires, 'ingest-secret');

        assertIndistinguishable404($this->getJson('/_snag/context/'.$id, ['Authorization' => "Bearer v1.{$expires}.{$signature}"]));
    });

    it('is closed while the package is switched off', function () {
        $id = recordContext();
        config()->set('snag-client.enabled', false);

        assertIndistinguishable404($this->getJson('/_snag/context/'.$id, bearerFor($id)));
    });

    it('is closed when context is switched off on its own', function () {
        $id = recordContext();
        config()->set('snag-client.context.enabled', false);

        assertIndistinguishable404($this->getJson('/_snag/context/'.$id, bearerFor($id)));
    });

    it('is closed with no secret configured, however the token was made', function () {
        $id = recordContext();
        $headers = bearerFor($id, ['secret' => '']);
        config()->set('snag-client.secret', '');

        assertIndistinguishable404($this->getJson('/_snag/context/'.$id, $headers));
    });

    it('is closed with no app key configured', function () {
        $id = recordContext();
        $headers = bearerFor($id, ['key' => '']);
        config()->set('snag-client.key', '');

        assertIndistinguishable404($this->getJson('/_snag/context/'.$id, $headers));
    });

    it('never reads another app\'s record when the cache is shared', function () {
        $id = (string) Str::ulid();
        Cache::put('snag-client:context:zero:'.strtolower($id), ['route' => 'zero-secret', 'user_ref' => 'x', 'query_count' => 1], 600);

        assertIndistinguishable404($this->getJson('/_snag/context/'.$id, bearerFor($id)));
    });
});

describe('odd request ids', function () {
    it('answers every one with the same 404 even given a valid token for it', function (string $path) {
        $headers = bearerFor(rawurldecode($path));

        assertIndistinguishable404($this->getJson('/_snag/context/'.$path, $headers));
    })->with([
        'empty' => '',
        'too short' => '01hzz',
        'too long' => str_repeat('0', 5000),
        'path traversal' => '..%2F..%2F.env',
        'encoded slash' => '01hzzzzzzzzzzzzzzzzzzzzzzz%2Fextra',
        'dot dot' => '..',
        'unicode' => '%F0%9F%92%A5%F0%9F%92%A5',
        'ulid with newline' => '01hzzzzzzzzzzzzzzzzzzzzzz%0A',
        'null byte' => '01hzzzzzzzzzzzzzzzzzzzzzz%00',
        'ulid with illegal letter u' => '01huuuuuuuuuuuuuuuuuuuuuuu',
        'wildcard' => '*',
        'cache key injection' => 'a:b:c',
        'sql' => '%27%20OR%201%3D1--',
    ]);

    it('answers an id that was never recorded with the same 404', function () {
        $id = (string) Str::ulid();

        assertIndistinguishable404($this->getJson('/_snag/context/'.$id, bearerFor($id)));
    });

    it('answers an id that has expired with the same 404', function () {
        $id = recordContext();
        $this->travel(16)->minutes();

        assertIndistinguishable404($this->getJson('/_snag/context/'.$id, bearerFor($id)));
    });

    it('answers a missing id segment with the same 404', function () {
        assertIndistinguishable404($this->getJson('/_snag/context'));
        assertIndistinguishable404($this->getJson('/_snag/context/'));
    });

    it('refuses other methods', function () {
        $id = recordContext();

        $this->postJson('/_snag/context/'.$id, [], bearerFor($id))->assertStatus(405);
    });
});

describe('a damaged record', function () {
    it('is answered as not found when the cache holds something that is not a record', function (mixed $value) {
        $id = (string) Str::ulid();
        Cache::put('snag-client:context:billr:'.strtolower($id), $value, 600);

        assertIndistinguishable404($this->getJson('/_snag/context/'.$id, bearerFor($id)));
    })->with([
        'string' => 'garbage',
        'integer' => 7,
        'true' => true,
    ]);

    it('returns a well formed answer from a record with missing keys', function () {
        $id = (string) Str::ulid();
        Cache::put('snag-client:context:billr:'.strtolower($id), ['route' => 'x'], 600);

        $this->getJson('/_snag/context/'.$id, bearerFor($id))
            ->assertOk()
            ->assertExactJson([
                'route' => 'x',
                'uri' => null,
                'action' => null,
                'method' => null,
                'status' => null,
                'user_ref' => null,
                'query_count' => null,
                'recorded_at' => null,
            ]);
    });

    it('nulls fields of the wrong type instead of passing them on', function () {
        $id = recordContext(null, [
            'route' => ['nested' => 'array'],
            'uri' => 123,
            'action' => new stdClass,
            'method' => true,
            'status' => 'ok',
            'user_ref' => ['a'],
            'query_count' => '12',
            'recorded_at' => 'yesterday',
            'extra' => 'secret-extra-field',
        ]);

        $response = $this->getJson('/_snag/context/'.$id, bearerFor($id))->assertOk();

        expect(array_filter($response->json(), fn ($v) => $v !== null))->toBe([])
            ->and($response->getContent())->not->toContain('secret-extra-field');
    });

    it('truncates huge strings and clamps huge numbers', function () {
        $id = recordContext(null, [
            'route' => str_repeat('r', 100000),
            'action' => str_repeat('a', 100000),
            'user_ref' => str_repeat('u', 100000),
            'query_count' => PHP_INT_MAX,
            'status' => 99999,
        ]);

        $json = $this->getJson('/_snag/context/'.$id, bearerFor($id))->assertOk()->json();

        expect(strlen($json['route']))->toBeLessThanOrEqual(255)
            ->and(strlen($json['action']))->toBeLessThanOrEqual(255)
            ->and($json['user_ref'])->toBeNull()
            ->and($json['query_count'])->toBe(1_000_000)
            ->and($json['status'])->toBeNull();
    });
});

describe('abuse', function () {
    it('is rate limited per caller', function () {
        RateLimiter::clear('snag-client-context');
        $id = recordContext();

        foreach (range(1, 60) as $_) {
            $this->getJson('/_snag/context/'.$id);
        }

        $this->getJson('/_snag/context/'.$id, bearerFor($id))->assertStatus(429);
    });
});
