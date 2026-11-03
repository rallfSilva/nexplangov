<?php

namespace Tests\Feature\Lei14133;

use App\Models\Cotacao;
use App\Models\ItemProcesso;
use App\Models\Processo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Correção e exclusão do item do processo na fase preparatória, mantendo a pesquisa de preços (art. 23). */
class EdicaoItemTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    private Processo $p;

    private ItemProcesso $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->p = $this->processo();
        $this->itemComPreco($this->p, 10.0, 30);
        $this->item = $this->p->itens()->first();
    }

    private function dados(array $extra = []): array
    {
        return $extra + ['descricao' => 'Caneta esferográfica azul', 'material_servico' => 'M', 'unidade_medida' => 'UN', 'quantidade' => '30', 'tipo_beneficio' => 4];
    }

    public function test_diminui_quantidade_mantem_cotacoes_e_recalcula_total(): void
    {
        $this->assertEquals(300.0, (float) $this->item->valor_total_estimado);

        $this->actingAs($this->u['planejamento'])->put("/processos/{$this->p->id}/itens/{$this->item->id}", $this->dados(['quantidade' => '20', 'descricao' => 'Caneta esferográfica azul, ponta média']))
            ->assertRedirect()->assertSessionHas('sucesso');

        $this->item->refresh();
        $this->assertEquals(20.0, (float) $this->item->quantidade);
        $this->assertSame('Caneta esferográfica azul, ponta média', $this->item->descricao);
        $this->assertSame(3, $this->item->cotacoes()->count());
        $this->assertEquals(200.0, (float) $this->item->valor_total_estimado);
        $this->assertEquals(200.0, (float) $this->p->refresh()->valor_estimado);
    }

    public function test_edicao_valida_os_campos(): void
    {
        $this->actingAs($this->u['planejamento'])->put("/processos/{$this->p->id}/itens/{$this->item->id}", $this->dados(['quantidade' => '0', 'descricao' => '']))
            ->assertSessionHasErrors(['quantidade', 'descricao']);

        $this->assertEquals(30.0, (float) $this->item->refresh()->quantidade);
    }

    public function test_tela_mostra_botoes_e_formulario_preenchido(): void
    {
        $this->actingAs($this->u['planejamento'])->get("/processos/{$this->p->id}?aba=itens")
            ->assertOk()->assertSee('Editar item')->assertSee('Excluir item');

        $this->actingAs($this->u['planejamento'])->get("/processos/{$this->p->id}?aba=itens&item={$this->item->id}")
            ->assertOk()->assertSee('Editar item '.$this->item->numero)->assertSee('value="30"', false);
    }

    public function test_quantidade_grande_volta_sem_separador_de_milhar(): void
    {
        $this->item->update(['quantidade' => 3200]);

        $this->actingAs($this->u['planejamento'])->get("/processos/{$this->p->id}?aba=itens&item={$this->item->id}")
            ->assertSee('value="3200"', false);

        // Reenviar o formulário sem mudar nada mantém a quantidade.
        $this->actingAs($this->u['planejamento'])->put("/processos/{$this->p->id}/itens/{$this->item->id}", $this->dados(['quantidade' => '3200']))->assertRedirect();
        $this->assertEquals(3200.0, (float) $this->item->refresh()->quantidade);
    }

    public function test_exclui_item_com_cotacoes_e_recalcula_processo(): void
    {
        $this->actingAs($this->u['planejamento'])->delete("/processos/{$this->p->id}/itens/{$this->item->id}")->assertRedirect();

        $this->assertSame(0, $this->p->itens()->count());
        $this->assertSame(0, Cotacao::count());
        $this->assertEquals(0.0, (float) $this->p->refresh()->valor_estimado);
    }

    public function test_fora_da_fase_preparatoria_nao_edita_nem_exclui(): void
    {
        $this->p->forceFill(['situacao' => 'analise_juridica'])->save();

        $this->actingAs($this->u['planejamento'])->put("/processos/{$this->p->id}/itens/{$this->item->id}", $this->dados(['quantidade' => '5']))->assertStatus(422);
        $this->actingAs($this->u['planejamento'])->delete("/processos/{$this->p->id}/itens/{$this->item->id}")->assertStatus(422);
        $this->assertEquals(30.0, (float) $this->item->refresh()->quantidade);
    }

    public function test_item_de_outro_processo_e_perfil_sem_permissao(): void
    {
        $outro = $this->processo();

        $this->actingAs($this->u['planejamento'])->put("/processos/{$outro->id}/itens/{$this->item->id}", $this->dados())->assertNotFound();
        $this->actingAs($this->u['auditor'])->put("/processos/{$this->p->id}/itens/{$this->item->id}", $this->dados(['quantidade' => '5']))->assertForbidden();
    }
}
