<?php

namespace App\Http\Controllers;

use App\Models\ValorLimite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Valores da Lei 14.133 atualizados por decreto (art. 182). */
class ConfiguracaoController extends Controller
{
    public function index(): View
    {
        return view('configuracoes.index', ['valores' => ValorLimite::orderByDesc('ano')->orderBy('dispositivo')->get()]);
    }

    public function salvarValor(Request $request): RedirectResponse
    {
        $request->merge(['valor' => decimal_br($request->input('valor'))]);

        $dados = $request->validate([
            'ano' => ['required', 'integer', 'min:2023', 'max:2100'],
            'dispositivo' => ['required', Rule::in(array_keys(ValorLimite::DISPOSITIVOS))],
            'valor' => ['required', 'numeric', 'gt:0'],
            'ato_normativo' => ['required', 'string', 'max:255'],
        ]);

        ValorLimite::updateOrCreate(
            ['ano' => $dados['ano'], 'dispositivo' => $dados['dispositivo']],
            ['valor' => $dados['valor'], 'ato_normativo' => $dados['ato_normativo'], 'conferido' => $request->boolean('conferido')],
        );

        return back()->with('sucesso', 'Valor salvo.');
    }
}
