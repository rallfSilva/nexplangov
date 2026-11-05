<?php

namespace App\Http\Controllers;

use App\Models\Orgao;
use App\Models\Unidade;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UsuarioController extends Controller
{
    public function index(Request $request): View
    {
        $usuarios = User::visiveisPara($request->user())
            ->with(['roles', 'orgao'])
            ->when($request->filled('busca'), function ($q) use ($request) {
                $termo = '%'.mb_strtolower($request->string('busca')).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(name) like ?', [$termo])->orWhereRaw('lower(email) like ?', [$termo]));
            })
            ->when($request->filled('perfil'), fn ($q) => $q->role($request->string('perfil')->toString()))
            ->when($request->filled('situacao'), fn ($q) => $q->where('ativo', $request->input('situacao') === 'ativos'))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('usuarios.index', ['usuarios' => $usuarios]);
    }

    public function create(Request $request): View
    {
        return view('usuarios.form', $this->dadosFormulario($request, new User(['ativo' => true])));
    }

    public function store(Request $request): RedirectResponse
    {
        [$dados, $perfil] = $this->validar($request);

        $usuario = User::create([...$dados, 'password' => Str::password(32)]);
        $usuario->assignRole($perfil);
        Auditoria::registrar('permissao', $usuario, "Perfil {$perfil} atribuído a {$usuario->email}", null, ['perfil' => $perfil]);

        $status = Password::sendResetLink(['email' => $usuario->email]);
        $mensagem = $status === Password::RESET_LINK_SENT
            ? "Usuário cadastrado. Enviamos para {$usuario->email} o link para criar a senha."
            : 'Usuário cadastrado, mas o e-mail com o link de senha não pôde ser enviado. Use "Enviar link de senha" depois.';

        return redirect()->route('usuarios.index')->with('sucesso', $mensagem);
    }

    public function edit(Request $request, User $usuario): View
    {
        Gate::authorize('alterar-usuario', $usuario);

        return view('usuarios.form', $this->dadosFormulario($request, $usuario));
    }

    public function update(Request $request, User $usuario): RedirectResponse
    {
        Gate::authorize('alterar-usuario', $usuario);

        [$dados, $perfil] = $this->validar($request, $usuario);
        $perfilAnterior = $usuario->perfilPrincipal();

        $usuario->update($dados);

        if ($perfil !== $perfilAnterior) {
            $usuario->syncRoles([$perfil]);
            Auditoria::registrar('permissao', $usuario, "Perfil de {$usuario->email} alterado de {$perfilAnterior} para {$perfil}", ['perfil' => $perfilAnterior], ['perfil' => $perfil]);
        }

        return redirect()->route('usuarios.index')->with('sucesso', "Dados de {$usuario->name} atualizados.");
    }

    public function alternarAtivo(Request $request, User $usuario): RedirectResponse
    {
        Gate::authorize('alterar-usuario', $usuario);

        if ($usuario->is($request->user())) {
            return back()->with('erro', 'Você não pode desativar o seu próprio acesso.');
        }

        $usuario->update(['ativo' => ! $usuario->ativo]);

        return back()->with('sucesso', $usuario->ativo ? "Acesso de {$usuario->name} reativado." : "Acesso de {$usuario->name} desativado. A sessão dele foi encerrada.");
    }

    public function enviarLinkSenha(User $usuario): RedirectResponse
    {
        Gate::authorize('alterar-usuario', $usuario);

        $status = Password::sendResetLink(['email' => $usuario->email]);
        Auditoria::registrar('senha', $usuario, "Link de criação de senha enviado para {$usuario->email}");

        return back()->with($status === Password::RESET_LINK_SENT ? 'sucesso' : 'erro', __($status));
    }

    /** @return array<string, mixed> */
    private function dadosFormulario(Request $request, User $usuario): array
    {
        return [
            'usuario' => $usuario,
            'perfis' => $this->perfisPermitidos($request->user()),
            'orgaos' => $request->user()->orgao_id === null ? Orgao::where('ativo', true)->orderBy('sigla')->get() : collect(),
            'unidades' => Unidade::with('orgao')->where('ativo', true)->orderBy('sigla')->get(),
            'perfilAtual' => $usuario->exists ? $usuario->perfilPrincipal() : 'requisitante',
        ];
    }

    /** @return array<string, string> */
    private function perfisPermitidos(User $quem): array
    {
        $perfis = User::PERFIS;

        if (! $quem->ehAdministradorGeral()) {
            unset($perfis['administrador-geral']);
        }

        return $perfis;
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    private function validar(Request $request, ?User $usuario = null): array
    {
        $quem = $request->user();
        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
            'cpf' => $request->filled('cpf') ? preg_replace('/\D/', '', (string) $request->input('cpf')) : null,
        ]);

        $dados = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($usuario)],
            'cpf' => ['nullable', 'digits:11', Rule::unique('users', 'cpf')->ignore($usuario)],
            'perfil' => ['required', Rule::in(array_keys($this->perfisPermitidos($quem)))],
            'unidade_id' => ['nullable', 'integer'],
            'orgao_id' => [Rule::requiredIf($quem->orgao_id === null && $request->input('perfil') !== 'administrador-geral'), 'nullable', Rule::exists('orgaos', 'id')],
        ], [
            'perfil.in' => 'Você não tem permissão para atribuir esse perfil.',
            'orgao_id.required' => 'Escolha o órgão do usuário.',
            'cpf.digits' => 'O CPF deve ter 11 dígitos.',
        ]);

        $perfil = $dados['perfil'];

        if ($usuario && $usuario->is($quem) && $perfil !== $usuario->perfilPrincipal()) {
            abort(403, 'Você não pode alterar o seu próprio perfil.');
        }

        $orgaoId = match (true) {
            $quem->orgao_id !== null => $quem->orgao_id,
            $perfil === 'administrador-geral' => null,
            default => (int) $dados['orgao_id'],
        };

        $unidadeId = null;
        if (! empty($dados['unidade_id'])) {
            $unidade = Unidade::find($dados['unidade_id']);
            if (! $unidade || $unidade->orgao_id !== $orgaoId) {
                throw ValidationException::withMessages(['unidade_id' => 'A unidade de lotação precisa ser do mesmo órgão do usuário.']);
            }
            $unidadeId = $unidade->id;
        }

        return [[
            'name' => $dados['name'],
            'unidade_id' => $unidadeId,
            'email' => $dados['email'],
            'cpf' => $dados['cpf'] ?? null,
            'orgao_id' => $orgaoId,
        ], $perfil];
    }
}
