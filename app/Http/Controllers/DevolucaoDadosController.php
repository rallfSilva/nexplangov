<?php

namespace App\Http\Controllers;

use App\Jobs\GerarPacoteDevolucao;
use App\Models\Orgao;
use App\Models\PacoteDevolucao;
use App\Support\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Devolução dos dados do órgão ao fim do contrato (ou quando o órgão pedir): todos os registros,
 * arquivos e a trilha de auditoria num ZIP com manifesto SHA-256. Só o administrador do órgão (ou o
 * administrador geral) gera e baixa; o arquivo fica disponível por poucos dias.
 */
class DevolucaoDadosController extends Controller
{
    public function index(Request $request): View
    {
        return view('devolucao.index', [
            'pacotes' => PacoteDevolucao::with(['usuario', 'orgao'])->latest('id')->limit(30)->get(),
            'orgaos' => $request->user()->orgao_id === null ? Orgao::where('ativo', true)->orderBy('sigla')->get() : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $orgaoId = $request->user()->orgao_id ?? (int) $request->validate(['orgao_id' => ['required', 'integer', 'exists:orgaos,id']])['orgao_id'];

        if (PacoteDevolucao::where('orgao_id', $orgaoId)->whereIn('situacao', ['na_fila', 'gerando'])->exists()) {
            return back()->with('erro', 'Já há um pacote sendo gerado para este órgão. Aguarde terminar.');
        }

        $pacote = PacoteDevolucao::create(['orgao_id' => $orgaoId, 'user_id' => $request->user()->id, 'situacao' => 'na_fila']);
        GerarPacoteDevolucao::dispatch($pacote->id);

        return back()->with('sucesso', 'Pacote na fila. Quando ficar pronto, ele aparece abaixo para baixar.');
    }

    public function baixar(PacoteDevolucao $pacote): StreamedResponse|RedirectResponse
    {
        if (! $pacote->disponivel() || ! Storage::disk('local')->exists((string) $pacote->arquivo_caminho)) {
            return back()->with('erro', 'Este pacote não está disponível. Gere um novo.');
        }

        $pacote->forceFill(['baixado_em' => now()])->save();
        Auditoria::registrar('exportacao', $pacote, 'Pacote de devolução de dados baixado');
        $sigla = preg_replace('/[^A-Za-z0-9]/', '', (string) $pacote->orgao?->sigla) ?: 'orgao';

        return Storage::disk('local')->download($pacote->arquivo_caminho, "devolucao-{$sigla}-{$pacote->created_at->format('Y-m-d')}.zip");
    }
}
