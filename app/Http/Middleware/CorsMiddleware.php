<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CorsMiddleware
 *
 * Handles CORS for the LevelUp API.
 *
 * Allowed origins:
 *   - app.levelupgrowth.io        (SaaS React SPA)
 *   - levelupgrowth.io             (Marketing site)
 *   - admin.levelupgrowth.io       (Admin panel)
 *   - localhost:3000               (Local React dev)
 *   - localhost:8000               (Local Laravel dev)
 *   - Expo Go / APP888 mobile      (capacitor:// and exp://)
 *   - Staging domain               (staging1.shukranuae.com)
 *
 * APP888 uses the Expo SDK which makes requests from capacitor://
 * and exp:// schemes — these are treated as trusted origins.
 */
class CorsMiddleware
{
    private array $allowedOrigins;
    private array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
    private array $allowedHeaders = [
        'Authorization', 'Content-Type', 'Accept', 'X-Requested-With',
        'X-Runtime-Secret', 'X-VMS-Token', 'Cache-Control',
    ];

    public function __construct()
    {
        $this->allowedOrigins = array_filter([
            'https://app.levelupgrowth.io',
            'https://levelupgrowth.io',
            'https://www.levelupgrowth.io',
            'https://admin.levelupgrowth.io',
            'https://staging1.shukranuae.com',
            'http://localhost:3000',
            'http://localhost:8000',
            'http://127.0.0.1:3000',
            'http://127.0.0.1:8000',
            env('CORS_ALLOWED_ORIGIN_EXTRA', ''),
        ]);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin', '');

        // LOW-08 FIX: Stripe webhooks are server-to-server — no CORS headers needed.
        // Adding them just leaks allowed origins to Stripe's logging.
        if ($request->is('api/webhook/*') || $request->is('webhook/*')) {
            return $next($request);
        }

        // Always allow preflight
        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);
            $this->addCorsHeaders($response, $origin);
            return $response;
        }

        $response = $next($request);
        $this->addCorsHeaders($response, $origin);

        return $response;
    }

    private function addCorsHeaders(Response $response, string $origin): void
    {
        // Allow specific origins or mobile app origins (capacitor://, exp://)
        $isAllowed = in_array($origin, $this->allowedOrigins)
            || str_starts_with($origin, 'capacitor://')
            || str_starts_with($origin, 'exp://')
            || (config('app.env') !== 'production' && in_array($origin, ['http://localhost:3000', 'http://127.0.0.1:3000']));

        if ($isAllowed && $origin) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Vary', 'Origin');
        }

        $response->headers->set('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods));
        $response->headers->set('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders));
        $response->headers->set('Access-Control-Max-Age', '86400'); // 24h preflight cache
    }
}
