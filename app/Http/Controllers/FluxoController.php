<?php

namespace App\Http\Controllers;

use App\Licitacao\Fluxo\FluxoDocumento;
use App\Licitacao\TiposDocumento;
use App\Models\Fluxo;
use App\Models\FluxoEtapa;
use App\Models\Processo;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Fluxos de documentos do órgão: dependências, prazos, revisão, aprovação, assinatura e responsáveis. */
class FluxoController extends Controller
{
    public function index(): View
    {
        return view('fluxos.index', ['fluxos' => Fluxo::withCount('etapas')->orderByDesc('padrao')->orderBy('nome')->get()]);
    }

    public function create(): View
    {
        return view('fluxos.form', $this->dados(new Fluxo(['aplicacao' => 'todos', 'ativo' => true])));
    }

    public function store(Request $request): RedirectResponse
    {
        $orgaoId = $request->user()->orgao_id;
        abort_unless($orgaoId, 422);
        $fluxo = $this->gravar(new Fluxo, $request, $orgaoId);

        return $fluxo instanceof RedirectResponse ? $fluxo : redirect()->route('fluxos.edit', $fluxo)->with('sucesso', "Fluxo {$fluxo->nome} criado.");
    }

    public function edit(Fluxo $fluxo): View
    {
        return view('fluxos.form', $this->dados($fluxo->load('etapas')));
    }

    public function update(Request $request, Fluxo $fluxo): RedirectResponse
    {
        $resultado = $this->gravar($fluxo, $request, $fluxo->orgao_id);

        return $resultado instanceof RedirectResponse ? $resultado : redirect()->route('fluxos.edit', $fluxo)->with('sucesso', 'Fluxo atualizado. Processos já iniciados mantêm o fluxo que receberam.');
    }

    public function destroy(Fluxo $fluxo): RedirectResponse
    {
        if (Processo::where('fluxo_id', $fluxo->id)->exists()) {
            $fluxo->update(['ativo' => false, 'padrao' => false]);

            return redirect()->route('fluxos.index')->with('sucesso', 'O fluxo já foi usado em processos, então foi desativado em vez de excluído.');
        }

        $fluxo->etapas()->delete();
        $fluxo->delete();

        return redirect()->route('fluxos.index')->with('sucesso', 'Fluxo excluído.');
    }

    /** Aplica um fluxo a um processo ainda na fase preparatória (congela as etapas). */
    public function aplicar(Request $request, Processo $processo, FluxoDocumento $servico): RedirectResponse
    {
        abort_unless($processo->editavel(), 403);
        $dados = $request->validate(['fluxo_id' => ['required', 'integer']]);
        $fluxo = Fluxo::where('ativo', true)->findOrFail($dados['fluxo_id']);
        $n = $servico->aplicar($processo, $fluxo);

        return back()->with('sucesso', "Fluxo {$fluxo->nome} aplicado ao processo ({$n} etapas).");
    }

