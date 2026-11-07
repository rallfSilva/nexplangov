<?php

namespace App\Http\Controllers;

use App\Licitacao\Enums\AmparoLegal;
use App\Licitacao\Enums\Modalidade;
use App\Licitacao\Enums\SituacaoProcesso;
use App\Licitacao\Regras\ConsolidacaoPca;
use App\Licitacao\Regras\CronogramaPca;
use App\Licitacao\Regras\FracionamentoHistorico;
use App\Models\CatalogoItem;
use App\Models\DotacaoOrcamentaria;
use App\Models\ItemPca;
use App\Models\PlanoContratacao;
use App\Models\Processo;
use App\Models\ValorLimite;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Consolidação do PCA: sugestões de agrupamento (art. 40, V, e § 2º), compras recorrentes por dispensa
 * (art. 75, § 1º) e cronograma mensal com a dotação de cada natureza de despesa (art. 18, caput, e art. 150).
 */
class PcaConsolidacaoController extends Controller
{
    public function show(Request $request, PlanoContratacao $plano): View
    {
        $plano->load('itens.unidade');
        $itens = $plano->itens;
        $classes = CatalogoItem::whereIn('codigo', $itens->pluck('codigo_catalogo')->filter()->unique())->get(['tipo', 'codigo', 'classe_codigo', 'classe_nome'])
            ->keyBy(fn ($c) => $c->tipo.':'.$c->codigo);

        $sugestoes = ConsolidacaoPca::sugestoes($itens->map(function (ItemPca $i) use ($classes) {
            $c = $classes[$i->material_servico.':'.$i->codigo_catalogo] ?? null;

            return ['id' => $i->id, 'numero' => (int) $i->numero, 'unidade' => $i->unidade?->sigla, 'tipo' => $i->material_servico, 'codigo' => $i->codigo_catalogo ?: null,
                'classe' => $c?->classe_codigo, 'classe_nome' => $c?->classe_nome, 'natureza' => $i->natureza_despesa, 'descricao' => $i->descricao,
                'valor' => (float) $i->valor_total_estimado, 'data' => $i->data_desejada?->toDateString()];
        })->all());

        $dotacoes = DotacaoOrcamentaria::where('exercicio', $plano->ano)->orderBy('natureza_despesa')->get();
        $cronograma = CronogramaPca::montar($plano->ano, $itens->map(fn (ItemPca $i) => ['natureza' => $i->natureza_despesa, 'valor' => (float) $i->valor_total_estimado, 'data' => $i->data_desejada?->toDateString()])->all(),
            $dotacoes->mapWithKeys(fn ($d) => [$d->natureza_despesa => (float) $d->valor_autorizado])->all());

        return view('pca.consolidacao', [
            'plano' => $plano, 'itens' => $itens->keyBy('id'), 'sugestoes' => $sugestoes, 'cronograma' => $cronograma, 'dotacoes' => $dotacoes,
            'recorrentes' => $this->recorrentes($request),
        ]);
    }

    public function salvarDotacao(Request $request, PlanoContratacao $plano): RedirectResponse
    {
        $request->merge(['valor_autorizado' => decimal_br($request->input('valor_autorizado'))]);
        $d = $request->validate(['natureza_despesa' => ['required', 'string', 'max:40'], 'descricao' => ['nullable', 'string', 'max:200'], 'valor_autorizado' => ['required', 'numeric', 'gte:0']]);

        DotacaoOrcamentaria::updateOrCreate(['orgao_id' => $plano->orgao_id, 'exercicio' => $plano->ano, 'natureza_despesa' => trim($d['natureza_despesa'])],
            ['descricao' => $d['descricao'] ?? null, 'valor_autorizado' => $d['valor_autorizado'], 'registrado_por' => $request->user()->id]);

        return redirect()->route('pca.consolidacao', $plano)->with('sucesso', 'Dotação salva.');
    }

    public function excluirDotacao(PlanoContratacao $plano, DotacaoOrcamentaria $dotacao): RedirectResponse
    {
        abort_unless($dotacao->exercicio === $plano->ano, 404);
        $dotacao->delete();

        return redirect()->route('pca.consolidacao', $plano)->with('sucesso', 'Dotação excluída.');
    }

    /** Dispensas por valor (art. 75, I e II) dos últimos 12 meses, por natureza e por código dos itens. */
    private function recorrentes(Request $request): array
    {
        $hoje = CarbonImmutable::today();
        $processos = Processo::with('itens:id,processo_id,codigo_catalogo,quantidade,valor_unitario_homologado,valor_total_estimado')
            ->where('modalidade', Modalidade::Dispensa->value)->whereIn('amparo_legal', [AmparoLegal::Art75I->value, AmparoLegal::Art75II->value])
            ->whereNotIn('situacao', [SituacaoProcesso::Revogado->value, SituacaoProcesso::Anulado->value, SituacaoProcesso::Fracassado->value, SituacaoProcesso::Deserto->value])
            ->where('created_at', '>=', $hoje->subMonths(FracionamentoHistorico::JANELA_MESES))->get();

        $compras = [];
        foreach ($processos as $p) {
            $data = $p->created_at->toDateString();
            $compras[] = ['processo' => $p->identificacao(), 'data' => $data, 'natureza' => $p->natureza_despesa, 'codigo' => null, 'valor' => (float) ($p->valor_homologado ?? $p->valor_estimado)];
            foreach ($p->itens->whereNotNull('codigo_catalogo')->unique('codigo_catalogo') as $i) {
                $valor = $i->valor_unitario_homologado !== null ? (float) $i->quantidade * (float) $i->valor_unitario_homologado : (float) $i->valor_total_estimado;
                $compras[] = ['processo' => $p->identificacao(), 'data' => $data, 'natureza' => null, 'codigo' => $i->codigo_catalogo, 'valor' => $valor];
            }
        }

        $limite = (float) ValorLimite::where('ano', (int) $hoje->format('Y'))->where('dispositivo', 'art75_II')->value('valor');
        if ($request->user()->orgao?->limite_dispensa_em_dobro) {
            $limite *= 2;
        }

        return FracionamentoHistorico::alertas($compras, $hoje, $limite);
    }
}
