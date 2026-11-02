<?php

namespace App\Http\Controllers;

use App\Models\OperacaoTratamento;
use App\Models\Orgao;
use App\Models\SolicitacaoTitular;
use App\Rules\Cpf;
use App\Support\Documento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** LGPD: canal do titular (arts. 18 e 19), registro de tratamento (art. 37) e atendimento pelo encarregado (art. 41). */
class LgpdController extends Controller
{
    public function formularioPublico(): View
    {
        return view('lgpd.publico', ['orgaos' => Orgao::where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'sigla'])]);
    }

    public function registrarPublico(Request $request): RedirectResponse
    {
        // Campo isca contra robôs: pessoas não veem nem preenchem.
        if ($request->filled('site')) {
            return back()->with('sucesso', 'Pedido registrado.');
        }

        $dados = $request->validate([
            'orgao_id' => ['required', 'integer', Rule::exists('orgaos', 'id')->where('ativo', true)],
            'nome' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'cpf' => ['nullable', new Cpf],
            'tipo' => ['required', Rule::in(array_keys(SolicitacaoTitular::TIPOS))],
            'descricao' => ['required', 'string', 'min:10', 'max:5000'],
            'ciente' => ['accepted'],
        ], ['ciente.accepted' => 'Confirme que leu o aviso sobre o uso dos dados deste pedido.']);

        $solicitacao = SolicitacaoTitular::create([
            'orgao_id' => $dados['orgao_id'],
            'nome' => $dados['nome'],
            'email' => mb_strtolower($dados['email']),
            'cpf_cifrado' => isset($dados['cpf']) ? Documento::digitos($dados['cpf']) : null,
            'tipo' => $dados['tipo'],
            'descricao' => $dados['descricao'],
        ]);

        return redirect()->route('lgpd.publico')->with('sucesso', "Pedido registrado com o protocolo {$solicitacao->protocolo}. A resposta chega ao seu e-mail em até 15 dias (LGPD, art. 19, II).");
    }

    public function index(Request $request): View
    {
        return view('lgpd.index', [
            'solicitacoes' => SolicitacaoTitular::orderByRaw("case when situacao in ('concluida','indeferida') then 1 else 0 end")->orderBy('prazo_em')->paginate(20),
            'tratamentos' => OperacaoTratamento::orderBy('nome')->get(),
        ]);
    }

    public function show(SolicitacaoTitular $solicitacao): View
    {
        return view('lgpd.show', ['solicitacao' => $solicitacao]);
    }

    public function responder(Request $request, SolicitacaoTitular $solicitacao): RedirectResponse
    {
        $dados = $request->validate([
            'situacao' => ['required', Rule::in(array_keys(SolicitacaoTitular::SITUACOES))],
            'resposta' => ['required_if:situacao,concluida,indeferida', 'nullable', 'string', 'max:10000'],
        ], ['resposta.required_if' => 'Escreva a resposta ao titular (no indeferimento, com a justificativa).']);

        $solicitacao->forceFill($dados + [
            'atendido_por' => $request->user()->id,
            'concluida_em' => in_array($dados['situacao'], ['concluida', 'indeferida'], true) ? now() : null,
        ])->save();

        return back()->with('sucesso', 'Atendimento registrado.');
    }

    public function salvarTratamento(Request $request): RedirectResponse
    {
        $orgaoId = $request->user()->orgao_id;
        abort_unless($orgaoId, 422);

        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'finalidade' => ['required', 'string', 'max:5000'],
            'base_legal' => ['required', Rule::in(array_keys(OperacaoTratamento::BASES_LEGAIS))],
            'dados_tratados' => ['required', 'string', 'max:5000'],
            'titulares' => ['required', 'string', 'max:255'],
            'compartilhamento' => ['nullable', 'string', 'max:5000'],
            'retencao' => ['required', 'string', 'max:255'],
            'medidas_seguranca' => ['required', 'string', 'max:5000'],
        ]);

        OperacaoTratamento::create($dados + ['orgao_id' => $orgaoId]);

        return back()->with('sucesso', 'Operação incluída no registro de tratamento.');
    }
}
