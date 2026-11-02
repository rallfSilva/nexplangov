<?php

namespace Tests\Feature;

use App\Models\Orgao;
use App\Models\RegistroAuditoria;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Um órgão nunca enxerga nem altera dados de outro. */
class IsolamentoOrgaoTest extends TestCase
{
    use RefreshDatabase;

    private Orgao $selc;

    private Orgao $outro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->semearPerfis();
        $this->selc = Orgao::factory()->create(['sigla' => 'SELC']);
        $this->outro = Orgao::factory()->create(['sigla' => 'OUTRO']);
    }

    public function test_lista_de_unidades_mostra_so_as_do_proprio_orgao(): void
    {
        Unidade::factory()->for($this->selc)->create(['sigla' => 'DTI', 'nome' => 'Tecnologia SELC']);
        Unidade::factory()->for($this->outro)->create(['sigla' => 'DAF', 'nome' => 'Financeiro do outro']);

        $this->actingAs($this->usuario('planejamento', $this->selc))
            ->get('/unidades')
            ->assertOk()
            ->assertSee('Tecnologia SELC')
            ->assertDontSee('Financeiro do outro');
    }

    public function test_unidade_de_outro_orgao_responde_404(): void
    {
        $alheia = Unidade::factory()->for($this->outro)->create();
        $admin = $this->usuario('administrador-orgao', $this->selc);

        $this->actingAs($admin)->get("/unidades/{$alheia->id}/edit")->assertNotFound();
        $this->actingAs($admin)->put("/unidades/{$alheia->id}", ['sigla' => 'X', 'nome' => 'X', 'tipo' => 'departamento'])->assertNotFound();
        $this->actingAs($admin)->delete("/unidades/{$alheia->id}")->assertNotFound();

        $this->assertModelExists($alheia);
    }

    public function test_usuario_de_outro_orgao_responde_404(): void
    {
        $alheio = $this->usuario('planejamento', $this->outro);
        $admin = $this->usuario('administrador-orgao', $this->selc);

        $this->actingAs($admin)->get("/usuarios/{$alheio->id}/edit")->assertNotFound();
        $this->actingAs($admin)->patch("/usuarios/{$alheio->id}/ativo")->assertNotFound();
        $this->assertTrue($alheio->fresh()->ativo);
    }

    public function test_lista_de_usuarios_nao_mostra_outro_orgao(): void
    {
        $this->usuario('planejamento', $this->outro, ['name' => 'Pessoa do Outro Órgão']);

        $this->actingAs($this->usuario('administrador-orgao', $this->selc))
            ->get('/usuarios')
            ->assertOk()
            ->assertDontSee('Pessoa do Outro Órgão');
    }

    public function test_nao_aceita_unidade_superior_nem_chefe_de_outro_orgao(): void
    {
        $paiAlheio = Unidade::factory()->for($this->outro)->create();
        $chefeAlheio = $this->usuario('autoridade', $this->outro);

        $this->actingAs($this->usuario('administrador-orgao', $this->selc))
            ->post('/unidades', [
                'sigla' => 'NOVA', 'nome' => 'Nova', 'tipo' => 'departamento',
                'parent_id' => $paiAlheio->id, 'chefe_user_id' => $chefeAlheio->id,
            ])
            ->assertSessionHasErrors(['parent_id', 'chefe_user_id']);
    }

    public function test_unidade_criada_vai_para_o_orgao_de_quem_criou_mesmo_forjando_o_campo(): void
    {
        $this->actingAs($this->usuario('administrador-orgao', $this->selc))
            ->post('/unidades', ['orgao_id' => $this->outro->id, 'sigla' => 'nova', 'nome' => 'Nova', 'tipo' => 'departamento', 'ativo' => '1'])
            ->assertRedirect();

        $this->assertDatabaseHas('unidades', ['sigla' => 'NOVA', 'orgao_id' => $this->selc->id]);
    }

    public function test_auditoria_mostra_so_o_proprio_orgao(): void
    {
        RegistroAuditoria::create(['orgao_id' => $this->outro->id, 'evento' => 'criado', 'descricao' => 'Evento secreto do outro órgão']);
        RegistroAuditoria::create(['orgao_id' => $this->selc->id, 'evento' => 'criado', 'descricao' => 'Evento da SELC']);

        $this->actingAs($this->usuario('auditor', $this->selc))
            ->get('/auditoria')
            ->assertOk()
            ->assertSee('Evento da SELC')
            ->assertDontSee('Evento secreto do outro órgão');
    }

    public function test_administrador_geral_ve_todos_os_orgaos(): void
    {
        Unidade::factory()->for($this->outro)->create(['nome' => 'Financeiro do outro']);

        $this->actingAs($this->usuario('administrador-geral'))
            ->get('/unidades?orgao='.$this->outro->id)
            ->assertOk()
            ->assertSee('Financeiro do outro');
    }
}
