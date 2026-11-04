<?php

namespace Tests\Feature\Lei14133;

use App\Models\ItemPca;
use App\Models\PlanoContratacao;
use App\Models\VersaoPca;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Versões e revisão do PCA depois de aprovado (art. 12, VII e § 1º). */
class PcaVersoesTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    private PlanoContratacao $plano;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->plano = PlanoContratacao::create(['orgao_id' => $this->orgao->id, 'ano' => 2027, 'situacao' => 'rascunho']);
        foreach ([[1, 'Papel A4 (fictício)', 100, 30], [2, 'Caneta azul (fictícia)', 50, 2]] as [$n, $d, $q, $v]) {
            ItemPca::create(['orgao_id' => $this->orgao->id, 'plano_contratacao_id' => $this->plano->id, 'numero' => $n, 'material_servico' => 'M', 'categoria' => 2,
                'descricao' => $d, 'unidade_fornecimento' => 'UN', 'quantidade' => $q, 'valor_unitario_estimado' => $v, 'prioridade' => 2]);
        }
    }

    private function aprovar(): void
    {
        $this->actingAs($this->u['planejamento'])->post(route('pca.enviar', $this->plano))->assertSessionHas('sucesso');
        $this->actingAs($this->u['autoridade'])->post(route('pca.aprovar', $this->plano))->assertSessionHas('sucesso');
        $this->plano->refresh();
    }

    public function test_aprovacao_gera_versao_e_revisao_justificada_gera_a_seguinte(): void
    {
        $this->aprovar();
        $v1 = VersaoPca::where('plano_contratacao_id', $this->plano->id)->sole();
        $this->assertSame([1, 2, '3100.00'], [$v1->numero, $v1->qtd_itens, $v1->valor_total]);
        $this->assertSame(1, $this->plano->versao_atual);

        // Aprovado: alteração direta é recusada.
        $this->actingAs($this->u['planejamento'])->delete(route('pca.itens.destroy', [$this->plano, $this->plano->itens()->first()]))->assertSessionHas('erro');

        // Revisão exige justificativa com conteúdo.
        $this->actingAs($this->u['planejamento'])->post(route('pca.revisao.abrir', $this->plano), ['justificativa' => 'ajuste'])->assertSessionHas('erro');
        $this->actingAs($this->u['auditor'])->post(route('pca.revisao.abrir', $this->plano), ['justificativa' => 'Inclusão de demanda nova da Saúde'])->assertForbidden();
        $this->actingAs($this->u['planejamento'])->post(route('pca.revisao.abrir', $this->plano), ['justificativa' => 'Inclusão de demanda nova da Saúde'])->assertSessionHas('sucesso');
        $this->plano->refresh();
        $this->assertTrue($this->plano->emRevisao());
        $this->assertDatabaseHas('registros_auditoria', ['evento' => 'revisao', 'descricao' => 'Revisão do PCA 2027 aberta sobre a versão 1']);

        // Altera um, exclui outro, inclui um novo: o número 2 não é reaproveitado.
        $papel = $this->plano->itens()->where('numero', 1)->first();
        $dados = ['material_servico' => 'M', 'categoria' => 2, 'descricao' => 'Papel A4 (fictício)', 'unidade_fornecimento' => 'UN', 'quantidade' => '150', 'valor_unitario_estimado' => '30,00', 'prioridade' => 1];
        $this->actingAs($this->u['planejamento'])->put(route('pca.itens.update', [$this->plano, $papel]), $dados)->assertSessionHas('sucesso');
        $this->actingAs($this->u['planejamento'])->delete(route('pca.itens.destroy', [$this->plano, $this->plano->itens()->where('numero', 2)->first()]))->assertSessionHas('sucesso');
        $this->actingAs($this->u['planejamento'])->post(route('pca.itens.store', $this->plano), ['descricao' => 'Termômetro (fictício)'] + $dados)->assertSessionHas('sucesso');
        $this->actingAs($this->u['planejamento'])->post(route('pca.itens.store', $this->plano), ['descricao' => 'Luva (fictícia)'] + $dados)->assertSessionHas('sucesso');
        $this->assertSame([1, 3, 4], $this->plano->itens()->pluck('numero')->all());

        $this->actingAs($this->u['planejamento'])->get(route('pca.versoes', $this->plano))->assertOk()
            ->assertSee('Revisão em andamento')->assertSee('Termômetro (fictício)')->assertSee('Caneta azul (fictícia)')->assertSee('100 → 150');

        $this->aprovar();
        $v2 = VersaoPca::where('plano_contratacao_id', $this->plano->id)->where('numero', 2)->sole();
        $this->assertSame(['Inclusão de demanda nova da Saúde', 3, $this->u['planejamento']->id], [$v2->justificativa, $v2->qtd_itens, $v2->aberta_por]);
        $this->assertSame([2, false], [$this->plano->versao_atual, $this->plano->emRevisao()]);
        $this->assertSame([2, 1, 1, 2], [$v1->fresh()->qtd_itens, $v1->fresh()->numero, $v1->fresh()->itens[0]['numero'], count($v1->fresh()->itens)], 'versão anterior fica intacta');

        $this->actingAs($this->u['auditor'])->get(route('pca.versoes', ['plano' => $this->plano, 'de' => 1, 'para' => 2]))->assertOk()
            ->assertSee('Inclusão de demanda nova da Saúde')->assertSee('Luva (fictícia)');
    }

    public function test_descartar_revisao_volta_os_itens_da_versao_vigente(): void
    {
        $this->aprovar();
        $this->actingAs($this->u['planejamento'])->post(route('pca.revisao.abrir', $this->plano), ['justificativa' => 'Teste de revisão descartada']);
        $this->plano->itens()->where('numero', 1)->first()->update(['quantidade' => 999]);
        $this->plano->itens()->where('numero', 2)->first()->delete();
        ItemPca::create(['orgao_id' => $this->orgao->id, 'plano_contratacao_id' => $this->plano->id, 'numero' => 3, 'material_servico' => 'M', 'categoria' => 2,
            'descricao' => 'Item a descartar', 'unidade_fornecimento' => 'UN', 'quantidade' => 1, 'valor_unitario_estimado' => 1, 'prioridade' => 2]);

        $this->actingAs($this->u['planejamento'])->post(route('pca.revisao.descartar', $this->plano))->assertSessionHas('sucesso');
        $this->plano->refresh();

        $this->assertSame(['aprovado', 1, false], [$this->plano->situacao, $this->plano->versao_atual, $this->plano->emRevisao()]);
        $this->assertSame([1, 2], $this->plano->itens()->pluck('numero')->all());
        $this->assertSame('100.0000', $this->plano->itens()->where('numero', 1)->first()->quantidade);
        $this->assertSame(1, VersaoPca::count());
        $this->expectException(\LogicException::class);
        VersaoPca::first()->update(['justificativa' => 'adulterada']);
    }
}
