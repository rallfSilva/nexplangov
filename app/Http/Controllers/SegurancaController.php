<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class SegurancaController extends Controller
{
    public function __invoke(Request $request): View
    {
        $confirmadaEm = (int) $request->session()->get('auth.password_confirmed_at', 0);
        $senhaConfirmada = time() - $confirmadaEm < config('auth.password_timeout', 10800);

        // Mudanças na verificação em duas etapas pedem a senha de novo. Depois de confirmar,
        // a pessoa volta para esta página (e não para o endereço do formulário).
        if (! $senhaConfirmada) {
            $request->session()->put('url.intended', route('seguranca'));
        }

        return view('seguranca', ['user' => $request->user(), 'senhaConfirmada' => $senhaConfirmada]);
    }
}
