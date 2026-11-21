<?php

namespace App\Http\Controllers;

use App\Licitacao\Enums\CategoriaProcesso;
use App\Licitacao\Pca\PainelPca;
use App\Licitacao\Regras\CalendarioPca;
use App\Licitacao\Regras\ConsolidacaoDfd;
use App\Licitacao\Regras\IndicadoresPca;
use App\Models\Demanda;
use App\Models\ItemPca;
use App\Models\PlanoContratacao;
use App\Models\Processo;
use App\Models\Unidade;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/** Abas de acompanhamento do PCA: visão geral, quadro de DFDs e indicadores operacionais (art. 12, VII). */
class PcaPainelController extends Controller
{
    /** Colunas do quadro: em elaboração (inclui devolvidos), em análise e finalizados. */
    public const COLUNAS = [
        'elaboracao' => ['Em elaboração', ['rascunho', 'recusada']],
        'analise' => ['Em análise', ['enviada']],
        'finalizados' => ['Finalizados', ['aceita', 'consolidada', 'atendida']],
    ];

    public function dfds(Request $request, PlanoContratacao $plano): View
    {
        $dfds = Demanda::with(['unidade', 'solicitante'])->withCount('itens')
            ->where('exercicio', $plano->ano)
            ->when(! $request->user()->can('pca.gerenciar'), fn ($q) => $q->where(fn ($q) => $q->where('solicitante_id', $request->user()->id)->orWhere('situacao', '!=', 'rascunho')))
            ->when($request->filled('q'), fn ($q) => $q->where('objeto', 'ilike', '%'.addcslashes((string) $request->string('q'), '%_\\').'%'))
            ->when($request->filled('unidade'), fn ($q) => $q->where('unidade_id', $request->integer('unidade')))
            ->orderByDesc('id')->get();

        return view('pca.dfds', [
            'plano' => $plano, 'resumo' => PainelPca::resumo($plano),
            'colunas' => collect(self::COLUNAS)->map(fn ($c) => ['titulo' => $c[0], 'dfds' => $dfds->whereIn('situacao', $c[1])->values()]),
            'unidades' => Unidade::where('ativo', true)->orderBy('sigla')->get(),
        ]);
    }

    public function visaoGeral(PlanoContratacao $plano): View
    {
        $itens = ItemPca::with('unidade')->where('plano_contratacao_id', $plano->id)->get();
        $pais = $plano->mapaPais();
        $participantes = $plano->idsParticipantes();
        $siglas = Unidade::where('orgao_id', $plano->orgao_id)->pluck('sigla', 'id');
        $unidadeDe = fn (ItemPca $i) => $i->unidade_id ? ($siglas[ConsolidacaoDfd::participanteDe($pais, $participantes, $i->unidade_id) ?? $i->unidade_id] ?? '—') : 'Sem unidade';

        return view('pca.visao', [
            'plano' => $plano, 'resumo' => PainelPca::resumo($plano),
            'objetos' => $itens->pluck('codigo_catalogo')->filter()->unique()->count() + $itens->whereNull('codigo_catalogo')->count(),
            'porUnidade' => IndicadoresPca::valoresPorUnidade($itens->map(fn (ItemPca $i) => ['unidade' => $unidadeDe($i), 'categoria' => (int) $i->categoria, 'valor' => (float) $i->valor_total_estimado])->all()),
            'calendario' => IndicadoresPca::calendario($plano->ano, $itens->map(fn (ItemPca $i) => ['data' => $i->data_desejada?->toDateString(), 'valor' => (float) $i->valor_total_estimado])->all()),
            'categorias' => CategoriaProcesso::opcoes(),
            'calendarioPlano' => CalendarioPca::etapaAtual($plano->calendario(), today()->toDateString()),
        ]);
    }

    public function operacional(Request $request, PlanoContratacao $plano): View
    {
        $ate = $request->filled('ate') ? Carbon::parse($request->input('ate'))->endOfDay() : now();
        $unidade = $request->integer('unidade') ?: null;

        $processos = Processo::with(['unidade', 'demanda'])->where('ano', $plano->ano)->where('created_at', '<=', $ate)
            ->when($unidade, fn ($q) => $q->where('unidade_id', $unidade))->get();
        $dfds = Demanda::with('unidade')->where('exercicio', $plano->ano)->where('created_at', '<=', $ate)
            ->when($unidade, fn ($q) => $q->where('unidade_id', $unidade))->get();

        $indicadores = IndicadoresPca::calcular(
            $processos->map(fn (Processo $p) => ['unidade' => $p->unidade?->sigla ?? 'Sem unidade', 'planejado' => $p->item_pca_id !== null,
                'tardio' => $p->demanda?->excecao_prazo !== null, 'valor' => (float) $p->valor_estimado])->all(),
            $dfds->map(fn (Demanda $d) => ['unidade' => $d->unidade?->sigla ?? 'Sem unidade', 'situacao' => $d->situacao, 'data' => $d->data_pretendida?->toDateString(), 'valor' => (float) $d->valor_estimado])->all(),
            $ate->toDateString(),
        );

        return view('pca.operacional', [
            'plano' => $plano, 'ind' => $indicadores, 'ate' => $ate, 'unidade' => $unidade,
            'unidades' => Unidade::where('ativo', true)->orderBy('sigla')->get(),
            'foraDoPlano' => $processos->whereNull('item_pca_id')->sortByDesc('valor_estimado')->take(20),
            'emAtraso' => $dfds->whereIn('situacao', ['aceita', 'consolidada'])->filter(fn ($d) => $d->data_pretendida && $d->data_pretendida->lt($ate->copy()->startOfDay()))->sortBy('data_pretendida')->take(20),
        ]);
    }
}
