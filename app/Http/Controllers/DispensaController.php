<?php

namespace App\Http\Controllers;

use App\Licitacao\Enums\AmparoLegal;
use App\Licitacao\Regras\LimiteDispensa;
use App\Models\Orgao;
use App\Models\Processo;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** Simulador do limite de dispensa por valor com controle de fracionamento (art. 75, § 1º). */
class DispensaController extends Controller
{
    public function __invoke(Request $request, LimiteDispensa $limite): View
    {
        $user = $request->user();
        $orgao = $user->orgao ?? Orgao::find($request->integer('orgao'));
        $ano = $request->integer('ano') ?: (int) now()->format('Y');
        $inciso = $request->query('inciso') === 'I' ? AmparoLegal::Art75I : AmparoLegal::Art75II;
        $valor = decimal_br($request->query('valor')) ?? 0.0;
        $natureza = $request->query('natureza');
        $resultado = null;
        $erro = null;

        if ($orgao && $request->has('valor')) {
            try {
                $resultado = $limite->verificar($orgao, $ano, $inciso, $valor, $natureza ?: null);
            } catch (RuntimeException $e) {
                $erro = $e->getMessage();
            }
        }

        return view('dispensa.simulador', [
            'orgao' => $orgao,
            'orgaos' => $user->orgao_id === null ? Orgao::orderBy('sigla')->get() : collect(),
            'ano' => $ano,
            'inciso' => $inciso === AmparoLegal::Art75I ? 'I' : 'II',
            'valor' => $valor,
            'natureza' => $natureza,
            'naturezas' => $orgao ? Processo::withoutGlobalScopes()->where('orgao_id', $orgao->id)->whereNotNull('natureza_despesa')->distinct()->orderBy('natureza_despesa')->pluck('natureza_despesa') : collect(),
            'resultado' => $resultado,
            'erro' => $erro,
        ]);
    }
}
