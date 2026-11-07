<?php

namespace Tests\Feature\Lei14133;

use App\Models\ConcessaoPermissao;
use App\Models\Orgao;
use App\Models\ParametroOrgao;
use App\Support\Parametros;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Parametrização do órgão e permissões extras pela tela (11.2, 11.4 e 11.5), com limites e segregação de funções. */
class ParametrosPermissoesTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->u['admin'] = $this->usuario('administrador-orgao', $this->orgao);
    }

    public function test_ajusta_parametro_dentro_do_limite_e_isola_por_orgao(): void
    {
        $admin = $this->u['admin'];
        $this->actingAs($admin)->get('/parametros')->assertOk()->assertSee('Prazo para pagar depois da liquidação')->assertSee('art. 7º, § 1º');
        $this->actingAs($this->u['planejamento'])->get('/parametros')->assertForbidden();

        $this->actingAs($admin)->put('/parametros/pagamento_prazo_dias', ['valor' => '45', 'justificativa' => 'Prazo maior que a lei admite para teste.'])
            ->assertSessionHasErrors('valor_pagamento_prazo_dias');
        $this->actingAs($admin)->put('/parametros/pagamento_prazo_dias', ['valor' => '10', 'justificativa' => 'curta'])->assertSessionHasErrors('justificativa');
        $this->actingAs($admin)->put('/parametros/pagamento_prazo_dias', ['valor' => '10', 'justificativa' => 'Decreto municipal fixou dez dias para pagar.'])
            ->assertRedirect('/parametros')->assertSessionHasNoErrors();
        $this->actingAs($admin)->put('/parametros/alertas_vigencia', ['valor' => '60, 120', 'justificativa' => 'Renovações exigem mais antecedência aqui.'])->assertSessionHasNoErrors();

        $outro = Orgao::factory()->create(['cnpj' => '99.111.222/0001-33']);
        $this->assertSame(10, Parametros::valor('pagamento_prazo_dias', $this->orgao->id));
        $this->assertSame([120, 60], Parametros::valor('alertas_vigencia', $this->orgao->id));
        $this->assertSame(config('nexplangov.pagamento_prazo_dias'), Parametros::valor('pagamento_prazo_dias', $outro->id));
        $this->assertSame('Decreto municipal fixou dez dias para pagar.', ParametroOrgao::where('chave', 'pagamento_prazo_dias')->value('justificativa'));

        $this->actingAs($admin)->delete('/parametros/pagamento_prazo_dias')->assertRedirect('/parametros');
        $this->assertSame(config('nexplangov.pagamento_prazo_dias'), Parametros::valor('pagamento_prazo_dias', $this->orgao->id));
        $this->actingAs($admin)->put('/parametros/inexistente', ['valor' => '1', 'justificativa' => 'Parâmetro que não existe no sistema.'])->assertNotFound();
    }

    public function test_concede_e_revoga_sem_acumular_funcoes(): void
    {
        $admin = $this->u['admin'];
        $req = $this->u['requisitante'];
        $just = 'Cobertura das férias do gestor de contratos em dezembro.';

        $this->actingAs($admin)->post('/parametros/permissoes', ['user_id' => $this->u['juridico']->id, 'permissao' => 'processos.gerenciar', 'justificativa' => $just])
            ->assertSessionHasErrors('permissao');
        $this->actingAs($admin)->post('/parametros/permissoes', ['user_id' => $req->id, 'permissao' => 'processos.parecer', 'justificativa' => $just])
            ->assertSessionHasErrors('permissao');
        $this->actingAs($admin)->post('/parametros/permissoes', ['user_id' => $admin->id, 'permissao' => 'contratos.ver', 'justificativa' => $just])->assertForbidden();
        $this->assertFalse($this->u['juridico']->fresh()->can('processos.gerenciar'));

        $this->actingAs($admin)->post('/parametros/permissoes', ['user_id' => $req->id, 'permissao' => 'contratos.ver', 'justificativa' => $just])
            ->assertRedirect('/parametros')->assertSessionHasNoErrors();
        $this->assertTrue($req->fresh()->can('contratos.ver'));
        $c = ConcessaoPermissao::firstOrFail();
        $this->assertSame($admin->id, $c->concedida_por);
        $this->actingAs($admin)->get('/parametros')->assertSee($just);

        $this->actingAs($admin)->post("/parametros/permissoes/{$c->id}/revogar", ['motivo' => 'Titular voltou das férias.'])->assertRedirect('/parametros');
        $this->assertFalse($req->fresh()->can('contratos.ver'));
        $this->assertNotNull($c->fresh()->revogada_em);
    }
}
