<?php

namespace App\Http\Controllers;

use App\Licitacao\AutosDoProcesso;
use App\Licitacao\RegraViolada;
use App\Models\AnotacaoProcesso;
use App\Models\JuntadaProcesso;
use App\Models\Processo;
use App\Support\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Juntada livre de documentos aos autos, desentranhamento e PDF único dos autos. */
class JuntadaController extends Controller
{
    public function __construct(private AutosDoProcesso $autos) {}

    public function store(Request $request, Processo $processo): RedirectResponse
    {
        abort_unless(AnotacaoProcesso::podeEscrever($request->user()), 403);

        $d = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(JuntadaProcesso::TIPOS))],
            'titulo' => ['required', 'string', 'max:200'],
            'descricao' => ['nullable', 'string', 'max:2000'],
            'arquivo' => ['required', 'file', 'mimes:'.JuntadaProcesso::MIMES, 'max:30720'],
        ], ['arquivo.mimes' => 'Envie PDF, DOCX, ODT, XLSX, ODS, PNG ou JPG.', 'arquivo.max' => 'O arquivo pode ter no máximo 30 MB.']);

        $j = $this->autos->juntar($processo, $request->user(), $request->file('arquivo'), $d['tipo'], $d['titulo'], $d['descricao'] ?? null);

        return redirect()->route('processos.show', [$processo, 'aba' => 'autos'])->with('sucesso', "Documento \"{$j->titulo}\" juntado aos autos.");
    }

    public function arquivo(Processo $processo, JuntadaProcesso $juntada): StreamedResponse
    {
        abort_unless($juntada->processo_id === $processo->id && Storage::disk('local')->exists($juntada->arquivo_caminho), 404);
        Auditoria::registrar('download', $juntada, "Download do documento juntado \"{$juntada->titulo}\" do processo {$processo->identificacao()}");

        return Storage::disk('local')->download($juntada->arquivo_caminho, $juntada->arquivo_nome);
    }

    /** Sai dos autos quem juntou por engano (o autor) ou por decisão da autoridade. O registro fica no histórico. */
    public function desentranhar(Request $request, Processo $processo, JuntadaProcesso $juntada): RedirectResponse
    {
        abort_unless($juntada->processo_id === $processo->id, 404);
        abort_unless($juntada->autor_id === $request->user()->id || $request->user()->can('processos.autorizar'), 403, 'Só quem juntou ou a autoridade pode desentranhar.');

        try {
            $this->autos->desentranhar($juntada, $request->user(), $request->input('motivo'));
        } catch (RegraViolada $e) {
            return back()->with('erro', 'Não foi possível desentranhar.')->with('motivos', $e->motivos);
        }

        return redirect()->route('processos.show', [$processo, 'aba' => 'autos'])->with('sucesso', 'Documento desentranhado. O registro continua no histórico dos autos.');
    }

    public function pdf(Processo $processo): Response
    {
        $conteudo = $this->autos->pdf($processo);
        Auditoria::registrar('exportacao', $processo, "PDF único dos autos do processo {$processo->identificacao()}");
        $nome = 'autos-'.str_replace('/', '-', $processo->identificacao()).'.pdf';

        return response($conteudo, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename=\"{$nome}\""]);
    }
}
