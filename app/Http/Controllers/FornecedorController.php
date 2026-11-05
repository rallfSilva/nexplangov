<?php

namespace App\Http\Controllers;

use App\Integracoes\Transparencia\ConsultaCeisCnep;
use App\Licitacao\Regras\ImpedimentoCadastral;
use App\Models\ConsultaImpedimento;
use App\Models\Fornecedor;
use App\Models\SancaoFornecedor;
use App\Rules\Cnpj;
use App\Rules\Cpf;
use App\Support\Documento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

class FornecedorController extends Controller
{
    public function index(Request $request): View
    {
        $fornecedores = Fornecedor::withCount(['sancoes as sancoes_vigentes_count' => fn ($q) => $q->whereIn('tipo', ['impedimento', 'inidoneidade'])->where(fn ($w) => $w->whereNull('fim')->orWhereDate('fim', '>=', today()))])
            ->when($request->filled('busca'), function ($q) use ($request) {
                $busca = (string) $request->string('busca');
                $digitos = Documento::digitos($busca);
                $q->where(function ($w) use ($busca, $digitos) {
                    $w->whereRaw('lower(razao_social) like ?', ['%'.mb_strtolower($busca).'%']);
                    if (strlen($digitos) >= 11) {
                        $w->orWhere('documento_hash', Documento::hash($digitos));
                    }
                });
            })
            ->orderBy('razao_social')
            ->paginate(25)
            ->withQueryString();

        return view('fornecedores.index', ['fornecedores' => $fornecedores]);
    }

    public function create(): View
    {
        return view('fornecedores.form', ['fornecedor' => new Fornecedor(['tipo_pessoa' => 'PJ', 'ativo' => true])]);
    }

    public function store(Request $request, ConsultaCeisCnep $consulta): RedirectResponse
    {
        $orgaoId = $request->user()->orgao_id;
        abort_unless($orgaoId, 422);
        $fornecedor = Fornecedor::create($this->validar($request, $orgaoId) + ['orgao_id' => $orgaoId]);
        $msg = "Fornecedor {$fornecedor->razao_social} cadastrado.";

        // Consulta preventiva ao CEIS e ao CNEP logo no cadastro (art. 161).
        if ($consulta->habilitada()) {
            $c = $consulta->consultar($fornecedor, $request->user());
            $msg .= ' '.$this->resumoConsulta($c);
        }

        return redirect()->route('fornecedores.edit', $fornecedor)->with('sucesso', $msg);
    }

    public function edit(Fornecedor $fornecedor): View
    {
        return view('fornecedores.form', [
            'fornecedor' => $fornecedor->load('sancoes'),
            'ultimaConsulta' => ConsultaImpedimento::where('fornecedor_id', $fornecedor->id)->latest('consultado_em')->latest('id')->first(),
        ]);
    }

    public function update(Request $request, Fornecedor $fornecedor): RedirectResponse
    {
        $dados = $this->validar($request, $fornecedor->orgao_id, $fornecedor);
        unset($dados['documento'], $dados['tipo_pessoa']); // documento não muda depois do cadastro
        $fornecedor->update($dados);

        return back()->with('sucesso', 'Fornecedor atualizado.');
    }

