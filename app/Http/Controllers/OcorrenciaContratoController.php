<?php

namespace App\Http\Controllers;

use App\Licitacao\Contratos\LivroDeOcorrencias;
use App\Licitacao\Regras\LivroOcorrencias;
use App\Licitacao\RegraViolada;
use App\Models\Contrato;
use App\Support\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Livro de ocorrências do fiscal e designação formal de gestor e fiscal (art. 117). */
class OcorrenciaContratoController extends Controller
{
    public function store(Request $request, Contrato $contrato, LivroDeOcorrencias $livro): RedirectResponse
    {
        $dados = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(LivroOcorrencias::TIPOS))],
            'ocorrida_em' => ['required', 'date'],
            'descricao' => ['required', 'string', 'max:10000'],
            'providencias' => ['nullable', 'string', 'max:5000'],
            'prazo_regularizacao' => ['nullable', 'date'],
            'retifica_id' => ['nullable', 'integer'],
        ]);

        try {
            $o = $livro->registrar($contrato, $request->user(), $dados);
        } catch (RegraViolada $e) {
            return back()->withInput()->with('erro', 'A anotação não foi registrada.')->with('motivos', $e->motivos);
        }

        return redirect()->to(route('contratos.show', $contrato).'#ocorrencias')->with('sucesso', "Anotação nº {$o->numero} registrada no livro de ocorrências. Ela não pode ser alterada; para corrigir, registre uma retificação.");
    }

    public function designar(Request $request, Contrato $contrato, LivroDeOcorrencias $livro): RedirectResponse
    {
        $dados = $request->validate(['designacao_ato' => ['required', 'string', 'max:60'], 'designacao_em' => ['required', 'date']]);

        try {
            $livro->designar($contrato, $request->user(), $dados['designacao_ato'], $dados['designacao_em']);
        } catch (RegraViolada $e) {
            return back()->withInput()->with('erro', 'A designação não foi registrada.')->with('motivos', $e->motivos);
        }

        return back()->with('sucesso', 'Designação de gestor e fiscal registrada. Baixe a portaria em PDF para assinatura e publicação.');
    }

    public function portaria(Contrato $contrato): Response
    {
        $contrato->load(['orgao', 'fornecedor', 'gestor', 'fiscal', 'designador', 'processo']);

        return response(Pdf::daView('ocorrencias.portaria-pdf', ['c' => $contrato]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="designacao-contrato-'.str_replace('/', '-', $contrato->identificacao()).'.pdf"',
        ]);
    }

    public function livroPdf(Contrato $contrato, LivroDeOcorrencias $livro): Response
    {
        $contrato->load(['orgao', 'fornecedor', 'gestor', 'fiscal']);

        return response(Pdf::daView('ocorrencias.livro-pdf', ['c' => $contrato, 'ocorrencias' => $contrato->ocorrencias()->reorder('numero')->get(), 'integro' => $livro->integro($contrato)]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="livro-ocorrencias-'.str_replace('/', '-', $contrato->identificacao()).'.pdf"',
        ]);
    }
}
