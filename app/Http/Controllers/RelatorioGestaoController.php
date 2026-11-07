<?php

namespace App\Http\Controllers;

use App\Licitacao\Regras\IndicadoresGestao;
use App\Models\Contrato;
use App\Models\ItemPca;
use App\Models\ItemProcesso;
use App\Models\Processo;
use App\Support\Auditoria;
use App\Support\Exportacao;
use App\Support\Pdf;
use App\Support\Planilha;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Painéis gerenciais (10.1 a 10.3): visão por secretaria, perfil dos fornecedores contratados e fomento ao mercado
 * local (art. 11, parágrafo único; art. 40, § 2º, II), com exportação em PDF, CSV, TXT e XLSX.
 */
class RelatorioGestaoController extends Controller
{
    public const RELATORIOS = ['secretarias' => 'Contratações por secretaria', 'fornecedores' => 'Perfil dos fornecedores contratados', 'fomento' => 'Fomento ao mercado local e regional'];

    public function index(Request $request): View
    {
        $ano = $this->ano($request);

        return view('relatorios.gestao', ['ano' => $ano, 'orgao' => $request->user()->orgao] + $this->dados($request, $ano));
    }

    public function exportar(Request $request, string $relatorio, string $formato): Response
    {
        abort_unless(isset(self::RELATORIOS[$relatorio]), 404);
        $ano = $this->ano($request);
        [$cabecalho, $linhas] = $this->tabela($relatorio, $this->dados($request, $ano));
        $titulo = self::RELATORIOS[$relatorio].' · '.$ano.' · '.($request->user()->orgao?->nome ?? 'Todos os órgãos');
        $nome = "{$relatorio}-{$ano}";
        Auditoria::registrar('exportacao', null, "Relatório \"{$titulo}\" em ".strtoupper($formato));

        return match ($formato) {
            'csv' => response(Exportacao::csv($cabecalho, $linhas), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$nome}.csv\""]),
            'txt' => response(Exportacao::txt($titulo, $cabecalho, $linhas), 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$nome}.txt\""]),
            'pdf' => response(Pdf::daView('relatorios.tabela-pdf', ['titulo' => $titulo, 'cabecalho' => $cabecalho, 'linhas' => $linhas, 'orgao' => $request->user()->orgao], $titulo), 200,
                ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename=\"{$nome}.pdf\""]),
            'xlsx' => response()->download(Planilha::xlsx(tempnam(sys_get_temp_dir(), 'rel').'.xlsx', [self::RELATORIOS[$relatorio] => ['cabecalho' => $cabecalho, 'linhas' => $linhas]]), "{$nome}.xlsx")->deleteFileAfterSend(),
            default => abort(404),
        };
    }

    private function ano(Request $request): int
    {
        $ano = $request->integer('ano', (int) now()->year);

        return $ano >= 2023 && $ano <= 2100 ? $ano : (int) now()->year;
    }

    private function dados(Request $request, int $ano): array
    {
        $linhas = [];
        $processos = Processo::with('unidade:id,sigla')->where('ano', $ano)->whereNotIn('situacao', ['revogado', 'anulado'])->get(['id', 'unidade_id', 'situacao', 'valor_estimado', 'valor_homologado']);
        foreach ($processos as $p) {
            $linhas[] = ['unidade' => $p->unidade?->sigla, 'andamento' => ! in_array($p->situacao, ['homologado', 'contratado', 'deserto', 'fracassado'], true), 'valor' => (float) ($p->valor_homologado ?? $p->valor_estimado)];
        }
        $usados = ItemProcesso::whereNotNull('item_pca_id')->distinct()->pluck('item_pca_id')->flip();
        foreach (ItemPca::with('unidade:id,sigla')->whereHas('plano', fn ($q) => $q->where('ano', $ano))->get(['id', 'unidade_id']) as $i) {
            $linhas[] = ['unidade' => $i->unidade?->sigla, 'itens_pca' => 1, 'itens_pca_usados' => isset($usados[$i->id]) ? 1 : 0];
        }
        $contratos = Contrato::with(['fornecedor:id,porte,uf,municipio', 'processo.unidade:id,sigla'])->where('ano', $ano)->get();
        foreach ($contratos as $c) {
            $linhas[] = ['unidade' => $c->processo?->unidade?->sigla, 'contratos' => 1, 'valor_contratos' => (float) $c->valor_global];
        }
        $perfil = $contratos->map(fn (Contrato $c) => ['fornecedor' => $c->fornecedor_id, 'porte' => $c->fornecedor?->porte, 'uf' => $c->fornecedor?->uf, 'municipio' => $c->fornecedor?->municipio, 'valor' => (float) $c->valor_global])->all();
        $orgao = $request->user()->orgao;

        return [
            'secretarias' => IndicadoresGestao::porUnidade($linhas),
            'perfil' => IndicadoresGestao::perfilFornecedores($perfil),
            'fomento' => IndicadoresGestao::fomentoLocal($perfil, $orgao?->municipio, $orgao?->uf),
        ];
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>} */
    private function tabela(string $relatorio, array $d): array
    {
        return match ($relatorio) {
            'secretarias' => [['Secretaria', 'Processos', 'Em andamento', 'Concluídos', 'Valor dos processos (R$)', 'Participação (%)', 'Itens do PCA', 'Execução do PCA (%)', 'Contratos', 'Valor contratado (R$)'],
                array_map(fn ($u) => [$u['unidade'], $u['processos'], $u['andamento'], $u['concluidos'], (float) $u['valor'], $u['participacao'], $u['itens_pca'], $u['execucao_pca'], $u['contratos'], (float) $u['valor_contratos']], $d['secretarias'])],
            'fornecedores' => [['Dimensão', 'Grupo', 'Fornecedores', 'Valor contratado (R$)', 'Participação no valor (%)'],
                array_merge(...array_map(fn ($dim, $rot) => array_map(fn ($x) => [$rot, $x['rotulo'], $x['fornecedores'], (float) $x['valor'], $x['pct_valor']], $d['perfil'][$dim]), ['porte', 'uf', 'municipio'], ['Porte', 'UF', 'Município']))],
            'fomento' => [['Origem do fornecedor', 'Fornecedores', 'Valor contratado (R$)', 'Participação (%)'],
                array_map(fn ($k, $rot) => [$rot, $d['fomento'][$k]['fornecedores'], (float) $d['fomento'][$k]['valor'], $d['fomento'][$k]['pct']], ['local', 'regional', 'fora', 'sem_endereco'],
                    ['Mesmo município do órgão', 'Outros municípios da UF', 'Outras UFs', 'Sem endereço no cadastro'])],
        };
    }
}
