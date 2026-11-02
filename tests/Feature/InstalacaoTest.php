<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PerfisSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InstalacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_perfis_podem_ser_semeados_varias_vezes(): void
    {
        $this->seed(PerfisSeeder::class);
        $this->seed(PerfisSeeder::class);

        $this->assertSame(count(PerfisSeeder::PERFIS), Role::count());
        $this->assertTrue(Role::findByName('auditor')->hasPermissionTo('auditoria.ver'));
        $this->assertFalse(Role::findByName('auditor')->hasPermissionTo('usuarios.gerenciar'));
    }

    public function test_comando_de_instalacao_cria_orgao_e_administrador_geral(): void
    {
        $this->artisan('nexplangov:instalar', [
            '--orgao-nome' => 'Secretaria de Teste',
            '--orgao-sigla' => 'sete',
            '--nome' => 'Admin Teste',
            '--email' => 'Admin@Teste.gov.br',
            '--senha' => 'SenhaForte123',
        ])->assertSuccessful();

        $admin = User::where('email', 'admin@teste.gov.br')->firstOrFail();
        $this->assertNull($admin->orgao_id);
        $this->assertTrue($admin->ehAdministradorGeral());
        $this->assertDatabaseHas('orgaos', ['sigla' => 'SETE']);
    }

    public function test_comando_recusa_senha_fraca(): void
    {
        $this->artisan('nexplangov:instalar', [
            '--orgao-nome' => 'X', '--orgao-sigla' => 'X', '--nome' => 'X', '--email' => 'x@x.gov.br', '--senha' => '123',
        ])->assertFailed();
    }

    public function test_dados_de_demonstracao_sao_ficticios_e_consistentes(): void
    {
        $this->seed(PerfisSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertSame(11, User::count());
        $this->assertTrue(User::where('email', 'admin@nexplangov.local')->first()->ehAdministradorGeral());
        $this->assertSame(0, User::whereNotNull('cpf')->count());
    }
}
