<?php

declare(strict_types=1);

namespace Thijssensoftware\SnagClient\Context;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Thijssensoftware\RequestId\RequestIdContext;
use Thijssensoftware\SnagClient\Snag;
use Throwable;

/**
 * Remembers, against the request id, what a report filed from this request's page would want to
 * know about it. Written in terminate(), after the response has gone, so it costs the visitor
 * nothing and sees the final route, user and query count.
 */
final class ContextRecorder
{
    public function handle(Request $request, Closure $next): Response
    {
        app(RequestStats::class)->queries = 0;

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! ContextSettings::active() || $request->is('_snag/context', '_snag/context/*')) {
            return;
        }

        try {
            app(ContextStore::class)->put(
                app(RequestIdContext::class)->current(),
                $this->describe($request, $response),
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Request $request, Response $response): array
    {
        $route = $request->route();

        return [
            'route' => $route instanceof Route ? $route->getName() : null,
            // The pattern, never the url: the url carries ids, tokens and query strings.
            'uri' => $route instanceof Route ? $route->uri() : null,
            'action' => $route instanceof Route ? $route->getActionName() : null,
            'method' => $request->method(),
            'status' => $response->getStatusCode(),
            'user_ref' => Snag::reporterReferenceOrNull(),
            'query_count' => app(RequestStats::class)->queries,
            'recorded_at' => time(),
        ];
    }
}
