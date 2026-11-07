<?php

namespace App\Http\Controllers;

use App\Licitacao\Enums\Modalidade;
use App\Models\Capacitacao;
use App\Models\EtapaProcesso;
use App\Models\EventoProcesso;
use App\Models\Impugnacao;
use App\Models\ItemPca;
use App\Models\ItemProcesso;
use App\Models\OrdemFornecimento;
use App\Models\Processo;
use App\Models\User;
use App\Support\Parametros;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Painel de governança das contratações (art. 11, parágrafo único): indicadores para a alta administração
 * acompanhar planejamento, competição, economicidade, prazos e capacitação. Só contagens do próprio órgão.
 */
class GovernancaController extends Controller
{
    public function __invoke(Request $request): View
    {
        $ano = (int) $request->integer('ano', (int) now()->year);
        $orgaoId = $request->user()->orgao_id;

        // 1. Execução do PCA: itens do plano que já viraram item de processo.
        $itensPca = ItemPca::whereHas('plano', fn ($q) => $q->where('ano', $ano))->count();
        $itensPcaUsados = ItemProcesso::whereNotNull('item_pca_id')->whereHas('itemPca.plano', fn ($q) => $q->where('ano', $ano))->distinct('item_pca_id')->count('item_pca_id');

        // 2. Competição: peso das contratações diretas no total do ano.
        $processos = Processo::where('ano', $ano)->whereNotIn('situacao', ['revogado', 'anulado']);
        $total = (clone $processos)->count();
        $valorTotal = (float) (clone $processos)->sum('valor_estimado');
        $diretas = (clone $processos)->whereIn('modalidade', [Modalidade::Dispensa->value, Modalidade::Inexigibilidade->value]);
        $qtdDiretas = (clone $diretas)->count();
        $valorDiretas = (float) (clone $diretas)->sum('valor_estimado');

        // 3. Economicidade: diferença entre estimado e homologado nos processos concluídos.
        $concluidos = Processo::where('ano', $ano)->whereIn('situacao', ['homologado', 'contratado'])->where('valor_homologado', '>', 0);
        $estimadoConcluidos = (float) (clone $concluidos)->sum('valor_estimado');
        $homologado = (float) (clone $concluidos)->sum('valor_homologado');

        // 4. Tempo da fase preparatória: da abertura do processo à publicação do edital ou aviso.
        $dias = EventoProcesso::query()->join('processos', 'processos.id', '=', 'eventos_processo.processo_id')
            ->where('eventos_processo.para', 'publicado')->where('processos.ano', $ano)
            ->when($orgaoId, fn ($q) => $q->where('processos.orgao_id', $orgaoId))
            ->selectRaw('avg(extract(epoch from (eventos_processo.created_at - processos.created_at)) / 86400) as media')->value('media');

        // 5. Prazos.
        $pecasVencidas = EtapaProcesso::whereNotNull('prazo_em')->whereDate('prazo_em', '<', today())
            ->whereHas('processo', fn ($q) => $q->whereNotIn('situacao', ['contratado', 'revogado', 'anulado', 'deserto', 'fracassado']))->get()
            ->reject(fn ($e) => $e->processo->documentoConcluido($e->tipo))->count();
        $impugnacoesAtrasadas = Impugnacao::where('situacao', 'recebida')->whereDate('prazo_resposta_em', '<', today())->count();
        $ordensAtrasadas = OrdemFornecimento::whereIn('situacao', ['emitida', 'parcial'])->whereDate('prazo_entrega', '<', today())->count();

        // 6. Capacitação dos agentes (art. 7º, II).
        $agentes = User::role(CapacitacaoController::PERFIS_ALVO)->where('ativo', true)->when($orgaoId, fn ($q) => $q->where('orgao_id', $orgaoId))->pluck('id');
        $capacitados = Capacitacao::whereIn('user_id', $agentes)->whereDate('concluida_em', '>=', today()->subMonths(Parametros::valor('capacitacao_validade_meses')))->distinct('user_id')->count('user_id');

        $porModalidade = (clone $processos)->selectRaw('modalidade, count(*) as total, sum(valor_estimado) as valor')->groupBy('modalidade')->orderByDesc('valor')->get();

        return view('governanca', [
            'ano' => $ano,
            'pca' => ['itens' => $itensPca, 'usados' => $itensPcaUsados, 'pct' => $itensPca ? round($itensPcaUsados / $itensPca * 100) : null],
            'competicao' => ['total' => $total, 'diretas' => $qtdDiretas, 'pct_qtd' => $total ? round($qtdDiretas / $total * 100) : null, 'pct_valor' => $valorTotal > 0 ? round($valorDiretas / $valorTotal * 100) : null],
            'economia' => ['estimado' => $estimadoConcluidos, 'homologado' => $homologado, 'pct' => $estimadoConcluidos > 0 ? round((1 - $homologado / $estimadoConcluidos) * 100, 1) : null],
            'diasPreparatoria' => $dias !== null ? (int) round((float) $dias) : null,
            'prazos' => ['pecas' => $pecasVencidas, 'impugnacoes' => $impugnacoesAtrasadas, 'ordens' => $ordensAtrasadas],
            'capacitacao' => ['agentes' => $agentes->count(), 'capacitados' => $capacitados, 'pct' => $agentes->count() ? round($capacitados / $agentes->count() * 100) : null],
            'porModalidade' => $porModalidade,
        ]);
    }
}
