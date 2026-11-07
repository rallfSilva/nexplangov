<?php

namespace App\Http\Controllers;

use App\Licitacao\Regras\ConcessaoPermissao as RegraConcessao;
use App\Licitacao\Regras\ParametrosOrgao;
use App\Models\ConcessaoPermissao;
use App\Models\ParametroOrgao;
use App\Models\User;
use App\Support\Parametros;
use Database\Seeders\PerfisSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Spatie\Permission\PermissionRegistrar;

/**
 * Parametrização do órgão pela tela (11.2, 11.4 e 11.5): prazos e regras dentro de limites, e permissões extras
 * para um servidor sem quebrar a segregação de funções (art. 7º, § 1º). Tudo com justificativa e na auditoria.
 */
class ParametroController extends Controller
{
    public function index(Request $request): View
    {
        $orgaoId = $this->orgaoDoUsuario($request);
        $ajustes = ParametroOrgao::where('orgao_id', $orgaoId)->get()->keyBy('chave');
        $grupos = collect(ParametrosOrgao::DEFINICOES)->map(fn ($d, $k) => $d + ['chave' => $k, 'atual' => Parametros::valor($k, $orgaoId), 'padrao' => config($d['config']), 'ajuste' => $ajustes[$k] ?? null])->groupBy('grupo');

        return view('parametros.index', [
            'grupos' => $grupos,
            'perfis' => PerfisSeeder::PERFIS, 'permissoes' => PerfisSeeder::PERMISSOES, 'rotulosPerfis' => User::PERFIS,
            'usuarios' => User::visiveisPara($request->user())->where('ativo', true)->orderBy('name')->get(),
            'concessoes' => ConcessaoPermissao::with(['usuario', 'concedente'])->vigentes()->latest('id')->get(),
            'concediveis' => array_diff_key(PerfisSeeder::PERMISSOES, array_flip(RegraConcessao::NAO_CONCEDIVEIS)),
        ]);
    }

    public function salvar(Request $request, string $chave): RedirectResponse
    {
        abort_unless(isset(ParametrosOrgao::DEFINICOES[$chave]), 404);
        $d = $request->validate(['valor' => ['required', 'string', 'max:200'], 'justificativa' => ['required', 'string', 'min:15', 'max:500']],
            ['justificativa.*' => 'Justifique a mudança (mínimo 15 caracteres): ela fica na trilha de auditoria.']);
        [$valor, $motivos] = ParametrosOrgao::validar($chave, $d['valor']);
        if ($motivos) {
            throw ValidationException::withMessages(["valor_{$chave}" => implode(' ', $motivos)]);
        }
        $orgaoId = $this->orgaoDoUsuario($request);
        ParametroOrgao::updateOrCreate(['orgao_id' => $orgaoId, 'chave' => $chave],
            ['valor' => is_array($valor) ? json_encode($valor) : (string) $valor, 'justificativa' => trim($d['justificativa']), 'alterado_por' => $request->user()->id]);

        return redirect()->route('parametros.index')->with('sucesso', ParametrosOrgao::DEFINICOES[$chave]['rotulo'].' atualizado.');
    }

    public function restaurar(Request $request, string $chave): RedirectResponse
    {
        abort_unless(isset(ParametrosOrgao::DEFINICOES[$chave]), 404);
        ParametroOrgao::where('orgao_id', $this->orgaoDoUsuario($request))->where('chave', $chave)->first()?->delete();

        return redirect()->route('parametros.index')->with('sucesso', 'Parâmetro voltou ao padrão do sistema.');
    }

    public function conceder(Request $request): RedirectResponse
    {
        $orgaoId = $this->orgaoDoUsuario($request);
        $d = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('orgao_id', $orgaoId)],
            'permissao' => ['required', Rule::in(array_keys(PerfisSeeder::PERMISSOES))],
            'justificativa' => ['nullable', 'string', 'max:1000'],
        ]);
        $u = User::findOrFail($d['user_id']);
        abort_if($u->id === $request->user()->id, 403, 'Ninguém concede permissão a si mesmo.');
        if ($m = RegraConcessao::motivos((string) $u->perfilPrincipal(), $d['permissao'], $u->getAllPermissions()->pluck('name')->all(), $d['justificativa'] ?? null)) {
            throw ValidationException::withMessages(['permissao' => implode(' ', $m)]);
        }

        DB::transaction(function () use ($u, $d, $orgaoId, $request) {
            ConcessaoPermissao::create(['orgao_id' => $orgaoId, 'user_id' => $u->id, 'permissao' => $d['permissao'], 'justificativa' => trim($d['justificativa']), 'concedida_por' => $request->user()->id]);
            $u->givePermissionTo($d['permissao']);
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('parametros.index')->with('sucesso', "Permissão concedida a {$u->name}.");
    }

    public function revogar(Request $request, ConcessaoPermissao $concessao): RedirectResponse
    {
        abort_if($concessao->revogada_em !== null, 404);
        $d = $request->validate(['motivo' => ['required', 'string', 'min:10', 'max:500']], ['motivo.*' => 'Informe o motivo da revogação (mínimo 10 caracteres).']);

        DB::transaction(function () use ($concessao, $d, $request) {
            $concessao->update(['revogada_em' => now(), 'revogada_por' => $request->user()->id, 'motivo_revogacao' => trim($d['motivo'])]);
            $concessao->usuario->revokePermissionTo($concessao->permissao);
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('parametros.index')->with('sucesso', 'Permissão revogada.');
    }

    private function orgaoDoUsuario(Request $request): int
    {
        return $request->user()->orgao_id ?? abort(403, 'Os parâmetros são de cada órgão: entre como administrador do órgão.');
    }
}
