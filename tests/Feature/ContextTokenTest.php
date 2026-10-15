<?php

declare(strict_types=1);

use Thijssensoftware\SnagClient\Context\ContextToken;

const CONTEXT_VECTOR_ID = '01JABCDEFGHJKMNPQRSTVWXYZ0';

describe('the bearer', function () {
    it('matches the vector snag\'s own tests pin, so the two implementations cannot drift', function () {
        // The same line is asserted in snag's ContextTokenTest. If one side changes the
        // algorithm, one of the two suites goes red.
        expect(ContextToken::bearer('billr', 'ingest-secret', CONTEXT_VECTOR_ID, 1_900_000_000))
            ->toBe('v1.1900000000.9a567cf6e049a34f4aa18fc29fbcb20554b67c08f43b5d90aa61a5b50a2aeb2b');
    });

    it('is the same whatever case the id is written in', function () {
        expect(ContextToken::bearer('billr', 'ingest-secret', strtolower(CONTEXT_VECTOR_ID), 1_900_000_000))
            ->toBe(ContextToken::bearer('billr', 'ingest-secret', CONTEXT_VECTOR_ID, 1_900_000_000));
    });

    it('verifies within its window, with a minute of grace for clock skew, and not outside it', function () {
        $bearer = ContextToken::bearer('billr', 'ingest-secret', CONTEXT_VECTOR_ID, 1_000_100);

        expect(ContextToken::verify($bearer, 'billr', 'ingest-secret', CONTEXT_VECTOR_ID, 1_000_000))->toBeTrue()
            ->and(ContextToken::verify($bearer, 'billr', 'ingest-secret', CONTEXT_VECTOR_ID, 1_000_150))->toBeTrue()
            ->and(ContextToken::verify($bearer, 'billr', 'ingest-secret', CONTEXT_VECTOR_ID, 1_000_160))->toBeFalse()
            ->and(ContextToken::verify($bearer, 'billr', 'ingest-secret', CONTEXT_VECTOR_ID, 999_800))->toBeTrue()
            ->and(ContextToken::verify($bearer, 'billr', 'ingest-secret', CONTEXT_VECTOR_ID, 999_700))->toBeFalse();
    });

    it('is not a reporter signature, even with the same secret', function () {
        $expires = 1_900_000_000;
        $reporterSignature = hash_hmac('sha256', CONTEXT_VECTOR_ID.'|'.$expires, 'ingest-secret');

        expect(str_contains(ContextToken::bearer('billr', 'ingest-secret', CONTEXT_VECTOR_ID, $expires), $reporterSignature))->toBeFalse();
    });

    it('rejects a null bearer', function () {
        expect(ContextToken::verify(null, 'billr', 'ingest-secret', CONTEXT_VECTOR_ID))->toBeFalse();
    });
});
