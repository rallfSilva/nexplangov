<?php

namespace App\Http\Controllers;

use App\Jobs\CotarItemComIa;
use App\Licitacao\Ia\AssistenteIa;
use App\Licitacao\Pca\PlanilhaPca;
use App\Licitacao\Regras\PesquisaPrecos;
use App\Models\ItemProcesso;
use App\Models\LoteCotacaoIa;
use App\Models\Processo;
use App\Rules\CodigoDeCatalogo;
use App\Rules\DescricaoNaoVaga;
use App\Support\Parametros;
use App\Support\Pdf;
use App\Support\Planilha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Cotação automática por IA na pesquisa de preços (art. 23): planilha modelo e importação dos itens, pedido de
 * cotação em fila, andamento e mapa de preços em PDF.
 */
class CotacaoIaController extends Controller
{
    /** Colunas da planilha de itens do processo, com a ajuda da segunda linha. */
    public const COLUNAS = [
        'tipo' => 'M = material (CATMAT) ou S = serviço (CATSER)',
        'codigo_catmat_catser' => 'Só números (opcional: a IA procura se ficar vazio)',
        'descricao' => 'Descrição do item, com a especificação essencial',
        'unidade_fornecimento' => 'UN, CX, RESMA, MÊS...',
        'quantidade' => 'Número maior que zero',
    ];

    public function iniciar(Request $request, Processo $processo): RedirectResponse
    {
        $this->exigirFasePreparatoria($processo);
        $d = $request->validate([
            'escopo' => ['required', Rule::in(array_keys(LoteCotacaoIa::ESCOPOS))],
            'resultados' => ['required', 'integer', Rule::in(LoteCotacaoIa::RESULTADOS)],
        ]);

        if (! AssistenteIa::habilitada()) {
            return back()->with('erro', 'A NexMind IA não está ativada neste servidor.');
        }
        if (LoteCotacaoIa::where('processo_id', $processo->id)->where('situacao', '!=', 'concluido')->exists()) {
            return back()->with('erro', 'Já há uma cotação por IA em andamento neste processo. Aguarde terminar.');
        }

        $itens = $processo->itens()->when($d['escopo'] === 'sem_preco', fn ($q) => $q->where(fn ($q) => $q->whereNull('valor_unitario_estimado')->orWhere('valor_unitario_estimado', '<=', 0)))->orderBy('numero')->get();
        if ($itens->isEmpty()) {
            return back()->with('erro', $d['escopo'] === 'sem_preco' ? 'Todos os itens já têm preço. Escolha "Todos os itens" para cotar de novo.' : 'Inclua os itens antes de cotar.');
        }
        $max = (int) config('nexplangov.cotacao_ia.max_itens_por_lote', 500);
        if ($itens->count() > $max) {
            return back()->with('erro', "Cada cotação por IA aceita até {$max} itens. Divida o pedido.");
        }

        $lote = DB::transaction(function () use ($processo, $itens, $d, $request) {
            $lote = LoteCotacaoIa::create(['orgao_id' => $processo->orgao_id, 'processo_id' => $processo->id, 'user_id' => $request->user()->id, 'escopo' => $d['escopo'], 'resultados' => (int) $d['resultados'], 'total_itens' => $itens->count()]);
            ItemProcesso::whereIn('id', $itens->pluck('id'))->update(['cotacao_ia_situacao' => 'na_fila', 'cotacao_ia_mensagem' => null]);

            return $lote;
        });

        foreach ($itens as $item) {
            CotarItemComIa::dispatch($lote->id, $item->id)->afterCommit();
        }

        return redirect()->route('processos.show', [$processo, 'aba' => 'itens'])
            ->with('sucesso', "Cotação por IA de {$itens->count()} item(ns) na fila. Você recebe um e-mail quando terminar; pode sair desta tela.");
    }

    public function andamento(Processo $processo): JsonResponse
    {
        $lote = LoteCotacaoIa::where('processo_id', $processo->id)->latest('id')->first();

        return response()->json($lote ? [
            'situacao' => $lote->situacao, 'total' => $lote->total_itens, 'processados' => $lote->itens_processados,
            'cotados' => $lote->itens_cotados, 'percentual' => $lote->percentual(),
        ] : ['situacao' => null]);
    }

    public function modelo(Processo $processo): BinaryFileResponse
    {
        $arquivo = storage_path('app/private/tmp-'.Str::random(24).'.xlsx');
        @mkdir(dirname($arquivo), 0750, true);

        Planilha::xlsx($arquivo, ['Itens' => [
            'cabecalho' => array_keys(self::COLUNAS),
            'ajuda' => array_values(self::COLUNAS),
            'larguras' => [8, 22, 60, 20, 14],
            'linhas' => [['M', '', 'Papel A4 75 g/m², branco, pacote com 500 folhas (exemplo: apague esta linha)', 'RESMA', 200]],
        ]]);

        return response()->download($arquivo, 'modelo-itens-cotacao.xlsx')->deleteFileAfterSend();
    }

