<?php

namespace Tests\Feature\Lei14133;

use App\Models\Cotacao;
use App\Models\ItemProcesso;
use App\Models\Processo;
use App\Models\RegistroAuditoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Correção de cotação da pesquisa de preços (art. 23, § 1º) sem excluir e lançar de novo. */
class EdicaoCotacaoTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    private Processo $p;

    private ItemProcesso $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->p = $this->processo();
        $this->itemComPreco($this->p, 10.0, 100);
        $this->item = $this->p->itens()->first();
    }

    private function dados(array $extra = []): array
    {
        return $extra + ['fonte' => 'painel_oficial', 'referencia' => 'Painel de Preços', 'valor_unitario' => '10,00', 'data_cotacao' => today()->subDays(5)->format('Y-m-d')];
    }

    public function test_corrige_valor_e_recalcula_preco_de_referencia(): void
    {
        $c = $this->item->cotacoes()->orderBy('id')->first();

        $this->actingAs($this->u['planejamento'])->put("/processos/{$this->p->id}/cotacoes/{$c->id}", $this->dados(['valor_unitario' => '11,00', 'referencia' => 'Painel de Preços (corrigido)']))
            ->assertRedirect(route('processos.show', [$this->p, 'aba' => 'itens']))
            ->assertSessionHas('sucesso');

        $c->refresh();
        $this->assertSame('11.0000', $c->valor_unitario);
        $this->assertSame('Painel de Preços (corrigido)', $c->referencia);
        $this->assertSame(3, Cotacao::count());
        // Mediana de 11,00; 10,50; 9,50 = 10,50.
        $this->assertEquals(10.5, (float) $this->item->refresh()->valor_unitario_estimado);
        $this->assertEquals(1050.0, (float) $this->item->valor_total_estimado);
        $this->assertTrue(RegistroAuditoria::where('auditable_type', $c->getMorphClass())->where('auditable_id', $c->id)->where('evento', 'alterado')->exists());
    }

    public function test_edicao_passa_pelas_mesmas_validacoes(): void
    {
        $c = $this->item->cotacoes()->first();

        $this->actingAs($this->u['planejamento'])->put("/processos/{$this->p->id}/cotacoes/{$c->id}", $this->dados(['fonte' => 'fornecedor', 'documento_fonte' => '', 'valor_unitario' => '0']))
            ->assertSessionHasErrors(['documento_fonte', 'valor_unitario']);

        $this->assertEquals(10.0, (float) $c->refresh()->valor_unitario);
    }

    public function test_tela_mostra_botao_e_formulario_preenchido(): void
    {
        $c = $this->item->cotacoes()->first();

        $this->actingAs($this->u['planejamento'])->get("/processos/{$this->p->id}?aba=itens")
            ->assertOk()->assertSee("cotacao={$c->id}", false)->assertSee('Editar cotação');

        $this->actingAs($this->u['planejamento'])->get("/processos/{$this->p->id}?aba=itens&cotacao={$c->id}")
            ->assertOk()->assertSee('Salvar alterações')->assertSee('value="10,00"', false);
    }

    public function test_fora_da_fase_preparatoria_nao_edita(): void
    {
        $c = $this->item->cotacoes()->first();
        $this->p->forceFill(['situacao' => 'analise_juridica'])->save();

        $this->actingAs($this->u['planejamento'])->put("/processos/{$this->p->id}/cotacoes/{$c->id}", $this->dados(['valor_unitario' => '99,00']))->assertStatus(422);
        $this->assertEquals(10.0, (float) $c->refresh()->valor_unitario);
    }

    public function test_cotacao_de_outro_processo_nao_edita(): void
    {
        $outro = $this->processo();
        $c = $this->item->cotacoes()->first();

        $this->actingAs($this->u['planejamento'])->put("/processos/{$outro->id}/cotacoes/{$c->id}", $this->dados(['valor_unitario' => '99,00']))->assertNotFound();
    }

    public function test_perfil_sem_permissao_nao_edita(): void
    {
        $c = $this->item->cotacoes()->first();

        $this->actingAs($this->u['auditor'])->put("/processos/{$this->p->id}/cotacoes/{$c->id}", $this->dados(['valor_unitario' => '99,00']))->assertForbidden();
    }
}
