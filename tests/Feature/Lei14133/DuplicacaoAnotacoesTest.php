<?php

namespace Tests\Feature\Lei14133;

use App\Models\AnotacaoProcesso;
use App\Models\DocumentoProcesso;
use App\Models\Processo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Duplicar processo (reaproveitar a instrução) e anotações internas que não compõem os autos. */
class DuplicacaoAnotacoesTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
    }

    public function test_duplicar_copia_itens_cotacoes_e_pecas_do_editor_como_rascunho(): void
    {
        $origem = $this->processo(['numero' => '10']);
        $this->itemComPreco($origem, 10.0, 50);
        $this->concluirDocumento($origem, 'dfd', $this->u['planejamento']);
        DocumentoProcesso::create(['orgao_id' => $this->orgao->id, 'processo_id' => $origem->id, 'tipo' => 'parecer_juridico', 'versao' => 1, 'conteudo' => ['conclusao' => 'Favorável.'],
            'situacao' => 'concluido', 'autor_id' => $this->u['juridico']->id, 'origem' => 'formulario']);
        $origem->forceFill(['situacao' => 'fracassado', 'numero_controle_pncp' => '99000000000001-1-000010/2026', 'valor_homologado' => 0])->save();

        $this->actingAs($this->u['planejamento'])->post("/processos/{$origem->id}/duplicar", ['numero' => '11', 'ano' => 2026])
            ->assertSessionHasNoErrors()->assertRedirect();

        $novo = Processo::where('numero', '11')->firstOrFail();
        $this->assertSame('planejamento', $novo->situacao);
        $this->assertSame($origem->id, $novo->origem_id);
        $this->assertSame($this->u['planejamento']->id, $novo->responsavel_id);
        $this->assertNull($novo->numero_controle_pncp);
        $this->assertNull($novo->valor_homologado);
        $this->assertSame($origem->objeto, $novo->objeto);
        $this->assertSame($origem->justificativa_fora_pca, $novo->justificativa_fora_pca);

        $this->assertCount(1, $novo->itens);
        $this->assertCount(3, $novo->itens->first()->cotacoes);
        $this->assertEqualsWithDelta((float) $origem->fresh()->valor_estimado, (float) $novo->valor_estimado, 0.01);

        $pecas = DocumentoProcesso::where('processo_id', $novo->id)->get();
        $this->assertSame(['dfd'], $pecas->pluck('tipo')->all(), 'o parecer jurídico é ato do processo de origem e não se copia');
        $this->assertSame('rascunho', $pecas->first()->situacao);
        $this->assertSame(1, (int) $pecas->first()->versao);

        $this->actingAs($this->u['planejamento'])->get("/processos/{$novo->id}")->assertOk()->assertSee('Duplicado de')->assertSee($origem->identificacao());
    }

    public function test_duplicar_para_outro_ano_exige_nova_previsao_no_pca_e_numero_unico(): void
    {
        $origem = $this->processo(['numero' => '20']);

        $this->actingAs($this->u['planejamento'])->post("/processos/{$origem->id}/duplicar", ['numero' => '20', 'ano' => 2026])
            ->assertSessionHasErrors('numero');

        $this->actingAs($this->u['planejamento'])->post("/processos/{$origem->id}/duplicar", ['numero' => '20', 'ano' => 2027])
            ->assertSessionHasNoErrors();
        $this->assertNull(Processo::where('ano', 2027)->firstOrFail()->item_pca_id);
    }

    public function test_so_quem_instrui_pode_duplicar(): void
    {
        $origem = $this->processo();

        $this->actingAs($this->u['auditor'])->post("/processos/{$origem->id}/duplicar", ['numero' => '999', 'ano' => 2026])->assertForbidden();
        $this->assertSame(1, Processo::count());
    }

    public function test_anotacao_interna_e_registrada_e_so_o_autor_altera(): void
    {
        $p = $this->processo();

        $this->actingAs($this->u['juridico'])->post("/processos/{$p->id}/anotacoes", ['texto' => 'Conferir a quantidade do item 1 com o requisitante.'])
            ->assertRedirect("/processos/{$p->id}?aba=anotacoes");
        $n = AnotacaoProcesso::firstOrFail();
        $this->assertSame($this->u['juridico']->id, $n->user_id);

        $this->actingAs($this->u['planejamento'])->get("/processos/{$p->id}?aba=anotacoes")->assertOk()->assertSee('Conferir a quantidade do item 1')->assertSee('Não compõe os autos');

        $this->actingAs($this->u['planejamento'])->put("/processos/{$p->id}/anotacoes/{$n->id}", ['texto' => 'Alterado por outra pessoa'])->assertForbidden();
        $this->actingAs($this->u['planejamento'])->delete("/processos/{$p->id}/anotacoes/{$n->id}")->assertForbidden();

        $this->actingAs($this->u['juridico'])->put("/processos/{$p->id}/anotacoes/{$n->id}", ['texto' => 'Quantidade conferida.'])->assertRedirect();
        $this->assertSame('Quantidade conferida.', $n->fresh()->texto);

        $this->actingAs($this->u['juridico'])->delete("/processos/{$p->id}/anotacoes/{$n->id}")->assertRedirect();
        $this->assertSame(0, AnotacaoProcesso::count());
    }

    public function test_quem_so_consulta_le_mas_nao_anota_e_anotacao_nao_vai_ao_dossie(): void
    {
        $p = $this->processo();
        AnotacaoProcesso::create(['orgao_id' => $this->orgao->id, 'processo_id' => $p->id, 'user_id' => $this->u['planejamento']->id, 'texto' => 'Rascunho interno sigiloso de trabalho']);

        $this->actingAs($this->u['auditor'])->post("/processos/{$p->id}/anotacoes", ['texto' => 'Tentativa'])->assertForbidden();
        $this->actingAs($this->u['auditor'])->get("/processos/{$p->id}?aba=anotacoes")->assertOk()->assertSee('Rascunho interno sigiloso')->assertDontSee('Anotar</button>', false);
    }

    public function test_anotacao_de_outro_processo_nao_se_altera_por_esta_rota(): void
    {
        $a = $this->processo();
        $b = $this->processo();
        $n = AnotacaoProcesso::create(['orgao_id' => $this->orgao->id, 'processo_id' => $a->id, 'user_id' => $this->u['juridico']->id, 'texto' => 'Nota do processo A']);

        $this->actingAs($this->u['juridico'])->delete("/processos/{$b->id}/anotacoes/{$n->id}")->assertNotFound();
        $this->assertSame(1, AnotacaoProcesso::count());
    }
}
