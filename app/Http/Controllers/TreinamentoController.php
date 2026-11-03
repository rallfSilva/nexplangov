<?php

namespace App\Http\Controllers;

use App\Models\Orgao;
use App\Support\TurmaTreinamento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Turmas de treinamento, pelo painel master. As senhas aparecem uma única vez, logo depois de criadas. */
class TreinamentoController extends Controller
{
    public function __construct(private TurmaTreinamento $turmas) {}

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'turma' => ['required', 'string', 'max:8', 'regex:/^[A-Za-z0-9]+$/'],
            'dias' => ['required', 'integer', 'min:1', 'max:180'],
        ], ['turma.regex' => 'Use só letras e números no nome da turma.']);

        try {
            $r = $this->turmas->preparar($d['turma'], (int) $d['dias']);
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('erro', $e->getMessage());
        }

        return redirect()->route('master')
            ->with('sucesso', "Turma {$r['orgao']->sigla} pronta, com acesso até {$r['orgao']->treinamento_expira_em->format('d/m/Y')}.")
            ->with('acessos_treinamento', $r['acessos']);
    }

    public function encerrar(Orgao $orgao): RedirectResponse
    {
        abort_unless($orgao->treinamento, 404);
        $n = $this->turmas->encerrar($orgao);

        return redirect()->route('master')->with('sucesso', "Turma {$orgao->sigla} encerrada: {$n} acesso(s) desativado(s).");
    }
}
