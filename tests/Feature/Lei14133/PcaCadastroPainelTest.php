<?php

namespace Tests\Feature\Lei14133;

use App\Models\Demanda;
use App\Models\EnvioPncp;
use App\Models\ItemPca;
use App\Models\PcaUnidade;
use App\Models\PlanoContratacao;
use App\Models\PrazoDfd;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cadastro do PCA com unidades participantes e calendário, quadro de DFDs, visão geral, aba operacional
 * e conferência com o PNCP (art. 12, VII, e § 1º; art. 174).
 */
class PcaCadastroPainelTest extends TestCase
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

    private function cadastro(array $extra = []): array
    {
        $ano = now()->year + 1;

        return $extra + ['ano' => $ano, 'abrangencia' => 'unidades', 'participantes' => [$this->semed->id], 'possui_objetivo_estrategico' => '1',
            'objetivo_estrategico' => 'Melhorar a infraestrutura das escolas da rede municipal.', 'tipo_consolidacao' => 'unidade',
            'unidade_responsavel_id' => $this->unidade->id, 'nivel_detalhamento' => 'item',
            'inicio_demandas' => today()->toDateString(), 'fim_dfds' => today()->addDays(5)->toDateString(), 'fim_aprovacao' => today()->addDays(10)->toDateString(),
            'fim_revisao' => today()->addDays(15)->toDateString(), 'fim_consolidacao' => today()->addDays(20)->toDateString(), 'fim_divulgacao' => "{$ano}-01-31"];
    }

    private function plano(): PlanoContratacao
    {
        $this->actingAs($this->u['planejamento'])->post(route('pca.store'), $this->cadastro())->assertSessionHasNoErrors();

        return PlanoContratacao::firstOrFail();
    }

    public function test_cadastro_valida_o_calendario_e_guarda_participantes_e_prazo_dos_dfds(): void
    {
        $this->actingAs($this->u['planejamento'])->get(route('pca.create'))->assertOk()->assertSee('Unidades participantes');
        $this->actingAs($this->u['requisitante'])->post(route('pca.store'), $this->cadastro())->assertForbidden();

        $this->actingAs($this->u['planejamento'])->post(route('pca.store'), $this->cadastro(['fim_dfds' => today()->subDay()->toDateString()]))->assertSessionHasErrors('fim_divulgacao');
        $this->actingAs($this->u['planejamento'])->post(route('pca.store'), $this->cadastro(['participantes' => [], 'objetivo_estrategico' => '']))
            ->assertSessionHasErrors(['participantes', 'objetivo_estrategico']);

        $plano = $this->plano();
        $this->assertSame([$this->semed->id], $plano->participantes->pluck('id')->all());
        $this->assertSame('item', $plano->nivel_detalhamento);
        $this->assertSame(today()->addDays(5)->toDateString(), PrazoDfd::where('exercicio', $plano->ano)->value('data_limite')->toDateString());

        $this->actingAs($this->u['planejamento'])->put(route('pca.update', $plano), $this->cadastro(['abrangencia' => 'orgao', 'possui_objetivo_estrategico' => '0']))->assertSessionHasNoErrors();
        $plano->refresh();
        $this->assertSame('orgao', $plano->abrangencia);
        $this->assertNull($plano->objetivo_estrategico);
        $this->assertCount(0, $plano->participantes);
    }

    private function dados(array $extra = []): array
    {
        return $extra + ['unidade_id' => $this->escola->id, 'exercicio' => now()->year + 1, 'objeto' => 'Material de expediente da escola',
            'justificativa' => 'Reposição anual do material de expediente da escola pelo consumo do ano.', 'data_pretendida' => today()->addMonths(5)->toDateString(), 'prioridade' => 2,
            'itens' => [['material_servico' => 'M', 'categoria' => 2, 'codigo_catalogo' => '150000', 'descricao' => 'Papel A4', 'unidade_fornecimento' => 'RESMA', 'quantidade' => '10', 'valor_unitario_estimado' => '25']]];
    }

    public function test_dfd_segue_participantes_e_nivel_de_detalhamento_e_consolida_na_unidade(): void
    {
        $plano = $this->plano();

        $this->actingAs($this->u['requisitante'])->post('/demandas', $this->dados(['unidade_id' => $this->unidade->id]))->assertSessionHasErrors('unidade_id');
        $semCodigo = $this->dados();
        $semCodigo['itens'][0]['codigo_catalogo'] = '';
        $this->actingAs($this->u['requisitante'])->post('/demandas', $semCodigo)->assertSessionHasErrors('itens.0.codigo_catalogo');

        $this->actingAs($this->u['requisitante'])->post('/demandas', $this->dados())->assertSessionHasNoErrors();
        $dfd = Demanda::firstOrFail();
        $this->actingAs($this->u['planejamento'])->patch(route('demandas.analisar', $dfd), ['decisao' => 'aceita']);
        $this->actingAs($this->u['planejamento'])->post(route('pca.dfds.consolidar', $plano))->assertSessionHas('sucesso');

        $this->assertSame($this->semed->id, $plano->itens()->value('unidade_id'), 'o DFD da escola soma na SEMED, a participante');

        // Por setor, cada setor fica com as suas linhas.
        $plano->update(['tipo_consolidacao' => 'setor']);
        $this->actingAs($this->u['requisitante'])->post('/demandas', $this->dados(['objeto' => 'Mais papel']))->assertSessionHasNoErrors();
        $outro = Demanda::latest('id')->firstOrFail();
        $this->actingAs($this->u['planejamento'])->patch(route('demandas.analisar', $outro), ['decisao' => 'aceita']);
        $this->actingAs($this->u['planejamento'])->post(route('pca.dfds.consolidar', $plano))->assertSessionHas('sucesso');
        $this->assertSame(1, $plano->itens()->where('unidade_id', $this->escola->id)->count());
    }

    public function test_lista_quadro_visao_geral_e_operacional(): void
    {
        $plano = $this->plano();
        $this->actingAs($this->u['requisitante'])->post('/demandas', $this->dados(['acao' => 'rascunho']))->assertSessionHasNoErrors();
        $this->actingAs($this->u['requisitante'])->post('/demandas', $this->dados(['objeto' => 'Cadeiras para a sala de leitura']))->assertSessionHasNoErrors();
        ItemPca::create(['orgao_id' => $this->orgao->id, 'plano_contratacao_id' => $plano->id, 'unidade_id' => $this->escola->id, 'numero' => 1, 'material_servico' => 'M', 'categoria' => 2,
            'descricao' => 'Papel A4', 'unidade_fornecimento' => 'RESMA', 'quantidade' => 10, 'valor_unitario_estimado' => 25, 'data_desejada' => ($plano->ano).'-03-01', 'prioridade' => 2]);
        $item = $plano->itens()->first();
        $this->processo(['ano' => $plano->ano, 'unidade_id' => $this->semed->id, 'item_pca_id' => $item->id, 'justificativa_fora_pca' => null]);
        $this->processo(['ano' => $plano->ano, 'unidade_id' => $this->semed->id]);

        $this->actingAs($this->u['planejamento'])->get(route('pca.index'))->assertOk()
            ->assertSee('PCA '.$plano->ano)->assertSee('Unidades participantes')->assertSee('SEMED')->assertSee('R$ 250,00')->assertSee('0 de 1');

        $this->actingAs($this->u['planejamento'])->get(route('pca.dfds', $plano))->assertOk()
            ->assertSeeInOrder(['Em elaboração', 'Material de expediente da escola', 'Em análise', 'Cadeiras para a sala de leitura', 'Finalizados']);
        $this->actingAs($this->u['planejamento'])->get(route('pca.dfds', [$plano, 'q' => 'cadeiras']))->assertOk()->assertDontSee('Material de expediente da escola');

        $this->actingAs($this->u['auditor'])->get(route('pca.visao', $plano))->assertOk()->assertSee('Valores por unidade')->assertSee('Março')->assertSee('Etapa atual');
        $this->actingAs($this->u['auditor'])->get(route('pca.operacional', $plano))->assertOk()->assertSee('50,00%')->assertSee('Contratações fora do PCA');
    }

    public function test_atualiza_no_pncp_retificando_os_itens_publicados_e_incluindo_os_novos(): void
    {
        $remotos = [];
        Http::fake([
            '*/v1/usuarios/login' => Http::response(null, 200, ['Authorization' => 'Bearer t']),
            '*/v1/orgaos/11222333000181/pca' => Http::response([], 201, ['location' => 'https://treina.pncp.gov.br/api/pncp/v1/orgaos/11222333000181/pca/2027/7']),
            '*/pca/2027/7/itens?*' => function () use (&$remotos) {
                return Http::response($remotos, 200);
            },
            '*' => Http::response([], 200),
        ]);
        $plano = PlanoContratacao::create(['orgao_id' => $this->orgao->id, 'ano' => 2027, 'situacao' => 'aprovado']);
        $item = fn (int $n, float $q) => ItemPca::create(['orgao_id' => $this->orgao->id, 'plano_contratacao_id' => $plano->id, 'unidade_id' => $this->semed->id, 'numero' => $n, 'material_servico' => 'M',
            'categoria' => 2, 'descricao' => 'Item '.$n, 'unidade_fornecimento' => 'UN', 'quantidade' => $q, 'valor_unitario_estimado' => 10, 'data_desejada' => '2027-03-01', 'prioridade' => 2]);
        $item(1, 10);
        $item(2, 20);

        $this->actingAs($this->u['agente-contratacao'])->post(route('pncp.pca', $plano))->assertSessionHas('sucesso');
        $parte = PcaUnidade::firstOrFail();
        $this->assertSame([1, 2], $parte->itens_publicados);

        // O PNCP tem o item 2 com outra quantidade; o plano ganhou o item 3.
        $remotos = [['numeroItem' => 1, 'descricao' => 'Item 1', 'quantidade' => 10, 'valorUnitario' => 10, 'dataDesejada' => '2027-03-01'],
            ['numeroItem' => 2, 'descricao' => 'Item 2', 'quantidade' => 15, 'valorUnitario' => 10, 'dataDesejada' => '2027-03-01']];
        $item(3, 5);
        $this->actingAs($this->u['agente-contratacao'])->post(route('pca.sincronizar.executar', $plano))->assertSessionHas('sucesso');
        $this->actingAs($this->u['agente-contratacao'])->get(route('pca.sincronizar', $plano))->assertOk()->assertSee('A incluir')->assertSee('itens 3')->assertSee('A retificar');

        $this->actingAs($this->u['agente-contratacao'])->post(route('pncp.pca', $plano))->assertSessionHas('sucesso');

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/pca/2027/7/itens')
            && array_column($r['lista'], 'numeroItem') === [1, 2] && filled($r['justificativa']));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/pca/2027/7/itens') && array_column($r->data(), 'numeroItem') === [3]);
        $this->assertSame([1, 2, 3], $parte->fresh()->itens_publicados);
        $this->assertSame(3, EnvioPncp::where('estado', 'publicado')->count());
    }
}
