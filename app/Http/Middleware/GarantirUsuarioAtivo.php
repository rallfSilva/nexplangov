<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Encerra na hora a sessão de quem foi desativado enquanto estava logado. */
class GarantirUsuarioAtivo
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && (! $user->ativo || ($user->orgao_id !== null && ! $user->orgao?->ativo))) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Seu acesso está desativado. Procure o administrador do órgão.']);
        }

        return $next($request);
    }
}
