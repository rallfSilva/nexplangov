<?php

namespace App\Http\Controllers;

use App\Licitacao\Externos\PedidosExternos;
use App\Licitacao\Regras\PedidoExterno;
use App\Licitacao\RegraViolada;
use App\Models\AnotacaoProcesso;
use App\Models\DocumentoProcesso;
use App\Models\Processo;
use App\Models\SolicitacaoExterna;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Pedidos a pessoas de fora do órgão, feitos por quem instrui o processo. */
class PedidoExternoController extends Controller
{
    public function __construct(private PedidosExternos $pedidos) {}

    public function store(Request $request, Processo $processo): RedirectResponse
    {
        abort_unless(AnotacaoProcesso::podeEscrever($request->user()), 403);

        $d = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(PedidoExterno::TIPOS))],
            'nome' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:255'],
            'mensagem' => ['required', 'string', 'max:3000'],
            'prazo_em' => ['required', 'date'],
            'documento_id' => ['nullable', 'integer'],
        ]);

        $doc = ! empty($d['documento_id']) ? DocumentoProcesso::where('processo_id', $processo->id)->find($d['documento_id']) : null;

        try {
            [$s] = $this->pedidos->criar($processo, $request->user(), $d['tipo'], $d['nome'], $d['email'], $d['mensagem'], CarbonImmutable::parse($d['prazo_em']), $doc);
        } catch (RegraViolada $e) {
            return back()->withInput()->with('erro', 'Não foi possível enviar o pedido.')->with('motivos', $e->motivos);
        }

        return redirect()->route('processos.show', [$processo, 'aba' => 'autos'])->with('sucesso', "Pedido enviado por e-mail a {$s->nome}. O link vale até {$s->prazo_em->format('d/m/Y')}.");
    }

    public function cancelar(Request $request, Processo $processo, SolicitacaoExterna $pedido): RedirectResponse
    {
        abort_unless($pedido->processo_id === $processo->id, 404);
        abort_unless(AnotacaoProcesso::podeEscrever($request->user()), 403);

        try {
            $this->pedidos->cancelar($pedido, $request->user());
        } catch (RegraViolada $e) {
            return back()->with('erro', 'Não foi possível cancelar.')->with('motivos', $e->motivos);
        }

        return redirect()->route('processos.show', [$processo, 'aba' => 'autos'])->with('sucesso', 'Pedido cancelado. O link deixou de valer.');
    }
}
