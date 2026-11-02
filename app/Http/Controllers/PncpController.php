<?php

namespace App\Http\Controllers;

use App\Models\Contrato;
use App\Models\EnvioPncp;
use App\Models\TermoContrato;
use App\Pncp\FilaPncp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PncpController extends Controller
{
    public function index(Request $request): View
    {
        $envios = EnvioPncp::with(['publicavel', 'usuario'])
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->string('estado')))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('pncp.index', [
            'envios' => $envios,
            'contagem' => EnvioPncp::selectRaw('estado, count(*) as total')->groupBy('estado')->pluck('total', 'estado'),
        ]);
    }

    public function publicarContrato(Request $request, Contrato $contrato, FilaPncp $fila): RedirectResponse
    {
        $envio = $fila->agendar($contrato, 'contrato', 'inserir', $request->user());

        return back()->with($envio->estado === 'erro' ? 'erro' : 'sucesso', $envio->estado === 'erro' ? $envio->erro : 'Contrato na fila de publicação do PNCP.');
    }

    public function publicarTermo(Request $request, TermoContrato $termo, FilaPncp $fila): RedirectResponse
    {
        $envio = $fila->agendar($termo, 'termo', 'inserir', $request->user());

        return back()->with($envio->estado === 'erro' ? 'erro' : 'sucesso', $envio->estado === 'erro' ? $envio->erro : 'Termo na fila de publicação do PNCP.');
    }

    /** Monta de novo a partir dos dados atuais (depois da correção) e põe na fila. */
    public function reenviar(Request $request, EnvioPncp $envio, FilaPncp $fila): RedirectResponse
    {
        if ($envio->estado !== 'erro') {
            return back()->with('erro', 'Só envios com erro podem ser reenviados.');
        }

        $alvo = $envio->publicavel;
        abort_unless($alvo, 404);
        $novo = $fila->agendar($alvo, $envio->recurso, $envio->operacao, $request->user());

        return back()->with($novo->estado === 'erro' ? 'erro' : 'sucesso', $novo->estado === 'erro' ? $novo->erro : 'Envio refeito com os dados atuais e colocado na fila.');
    }
}
