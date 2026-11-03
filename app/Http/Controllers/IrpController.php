<?php

namespace App\Http\Controllers;

use App\Licitacao\Nexlicita\RegistroPrecos;
use App\Licitacao\RegraViolada;
use App\Models\Irp;
use App\Models\IrpManifestacao;
use App\Models\Processo;
use App\Models\Unidade;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Intenção de registro de preços (art. 86). */
class IrpController extends Controller
{
    public function __construct(private RegistroPrecos $servico) {}

    public function index(): View
    {
        return view('irp.index', ['irps' => Irp::withCount('manifestacoes')->orderByDesc('ano')->orderByDesc('numero')->get(), 'prazoMinimo' => $this->servico->prazoMinimo(), 'unidades' => Unidade::orderBy('sigla')->get(['id', 'sigla', 'nome'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->orgao_id, 422);
        $d = $request->validate(['objeto' => ['required', 'string', 'max:500'], 'justificativa' => ['nullable', 'string', 'max:5000'], 'manifestacao_ate' => ['required', 'date'], 'unidade_id' => ['nullable', 'integer']]);

        try {
            $irp = $this->servico->abrir($request->user(), $d['objeto'], $d['justificativa'] ?? null, $d['manifestacao_ate'], $d['unidade_id'] ?? null);
        } catch (RegraViolada $e) {
            return back()->withInput()->with('erro', 'Não foi possível abrir a IRP.')->with('motivos', $e->motivos);
        }

        return redirect()->route('irp.show', $irp)->with('sucesso', "IRP {$irp->identificacao()} aberta.");
    }

    public function show(Irp $irp): View
    {
        $irp->load(['manifestacoes', 'processo']);

        return view('irp.show', [
            'irp' => $irp, 'unidades' => Unidade::orderBy('sigla')->get(['id', 'sigla', 'nome']),
            'processos' => Processo::where('situacao', 'planejamento')->orderByDesc('id')->get(['id', 'numero', 'ano', 'objeto', 'srp']),
        ]);
    }

    public function manifestar(Request $request, Irp $irp): RedirectResponse
    {
        $request->merge(['quantidade' => decimal_br($request->input('quantidade'))]);
        $d = $request->validate([
            'participante' => ['required', 'string', 'max:160'], 'descricao' => ['required', 'string', 'max:300'], 'unidade_medida' => ['required', 'string', 'max:20'],
            'quantidade' => ['required', 'numeric', 'gt:0'], 'justificativa' => ['nullable', 'string', 'max:2000'], 'unidade_id' => ['nullable', 'integer'],
        ]);

        return $this->tentar(fn () => $this->servico->manifestar($irp, $request->user(), $d['participante'], $d['descricao'], $d['unidade_medida'], (float) $d['quantidade'], $d['justificativa'] ?? null, $d['unidade_id'] ?? null), 'Manifestação enviada.');
    }

    public function decidir(Request $request, Irp $irp, IrpManifestacao $manifestacao): RedirectResponse
    {
        abort_unless($manifestacao->irp_id === $irp->id, 404);
        $d = $request->validate(['acao' => ['required', 'in:aceitar,recusar'], 'motivo' => ['nullable', 'string', 'max:500']]);

        return $this->tentar(fn () => $this->servico->decidir($manifestacao, $d['acao'] === 'aceitar', $d['motivo'] ?? null), 'Decisão registrada.');
    }

    public function encerrar(Irp $irp): RedirectResponse
    {
        return $this->tentar(fn () => $this->servico->encerrar($irp), 'IRP encerrada: não recebe mais manifestações.');
    }

    public function consolidar(Request $request, Irp $irp): RedirectResponse
    {
        $d = $request->validate(['processo_id' => ['required', 'integer']]);
        $processo = Processo::findOrFail($d['processo_id']);

        try {
            $n = $this->servico->consolidar($irp, $processo);
        } catch (RegraViolada $e) {
            return back()->with('erro', 'Não foi possível consolidar.')->with('motivos', $e->motivos);
        }

        return redirect()->route('processos.show', [$processo, 'aba' => 'itens'])->with('sucesso', "IRP consolidada: {$n} item(ns) criado(s) no processo, que passou a ser de registro de preços.");
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
