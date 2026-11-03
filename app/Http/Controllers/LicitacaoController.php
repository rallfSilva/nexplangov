<?php

namespace App\Http\Controllers;

use App\Licitacao\Enums\Modalidade;
use App\Licitacao\Nexlicita\Sessao;
use App\Licitacao\Regras\Julgamento;
use App\Licitacao\RegraViolada;
use App\Models\Fornecedor;
use App\Models\ItemProcesso;
use App\Models\Processo;
use App\Models\Proposta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** NexLicita: sessão de julgamento do processo (propostas, lances, habilitação e adjudicação). */
class LicitacaoController extends Controller
{
    public function __construct(private Sessao $sessao) {}

    public function show(Processo $processo): View
    {
        abort_if(Modalidade::from($processo->modalidade)->ehContratacaoDireta(), 404);
        $processo->load(['itens.propostas.fornecedor', 'itens.propostas.lances']);

        return view('licitacao.sessao', [
            'processo' => $processo,
            'classificacoes' => $processo->itens->mapWithKeys(fn (ItemProcesso $i) => [$i->id => $this->sessao->classificacao($i)])->all(),
            'fornecedores' => Fornecedor::where('ativo', true)->orderBy('razao_social')->get(['id', 'razao_social', 'porte']),
            'desempates' => Julgamento::DESEMPATE,
            'criterio' => $this->sessao->criterio($processo),
            'disputaAberta' => in_array($processo->modo_disputa, [1, 3], true),
        ]);
    }

    public function proposta(Request $request, Processo $processo, ItemProcesso $item): RedirectResponse
    {
        $request->merge(['valor' => decimal_br($request->input('valor'))]);
        $d = $request->validate([
            'fornecedor_id' => ['required', 'integer', Rule::exists('fornecedores', 'id')->where('orgao_id', $processo->orgao_id)],
            'valor' => ['required', 'numeric', 'gt:0'],
            'marca_modelo' => ['nullable', 'string', 'max:160'],
        ]);

        return $this->executar(fn () => $this->sessao->registrarProposta($processo, $item, Fornecedor::findOrFail($d['fornecedor_id']), (float) $d['valor'], $d['marca_modelo'] ?? null, $request->user()), 'Proposta registrada.');
    }

    public function lance(Request $request, Processo $processo, Proposta $proposta): RedirectResponse
    {
        abort_unless($proposta->processo_id === $processo->id, 404);
        $request->merge(['valor' => decimal_br($request->input('valor')), 'intervalo' => decimal_br($request->input('intervalo') ?: '0')]);
        $d = $request->validate(['valor' => ['required', 'numeric', 'gt:0'], 'intervalo' => ['nullable', 'numeric', 'min:0']]);

        return $this->executar(fn () => $this->sessao->registrarLance($proposta, (float) $d['valor'], (float) ($d['intervalo'] ?? 0), $request->user()), 'Lance registrado.');
    }

    public function desclassificar(Request $request, Processo $processo, Proposta $proposta): RedirectResponse
    {
        abort_unless($proposta->processo_id === $processo->id, 404);
        $d = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        return $this->executar(fn () => $this->sessao->desclassificar($proposta, $d['motivo']), 'Proposta desclassificada.');
    }

    public function habilitar(Processo $processo, Proposta $proposta): RedirectResponse
    {
        abort_unless($proposta->processo_id === $processo->id, 404);

        return $this->executar(fn () => $this->sessao->habilitar($proposta), 'Proposta habilitada.');
    }

    public function inabilitar(Request $request, Processo $processo, Proposta $proposta): RedirectResponse
    {
        abort_unless($proposta->processo_id === $processo->id, 404);
        $d = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        return $this->executar(fn () => $this->sessao->inabilitar($proposta, $d['motivo']), 'Proposta inabilitada. A próxima colocada passa a ser a primeira.');
    }

    public function desempate(Request $request, Processo $processo, Proposta $proposta): RedirectResponse
    {
        abort_unless($proposta->processo_id === $processo->id, 404);
        $d = $request->validate(['criterio' => ['required', Rule::in(array_keys(Julgamento::DESEMPATE))], 'justificativa' => ['required', 'string', 'max:500']]);

        return $this->executar(fn () => $this->sessao->registrarDesempate($proposta, $d['criterio'], $d['justificativa']), 'Critério de desempate registrado.');
    }

    public function adjudicar(Request $request, Processo $processo, ItemProcesso $item): RedirectResponse
    {
        abort_unless($item->processo_id === $processo->id, 404);
        $d = $request->validate(['proposta_id' => ['required', 'integer'], 'empate_ficto' => ['nullable', 'string', 'max:500']]);
        $proposta = $item->propostas()->findOrFail($d['proposta_id']);

        return $this->executar(fn () => $this->sessao->adjudicar($item, $proposta, $request->user(), $d['empate_ficto'] ?? null), "Item {$item->numero} adjudicado.");
    }

    public function revogarAdjudicacao(Request $request, Processo $processo, ItemProcesso $item): RedirectResponse
    {
        abort_unless($item->processo_id === $processo->id, 404);
        $d = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        return $this->executar(fn () => $this->sessao->revogarAdjudicacao($item, $d['motivo']), 'Adjudicação revogada.');
    }

    private function executar(callable $acao, string $sucesso): RedirectResponse
    {
        try {
            $acao();
        } catch (RegraViolada $e) {
            return back()->withInput()->with('erro', 'Não foi possível concluir.')->with('motivos', $e->motivos);
        }

        return back()->with('sucesso', $sucesso);
    }
}
