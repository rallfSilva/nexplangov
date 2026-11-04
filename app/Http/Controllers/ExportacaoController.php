<?php

namespace App\Http\Controllers;

use App\Licitacao\Enums\CategoriaProcesso;
use App\Licitacao\Fluxo\Assinaturas;
use App\Licitacao\TiposDocumento;
use App\Models\Contrato;
use App\Models\ItemPca;
use App\Models\PlanoContratacao;
use App\Models\Processo;
use App\Support\Auditoria;
use App\Support\DocumentoWord;
use App\Support\Planilha;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportações editáveis: PCA em XLSX, DOCX e CSV; peças do processo em DOCX; listas de processos e
 * contratos em XLSX. Toda exportação entra na trilha de auditoria. O arquivo é cópia de trabalho:
 * a peça assinada e com validade é a do sistema (assinatura eletrônica com código de verificação).
 */
class ExportacaoController extends Controller
{
    public function pca(PlanoContratacao $plano, string $formato): BinaryFileResponse|StreamedResponse
    {
        $plano->load(['itens.unidade', 'orgao', 'aprovador']);
        $cat = CategoriaProcesso::opcoes();
        $nome = "pca-{$plano->ano}".($plano->versao_atual ? "-v{$plano->versao_atual}" : '');
        Auditoria::registrar('exportacao', $plano, "PCA {$plano->ano} exportado em ".strtoupper($formato));

        $cabecalho = ['Nº', 'Tipo', 'Categoria', 'Código CATMAT/CATSER', 'Descrição', 'Unidade', 'Quantidade', 'Valor unitário (R$)', 'Valor total (R$)', 'Unidade requisitante', 'Data desejada', 'Prioridade', 'Natureza da despesa'];
        $linha = fn (ItemPca $i) => [
            $i->numero, $i->material_servico === 'S' ? 'Serviço' : 'Material', $cat[$i->categoria] ?? $i->categoria, $i->codigo_catalogo, $i->descricao,
            $i->unidade_fornecimento, (float) $i->quantidade, (float) $i->valor_unitario_estimado, (float) $i->valor_total_estimado,
            $i->unidade?->sigla, $i->data_desejada, ItemPca::PRIORIDADES[$i->prioridade] ?? '', $i->natureza_despesa,
        ];

        if ($formato === 'csv') {
            return response()->streamDownload(function () use ($plano, $cabecalho, $linha) {
                $h = fopen('php://output', 'w');
                fwrite($h, "\xEF\xBB\xBF");
                fputcsv($h, $cabecalho, ';', '"', '');
                foreach ($plano->itens as $i) {
                    $l = $linha($i);
                    $l[6] = numero_br($l[6], 4);
                    $l[7] = numero_br($l[7], 4);
                    $l[8] = numero_br($l[8]);
                    $l[10] = $l[10]?->format('d/m/Y');
                    // Proteção contra fórmula em planilha (CSV injection).
                    fputcsv($h, array_map(fn ($c) => is_string($c) && $c !== '' && in_array($c[0], ['=', '+', '-', '@'], true) ? "'".$c : $c, $l), ';', '"', '');
                }
                fclose($h);
            }, "{$nome}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $arquivo = $this->temporario($formato);

        if ($formato === 'xlsx') {
            $porCategoria = $plano->itens->groupBy('categoria')->map(fn ($g, $c) => [$cat[$c] ?? $c, $g->count(), (float) $g->sum('valor_total_estimado')])->values();
            $porUnidade = $plano->itens->groupBy(fn ($i) => $i->unidade?->sigla ?? 'Não informada')->map(fn ($g, $u) => [$u, $g->count(), (float) $g->sum('valor_total_estimado')])->values();
            $abas = [
                'Itens' => ['cabecalho' => $cabecalho, 'linhas' => $plano->itens->map($linha)->all(), 'larguras' => [6, 10, 18, 14, 50, 10, 12, 16, 16, 14, 13, 11, 16]],
                'Por categoria' => ['cabecalho' => ['Categoria', 'Itens', 'Valor (R$)'], 'linhas' => $porCategoria->all(), 'larguras' => [28, 10, 18]],
                'Por unidade' => ['cabecalho' => ['Unidade requisitante', 'Itens', 'Valor (R$)'], 'linhas' => $porUnidade->all(), 'larguras' => [28, 10, 18]],
            ];
            if ($plano->versoes()->exists()) {
                $abas['Versões'] = ['cabecalho' => ['Versão', 'Aprovada em', 'Itens', 'Valor total (R$)', 'Justificativa da revisão'], 'larguras' => [8, 18, 8, 18, 70],
                    'linhas' => $plano->versoes()->get()->map(fn ($v) => [$v->numero, $v->aprovado_em, $v->qtd_itens, (float) $v->valor_total, $v->justificativa ?? 'Versão inicial'])->all()];
            }
            Planilha::xlsx($arquivo, $abas);

            return response()->download($arquivo, "{$nome}.xlsx")->deleteFileAfterSend();
        }

        $doc = new DocumentoWord("Plano de Contratações Anual {$plano->ano}", $plano->orgao?->nome);
        $situacao = PlanoContratacao::SITUACOES[$plano->situacao] ?? $plano->situacao;
        $doc->subtitulo("Situação: {$situacao}".($plano->versao_atual ? " · versão {$plano->versao_atual}" : '').($plano->aprovado_em ? ' · aprovado por '.($plano->aprovador?->name ?? '—').' em '.$plano->aprovado_em->format('d/m/Y') : '').' · art. 12, VII, da Lei nº 14.133/2021');
        $doc->secao('Resumo')->tabela(['Itens', 'Valor total estimado', 'Prioridade alta'], [[
            (string) $plano->itens->count(), reais($plano->itens->sum('valor_total_estimado')), (string) $plano->itens->where('prioridade', 1)->count(),
        ]]);
        $doc->secao('Itens do plano')->tabela(
            ['Nº', 'Descrição', 'Categoria', 'Unid. req.', 'Qtd.', 'Valor total', 'Data', 'Prior.'],
            $plano->itens->map(fn (ItemPca $i) => [
                $i->numero, $i->descricao.($i->codigo_catalogo ? " ({$i->codigo_catalogo})" : ''), $cat[$i->categoria] ?? '', $i->unidade?->sigla ?? '—',
                numero_br($i->quantidade, 0).' '.$i->unidade_fornecimento, reais($i->valor_total_estimado), $i->data_desejada?->format('m/Y') ?? '—', ItemPca::PRIORIDADES[$i->prioridade] ?? '',
            ]),
            [500, 3800, 1300, 900, 1100, 1300, 700, 700], [4, 5],
        );
        $doc->nota('Divulgação obrigatória no sítio eletrônico oficial e no PNCP (art. 12, § 1º). Cópia editável gerada pelo NexPlanGov.');
        $doc->salvar($arquivo);

        return response()->download($arquivo, "{$nome}.docx")->deleteFileAfterSend();
    }

    /** Peça do processo (DFD, ETP, TR...) em DOCX, com os campos e as assinaturas registradas. */
    public function peca(Processo $processo, string $tipo): BinaryFileResponse|RedirectResponse
    {
        abort_unless(array_key_exists($tipo, TiposDocumento::todos()), 404);
        $def = TiposDocumento::get($tipo);
        $d = $processo->documentoAtual($tipo) ?? abort(404);
        if ($d->origem === 'arquivo') {
            return back()->with('erro', 'Esta peça foi enviada como arquivo. Baixe o arquivo original.');
        }
        $d->load('assinaturas');

        $doc = new DocumentoWord($def['rotulo'], $processo->orgao?->nome);
        $doc->subtitulo("Processo {$processo->identificacao()} · {$def['base']} · versão {$d->versao}".($d->concluido_em ? ' · concluída em '.$d->concluido_em->format('d/m/Y H:i') : ' · em elaboração'));
        $doc->secao('Objeto')->paragrafos($processo->objeto);
        foreach ($def['campos'] as $campo => $c) {
            $doc->secao($c['rotulo'])->paragrafos($d->conteudo[$campo] ?? null);
        }
        if ($d->assinaturas->isNotEmpty()) {
            $doc->secao('Assinaturas eletrônicas registradas no sistema')->tabela(['Nome', 'Perfil', 'Data', 'Código'],
                $d->assinaturas->map(fn ($a) => [$a->nome, $a->perfil, $a->assinado_em->format('d/m/Y H:i'), Assinaturas::formatarCodigo($a->codigo)]));
        }
        $doc->nota('Cópia editável. A peça com validade é a do sistema; confira as assinaturas em '.route('assinaturas.validar').'. Impressão digital (SHA-256): '.$d->hashAtual());

        $arquivo = $doc->salvar($this->temporario('docx'));
        Auditoria::registrar('exportacao', $processo, "Peça {$def['rotulo']} do processo {$processo->identificacao()} exportada em DOCX");

        return response()->download($arquivo, Str::slug($tipo.'-'.$processo->identificacao()).'.docx')->deleteFileAfterSend();
    }

    /** Lista de processos em XLSX, com os mesmos filtros da tela. */
    public function processos(Request $request): BinaryFileResponse
    {
        $processos = Processo::with('unidade')
            ->when($request->filled('situacao'), fn ($q) => $q->where('situacao', $request->string('situacao')))
            ->when($request->filled('modalidade'), fn ($q) => $q->where('modalidade', $request->integer('modalidade')))
            ->when($request->filled('busca'), function ($q) use ($request) {
                $termo = '%'.mb_strtolower($request->string('busca')).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(objeto) like ?', [$termo])->orWhere('numero', 'like', $termo));
            })
            ->latest('id')->limit(10000)->get();
        $cat = CategoriaProcesso::opcoes();

        $arquivo = Planilha::xlsx($this->temporario('xlsx'), ['Processos' => [
            'cabecalho' => ['Processo', 'Nº SEI', 'Objeto', 'Modalidade', 'Categoria', 'Unidade', 'Situação', 'Valor estimado (R$)', 'Valor homologado (R$)', 'Abertura das propostas', 'Criado em'],
            'larguras' => [12, 18, 60, 24, 18, 12, 22, 18, 18, 18, 14],
            'linhas' => $processos->map(fn (Processo $p) => [
                $p->identificacao(), $p->numero_sei, $p->objeto, $p->modalidadeEnum()->rotulo(), $cat[$p->categoria] ?? $p->categoria, $p->unidade?->sigla,
                $p->situacaoEnum()->rotulo(), (float) $p->valor_estimado, $p->valor_homologado === null ? null : (float) $p->valor_homologado, $p->abertura_propostas_em, $p->created_at,
            ])->all(),
        ]]);
        Auditoria::registrar('exportacao', null, "Lista de processos exportada em XLSX ({$processos->count()} registros)");

        return response()->download($arquivo, 'processos-'.now()->format('Y-m-d').'.xlsx')->deleteFileAfterSend();
    }

    public function contratos(Request $request): BinaryFileResponse
    {
        $contratos = Contrato::with('fornecedor')
            ->when($request->query('filtro') === 'vencendo', fn ($q) => $q->where('situacao', 'vigente')->whereDate('vigencia_fim', '<=', today()->addDays(120)))
            ->when($request->filled('busca'), fn ($q) => $q->whereRaw('lower(objeto) like ?', ['%'.mb_strtolower($request->string('busca')).'%']))
            ->orderBy('vigencia_fim')->limit(10000)->get();

        $arquivo = Planilha::xlsx($this->temporario('xlsx'), ['Contratos' => [
            'cabecalho' => ['Contrato', 'Tipo', 'Objeto', 'Fornecedor', 'CNPJ/CPF', 'Valor inicial (R$)', 'Valor global (R$)', 'Valor atualizado (R$)', 'Assinatura', 'Início da vigência', 'Fim da vigência', 'Situação'],
            'larguras' => [12, 14, 56, 36, 20, 16, 16, 18, 12, 14, 14, 12],
            'linhas' => $contratos->map(fn (Contrato $c) => [
                $c->identificacao(), Contrato::TIPOS[$c->tipo] ?? $c->tipo, $c->objeto, $c->fornecedor?->razao_social, $c->fornecedor?->documento,
                (float) $c->valor_inicial, (float) $c->valor_global, $c->valorAtualizadoTotal(), $c->assinatura, $c->vigencia_inicio, $c->vigencia_fim,
                Contrato::SITUACOES[$c->situacao] ?? $c->situacao,
            ])->all(),
        ]]);
        Auditoria::registrar('exportacao', null, "Lista de contratos exportada em XLSX ({$contratos->count()} registros)");

        return response()->download($arquivo, 'contratos-'.now()->format('Y-m-d').'.xlsx')->deleteFileAfterSend();
    }

    private function temporario(string $extensao): string
    {
        $arquivo = storage_path('app/private/tmp-'.Str::random(24).'.'.$extensao);
        @mkdir(dirname($arquivo), 0750, true);

        return $arquivo;
    }
}
