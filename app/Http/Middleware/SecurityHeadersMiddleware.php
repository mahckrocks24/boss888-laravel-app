<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SecurityHeadersMiddleware
 *
 * Applied globally to all requests (web + api).
 * Sets security headers required for production deployment.
 *
 * Hardening reference:
 *   - OWASP Secure Headers Project
 *   - GDPR Article 32 (appropriate technical measures)
 *   - PCI DSS 6.4.1 (injection prevention)
 */
class SecurityHeadersMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Prevent clickjacking
        // 2026-05-11: X-Frame-Options removed to allow iframe embedding via
        // WP Connector plugin. Frame-ancestors policy now set at nginx layer
        // (Content-Security-Policy 'frame-ancestors *;') and Laravel CSP below.
        // $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Prevent MIME sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // XSS protection (legacy browsers)
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // Referrer policy — don't leak URL params in referrer
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Permissions policy — disable browser features not needed
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), interest-cohort=()');

        // HSTS — force HTTPS for 1 year (set only in production)
        if (config('app.env') === 'production') {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        // Content Security Policy
        // MEDIUM-05 FIX: React 18 ES module bundle loaded with type="module" from same origin.
        // 'self' covers same-origin scripts including /app-react/assets/*.js
        // No 'unsafe-eval' or 'unsafe-inline' script needed — Vite prod build is clean.
        // connect-src includes wss: for any future WebSocket support.
        if (config('app.env') === 'production') {
            $csp = implode('; ', [
                "default-src 'self'",
                "script-src 'self' https://js.stripe.com",
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "font-src 'self' https://fonts.gstatic.com",
                "img-src 'self' data: blob: https:",
                "connect-src 'self' wss: https://api.stripe.com https://api.openai.com https://api.deepseek.com",
                "frame-src https://js.stripe.com https://hooks.stripe.com",
                "worker-src 'self' blob:",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "upgrade-insecure-requests",
            ]);
            $response->headers->set('Content-Security-Policy', $csp);
        }

        return $response;
    }
}
