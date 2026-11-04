<?php

namespace App\Http\Controllers;

use App\Licitacao\Pca\ImportadorPca;
use App\Licitacao\Pca\PlanilhaPca;
use App\Models\ImportacaoPca;
use App\Models\PlanoContratacao;
use App\Models\Unidade;
use App\Support\Planilha;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Importação de itens do PCA por planilha XLSX ou CSV, com pré-visualização (art. 12, VII). */
class PcaImportacaoController extends Controller
{
    public function __construct(private ImportadorPca $importador) {}

    public function modelo(PlanoContratacao $plano): BinaryFileResponse
    {
        $unidade = Unidade::where('orgao_id', $plano->orgao_id)->where('ativo', true)->orderBy('sigla')->value('sigla');
        $arquivo = storage_path('app/private/tmp-'.Str::random(24).'.xlsx');
        @mkdir(dirname($arquivo), 0750, true);

        Planilha::xlsx($arquivo, ['Itens do PCA' => [
            'cabecalho' => array_keys(PlanilhaPca::COLUNAS),
            'ajuda' => array_values(PlanilhaPca::COLUNAS),
            'larguras' => [8, 16, 14, 48, 14, 12, 16, 18, 14, 12, 16],
            'linhas' => [
                ['M', 2, '', 'Papel A4 75 g/m², pacote com 500 folhas (exemplo: apague esta linha)', 'RESMA', 200, '28,90', $unidade ?? '', '15/03/'.$plano->ano, 'Média', '3.3.90.30.16'],
            ],
        ]]);

        return response()->download($arquivo, "modelo-pca-{$plano->ano}.xlsx")->deleteFileAfterSend();
    }

    public function store(Request $request, PlanoContratacao $plano): RedirectResponse
    {
        $request->validate(
            ['arquivo' => ['required', 'file', 'max:5120', 'mimes:xlsx,csv,txt']],
            ['arquivo.mimes' => 'Envie a planilha em .xlsx ou .csv.'],
            ['arquivo' => 'planilha'],
        );

        try {
            $imp = $this->importador->analisar($plano, $request->file('arquivo'), $request->user());
        } catch (RuntimeException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return redirect()->route('pca.importacoes.show', [$plano, $imp]);
    }

    public function show(PlanoContratacao $plano, ImportacaoPca $importacao): View
    {
        abort_if($importacao->plano_contratacao_id !== $plano->id, 404);
        $importacao->load('usuario');

        return view('pca.importacao', [
            'plano' => $plano, 'imp' => $importacao,
            'unidades' => Unidade::where('orgao_id', $plano->orgao_id)->pluck('sigla', 'id'),
        ]);
    }

    public function confirmar(PlanoContratacao $plano, ImportacaoPca $importacao): RedirectResponse
    {
        abort_if($importacao->plano_contratacao_id !== $plano->id, 404);

        try {
            $n = $this->importador->confirmar($importacao);
        } catch (RuntimeException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return redirect()->route('pca.show', $plano)->with('sucesso', "{$n} item(ns) importado(s) para o PCA {$plano->ano}.");
    }

    public function cancelar(PlanoContratacao $plano, ImportacaoPca $importacao): RedirectResponse
    {
        abort_if($importacao->plano_contratacao_id !== $plano->id, 404);
        $this->importador->cancelar($importacao);

        return redirect()->route('pca.show', $plano)->with('sucesso', 'Importação cancelada. Nenhum item foi gravado.');
    }
}
