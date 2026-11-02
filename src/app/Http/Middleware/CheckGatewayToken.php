<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckGatewayToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('ingress.gateway_token');
        $given = (string) $request->header('X-Gateway-Token');

        // An empty secret must never match an empty header.
        // hash_equals takes the same time for any wrong token.
        if ($expected === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
