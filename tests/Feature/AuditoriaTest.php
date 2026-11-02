<?php

namespace Tests\Feature;

use App\Models\Orgao;
use App\Models\RegistroAuditoria;
use App\Models\User;
use Database\Seeders\PerfisSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/** A trilha de auditoria é somente inclusão: nem o sistema nem o banco deixam alterar ou apagar. */
class AuditoriaTest extends TestCase
{
    use RefreshDatabase;

    private function registro(): RegistroAuditoria
    {
        return RegistroAuditoria::create(['evento' => 'criado', 'descricao' => 'Teste']);
    }

    public function test_modelo_nao_deixa_alterar(): void
    {
        $this->expectException(LogicException::class);
        $this->registro()->update(['descricao' => 'Adulterado']);
    }

    public function test_modelo_nao_deixa_apagar(): void
    {
        $this->expectException(LogicException::class);
        $this->registro()->delete();
    }

    public function test_banco_bloqueia_update_direto(): void
    {
        $registro = $this->registro();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('A trilha de auditoria não pode ser alterada nem apagada');
        DB::table('registros_auditoria')->where('id', $registro->id)->update(['descricao' => 'Adulterado']);
    }

    public function test_banco_bloqueia_delete_direto(): void
    {
        $this->registro();

        $this->expectException(QueryException::class);
        DB::table('registros_auditoria')->delete();
    }

    public function test_campos_sensiveis_nunca_vao_para_a_auditoria(): void
    {
        $this->seed(PerfisSeeder::class);
        $user = User::factory()->create(['password' => 'SegredoMuitoForte1']);
        $user->update(['password' => 'OutroSegredoForte2', 'name' => 'Nome Trocado']);

        $todos = RegistroAuditoria::withoutGlobalScopes()->get()->toJson();

        $this->assertStringNotContainsString('password', $todos);
        $this->assertStringNotContainsString('remember_token', $todos);
        $this->assertStringContainsString('Nome Trocado', $todos);
    }

    public function test_filtro_por_evento_e_periodo(): void
    {
        $this->seed(PerfisSeeder::class);
        $orgao = Orgao::factory()->create();
        RegistroAuditoria::create(['orgao_id' => $orgao->id, 'evento' => 'login_falhou', 'descricao' => 'Tentativa suspeita']);
        RegistroAuditoria::create(['orgao_id' => $orgao->id, 'evento' => 'criado', 'descricao' => 'Cadastro comum']);

        $this->actingAs($this->usuario('auditor', $orgao))
            ->get('/auditoria?evento=login_falhou&de='.now()->toDateString())
            ->assertOk()
            ->assertSee('Tentativa suspeita')
            ->assertDontSee('Cadastro comum');
    }
}
