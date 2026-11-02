<?php

namespace App\Http\Controllers;

use App\Licitacao\Enums\CategoriaProcesso;
use App\Licitacao\Enums\TipoTermo;
use App\Licitacao\Regras\AlteracaoContratual;
use App\Licitacao\Regras\Garantia;
use App\Licitacao\Regras\VigenciaContratual;
use App\Models\Contrato;
use App\Models\Fornecedor;
use App\Models\Processo;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

/** Contratos (arts. 89 a 114), termos aditivos e apostilamentos (arts. 124 a 136). */
class ContratoController extends Controller
{
    public function index(Request $request): View
    {
        $contratos = Contrato::with('fornecedor')
            ->when($request->query('filtro') === 'vencendo', fn ($q) => $q->where('situacao', 'vigente')->whereDate('vigencia_fim', '<=', today()->addDays(120)))
            ->when($request->filled('busca'), fn ($q) => $q->whereRaw('lower(objeto) like ?', ['%'.mb_strtolower($request->string('busca')).'%']))
            ->orderBy('vigencia_fim')
            ->paginate(25)
            ->withQueryString();

        return view('contratos.index', ['contratos' => $contratos]);
    }

    public function create(Request $request): View
    {
        $processo = $request->integer('processo') ? Processo::with('itens.vencedor')->findOrFail($request->integer('processo')) : null;
        $vencedor = $processo?->itens->pluck('vencedor')->filter()->first();

        return view('contratos.form', [
            'contrato' => new Contrato([
                'ano' => (int) now()->format('Y'),
                'processo_id' => $processo?->id,
                'objeto' => $processo?->objeto,
                'categoria' => $processo?->categoria ?? CategoriaProcesso::Servicos->value,
                'fornecedor_id' => $vencedor?->id,
                'valor_inicial' => $processo?->valor_homologado,
                'tipo' => 1,
            ]),
            'processos' => Processo::whereIn('situacao', ['homologado', 'contratado'])->orderByDesc('id')->get(),
            'fornecedores' => Fornecedor::where('ativo', true)->orderBy('razao_social')->get(),
            'usuarios' => User::where('orgao_id', $request->user()->orgao_id)->where('ativo', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, VigenciaContratual $vigencia, Garantia $garantia): RedirectResponse
    {
        $orgaoId = $request->user()->orgao_id;
        abort_unless($orgaoId, 422);
        $request->merge(['valor_inicial' => decimal_br($request->input('valor_inicial')), 'garantia_percentual' => decimal_br($request->input('garantia_percentual'))]);

        $validador = validator($request->all(), [
            'processo_id' => ['nullable', 'integer', Rule::exists('processos', 'id')->where('orgao_id', $orgaoId)],
            'fornecedor_id' => ['required', 'integer', Rule::exists('fornecedores', 'id')->where('orgao_id', $orgaoId)],
            'gestor_id' => ['required', 'integer', Rule::exists('users', 'id')->where('orgao_id', $orgaoId)],
            'fiscal_id' => ['required', 'integer', 'different:gestor_id', Rule::exists('users', 'id')->where('orgao_id', $orgaoId)],
            'numero' => ['required', 'string', 'max:30', Rule::unique('contratos', 'numero')->where('orgao_id', $orgaoId)->where('ano', $request->integer('ano'))],
            'ano' => ['required', 'integer', 'min:2023', 'max:2100'],
            'tipo' => ['required', Rule::in(array_keys(Contrato::TIPOS))],
            'objeto' => ['required', 'string', 'min:15', 'max:5120'],
            'categoria' => ['required', Rule::enum(CategoriaProcesso::class)],
            'valor_inicial' => ['required', 'numeric', 'gt:0'],
            'garantia_percentual' => ['nullable', 'numeric', 'min:0'],
            'justificativa_garantia' => ['nullable', 'string', 'max:2000'],
            'assinatura' => ['required', 'date', 'before_or_equal:today'],
            'vigencia_inicio' => ['required', 'date'],
            'vigencia_fim' => ['required', 'date'],
            'fundamento_vigencia_especial' => ['nullable', 'string', 'max:255'],
        ], [
            'fiscal_id.different' => 'Gestor e fiscal devem ser pessoas diferentes (segregação de funções, art. 7º, § 1º).',
            'numero.unique' => 'Já existe contrato com esse número neste ano.',
        ]);

        $validador->after(function (Validator $v) use ($request, $vigencia, $garantia) {
            foreach ($vigencia->validarInicial($request->input('vigencia_inicio', 'today'), $request->input('vigencia_fim', 'today'), $request->boolean('servico_continuo'), $request->input('fundamento_vigencia_especial')) as $e) {
                $v->errors()->add('vigencia_fim', $e);
            }
            foreach ($garantia->validar($request->input('garantia_percentual') === null ? null : (float) $request->input('garantia_percentual'), $request->input('justificativa_garantia'), $request->boolean('grande_vulto_engenharia')) as $e) {
                $v->errors()->add('garantia_percentual', $e);
            }
            if ($request->filled('fornecedor_id') && ($f = Fornecedor::find($request->integer('fornecedor_id'))) && $f->impedidoEm()) {
                $v->errors()->add('fornecedor_id', "{$f->razao_social} tem sanção vigente que impede contratar (art. 156, III e IV).");
            }
        });

        $dados = $validador->validate();

        $contrato = DB::transaction(function () use ($dados, $request, $orgaoId) {
            $contrato = Contrato::create($dados + [
                'orgao_id' => $orgaoId,
                'valor_global' => $dados['valor_inicial'],
                'servico_continuo' => $request->boolean('servico_continuo'),
                'reforma_equipamento' => $request->boolean('reforma_equipamento'),
                'situacao' => 'vigente',
            ]);

            if ($contrato->processo && $contrato->processo->situacao === 'homologado') {
                $contrato->processo->forceFill(['situacao' => 'contratado'])->save();
            }

            return $contrato;
        });

        return redirect()->route('contratos.show', $contrato)->with('sucesso', "Contrato {$contrato->identificacao()} cadastrado. Publique no PNCP em até 20 dias úteis da assinatura (art. 94, II).");
    }

    public function show(Contrato $contrato, AlteracaoContratual $alteracao): View
    {
        $contrato->load(['fornecedor', 'processo', 'gestor', 'fiscal', 'termos', 'enviosPncp']);
        $base = $contrato->valorInicialAtualizado();

        return view('contratos.show', [
            'contrato' => $contrato,
            'limites' => $alteracao->avaliar($base, $contrato->totalAcrescimos(), $contrato->totalSupressoes(), 0, 0, $contrato->reforma_equipamento),
            'base' => $base,
            'dias' => $contrato->diasParaVencer(),
        ]);
    }

    public function registrarTermo(Request $request, Contrato $contrato, AlteracaoContratual $alteracao, VigenciaContratual $vigencia): RedirectResponse
    {
        foreach (['valor_acrescimo', 'valor_supressao', 'valor_reajuste'] as $c) {
            $request->merge([$c => decimal_br($request->input($c)) ?? 0]);
        }

        $validador = validator($request->all(), [
            'tipo' => ['required', Rule::enum(TipoTermo::class)],
            'numero' => ['required', 'string', 'max:30', Rule::unique('termos_contrato', 'numero')->where('contrato_id', $contrato->id)],
            'assinatura' => ['required', 'date', 'before_or_equal:today'],
            'valor_acrescimo' => ['numeric', 'min:0'],
            'valor_supressao' => ['numeric', 'min:0'],
            'valor_reajuste' => ['numeric', 'min:0'],
            'nova_vigencia_fim' => ['nullable', 'required_if:tipo,aditivo_prazo', 'date', 'after:'.$contrato->vigencia_fim->format('Y-m-d')],
            'justificativa' => ['required', 'string', 'min:20', 'max:5120'],
        ], ['nova_vigencia_fim.after' => 'A nova vigência precisa terminar depois da vigência atual.']);

        $validador->after(function (Validator $v) use ($request, $contrato, $alteracao, $vigencia) {
            if ($contrato->situacao !== 'vigente') {
                $v->errors()->add('tipo', 'O contrato não está vigente.');
            }

            if ($request->input('tipo') === TipoTermo::AditivoValor->value) {
                $r = $alteracao->avaliar($contrato->valorInicialAtualizado(), $contrato->totalAcrescimos(), $contrato->totalSupressoes(), (float) $request->input('valor_acrescimo'), (float) $request->input('valor_supressao'), $contrato->reforma_equipamento);
                foreach ($r['mensagens'] as $m) {
                    $v->errors()->add('valor_acrescimo', $m);
                }
            }

            if ($request->input('tipo') === TipoTermo::AditivoPrazo->value && $request->filled('nova_vigencia_fim')) {
                foreach ($vigencia->validarProrrogacao($contrato->vigencia_inicio, $request->input('nova_vigencia_fim'), $contrato->servico_continuo, $contrato->fundamento_vigencia_especial) as $m) {
                    $v->errors()->add('nova_vigencia_fim', $m);
                }
            }
        });

        $dados = $validador->validate();

        DB::transaction(function () use ($contrato, $dados) {
            $termo = $contrato->termos()->create($dados + ['orgao_id' => $contrato->orgao_id]);
            $delta = (float) $termo->valor_acrescimo - (float) $termo->valor_supressao + (float) $termo->valor_reajuste;

            $contrato->valor_global = round((float) $contrato->valor_global + $delta, 2);
            if ($termo->nova_vigencia_fim) {
                $contrato->vigencia_fim = $termo->nova_vigencia_fim;
            }
            if ($termo->tipo === TipoTermo::Rescisao->value) {
                $contrato->situacao = 'rescindido';
            }
            $contrato->save();
        });

        return back()->with('sucesso', 'Termo registrado. Publique no PNCP.');
    }
}
