<?php

namespace App\Http\Controllers;

use App\Licitacao\Contratos\GestaoOrdens;
use App\Licitacao\RegraViolada;
use App\Models\OrdemFornecimento;
use App\Models\Requisicao;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Ordens de fornecimento e recebimento provisório e definitivo (art. 140). */
class OrdemController extends Controller
{
    public function __construct(private GestaoOrdens $servico) {}

    public function index(): View
    {
        return view('ordens.index', ['ordens' => OrdemFornecimento::with('ataItem.ata.fornecedor', 'ataItem.item')->orderByDesc('ano')->orderByDesc('numero')->limit(200)->get()]);
    }

    public function show(OrdemFornecimento $ordem): View
    {
        $ordem->load('ataItem.ata.fornecedor', 'ataItem.item', 'recebimentos', 'emissor', 'requisicao.requisitante');

        return view('ordens.show', ['ordem' => $ordem, 'recebedores' => User::whereIn('id', $ordem->recebimentos->pluck('recebido_por'))->pluck('name', 'id')]);
    }

    public function emitir(Request $request, Requisicao $requisicao): RedirectResponse
    {
        $d = $request->validate(['prazo_entrega' => ['required', 'date']]);

        try {
            $ordem = $this->servico->emitir($requisicao, $d['prazo_entrega'], $request->user());
        } catch (RegraViolada $e) {
            return back()->withInput()->with('erro', 'Não foi possível emitir a ordem.')->with('motivos', $e->motivos);
        }

        return redirect()->route('ordens.show', $ordem)->with('sucesso', "Ordem {$ordem->identificacao()} emitida. O saldo da ata foi reservado.");
    }

    public function receber(Request $request, OrdemFornecimento $ordem): RedirectResponse
    {
        $request->merge(['quantidade' => decimal_br($request->input('quantidade'))]);
        $d = $request->validate(['tipo' => ['required', 'in:provisorio,definitivo'], 'quantidade' => ['required', 'numeric', 'gt:0'], 'documento' => ['required', 'string', 'max:120'], 'data' => ['required', 'date'], 'observacao' => ['nullable', 'string', 'max:1000']]);

        try {
            $this->servico->receber($ordem, $d['tipo'], (float) $d['quantidade'], $d['documento'], $d['data'], $d['observacao'] ?? null, $request->user());
        } catch (RegraViolada $e) {
            return back()->withInput()->with('erro', 'Não foi possível registrar o recebimento.')->with('motivos', $e->motivos);
        }

        return back()->with('sucesso', 'Recebimento '.($d['tipo'] === 'provisorio' ? 'provisório' : 'definitivo').' registrado.');
    }

    public function cancelar(Request $request, OrdemFornecimento $ordem): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        try {
            $this->servico->cancelar($ordem, $d['motivo'], $request->user());
        } catch (RegraViolada $e) {
            return back()->with('erro', 'Não foi possível cancelar.')->with('motivos', $e->motivos);
        }

        return back()->with('sucesso', 'Ordem cancelada e saldo devolvido à ata.');
    }
}
