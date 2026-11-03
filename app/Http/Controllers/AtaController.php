<?php

namespace App\Http\Controllers;

use App\Licitacao\Contratos\Carona;
use App\Licitacao\Contratos\GestaoAtas;
use App\Licitacao\RegraViolada;
use App\Models\Ata;
use App\Models\AtaAdesao;
use App\Models\AtaItem;
use App\Models\Processo;
use App\Models\Unidade;
use App\Rules\Cnpj;
use App\Support\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Atas de registro de preços (arts. 82 a 86). */
class AtaController extends Controller
{
    public function __construct(private GestaoAtas $servico, private Carona $carona) {}

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

        return view('atas.show', ['ata' => $ata, 'unidades' => Unidade::orderBy('sigla')->get(['id', 'sigla']), 'carona' => $this->carona]);
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
            'aderente_nome' => ['required', 'string', 'max:200'], 'aderente_cnpj' => ['required', new Cnpj], 'aderente_esfera' => ['required', Rule::in(array_keys(AtaAdesao::ESFERAS))],
            'aderente_uf' => ['nullable', 'string', 'size:2'], 'aderente_municipio' => ['nullable', 'string', 'max:120'], 'quantidade' => ['required', 'numeric', 'gt:0'],
            'justificativa' => ['required', 'string', 'max:2000'], 'vantagem' => ['required', 'string', 'max:2000'], 'oficio' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
        ]);

        return $this->tentar(fn () => $this->carona->solicitar($item, $d, $request->user(), $request->file('oficio')), 'Pedido de adesão registrado. Envie ao fornecedor o link de aceite.');
    }

    /** Aceite do fornecedor registrado pelo gerenciador, com o documento enviado por ele (art. 86, § 2º, III). */
    public function aceiteAdesao(Request $request, Ata $ata, AtaAdesao $adesao): RedirectResponse
    {
        abort_unless($adesao->ataItem->ata_id === $ata->id, 404);
        $d = $request->validate([
            'resposta' => ['required', 'in:aceito,recusado'], 'representante' => ['required', 'string', 'max:160'], 'obs' => ['nullable', 'string', 'max:500'],
            'documento' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
        ]);

        return $this->tentar(fn () => $this->carona->registrarAceite($adesao, $d['resposta'] === 'aceito', $d['representante'], $d['obs'] ?? null, 'registro', $request->file('documento'), $request->user()), 'Resposta do fornecedor registrada.');
    }

    public function decidirAdesao(Request $request, Ata $ata, AtaAdesao $adesao): RedirectResponse
    {
        abort_unless($adesao->ataItem->ata_id === $ata->id, 404);
        $d = $request->validate(['acao' => ['required', 'in:autorizar,negar'], 'motivo' => ['nullable', 'string', 'max:500']]);

        return $this->tentar(fn () => $this->carona->decidir($adesao, $d['acao'] === 'autorizar', $d['motivo'] ?? null, $request->user()), $d['acao'] === 'autorizar' ? 'Adesão autorizada. O ofício de autorização em PDF está pronto.' : 'Adesão negada.');
    }

    /** Ofício do aderente, documento de aceite do fornecedor ou autorização em PDF. */
    public function arquivoAdesao(Ata $ata, AtaAdesao $adesao, string $tipo): StreamedResponse
    {
        abort_unless($adesao->ataItem->ata_id === $ata->id, 404);
        $caminho = match ($tipo) {
            'oficio' => $adesao->oficio_caminho,
            'aceite' => $adesao->fornecedor_aceite_caminho,
            'autorizacao' => $adesao->autorizacao_caminho,
            default => null,
        };
        abort_unless($caminho && Storage::disk('local')->exists($caminho), 404);
        Auditoria::registrar('download', $adesao, "Baixou o arquivo ({$tipo}) da adesão {$adesao->protocolo}");

        return Storage::disk('local')->download($caminho, "{$tipo}-{$adesao->protocolo}.".pathinfo($caminho, PATHINFO_EXTENSION));
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
