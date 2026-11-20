<?php

namespace Tests\Feature\Lei14133;

use App\Models\Demanda;
use App\Models\EnvioPncp;
use App\Models\ItemDemanda;
use App\Models\ItemPca;
use App\Models\PcaUnidade;
use App\Models\PlanoContratacao;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * DFDs das unidades com itens do catálogo, consolidados no PCA (art. 12, VII), e o PCA publicado no PNCP
 * por unidade compradora, num clique (art. 12, § 1º, e art. 174).
 */
class PcaDfdUnidadesTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    private Unidade $semed;

    private Unidade $escola;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        config(['nexplangov.pncp.login' => 'plataforma', 'nexplangov.pncp.senha' => 'segredo-de-teste-123']);
        $this->semed = Unidade::factory()->for($this->orgao)->create(['sigla' => 'SEMED', 'codigo_unidade_pncp' => '2']);
        $this->escola = Unidade::factory()->for($this->orgao)->create(['sigla' => 'EMVV', 'parent_id' => $this->semed->id, 'codigo_unidade_pncp' => null]);
    }

    private function dfd(Unidade $unidade, array $itens, string $situacao = 'aceita'): Demanda
    {
        $d = Demanda::create(['orgao_id' => $this->orgao->id, 'unidade_id' => $unidade->id, 'solicitante_id' => $this->u['requisitante']->id, 'numero' => (string) random_int(1, 99999), 'ano' => 2026,
            'exercicio' => 2027, 'objeto' => 'Material de expediente', 'justificativa' => 'Reposição anual do material de expediente da unidade, pelo consumo de 2026.',
            'data_pretendida' => '2027-04-01', 'prioridade' => 2, 'situacao' => $situacao]);
        foreach ($itens as $n => [$codigo, $qtd, $valor]) {
            ItemDemanda::create(['orgao_id' => $this->orgao->id, 'demanda_id' => $d->id, 'numero' => $n + 1, 'material_servico' => 'M', 'categoria' => 2, 'codigo_catalogo' => $codigo,
                'descricao' => 'Item '.$codigo, 'unidade_fornecimento' => 'UN', 'quantidade' => $qtd, 'valor_unitario_estimado' => $valor]);
        }

        return $d;
    }

    public function test_consolida_os_dfds_aprovados_por_unidade(): void
    {
        $plano = PlanoContratacao::create(['orgao_id' => $this->orgao->id, 'ano' => 2027, 'situacao' => 'rascunho']);
        $a = $this->dfd($this->semed, [['150000', 100, 20], ['150001', 10, 5]]);
        $b = $this->dfd($this->semed, [['150000', 300, 24]]);
        $c = $this->dfd($this->unidade, [['150000', 50, 20]]);
        $enviado = $this->dfd($this->unidade, [['150002', 1, 1]], 'enviada');

        $this->actingAs($this->u['planejamento'])->get(route('pca.consolidacao', $plano))->assertOk()->assertSee('Consolidar 3 DFDs aprovados')->assertSee('0 de 2');
        $this->actingAs($this->u['requisitante'])->post(route('pca.dfds.consolidar', $plano))->assertForbidden();
        $this->actingAs($this->u['planejamento'])->post(route('pca.dfds.consolidar', $plano))->assertSessionHas('sucesso');

        $itens = $plano->itens()->get();
        $this->assertCount(3, $itens, 'papel da SEMED, caneta da SEMED e papel da SEMAD');
        $papelSemed = $itens->first(fn ($i) => $i->unidade_id === $this->semed->id && $i->codigo_catalogo === '150000');
        $this->assertEquals(400, (float) $papelSemed->quantidade);
        $this->assertEquals(23, (float) $papelSemed->valor_unitario_estimado);
        $this->assertSame('consolidada', $a->fresh()->situacao);
        $this->assertNotNull($a->fresh()->consolidada_em);
        $this->assertSame($papelSemed->id, $b->fresh()->item_pca_id, 'DFD de um item só aponta a linha do plano');
        $this->assertSame($papelSemed->id, $b->itens->first()->fresh()->item_pca_id);
        $this->assertSame('enviada', $enviado->fresh()->situacao, 'o DFD em análise fica de fora');

        // Um novo DFD aprovado soma na linha que já existe.
        $this->dfd($this->unidade, [['150000', 50, 30]]);
        $this->actingAs($this->u['planejamento'])->post(route('pca.dfds.consolidar', $plano))->assertSessionHas('sucesso');
        $this->assertSame(3, $plano->itens()->count());
        $this->assertEquals(100, (float) $plano->itens()->where('unidade_id', $this->unidade->id)->value('quantidade'));
        $this->actingAs($this->u['planejamento'])->get(route('pca.consolidacao', $plano))->assertSee('2 de 2');

        // Plano aprovado: só por revisão.
        $plano->forceFill(['situacao' => 'aprovado'])->save();
        $this->dfd($this->semed, [['150003', 1, 1]]);
        $this->actingAs($this->u['planejamento'])->post(route('pca.dfds.consolidar', $plano))->assertSessionHas('erro');
        $this->assertSame(3, $plano->itens()->count());
        $this->assertSame('consolidada', $c->fresh()->situacao);
    }

    private function item(PlanoContratacao $plano, int $numero, ?Unidade $unidade): ItemPca
    {
        return ItemPca::create(['orgao_id' => $this->orgao->id, 'plano_contratacao_id' => $plano->id, 'unidade_id' => $unidade?->id, 'numero' => $numero, 'material_servico' => 'M', 'categoria' => 2,
            'descricao' => 'Papel A4 '.$numero, 'unidade_fornecimento' => 'RESMA', 'quantidade' => 10, 'valor_unitario_estimado' => 25, 'data_desejada' => '2027-03-01', 'prioridade' => 2]);
    }

    public function test_publica_um_pca_por_unidade_num_clique(): void
    {
        $seq = 0;
        Http::fake([
            '*/v1/usuarios/login' => Http::response(null, 200, ['Authorization' => 'Bearer t']),
            '*/v1/orgaos/11222333000181/pca' => function () use (&$seq) {
                $seq++;

                return Http::response([], 201, ['location' => "https://treina.pncp.gov.br/api/pncp/v1/orgaos/11222333000181/pca/2027/{$seq}"]);
            },
            '*' => Http::response([], 200),
        ]);
        $plano = PlanoContratacao::create(['orgao_id' => $this->orgao->id, 'ano' => 2027, 'situacao' => 'aprovado']);
        $this->item($plano, 1, $this->unidade);
        $this->item($plano, 2, $this->escola);   // publica pela SEMED, a superior com código
        $this->item($plano, 3, $this->semed);
        $this->item($plano, 4, null);            // sem unidade: vai para a unidade padrão do órgão

        $this->actingAs($this->u['agente-contratacao'])->post(route('pncp.pca', $plano))->assertSessionHas('sucesso');

        $this->assertSame(2, EnvioPncp::count());
        $partes = PcaUnidade::with('unidade')->get()->keyBy(fn ($p) => $p->unidade->sigla);
        $this->assertSame(['SEMAD', 'SEMED'], $partes->keys()->sort()->values()->all());
        $this->assertNotNull($partes['SEMAD']->numero_controle_pncp);
        $this->assertNotNull($partes['SEMED']->numero_controle_pncp);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/pca') && $r['codigoUnidade'] === '2' && array_column($r['itensPlano'], 'numeroItem') === [2, 3]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/pca') && $r['codigoUnidade'] === '1' && array_column($r['itensPlano'], 'numeroItem') === [1, 4]);

        $this->actingAs($this->u['agente-contratacao'])->get(route('pca.show', $plano))->assertOk()->assertSee('Publicação no PNCP por unidade')->assertSee('Atualizar no PNCP');

        // Segundo clique: retificação de cada unidade já publicada.
        $this->actingAs($this->u['agente-contratacao'])->post(route('pncp.pca', $plano))->assertSessionHas('sucesso');
        $this->assertSame(2, EnvioPncp::where('operacao', 'retificar')->count());
    }

    public function test_sem_unidade_com_codigo_nao_publica(): void
    {
        Unidade::query()->update(['codigo_unidade_pncp' => null]);
        $plano = PlanoContratacao::create(['orgao_id' => $this->orgao->id, 'ano' => 2027, 'situacao' => 'aprovado']);
        $this->item($plano, 1, $this->semed);

        $this->actingAs($this->u['agente-contratacao'])->post(route('pncp.pca', $plano))->assertSessionHas('erro');
        $this->assertSame(0, EnvioPncp::count());
    }
}
