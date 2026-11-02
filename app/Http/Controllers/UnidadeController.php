<?php

namespace App\Http\Controllers;

use App\Models\Orgao;
use App\Models\Unidade;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

class UnidadeController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $orgaos = $user->orgao_id === null ? Orgao::orderBy('sigla')->get() : collect();
        $orgaoId = $user->orgao_id ?? $request->integer('orgao') ?: $orgaos->first()?->id;

        $unidades = Unidade::with(['chefe', 'superior'])
            ->where('orgao_id', $orgaoId)
            ->when($request->filled('busca'), function ($q) use ($request) {
                $termo = '%'.mb_strtolower($request->string('busca')).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(nome) like ?', [$termo])->orWhereRaw('lower(sigla) like ?', [$termo]));
            })
            ->orderBy('sigla')
            ->get();

        return view('unidades.index', [
            'arvore' => $request->filled('busca') ? null : $this->arvore($unidades),
            'unidades' => $unidades,
            'orgaos' => $orgaos,
            'orgaoId' => $orgaoId,
        ]);
    }

    public function create(Request $request): View
    {
        $unidade = new Unidade(['tipo' => 'departamento', 'ativo' => true, 'parent_id' => $request->integer('superior') ?: null]);

        return view('unidades.form', $this->dadosFormulario($request, $unidade));
    }

    public function store(Request $request): RedirectResponse
    {
        $unidade = Unidade::create($this->validar($request));

        return redirect()->route('unidades.index', ['orgao' => $unidade->orgao_id])->with('sucesso', "Unidade {$unidade->sigla} cadastrada.");
    }

    public function edit(Request $request, Unidade $unidade): View
    {
        return view('unidades.form', $this->dadosFormulario($request, $unidade));
    }

    public function update(Request $request, Unidade $unidade): RedirectResponse
    {
        $unidade->update($this->validar($request, $unidade));

        return redirect()->route('unidades.index', ['orgao' => $unidade->orgao_id])->with('sucesso', "Unidade {$unidade->sigla} atualizada.");
    }

    public function destroy(Unidade $unidade): RedirectResponse
    {
        if ($unidade->subordinadas()->exists()) {
            return back()->with('erro', "A unidade {$unidade->sigla} tem unidades subordinadas. Mova ou exclua as subordinadas primeiro, ou apenas desative a unidade.");
        }

        $unidade->delete();

        return redirect()->route('unidades.index', ['orgao' => $unidade->orgao_id])->with('sucesso', "Unidade {$unidade->sigla} excluída.");
    }

    /** @return array<string, mixed> */
    private function dadosFormulario(Request $request, Unidade $unidade): array
    {
        $user = $request->user();
        $orgaoId = $unidade->orgao_id ?? $user->orgao_id ?? $request->integer('orgao') ?: null;
        $excluir = $unidade->exists ? [$unidade->id, ...$unidade->idsDescendentes()] : [];

        return [
            'unidade' => $unidade,
            'orgaos' => $user->orgao_id === null && ! $unidade->exists ? Orgao::where('ativo', true)->orderBy('sigla')->get() : collect(),
            'orgaoId' => $orgaoId,
            'superiores' => $orgaoId ? Unidade::where('orgao_id', $orgaoId)->whereNotIn('id', $excluir)->orderBy('sigla')->get() : collect(),
            'chefes' => $orgaoId ? User::where('orgao_id', $orgaoId)->where('ativo', true)->orderBy('name')->get() : collect(),
        ];
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, ?Unidade $unidade = null): array
    {
        $user = $request->user();
        $orgaoId = $unidade?->orgao_id ?? $user->orgao_id ?? $request->integer('orgao_id');

        $request->merge(['sigla' => mb_strtoupper(trim((string) $request->input('sigla')))]);

        $validador = validator($request->all(), [
            'orgao_id' => [Rule::requiredIf($user->orgao_id === null && ! $unidade), 'nullable', Rule::exists('orgaos', 'id')],
            'sigla' => ['required', 'string', 'max:30', Rule::unique('unidades', 'sigla')->where('orgao_id', $orgaoId)->ignore($unidade)],
            'nome' => ['required', 'string', 'max:255'],
            'tipo' => ['required', Rule::in(array_keys(Unidade::TIPOS))],
            'parent_id' => ['nullable', 'integer', Rule::exists('unidades', 'id')->where('orgao_id', $orgaoId)],
            'chefe_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('orgao_id', $orgaoId)],
            'email' => ['nullable', 'email', 'max:255'],
            'telefone' => ['nullable', 'string', 'max:20'],
            'codigo_unidade_pncp' => ['nullable', 'string', 'max:30', 'regex:/^[0-9A-Za-z.\-]+$/'],
        ], [
            'parent_id.exists' => 'A unidade superior precisa ser do mesmo órgão.',
            'chefe_user_id.exists' => 'O chefe precisa ser um usuário do mesmo órgão.',
            'sigla.unique' => 'Já existe uma unidade com essa sigla neste órgão.',
        ]);

        $validador->after(function (Validator $v) use ($request, $unidade) {
            $pai = $request->integer('parent_id');

            if ($unidade && $pai && ($pai === $unidade->id || in_array($pai, $unidade->idsDescendentes(), true))) {
                $v->errors()->add('parent_id', 'A unidade não pode ficar subordinada a ela mesma nem a uma de suas subordinadas.');
            }
        });

        $dados = $validador->validate();
        $dados['orgao_id'] = $orgaoId;
        $dados['ativo'] = $request->boolean('ativo');

        return $dados;
    }

    /**
     * @param  Collection<int, Unidade>  $unidades
     * @return Collection<int, array{unidade: Unidade, nivel: int}>
     */
    private function arvore(Collection $unidades): Collection
    {
        $porPai = $unidades->groupBy(fn (Unidade $u) => $u->parent_id ?? 0);
        $ids = $unidades->pluck('id')->flip();
        $linhas = collect();

        $visitar = function (int $paiId, int $nivel) use (&$visitar, $porPai, $linhas) {
            foreach ($porPai->get($paiId, collect()) as $unidade) {
                $linhas->push(['unidade' => $unidade, 'nivel' => $nivel]);
                $visitar($unidade->id, $nivel + 1);
            }
        };

        // Raízes: sem superior, ou com superior fora da lista.
        foreach ($unidades as $unidade) {
            if ($unidade->parent_id === null || ! $ids->has($unidade->parent_id)) {
                $linhas->push(['unidade' => $unidade, 'nivel' => 0]);
                $visitar($unidade->id, 1);
            }
        }

        return $linhas;
    }
}