    public function registrarSancao(Request $request, Fornecedor $fornecedor): RedirectResponse
    {
        $dados = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(SancaoFornecedor::TIPOS))],
            'inicio' => ['required', 'date'],
            'fim' => ['nullable', 'date', 'after_or_equal:inicio'],
            'processo' => ['nullable', 'string', 'max:100'],
            'fundamento' => ['required', 'string', 'min:20', 'max:5000'],
        ]);

        $fornecedor->sancoes()->create($dados + ['orgao_id' => $fornecedor->orgao_id]);

        return back()->with('sucesso', 'Sanção registrada. Se for impedimento ou inidoneidade, informe também no cadastro nacional (art. 161).');
    }

    /** Consultas ao CEIS e ao CNEP do fornecedor, com a análise de abrangência (art. 161 e art. 156, § 4º). */
    public function impedimentos(Fornecedor $fornecedor, ConsultaCeisCnep $consulta): View
    {
        return view('fornecedores.ceis', [
            'fornecedor' => $fornecedor,
            'consultas' => ConsultaImpedimento::with(['autorRessalva', 'usuario'])->where('fornecedor_id', $fornecedor->id)->latest('consultado_em')->latest('id')->limit(10)->get(),
            'habilitada' => $consulta->habilitada(),
        ]);
    }

    /** Consulta o CEIS e o CNEP na hora (art. 161). */
    public function consultarImpedimento(Request $request, Fornecedor $fornecedor, ConsultaCeisCnep $consulta): RedirectResponse
    {
        if (! $consulta->habilitada()) {
            return back()->with('erro', 'A consulta ao CEIS e ao CNEP não está configurada. Informe a chave da API do Portal da Transparência no .env (TRANSPARENCIA_CHAVE).');
        }

        $c = $consulta->consultar($fornecedor, $request->user());

        return back()->with($c->situacao === 'indisponivel' ? 'erro' : 'sucesso', $this->resumoConsulta($c));
    }

    /**
     * Análise de abrangência pela autoridade: a sanção encontrada não alcança este órgão (art. 156, § 4º).
     * Não cabe para declaração de inidoneidade, que vale para toda a Administração (art. 156, § 5º).
     */
    public function ressalvarImpedimento(Request $request, Fornecedor $fornecedor, ConsultaImpedimento $consulta): RedirectResponse
    {
        abort_unless($consulta->fornecedor_id === $fornecedor->id, 404);

        $dados = $request->validate(['justificativa' => ['required', 'string', 'min:'.ImpedimentoCadastral::JUSTIFICATIVA_MINIMA, 'max:5000']],
            ['justificativa.min' => 'Explique por que a sanção não alcança este órgão, com pelo menos '.ImpedimentoCadastral::JUSTIFICATIVA_MINIMA.' caracteres.']);

        if (! ImpedimentoCadastral::podeRessalvar($consulta->vigentes())) {
            return back()->with('erro', 'Não há análise de abrangência a registrar: ou não há sanção impeditiva vigente, ou ela é de inidoneidade, que alcança toda a Administração (art. 156, § 5º).');
        }

        $consulta->update(['ressalva_justificativa' => trim($dados['justificativa']), 'ressalva_por' => $request->user()->id, 'ressalva_em' => now()]);

        return back()->with('sucesso', 'Análise de abrangência registrada. Ela vale enquanto as sanções do cadastro forem as mesmas.');
    }

    private function resumoConsulta(ConsultaImpedimento $c): string
    {
        return match ($c->situacao) {
            'regular' => 'CEIS e CNEP: nada consta.',
            'sancionado' => 'CEIS e CNEP: há '.count($c->registros ?? []).' registro(s) de sanção. Confira na ficha do fornecedor.',
            default => 'Não foi possível consultar o CEIS e o CNEP agora: '.$c->erro,
        };
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, int $orgaoId, ?Fornecedor $fornecedor = null): array
    {
        $pf = $request->input('tipo_pessoa', $fornecedor?->tipo_pessoa) === 'PF';

        $validador = validator($request->all(), [
            'tipo_pessoa' => [$fornecedor ? 'nullable' : 'required', Rule::in(['PJ', 'PF'])],
            'documento' => $fornecedor ? ['nullable'] : ['required', $pf ? new Cpf : new Cnpj],
            'razao_social' => ['required', 'string', 'max:255'],
            'nome_fantasia' => ['nullable', 'string', 'max:255'],
            'porte' => ['nullable', Rule::in(array_keys(Fornecedor::PORTES))],
            'email' => ['nullable', 'email', 'max:255'],
            'telefone' => ['nullable', 'string', 'max:20'],
            'municipio' => ['nullable', 'string', 'max:120'],
            'uf' => ['nullable', 'string', 'size:2'],
        ]);

        $validador->after(function (Validator $v) use ($request, $orgaoId, $fornecedor) {
            if (! $fornecedor && $request->filled('documento') && Fornecedor::where('orgao_id', $orgaoId)->where('documento_hash', Documento::hash((string) $request->input('documento')))->exists()) {
                $v->errors()->add('documento', 'Este fornecedor já está cadastrado.');
            }
        });

        $dados = $validador->validate();
        $dados['ativo'] = $request->boolean('ativo', true);
        $dados['uf'] = isset($dados['uf']) ? mb_strtoupper($dados['uf']) : null;

        return $dados;
    }
}
