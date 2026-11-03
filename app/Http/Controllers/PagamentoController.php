<?php

namespace App\Http\Controllers;

use App\Licitacao\Contratos\Pagamentos;
use App\Licitacao\Regras\OrdemCronologica;
use App\Licitacao\RegraViolada;
use App\Models\Contrato;
use App\Models\Liquidacao;
use App\Models\Medicao;
use App\Models\OrdemFornecimento;
use App\Models\Orgao;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Medição, liquidação e pagamento na ordem cronológica (arts. 140 e 141). */
class PagamentoController extends Controller
{
    public function __construct(private Pagamentos $servico) {}

    public function index(Request $request): View
    {
        $abertos = Liquidacao::with(['fornecedor', 'contrato', 'medicao', 'ordem'])->where('situacao', 'liquidado')->emOrdem()->get();
        $filas = $abertos->groupBy(fn (Liquidacao $l) => $l->fonte_recurso.'|'.$l->categoria)->sortKeys();

        return view('pagamentos.index', [
            'filas' => $filas,
            'pagos' => Liquidacao::with(['fornecedor', 'contrato', 'ordem'])->where('situacao', 'pago')->orderByDesc('pago_em')->orderByDesc('id')->limit(30)->get(),
            'comunicacoes' => Liquidacao::with('fornecedor')->where('situacao', 'pago')->whereNotNull('quebra_autorizada_por')
                ->where(fn ($q) => $q->whereNull('comunicado_controle_em')->orWhereNull('comunicado_tribunal_em'))->get(),
            'ordens' => OrdemFornecimento::with('ataItem.ata.fornecedor', 'ataItem.ata.processo')->whereHas('recebimentos', fn ($q) => $q->where('tipo', 'definitivo'))
                ->orderByDesc('id')->limit(100)->get()
                ->filter(fn (OrdemFornecimento $o) => $o->recebido('definitivo') * (float) $o->valor_unitario - (float) Liquidacao::where('ordem_id', $o->id)->where('situacao', '!=', 'cancelado')->sum('valor_bruto') > 0.009),
            'fontes' => Liquidacao::distinct()->orderBy('fonte_recurso')->pluck('fonte_recurso'),
            'orgao' => $request->user()->orgao,
        ]);
    }

    public function medir(Request $request, Contrato $contrato): RedirectResponse
    {
        $request->merge(['valor' => decimal_br($request->input('valor'))]);
        $d = $request->validate(['periodo_inicio' => ['required', 'date'], 'periodo_fim' => ['required', 'date'], 'valor' => ['required', 'numeric', 'gt:0'], 'descricao' => ['required', 'string', 'max:5000']]);

        return $this->tentar(fn () => $this->servico->medir($contrato, $d['periodo_inicio'], $d['periodo_fim'], (float) $d['valor'], $d['descricao'], $request->user()), 'Medição registrada. O gestor do contrato aprova ou recusa.');
    }

    public function decidirMedicao(Request $request, Contrato $contrato, Medicao $medicao): RedirectResponse
    {
        abort_unless($medicao->contrato_id === $contrato->id, 404);
        $d = $request->validate(['acao' => ['required', 'in:aprovar,recusar'], 'motivo' => ['nullable', 'string', 'max:500']]);

        return $this->tentar(fn () => $this->servico->decidirMedicao($medicao, $d['acao'] === 'aprovar', $d['motivo'] ?? null, $request->user()), $d['acao'] === 'aprovar' ? 'Medição aprovada. Já pode ser liquidada.' : 'Medição recusada.');
    }

    public function liquidarMedicao(Request $request, Medicao $medicao): RedirectResponse
    {
        return $this->tentar(fn () => $this->servico->liquidar($medicao, $this->dadosLiquidacao($request), $request->user()), 'Liquidação registrada. O crédito entrou na ordem cronológica.');
    }

    public function liquidarOrdem(Request $request, OrdemFornecimento $ordem): RedirectResponse
    {
        return $this->tentar(fn () => $this->servico->liquidar($ordem, $this->dadosLiquidacao($request), $request->user()), 'Liquidação registrada. O crédito entrou na ordem cronológica.');
    }

