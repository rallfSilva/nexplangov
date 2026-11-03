<?php

namespace App\Http\Controllers;

use App\Models\Capacitacao;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Capacitação dos agentes públicos que atuam em contratações (art. 7º, II, e art. 173). */
class CapacitacaoController extends Controller
{
    /** Perfis que atuam diretamente nas contratações e precisam de capacitação registrada. */
    public const PERFIS_ALVO = ['agente-contratacao', 'planejamento', 'gestor-contrato', 'juridico', 'autoridade'];

    public function index(Request $request): View
    {
        $u = $request->user();
        $gerencia = $u->can('capacitacao.gerenciar');

        $usuarios = $gerencia
            ? User::role(self::PERFIS_ALVO)->where('orgao_id', $u->orgao_id)->where('ativo', true)->orderBy('name')->get(['id', 'name'])
            : collect([$u]);

        $cursos = Capacitacao::with('usuario')->whereIn('user_id', $usuarios->pluck('id'))->orderByDesc('concluida_em')->get();

        return view('capacitacao.index', ['usuarios' => $usuarios, 'cursos' => $cursos, 'gerencia' => $gerencia, 'porUsuario' => $cursos->groupBy('user_id')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $u = $request->user();
        $d = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('orgao_id', $u->orgao_id)],
            'curso' => ['required', 'string', 'max:200'], 'instituicao' => ['required', 'string', 'max:200'], 'escola_governo' => ['nullable', 'boolean'],
            'tema' => ['required', Rule::in(array_keys(Capacitacao::TEMAS))], 'carga_horaria' => ['required', 'integer', 'min:1', 'max:2000'],
            'concluida_em' => ['required', 'date', 'before_or_equal:today'], 'certificado' => ['nullable', 'string', 'max:120'],
        ]);

        // Sem a permissão de gerenciar, cada um registra só a própria capacitação.
        abort_unless($u->can('capacitacao.gerenciar') || (int) $d['user_id'] === $u->id, 403);

        Capacitacao::create($d + ['orgao_id' => $u->orgao_id, 'registrada_por' => $u->id, 'escola_governo' => (bool) ($d['escola_governo'] ?? false)]);

        return back()->with('sucesso', 'Capacitação registrada.');
    }
}
