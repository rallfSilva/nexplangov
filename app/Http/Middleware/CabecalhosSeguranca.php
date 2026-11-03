<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class CabecalhosSeguranca
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        // Política de conteúdo: só recursos do próprio sistema. Em desenvolvimento com o Vite ativo, a política
        // fica de fora para não bloquear o servidor de recarga. 'unsafe-inline' cobre os scripts curtos das telas.
        if (! Vite::isRunningHot()) {
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'", "script-src 'self' 'unsafe-inline'", "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data:", "font-src 'self' data:", "connect-src 'self'", "frame-ancestors 'none'",
                "form-action 'self'", "base-uri 'self'", "object-src 'none'",
            ]));
        }

        if ($request->user() || $request->is('login', 'user/*', 'forgot-password', 'reset-password/*', 'two-factor-challenge')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
