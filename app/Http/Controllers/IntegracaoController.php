<?php

namespace App\Http\Controllers;

use App\Integracoes\Sei\DossieProcesso;
use App\Integracoes\Tce\ExportadorTce;
use App\Models\Orgao;
use App\Models\Processo;
use App\Support\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Remessa ao tribunal de contas, dossiê para o SEI e número SEI do processo. */
class IntegracaoController extends Controller
{
    public function index(Request $request): View
    {
        return view('integracoes.index', ['orgaos' => $request->user()->orgao_id ? collect() : Orgao::orderBy('sigla')->get(['id', 'sigla'])]);
    }

    public function tce(Request $request, ExportadorTce $exportador): BinaryFileResponse
    {
        $d = $request->validate(['inicio' => ['required', 'date'], 'fim' => ['required', 'date', 'after_or_equal:inicio'], 'orgao_id' => ['nullable', 'integer']]);
        $u = $request->user();
        $orgao = $u->orgao_id ? $u->orgao : Orgao::findOrFail($d['orgao_id'] ?? 0);

        $inicio = Carbon::parse($d['inicio']);
        $fim = Carbon::parse($d['fim']);
        abort_if($inicio->diffInDays($fim) > 400, 422, 'Gere a remessa por períodos de até um ano.');

        $arquivo = storage_path('app/private/tmp-'.Str::random(24).'.zip');
        @mkdir(dirname($arquivo), 0750, true);
        $exportador->gerar($orgao, $inicio, $fim, $arquivo);
        Auditoria::registrar('exportacao', $orgao, "Remessa ao tribunal de contas de {$inicio->format('d/m/Y')} a {$fim->format('d/m/Y')}");

        return response()->download($arquivo, "remessa-tce-{$orgao->sigla}-{$inicio->format('Ymd')}-{$fim->format('Ymd')}.zip")->deleteFileAfterSend();
    }

    public function dossie(Processo $processo, DossieProcesso $dossie): BinaryFileResponse
    {
        $arquivo = storage_path('app/private/tmp-'.Str::random(24).'.zip');
        @mkdir(dirname($arquivo), 0750, true);
        $dossie->gerar($processo, $arquivo);
        Auditoria::registrar('exportacao', $processo, "Dossiê do processo {$processo->identificacao()} exportado");

        return response()->download($arquivo, 'dossie-'.str_replace('/', '-', $processo->identificacao()).'.zip')->deleteFileAfterSend();
    }

    public function numeroSei(Request $request, Processo $processo): RedirectResponse
    {
        $d = $request->validate(['numero_sei' => ['nullable', 'string', 'max:40', 'regex:/^[0-9.\/\-]+$/']], ['numero_sei.regex' => 'Use só números, pontos, barras e hífen.']);
        $processo->update(['numero_sei' => $d['numero_sei'] ?: null]);

        return back()->with('sucesso', 'Número SEI salvo.');
    }
}