    /** Importa os itens da planilha modelo. Tudo ou nada: com qualquer linha errada, nenhum item entra. */
    public function importar(Request $request, Processo $processo): RedirectResponse
    {
        $this->exigirFasePreparatoria($processo);
        $request->validate(['planilha' => ['required', 'file', 'max:5120', 'mimes:xlsx,csv,txt']], ['planilha.mimes' => 'Envie a planilha em .xlsx ou .csv.']);
        $arquivo = $request->file('planilha');

        try {
            $linhas = PlanilhaPca::ler($arquivo->getRealPath(), $arquivo->getClientOriginalExtension());
        } catch (RuntimeException $e) {
            return back()->with('erro', $e->getMessage());
        }

        $novos = [];
        $erros = [];
        foreach ($linhas as $l) {
            $v = $l['valores'];
            $tipo = strtoupper(trim((string) ($v['material_servico'] ?? 'M'))) ?: 'M';
            $dados = [
                'material_servico' => in_array($tipo, ['M', 'S'], true) ? $tipo : ($tipo[0] ?? 'M'),
                'codigo_catalogo' => ($c = preg_replace('/\D/', '', (string) ($v['codigo_catalogo'] ?? ''))) !== '' ? $c : null,
                'descricao' => trim((string) ($v['descricao'] ?? '')),
                'unidade_medida' => mb_substr(trim((string) ($v['unidade_fornecimento'] ?? '')), 0, 30),
                'quantidade' => is_int($v['quantidade'] ?? null) || is_float($v['quantidade'] ?? null) ? (float) $v['quantidade'] : decimal_br(trim((string) ($v['quantidade'] ?? ''))),
            ];
            $val = Validator::make($dados, [
                'material_servico' => ['required', Rule::in(['M', 'S'])],
                'codigo_catalogo' => ['nullable', 'string', 'max:20', new CodigoDeCatalogo($dados['material_servico'])],
                'descricao' => ['required', 'string', 'max:2000', new DescricaoNaoVaga],
                'unidade_medida' => ['required', 'string', 'max:30'],
                'quantidade' => ['required', 'numeric', 'gt:0'],
            ]);
            if ($val->fails()) {
                $erros[] = "Linha {$l['linha']}: ".implode(' ', $val->errors()->all());

                continue;
            }
            $novos[] = $val->validated();
        }

        if ($erros) {
            return back()->with('erro', 'Nenhum item importado. Corrija a planilha: '.implode(' · ', array_slice($erros, 0, 8)).(count($erros) > 8 ? ' · e mais '.(count($erros) - 8).' linha(s).' : ''));
        }
        if (! $novos) {
            return back()->with('erro', 'A planilha não tem itens.');
        }

        DB::transaction(function () use ($processo, $novos) {
            Processo::whereKey($processo->id)->lockForUpdate()->first(); // dois envios ao mesmo tempo não repetem número
            $numero = (int) $processo->itens()->max('numero');
            foreach ($novos as $dados) {
                $processo->itens()->create($dados + ['orgao_id' => $processo->orgao_id, 'numero' => ++$numero, 'tipo_beneficio' => 4]);
            }
        });

        return redirect()->route('processos.show', [$processo, 'aba' => 'itens'])->with('sucesso', count($novos).' item(ns) importado(s). Agora use "Cotar com IA" ou lance as cotações.');
    }

    /** Mapa de preços em PDF, com as seções que o servidor escolher. */
    public function mapa(Request $request, Processo $processo): Response
    {
        $opcoes = $request->validate([
            'colunas' => ['nullable', 'array'], 'colunas.*' => [Rule::in(['minimo', 'media', 'mediana', 'media_ponderada'])],
        ]);
        // Sem o formulário de opções, o mapa sai completo.
        $configurado = $request->boolean('configurado');
        $opcoes['colunas'] = $configurado ? array_values(array_intersect(['minimo', 'media', 'mediana', 'media_ponderada'], $opcoes['colunas'] ?? [])) : ['minimo', 'media', 'mediana', 'media_ponderada'];
        foreach (['cotacoes', 'descartadas', 'justificativa'] as $secao) {
            $opcoes[$secao] = ! $configurado || $request->boolean($secao);
        }

        $processo->load(['itens.cotacoes', 'orgao']);
        $pesquisa = new PesquisaPrecos(Parametros::valor('preco_tolerancia_pct', $processo->orgao_id) / 100);
        $precos = $processo->itens->mapWithKeys(fn ($item) => [$item->id => $pesquisa->calcular($item->cotacoesParaCalculo(), $item->metodo_preco)]);

        $pdf = Pdf::daView('processos.mapa-precos-pdf', compact('processo', 'precos', 'opcoes'), "Mapa de preços · processo {$processo->identificacao()}");

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="mapa-de-precos-'.Str::slug($processo->identificacao()).'.pdf"']);
    }

    private function exigirFasePreparatoria(Processo $processo): void
    {
        abort_unless($processo->editavel(), 422, 'Itens e preços só mudam na fase preparatória.');
    }
}