    public function pagar(Request $request, Liquidacao $liquidacao): RedirectResponse
    {
        $d = $request->validate(['pago_em' => ['required', 'date'], 'ordem_bancaria' => ['required', 'string', 'max:60']]);

        return $this->tentar(fn () => $this->servico->pagar($liquidacao, $d['pago_em'], $d['ordem_bancaria'], $request->user()), 'Pagamento registrado.');
    }

    public function quebra(Request $request, Liquidacao $liquidacao): RedirectResponse
    {
        $d = $request->validate(['hipotese' => ['required', Rule::in(array_keys(OrdemCronologica::HIPOTESES))], 'justificativa' => ['required', 'string', 'max:5000']]);

        return $this->tentar(fn () => $this->servico->autorizarQuebra($liquidacao, $d['hipotese'], $d['justificativa'], $request->user()), 'Alteração da ordem justificada. Depois do pagamento, comunique ao controle interno e ao tribunal de contas (art. 141, § 1º).');
    }

    public function comunicacao(Request $request, Liquidacao $liquidacao): RedirectResponse
    {
        $d = $request->validate(['destino' => ['required', 'in:controle,tribunal'], 'data' => ['required', 'date']]);

        return $this->tentar(fn () => $this->servico->registrarComunicacao($liquidacao, $d['destino'], $d['data']), 'Comunicação registrada.');
    }

    public function cancelar(Request $request, Liquidacao $liquidacao): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        return $this->tentar(fn () => $this->servico->cancelar($liquidacao, $d['motivo']), 'Liquidação cancelada.');
    }

    /** Divulgação mensal da ordem cronológica e das justificativas (art. 141, § 3º). Página pública, sem login. */
    public function publico(Request $request, Orgao $orgao): View
    {
        $mes = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('mes')) ? $request->query('mes') : now()->format('Y-m');
        $inicio = CarbonImmutable::parse($mes.'-01');
        $fim = $inicio->endOfMonth();

        $base = Liquidacao::with(['fornecedor', 'contrato', 'ordem'])->where('orgao_id', $orgao->id)->where('situacao', '!=', 'cancelado');
        $pagos = (clone $base)->where('situacao', 'pago')->whereBetween('pago_em', [$inicio->toDateString(), $fim->toDateString()])->orderBy('pago_em')->orderBy('id')->get();
        $aPagar = (clone $base)->where('situacao', 'liquidado')->whereDate('liquidado_em', '<=', $fim->toDateString())->emOrdem()->get();

        return view('pagamentos.publico', ['orgao' => $orgao, 'mes' => $inicio, 'pagos' => $pagos, 'filas' => $aPagar->groupBy(fn ($l) => $l->fonte_recurso.'|'.$l->categoria)->sortKeys()]);
    }

    /** @return array<string, mixed> */
    private function dadosLiquidacao(Request $request): array
    {
        $request->merge(['valor_bruto' => decimal_br($request->input('valor_bruto')), 'retencoes' => decimal_br($request->input('retencoes')) ?? 0]);

        return $request->validate([
            'categoria' => ['required', Rule::in(array_keys(OrdemCronologica::CATEGORIAS))], 'fonte_recurso' => ['required', 'string', 'max:120'],
            'nota_fiscal' => ['required', 'string', 'max:60'], 'nota_emitida_em' => ['required', 'date'], 'valor_bruto' => ['required', 'numeric', 'gt:0'],
            'retencoes' => ['nullable', 'numeric', 'gte:0'], 'atesto' => ['required', 'string', 'max:5000'],
        ]);
    }

    private function tentar(callable $acao, string $ok): RedirectResponse
    {
        try {
            $acao();
        } catch (RegraViolada $e) {
            return back()->withInput()->with('erro', 'Não foi possível concluir.')->with('motivos', $e->motivos);
        }

        return back()->with('sucesso', $ok);
    }
}
