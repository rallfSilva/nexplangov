<?php

namespace App\Http\Controllers;

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

    public function store(Request $request): RedirectResponse
    {
        $orgaoId = $request->user()->orgao_id;
        abort_unless($orgaoId, 422);
        $fornecedor = Fornecedor::create($this->validar($request, $orgaoId) + ['orgao_id' => $orgaoId]);

        return redirect()->route('fornecedores.edit', $fornecedor)->with('sucesso', "Fornecedor {$fornecedor->razao_social} cadastrado.");
    }

    public function edit(Fornecedor $fornecedor): View
    {
        return view('fornecedores.form', ['fornecedor' => $fornecedor->load('sancoes')]);
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