    private function gravar(Fluxo $fluxo, Request $request, int $orgaoId): Fluxo|RedirectResponse
    {
        $tipos = array_keys(TiposDocumento::todos());
        $usuario = fn () => ['nullable', 'integer', Rule::exists('users', 'id')->where('orgao_id', $orgaoId)->where('ativo', true)];

        $d = $request->validate([
            'nome' => ['required', 'string', 'max:120'],
            'descricao' => ['nullable', 'string', 'max:500'],
            'aplicacao' => ['required', Rule::in(array_keys(Fluxo::APLICACOES))],
            'padrao' => ['nullable', 'boolean'],
            'ativo' => ['nullable', 'boolean'],
            'etapas' => ['array'],
            'etapas.*.tipo' => ['required', Rule::in($tipos), 'distinct'],
            'etapas.*.incluir' => ['nullable', 'boolean'],
            'etapas.*.depende_de' => ['nullable', 'array'],
            'etapas.*.depende_de.*' => [Rule::in($tipos)],
            'etapas.*.prazo_dias' => ['nullable', 'integer', 'min:1', 'max:365'],
            'etapas.*.exige_revisao' => ['nullable', 'boolean'],
            'etapas.*.exige_aprovacao' => ['nullable', 'boolean'],
            'etapas.*.exige_assinatura' => ['nullable', 'boolean'],
            'etapas.*.origem' => ['required', Rule::in(array_keys(FluxoDocumento::ORIGENS))],
            'etapas.*.responsavel_id' => $usuario(),
            'etapas.*.substituto_id' => $usuario(),
            'etapas.*.revisor_id' => $usuario(),
            'etapas.*.aprovador_id' => $usuario(),
            'etapas.*.assinantes' => ['nullable', 'array'],
            'etapas.*.assinantes.*' => [Rule::exists('users', 'id')->where('orgao_id', $orgaoId)],
        ]);

        $etapas = collect($d['etapas'] ?? [])->filter(fn ($e) => ! empty($e['incluir']))->values();
        $incluidos = $etapas->pluck('tipo')->all();
        $erros = [];

        foreach ($etapas as $i => $e) {
            $rot = TiposDocumento::get($e['tipo'])['rotulo'];
            $dep = array_values(array_intersect($e['depende_de'] ?? [], $incluidos));

            if (in_array($e['tipo'], $dep, true)) {
                $erros["etapa_$i"] = "{$rot} não pode depender dela mesma.";
            }
            // Parecer e autorização são assinados por quem tem a competência; as demais peças precisam de signatário escolhido.
            if (! empty($e['exige_assinatura']) && empty($e['assinantes']) && ! in_array($e['tipo'], ['parecer_juridico', 'autorizacao'], true)) {
                $erros["etapa_$i"] = "{$rot}: escolha ao menos um signatário.";
            }
            if (! empty($e['responsavel_id']) && ($e['substituto_id'] ?? null) == $e['responsavel_id']) {
                $erros["etapa_$i"] = "{$rot}: o substituto precisa ser diferente do responsável.";
            }
            if (! empty($e['revisor_id']) && ! empty($e['aprovador_id']) && $e['revisor_id'] == $e['aprovador_id']) {
                $erros["etapa_$i"] = "{$rot}: revisor e aprovador devem ser pessoas diferentes.";
            }
        }

        $grafo = $etapas->mapWithKeys(fn ($e) => [$e['tipo'] => array_values(array_intersect($e['depende_de'] ?? [], $incluidos))])->all();
        if ($ciclo = $this->ciclo($grafo)) {
            $erros['etapas'] = 'As dependências formam um ciclo: '.implode(' → ', array_map(fn ($t) => TiposDocumento::get($t)['rotulo'], $ciclo)).'.';
        }

        if ($erros) {
            return back()->withInput()->withErrors($erros);
        }

        DB::transaction(function () use ($fluxo, $d, $etapas, $incluidos, $orgaoId) {
            $fluxo->fill([
                'orgao_id' => $orgaoId, 'nome' => $d['nome'], 'descricao' => $d['descricao'] ?? null, 'aplicacao' => $d['aplicacao'],
                'padrao' => (bool) ($d['padrao'] ?? false), 'ativo' => (bool) ($d['ativo'] ?? true),
            ])->save();

            if ($fluxo->padrao) {
                Fluxo::where('id', '!=', $fluxo->id)->where('aplicacao', $fluxo->aplicacao)->update(['padrao' => false]);
            }

            $fluxo->etapas()->delete();
            foreach ($etapas as $i => $e) {
                FluxoEtapa::create([
                    'orgao_id' => $orgaoId, 'fluxo_id' => $fluxo->id, 'tipo' => $e['tipo'], 'ordem' => $i + 1,
                    'depende_de' => array_values(array_intersect($e['depende_de'] ?? [], $incluidos)),
                    'prazo_dias' => $e['prazo_dias'] ?? null,
                    'exige_revisao' => ! empty($e['exige_revisao']), 'exige_aprovacao' => ! empty($e['exige_aprovacao']), 'exige_assinatura' => ! empty($e['exige_assinatura']),
                    'origem' => $e['origem'], 'responsavel_id' => $e['responsavel_id'] ?? null, 'substituto_id' => $e['substituto_id'] ?? null,
                    'revisor_id' => $e['revisor_id'] ?? null, 'aprovador_id' => $e['aprovador_id'] ?? null,
                    'assinantes' => array_map('intval', $e['assinantes'] ?? []),
                ]);
            }
        });

        return $fluxo;
    }

    /** @param array<string, list<string>> $grafo @return list<string>|null caminho do ciclo, se houver */
    private function ciclo(array $grafo): ?array
    {
        $estado = [];
        $visitar = function (string $no, array $trilha) use (&$visitar, &$estado, $grafo) {
            if (($estado[$no] ?? 0) === 1) {
                return array_merge(array_slice($trilha, array_search($no, $trilha, true)), [$no]);
            }
            if (($estado[$no] ?? 0) === 2) {
                return null;
            }
            $estado[$no] = 1;
            foreach ($grafo[$no] ?? [] as $dep) {
                if ($achou = $visitar($dep, [...$trilha, $no])) {
                    return $achou;
                }
            }
            $estado[$no] = 2;

            return null;
        };

        foreach (array_keys($grafo) as $no) {
            if ($achou = $visitar($no, [])) {
                return $achou;
            }
        }

        return null;
    }

    private function dados(Fluxo $fluxo): array
    {
        return [
            'fluxo' => $fluxo, 'porTipo' => $fluxo->exists ? $fluxo->etapas->keyBy('tipo') : collect(), 'tipos' => TiposDocumento::todos(),
            'usuarios' => User::where('ativo', true)->whereNotNull('orgao_id')->orderBy('name')->get(['id', 'name']),
        ];
    }
}
