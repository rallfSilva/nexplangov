<?php

namespace App\Http\Controllers;

use App\Licitacao\Contratos\GestaoAtas;
use App\Licitacao\RegraViolada;
use App\Models\Ata;
use App\Models\AtaAdesao;
use App\Models\AtaItem;
use App\Models\Processo;
use App\Models\Unidade;
use App\Rules\Cnpj;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Atas de registro de preços (arts. 82 a 86). */
class AtaController extends Controller
{
    public function __construct(private GestaoAtas $servico) {}

    public function index(): View
    {
        return view('atas.index', [
            'atas' => Ata::with('fornecedor', 'processo')->withCount('itens')->orderByDesc('ano')->orderByDesc('numero')->get(),
            'processos' => Processo::where('srp', true)->whereIn('situacao', ['homologado', 'contratado'])->whereDoesntHave('atas')->orderByDesc('id')->get(['id', 'numero', 'ano', 'objeto']),
        ]);
    }

    public function gerar(Request $request): RedirectResponse
    {
        $d = $request->validate(['processo_id' => ['required', 'integer'], 'assinatura' => ['required', 'date'], 'vigencia_inicio' => ['required', 'date'], 'vigencia_fim' => ['required', 'date']]);
        $processo = Processo::findOrFail($d['processo_id']);

        try {
            $n = $this->servico->gerarDoProcesso($processo, $request->user(), $d['assinatura'], $d['vigencia_inicio'], $d['vigencia_fim']);
        } catch (RegraViolada $e) {
            return back()->withInput()->with('erro', 'Não foi possível gerar as atas.')->with('motivos', $e->motivos);
        }

        return redirect()->route('atas.index')->with('sucesso', "{$n} ata(s) gerada(s). Cada fornecedor vencedor tem a sua.");
    }

    public function show(Ata $ata): View
    {
        $ata->load(['fornecedor', 'processo', 'itens.item', 'itens.adesoes']);

        return view('atas.show', ['ata' => $ata, 'unidades' => Unidade::orderBy('sigla')->get(['id', 'sigla'])]);
    }

    public function consumo(Request $request, Ata $ata, AtaItem $item): RedirectResponse
    {
        abort_unless($item->ata_id === $ata->id, 404);
        $request->merge(['quantidade' => decimal_br($request->input('quantidade'))]);
        $d = $request->validate(['quantidade' => ['required', 'numeric', 'gt:0'], 'referencia' => ['required', 'string', 'max:200'], 'unidade_id' => ['nullable', 'integer'], 'adesao_id' => ['nullable', 'integer']]);
        $adesao = ($d['adesao_id'] ?? null) ? AtaAdesao::findOrFail($d['adesao_id']) : null;

        return $this->tentar(fn () => $this->servico->registrarConsumo($item, (float) $d['quantidade'], $d['referencia'], $d['unidade_id'] ?? null, $request->user(), $adesao), 'Consumo registrado.');
    }

    public function solicitarAdesao(Request $request, Ata $ata, AtaItem $item): RedirectResponse
    {
        abort_unless($item->ata_id === $ata->id, 404);
        $request->merge(['quantidade' => decimal_br($request->input('quantidade'))]);
        $d = $request->validate([
            'aderente_nome' => ['required', 'string', 'max:200'], 'aderente_cnpj' => ['required', new Cnpj], 'quantidade' => ['required', 'numeric', 'gt:0'],
            'justificativa' => ['required', 'string', 'max:2000'], 'vantagem' => ['required', 'string', 'max:2000'],
        ]);

        return $this->tentar(fn () => $this->servico->solicitarAdesao($item, $d['aderente_nome'], $d['aderente_cnpj'], (float) $d['quantidade'], $d['justificativa'], $d['vantagem'], $request->user()), 'Pedido de adesão registrado.');
    }

    public function decidirAdesao(Request $request, Ata $ata, AtaAdesao $adesao): RedirectResponse
    {
        abort_unless($adesao->ataItem->ata_id === $ata->id, 404);
        $d = $request->validate(['acao' => ['required', 'in:autorizar,negar'], 'motivo' => ['nullable', 'string', 'max:500']]);

        return $this->tentar(fn () => $this->servico->decidirAdesao($adesao, $d['acao'] === 'autorizar', $d['motivo'] ?? null, $request->user()), 'Decisão registrada.');
    }

    public function prorrogar(Request $request, Ata $ata): RedirectResponse
    {
        $d = $request->validate(['vigencia_fim' => ['required', 'date'], 'vantagem' => ['required', 'string', 'max:2000']]);

        return $this->tentar(fn () => $this->servico->prorrogar($ata, $d['vigencia_fim'], $d['vantagem']), 'Ata prorrogada.');
    }

    public function cancelar(Request $request, Ata $ata): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        return $this->tentar(fn () => $this->servico->cancelar($ata, $d['motivo']), 'Ata cancelada.');
    }

    private function tentar(callable $acao, string $ok): RedirectResponse
    {
        try {
            $acao();
        } catch (RegraViolada $e) {
            return back()->withInput()->with('erro', 'Não foi possível concluir.')->with('motivos', $e->motivos);
        }

        return back()->with('sucesso', $ok);
    }
}
