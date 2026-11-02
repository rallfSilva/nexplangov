<?php

namespace Tests\Feature;

use App\Models\Orgao;
use App\Models\RegistroAuditoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutenticacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->semearPerfis();
    }

    public function test_visitante_e_mandado_para_o_login(): void
    {
        $this->get('/painel')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Entrar no sistema');
    }

    public function test_cadastro_publico_de_usuario_nao_existe(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_entrada_com_senha_certa_registra_auditoria_e_ultimo_acesso(): void
    {
        $user = $this->usuario('planejamento', Orgao::factory()->create(), ['password' => 'SenhaForte123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'SenhaForte123'])->assertRedirect('/painel');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->ultimo_acesso_em);
        $this->assertDatabaseHas('registros_auditoria', ['evento' => 'login', 'user_id' => $user->id, 'orgao_id' => $user->orgao_id]);
    }

    public function test_senha_errada_e_recusada_e_auditada(): void
    {
        $user = $this->usuario('planejamento', Orgao::factory()->create(), ['password' => 'SenhaForte123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'errada'])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('registros_auditoria', ['evento' => 'login_falhou', 'user_id' => $user->id]);
    }

    public function test_usuario_inativo_nao_entra(): void
    {
        $user = User::factory()->comPerfil('planejamento')->inativo()->create(['password' => 'SenhaForte123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'SenhaForte123'])
            ->assertSessionHasErrors(['email' => 'Seu acesso está desativado. Procure o administrador do órgão.']);

        $this->assertGuest();
    }

    public function test_usuario_de_orgao_inativo_nao_entra(): void
    {
        $orgao = Orgao::factory()->create(['ativo' => false]);
        $user = $this->usuario('planejamento', $orgao, ['password' => 'SenhaForte123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'SenhaForte123'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_usuario_desativado_durante_a_sessao_e_desconectado(): void
    {
        $user = $this->usuario('planejamento', Orgao::factory()->create());
        $this->actingAs($user)->get('/painel')->assertOk();

        $user->update(['ativo' => false]);

        $this->actingAs($user->fresh())->get('/painel')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_entrada_e_limitada_apos_cinco_tentativas(): void
    {
        $user = $this->usuario('planejamento', Orgao::factory()->create());

        foreach (range(1, 5) as $_) {
            $this->post('/login', ['email' => $user->email, 'password' => 'errada']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'errada'])->assertStatus(429);
    }

    public function test_saida_registra_auditoria(): void
    {
        $user = $this->usuario('requisitante', Orgao::factory()->create());

        $this->actingAs($user)->post('/logout')->assertRedirect('/');

        $this->assertGuest();
        $this->assertSame(1, RegistroAuditoria::withoutGlobalScopes()->where('evento', 'logout')->where('user_id', $user->id)->count());
    }

    public function test_administrador_sem_2fa_e_mandado_ativar(): void
    {
        $admin = User::factory()->comPerfil('administrador-orgao')->create();

        $this->actingAs($admin)->get('/painel')->assertRedirect(route('seguranca'));
        $this->actingAs($admin)->get('/seguranca')->assertOk()->assertSee('Verificação em duas etapas');
    }

    public function test_administrador_com_2fa_usa_o_sistema(): void
    {
        $admin = $this->usuario('administrador-orgao', Orgao::factory()->create());

        $this->actingAs($admin)->get('/painel')->assertOk();
    }

    public function test_servidor_nao_precisa_de_2fa(): void
    {
        $user = $this->usuario('requisitante', Orgao::factory()->create());

        $this->actingAs($user)->get('/painel')->assertOk();
    }

    public function test_exigencia_de_2fa_pode_ser_desligada(): void
    {
        config(['nexplangov.exigir_2fa' => false]);
        $admin = User::factory()->comPerfil('administrador-orgao')->create();

        $this->actingAs($admin)->get('/painel')->assertOk();
    }

    public function test_cabecalhos_de_seguranca(): void
    {
        $this->get('/login')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
