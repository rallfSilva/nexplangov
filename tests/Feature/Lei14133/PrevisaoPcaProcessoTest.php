<?php

namespace Tests\Feature\Lei14133;

use App\Models\Demanda;
use App\Models\ItemPca;
use App\Models\PlanoContratacao;
use App\Models\Processo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Abertura do processo cruzada com o PCA vigente (art. 12, VII, e art. 18, § 1º, II). */
class PrevisaoPcaProcessoTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
    }

    private function itemPca(int $ano = 2026, int $versao = 1): ItemPca
    {
        $plano = PlanoContratacao::create(['orgao_id' => $this->orgao->id, 'ano' => $ano, 'situacao' => $versao ? 'aprovado' : 'rascunho']);
        $plano->forceFill(['versao_atual' => $versao])->save();

        return ItemPca::create(['orgao_id' => $this->orgao->id, 'plano_contratacao_id' => $plano->id, 'unidade_id' => $this->unidade->id, 'numero' => 1, 'material_servico' => 'M', 'categoria' => 2,
            'descricao' => 'Material de expediente para as secretarias', 'unidade_fornecimento' => 'UN', 'quantidade' => 100, 'valor_unitario_estimado' => 10]);
    }

    private function dados(array $extra = []): array
    {
        return $extra + ['numero' => '77', 'ano' => 2026, 'objeto' => 'Aquisição de material de expediente para as secretarias', 'unidade_id' => $this->unidade->id,
            'modalidade' => 6, 'amparo_legal' => 1, 'criterio_julgamento' => 1, 'modo_disputa' => 1, 'categoria' => 2];
    }

    public function test_processo_fora_do_pca_sem_justificativa_nao_abre(): void
    {
        $this->actingAs($this->u['planejamento'])->post('/processos', $this->dados())
            ->assertSessionHasErrors('justificativa_fora_pca');

        $this->assertSame(0, Processo::count());
    }

    public function test_processo_fora_do_pca_abre_com_justificativa_e_ela_aparece_nos_autos(): void
    {
        $this->actingAs($this->u['planejamento'])->post('/processos', $this->dados(['justificativa_fora_pca' => 'Demanda nova, surgida depois da aprovação do plano anual.']))
            ->assertSessionHasNoErrors();

        $p = Processo::firstOrFail();
        $this->assertNull($p->item_pca_id);
        $this->actingAs($this->u['planejamento'])->get("/processos/{$p->id}")->assertOk()->assertSee('fora do plano, justificado')->assertSee('Demanda nova, surgida');
    }

    public function test_item_de_plano_aprovado_libera_e_descarta_a_justificativa(): void
    {
        $item = $this->itemPca();

        $this->actingAs($this->u['planejamento'])->post('/processos', $this->dados(['item_pca_id' => $item->id, 'justificativa_fora_pca' => 'texto que não deve ficar gravado no processo']))
            ->assertSessionHasNoErrors();

        $p = Processo::firstOrFail();
        $this->assertSame($item->id, $p->item_pca_id);
        $this->assertNull($p->justificativa_fora_pca);
    }

    public function test_item_de_plano_nao_aprovado_ou_de_outro_ano_e_recusado(): void
    {
        $this->actingAs($this->u['planejamento'])->post('/processos', $this->dados(['item_pca_id' => $this->itemPca(2026, 0)->id]))
            ->assertSessionHasErrors('item_pca_id');
        $this->actingAs($this->u['planejamento'])->post('/processos', $this->dados(['item_pca_id' => $this->itemPca(2027)->id]))
            ->assertSessionHasErrors('item_pca_id');
    }

    public function test_formulario_sugere_item_parecido_do_plano(): void
    {
        $this->itemPca();
        $demanda = Demanda::create(['orgao_id' => $this->orgao->id, 'unidade_id' => $this->unidade->id, 'numero' => '0001', 'ano' => 2026,
            'objeto' => 'Material de expediente para a secretaria de administração', 'justificativa' => str_repeat('Necessidade real da unidade. ', 2), 'situacao' => 'aceita']);

        $this->actingAs($this->u['planejamento'])->get("/processos/novo?demanda={$demanda->id}")->assertOk()->assertSee('(sugerido)');
    }

    public function test_processo_antigo_sem_previsao_nao_vai_ao_juridico(): void
    {
        $p = $this->processo(['justificativa_fora_pca' => null]);

        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/tramitar", ['destino' => 'analise_juridica'])
            ->assertSessionHas('motivos', fn ($m) => collect($m)->contains(fn ($x) => str_contains($x, 'Previsão no PCA')));
    }
}
