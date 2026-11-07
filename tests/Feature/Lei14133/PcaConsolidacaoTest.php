<?php

namespace Tests\Feature\Lei14133;

use App\Models\DotacaoOrcamentaria;
use App\Models\ItemPca;
use App\Models\Orgao;
use App\Models\PlanoContratacao;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Consolidação do PCA: agrupamento, compras recorrentes por dispensa e cronograma com orçamento (2.3 e 4.5). */
class PcaConsolidacaoTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    private PlanoContratacao $plano;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->plano = PlanoContratacao::create(['orgao_id' => $this->orgao->id, 'ano' => 2027, 'situacao' => 'rascunho']);
        $educacao = Unidade::factory()->for($this->orgao)->create(['sigla' => 'SEMED']);
        $this->item(1, $this->unidade->id, '150123', 'Papel sulfite A4 branco', 100, 25, '2027-03-01');
        $this->item(2, $educacao->id, '150123', 'Papel sulfite A4 para as escolas', 300, 25, '2027-05-01');
        $this->item(3, $educacao->id, null, 'Serviço de dedetização das escolas', 1, 9000, '2027-07-01', '3.3.90.39');
    }

    private function item(int $n, int $unidade, ?string $codigo, string $descricao, float $qtd, float $valor, string $data, string $natureza = '3.3.90.30'): void
    {
        ItemPca::create(['orgao_id' => $this->orgao->id, 'plano_contratacao_id' => $this->plano->id, 'unidade_id' => $unidade, 'numero' => $n, 'material_servico' => $codigo ? 'M' : 'S',
            'categoria' => 1, 'codigo_catalogo' => $codigo, 'descricao' => $descricao, 'unidade_fornecimento' => 'UN', 'quantidade' => $qtd, 'valor_unitario_estimado' => $valor,
            'data_desejada' => $data, 'natureza_despesa' => $natureza]);
    }

    public function test_mostra_agrupamento_cronograma_e_falta_de_dotacao(): void
    {
        $this->actingAs($this->u['planejamento'])->get("/pca/{$this->plano->id}")->assertOk()->assertSee('Consolidação e orçamento');

        $this->actingAs($this->u['planejamento'])->get("/pca/{$this->plano->id}/consolidacao")->assertOk()
            ->assertSee('Código 150123')->assertSee('pedidos por SEMAD, SEMED')
            ->assertSee('R$ 10.000,00')
            ->assertSee('sem dotação cadastrada', false);
    }

    public function test_dotacao_cadastrada_aponta_saldo_insuficiente(): void
    {
        $this->actingAs($this->u['planejamento'])->post("/pca/{$this->plano->id}/dotacoes", ['natureza_despesa' => '3.3.90.30', 'valor_autorizado' => '8.000,00', 'descricao' => 'Ficha 12 · Manutenção da administração'])
            ->assertRedirect("/pca/{$this->plano->id}/consolidacao");
        $this->actingAs($this->u['planejamento'])->post("/pca/{$this->plano->id}/dotacoes", ['natureza_despesa' => '3.3.90.30', 'valor_autorizado' => '9.000,00'])->assertRedirect();

        $d = DotacaoOrcamentaria::sole();
        $this->assertSame(['9000.00', 2027], [$d->valor_autorizado, $d->exercicio]);
        $this->actingAs($this->u['planejamento'])->get("/pca/{$this->plano->id}/consolidacao")->assertOk()->assertSee('Insuficiente')->assertSee('passa a dotação em R$ 1.000,00');

        $this->actingAs($this->u['requisitante'])->post("/pca/{$this->plano->id}/dotacoes", ['natureza_despesa' => '3.3.90.39', 'valor_autorizado' => '1'])->assertForbidden();
        $this->actingAs($this->u['planejamento'])->delete("/pca/{$this->plano->id}/dotacoes/{$d->id}")->assertRedirect();
        $this->assertSame(0, DotacaoOrcamentaria::count());
    }

    public function test_aponta_compras_recorrentes_por_dispensa(): void
    {
        foreach ([1, 2, 3] as $n) {
            $p = $this->processo(['modalidade' => 8, 'amparo_legal' => 19, 'natureza_despesa' => '3.3.90.30', 'objeto' => "Compra de material de limpeza, lote {$n}"], 'contratado');
            $p->forceFill(['valor_estimado' => 15000])->save();
        }
        $outro = Orgao::factory()->create();
        $this->actingAs($this->usuario('planejamento', $outro))->get("/pca/{$this->plano->id}/consolidacao")->assertNotFound();

        $this->actingAs($this->u['planejamento'])->get("/pca/{$this->plano->id}/consolidacao")->assertOk()->assertSee('Natureza 3.3.90.30')->assertSee('3 dispensas');
    }
}
