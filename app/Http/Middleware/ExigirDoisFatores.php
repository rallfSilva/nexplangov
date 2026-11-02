<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Administradores precisam ativar a autenticação em dois fatores antes de usar o sistema. */
class ExigirDoisFatores
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && config('nexplangov.exigir_2fa') && $user->exigeDoisFatores() && ! $user->temDoisFatoresAtivo()) {
            return redirect()->route('seguranca')
                ->with('aviso', 'Por segurança, administradores precisam ativar a verificação em duas etapas antes de continuar.');
        }

        return $next($request);
    }
}
