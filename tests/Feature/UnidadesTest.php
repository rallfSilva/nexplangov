<?php

namespace Tests\Feature;

use App\Models\Orgao;
use App\Models\RegistroAuditoria;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnidadesTest extends TestCase
{
    use RefreshDatabase;

    private Orgao $orgao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->semearPerfis();
        $this->orgao = Orgao::factory()->create();
    }

    public function test_cria_unidade_subordinada_e_mostra_em_arvore(): void
    {
        $admin = $this->usuario('administrador-orgao', $this->orgao);
        $gab = Unidade::factory()->for($this->orgao)->create(['sigla' => 'GAB', 'nome' => 'Gabinete']);

        $this->actingAs($admin)->post('/unidades', [
            'sigla' => 'dti', 'nome' => 'Tecnologia', 'tipo' => 'departamento', 'parent_id' => $gab->id,
            'codigo_unidade_pncp' => '2', 'ativo' => '1',
        ])->assertRedirect();

        $dti = Unidade::where('sigla', 'DTI')->firstOrFail();
        $this->assertSame($gab->id, $dti->parent_id);
        $this->assertSame('2', $dti->codigo_unidade_pncp);

        $this->actingAs($admin)->get('/unidades')->assertSeeInOrder(['GAB', 'DTI']);
    }

    public function test_sigla_repetida_no_mesmo_orgao_e_recusada_mas_aceita_em_outro(): void
    {
        Unidade::factory()->for($this->orgao)->create(['sigla' => 'DTI']);
        $outro = Orgao::factory()->create();

        $this->actingAs($this->usuario('administrador-orgao', $this->orgao))
            ->post('/unidades', ['sigla' => 'DTI', 'nome' => 'Duplicada', 'tipo' => 'departamento'])
            ->assertSessionHasErrors('sigla');

        $this->actingAs($this->usuario('administrador-orgao', $outro))
            ->post('/unidades', ['sigla' => 'DTI', 'nome' => 'Outra DTI', 'tipo' => 'departamento'])
            ->assertSessionHasNoErrors();
    }

    public function test_unidade_nao_pode_ser_subordinada_a_si_mesma_nem_a_uma_subordinada(): void
    {
        $admin = $this->usuario('administrador-orgao', $this->orgao);
        $gab = Unidade::factory()->for($this->orgao)->create(['sigla' => 'GAB']);
        $coam = Unidade::factory()->for($this->orgao)->create(['sigla' => 'COAM', 'parent_id' => $gab->id]);
        $neta = Unidade::factory()->for($this->orgao)->create(['sigla' => 'DIV', 'parent_id' => $coam->id]);

        foreach ([$gab->id, $neta->id] as $pai) {
            $this->actingAs($admin)
                ->put("/unidades/{$gab->id}", ['sigla' => 'GAB', 'nome' => 'Gabinete', 'tipo' => 'gabinete', 'parent_id' => $pai])
                ->assertSessionHasErrors('parent_id');
        }
    }

    public function test_nao_exclui_unidade_com_subordinadas(): void
    {
        $admin = $this->usuario('administrador-orgao', $this->orgao);
        $gab = Unidade::factory()->for($this->orgao)->create();
        Unidade::factory()->for($this->orgao)->create(['parent_id' => $gab->id]);

        $this->actingAs($admin)->delete("/unidades/{$gab->id}")->assertSessionHas('erro');
        $this->assertModelExists($gab);
    }

    public function test_alteracao_guarda_antes_e_depois_na_auditoria(): void
    {
        $admin = $this->usuario('administrador-orgao', $this->orgao);
        $dti = Unidade::factory()->for($this->orgao)->create(['sigla' => 'DTI', 'nome' => 'Nome antigo']);

        $this->actingAs($admin)->put("/unidades/{$dti->id}", ['sigla' => 'DTI', 'nome' => 'Nome novo', 'tipo' => 'departamento', 'ativo' => '1']);

        $registro = RegistroAuditoria::where('evento', 'alterado')->where('auditable_id', $dti->id)->latest('id')->firstOrFail();
        $this->assertSame('Nome antigo', $registro->valores_antigos['nome']);
        $this->assertSame('Nome novo', $registro->valores_novos['nome']);
        $this->assertSame($admin->id, $registro->user_id);
        $this->assertSame($this->orgao->id, $registro->orgao_id);
    }
}
