<?php

declare(strict_types=1);

namespace Thijssensoftware\SnagClient\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Thijssensoftware\SnagClient\Context\ContextSettings;
use Thijssensoftware\SnagClient\Context\ContextStore;
use Thijssensoftware\SnagClient\Context\ContextToken;

/**
 * The one inbound endpoint: snag asks what happened on a request.
 *
 * Every refusal is the same 404 with the same body. Telling "wrong token" from "no such id"
 * would let a caller who holds no token find out which request ids exist.
 */
final class ContextController
{
    public function __invoke(Request $request, ContextStore $store, string $requestId = ''): JsonResponse
    {
        $id = ContextStore::normalize($requestId);

        if ($id === null
            || ! ContextSettings::active()
            || ! ContextToken::verify($request->bearerToken(), ContextSettings::key(), ContextSettings::secret(), $id)) {
            return $this->notFound();
        }

        $record = $store->get($id);

        return $record === null ? $this->notFound() : $this->respond($record);
    }

    /**
     * @param  array<mixed>  $record
     */
    private function respond(array $record): JsonResponse
    {
        return response()->json([
            'route' => self::text($record['route'] ?? null),
            'uri' => self::text($record['uri'] ?? null),
            'action' => self::text($record['action'] ?? null),
            'method' => self::method($record['method'] ?? null),
            'status' => self::status($record['status'] ?? null),
            'user_ref' => self::reference($record['user_ref'] ?? null),
            'query_count' => self::count($record['query_count'] ?? null),
            'recorded_at' => self::timestamp($record['recorded_at'] ?? null),
        ])->header('Cache-Control', 'no-store, private');
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Not Found'], 404)->header('Cache-Control', 'no-store, private');
    }

    private static function text(mixed $value): ?string
    {
        return self::clean($value) === null ? null : mb_strcut((string) $value, 0, 255, 'UTF-8');
    }

    private static function reference(mixed $value): ?string
    {
        $clean = self::clean($value);

        return $clean !== null && strlen($clean) <= 64 ? $clean : null;
    }

    private static function clean(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && mb_check_encoding($value, 'UTF-8') ? $value : null;
    }

    private static function method(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Z]{3,10}$/', $value) === 1 ? $value : null;
    }

    private static function status(mixed $value): ?int
    {
        return is_int($value) && $value >= 100 && $value <= 599 ? $value : null;
    }

    private static function count(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? min($value, 1_000_000) : null;
    }

    private static function timestamp(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
