<?php

namespace Tests\Feature;

use App\Models\Orgao;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PermissoesTest extends TestCase
{
    use RefreshDatabase;

    private Orgao $orgao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->semearPerfis();
        $this->orgao = Orgao::factory()->create();
    }

    /** @return array<string, array{string, string, int}> */
    public static function acessos(): array
    {
        return [
            'requisitante não vê unidades' => ['requisitante', '/unidades', 403],
            'requisitante não vê usuários' => ['requisitante', '/usuarios', 403],
            'requisitante não vê auditoria' => ['requisitante', '/auditoria', 403],
            'chefia vê unidades' => ['autoridade', '/unidades', 200],
            'chefia não cria unidade' => ['autoridade', '/unidades/create', 403],
            'planejamento vê usuários' => ['planejamento', '/usuarios', 200],
            'planejamento não cria usuário' => ['planejamento', '/usuarios/create', 403],
            'planejamento não vê auditoria' => ['planejamento', '/auditoria', 403],
            'auditor vê auditoria' => ['auditor', '/auditoria', 200],
            'auditor não cria unidade' => ['auditor', '/unidades/create', 403],
            'admin do órgão não gerencia órgãos' => ['administrador-orgao', '/orgaos', 403],
            'admin do órgão cria usuário' => ['administrador-orgao', '/usuarios/create', 200],
            'admin geral gerencia órgãos' => ['administrador-geral', '/orgaos', 200],
            'todos veem o painel' => ['requisitante', '/painel', 200],
        ];
    }

    #[DataProvider('acessos')]
    public function test_acesso_por_perfil(string $perfil, string $url, int $status): void
    {
        $this->actingAs($this->usuario($perfil, $this->orgao))->get($url)->assertStatus($status);
    }

    public function test_admin_do_orgao_nao_pode_criar_administrador_geral(): void
    {
        $this->actingAs($this->usuario('administrador-orgao', $this->orgao))
            ->post('/usuarios', ['name' => 'Golpe', 'email' => 'golpe@exemplo.gov.br', 'perfil' => 'administrador-geral'])
            ->assertSessionHasErrors('perfil');

        $this->assertDatabaseMissing('users', ['email' => 'golpe@exemplo.gov.br']);
    }

    public function test_admin_do_orgao_cadastra_usuario_no_proprio_orgao_e_envia_link(): void
    {
        Notification::fake();
        $outro = Orgao::factory()->create();

        $this->actingAs($this->usuario('administrador-orgao', $this->orgao))
            ->post('/usuarios', ['name' => 'Fulana de Teste', 'email' => 'Fulana@Exemplo.gov.br', 'cpf' => '123.456.789-09', 'perfil' => 'planejamento', 'orgao_id' => $outro->id])
            ->assertRedirect('/usuarios');

        $novo = User::where('email', 'fulana@exemplo.gov.br')->firstOrFail();
        $this->assertSame($this->orgao->id, $novo->orgao_id);
        $this->assertSame('12345678909', $novo->cpf);
        $this->assertTrue($novo->hasRole('planejamento'));
        Notification::assertSentTo($novo, ResetPassword::class);
        $this->assertDatabaseHas('registros_auditoria', ['evento' => 'permissao', 'auditable_id' => $novo->id]);
    }

    public function test_mudanca_de_perfil_fica_na_auditoria(): void
    {
        $alvo = $this->usuario('requisitante', $this->orgao);

        $this->actingAs($this->usuario('administrador-orgao', $this->orgao))
            ->put("/usuarios/{$alvo->id}", ['name' => $alvo->name, 'email' => $alvo->email, 'perfil' => 'autoridade'])
            ->assertRedirect('/usuarios');

        $this->assertTrue($alvo->fresh()->hasRole('autoridade'));
        $this->assertDatabaseHas('registros_auditoria', ['evento' => 'permissao', 'auditable_id' => $alvo->id]);
    }

    public function test_ninguem_altera_o_proprio_perfil(): void
    {
        $admin = $this->usuario('administrador-orgao', $this->orgao);

        $this->actingAs($admin)
            ->put("/usuarios/{$admin->id}", ['name' => $admin->name, 'email' => $admin->email, 'perfil' => 'requisitante'])
            ->assertForbidden();
    }

    public function test_ninguem_desativa_o_proprio_acesso(): void
    {
        $admin = $this->usuario('administrador-orgao', $this->orgao);

        $this->actingAs($admin)->patch("/usuarios/{$admin->id}/ativo")->assertSessionHas('erro');
        $this->assertTrue($admin->fresh()->ativo);
    }

    public function test_admin_desativa_e_reativa_usuario(): void
    {
        $alvo = $this->usuario('planejamento', $this->orgao);
        $admin = $this->usuario('administrador-orgao', $this->orgao);

        $this->actingAs($admin)->patch("/usuarios/{$alvo->id}/ativo");
        $this->assertFalse($alvo->fresh()->ativo);

        $this->actingAs($admin)->patch("/usuarios/{$alvo->id}/ativo");
        $this->assertTrue($alvo->fresh()->ativo);
    }

    public function test_admin_geral_cria_orgao(): void
    {
        $this->actingAs($this->usuario('administrador-geral'))
            ->post('/orgaos', ['nome' => 'Secretaria de Teste', 'sigla' => 'sete', 'esfera' => 'estadual', 'uf' => 'rr', 'municipio' => 'Boa Vista', 'ativo' => '1'])
            ->assertRedirect('/orgaos');

        $this->assertDatabaseHas('orgaos', ['sigla' => 'SETE', 'uf' => 'RR']);
        $this->assertDatabaseHas('registros_auditoria', ['evento' => 'criado', 'descricao' => 'Órgão SETE criado']);
    }
}
