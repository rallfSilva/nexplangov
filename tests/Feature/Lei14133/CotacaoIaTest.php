<?php

namespace Tests\Feature\Lei14133;

use App\Licitacao\Ia\ClienteIa;
use App\Models\CatalogoItem;
use App\Models\ItemProcesso;
use App\Models\LoteCotacaoIa;
use App\Models\UsoIa;
use App\Notifications\CotacaoIaConcluida;
use App\Support\Planilha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Cotação automática por IA com preços já praticados no Compras.gov.br e no PNCP (art. 23, § 1º, II). Dados fictícios. */
class CotacaoIaTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    /** @var list<string> */
    public array $pedidos = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        config(['nexplangov.ia.habilitada' => true]);
        Notification::fake();
        $teste = $this;
        $this->app->instance(ClienteIa::class, new class($teste) implements ClienteIa
        {
            public function __construct(private $teste) {}

            public function responder(string $sistema, string $pedido): array
            {
                $this->teste->pedidos[] = $pedido;
                if (str_contains($pedido, 'Itens do catálogo encontrados')) {
                    return ['texto' => '{"codigo": "461777", "termos": "papel a4"}', 'tokens_entrada' => 80, 'tokens_saida' => 10];
                }
                // Compatível tudo o que não for caixa com várias resmas.
                preg_match_all('/^\[(\d+)\] (.*)$/m', $pedido, $m, PREG_SET_ORDER);
                $av = array_map(fn ($l) => ['i' => (int) $l[1], 'compativel' => ! str_contains($l[2], 'CAIXA'), 'motivo' => str_contains($l[2], 'CAIXA') ? 'Unidade diferente' : 'Mesmo papel A4, mesma unidade'], $m);

                return ['texto' => json_encode(['avaliacoes' => $av, 'justificativa' => 'Foram considerados só preços de papel A4 75 g/m² vendidos por resma (art. 23, § 1º, II).']), 'tokens_entrada' => 300, 'tokens_saida' => 120];
            }
        });
        CatalogoItem::create(['tipo' => 'M', 'codigo' => '461777', 'descricao' => 'PAPEL A4, MATERIAL CELULOSE VEGETAL, GRAMATURA 75 G/M2, COR BRANCA', 'fonte' => 'demonstracao']);
    }

    private function fakeFontes(): void
    {
        $d = fn (int $meses) => today()->subMonths($meses)->toDateString();
        Http::fake([
            'dadosabertos.compras.gov.br/*' => Http::response(['totalPaginas' => 1, 'resultado' => [
                ['idCompra' => '2001', 'precoUnitario' => 24.5, 'dataResultado' => $d(2), 'nomeOrgao' => 'Prefeitura de Vale Verde (fictícia)', 'codigoUasg' => '999001', 'niFornecedor' => '99000333000188', 'quantidade' => 100, 'siglaUnidadeFornecimento' => 'RESMA', 'descricaoItem' => 'PAPEL A4 75G BRANCO'],
                ['idCompra' => '2002', 'numeroControlePNCPCompra' => '99222333000144-1-000015/2026', 'precoUnitario' => 26.0, 'dataResultado' => $d(4), 'nomeOrgao' => 'Câmara de Vale Verde (fictícia)', 'quantidade' => 300, 'siglaUnidadeFornecimento' => 'RESMA', 'descricaoItem' => 'PAPEL A4 75G'],
                ['idCompra' => '2003', 'precoUnitario' => 240.0, 'dataResultado' => $d(1), 'nomeOrgao' => 'Autarquia fictícia', 'siglaUnidadeFornecimento' => 'CX', 'descricaoItem' => 'PAPEL A4 CAIXA COM 10 RESMAS'],
            ]]),
            'pncp.gov.br/api/search/*' => Http::response(['items' => [['orgao_cnpj' => '99111222000133', 'ano' => 2026, 'numero_sequencial' => 7, 'orgao_nome' => 'Município de Serra Azul (fictício)', 'municipio_nome' => 'Serra Azul', 'uf' => 'RR']]]),
            'pncp.gov.br/api/pncp/v1/orgaos/99111222000133/compras/2026/7/itens/1/resultados' => Http::response([['valorUnitarioHomologado' => 25.1, 'quantidadeHomologada' => 50, 'dataResultado' => $d(3), 'niFornecedor' => '99000444000199', 'nomeRazaoSocialFornecedor' => 'Papelaria Fictícia']]),
            'pncp.gov.br/api/pncp/v1/orgaos/99111222000133/compras/2026/7/itens*' => Http::response([
                ['numeroItem' => 1, 'descricao' => 'PAPEL A4 75G BRANCO PACOTE 500 FOLHAS', 'unidadeMedida' => 'RESMA', 'quantidade' => 50, 'temResultado' => true],
                ['numeroItem' => 2, 'descricao' => 'CANETA ESFEROGRÁFICA AZUL', 'unidadeMedida' => 'UN', 'quantidade' => 500, 'temResultado' => true],
            ]),
        ]);
    }

    private function itemSemCodigo(): array
    {
        $p = $this->processo();
        $item = $p->itens()->create(['orgao_id' => $p->orgao_id, 'numero' => 1, 'material_servico' => 'M', 'descricao' => 'Papel A4 75g, branco, pacote com 500 folhas', 'unidade_medida' => 'RESMA', 'quantidade' => 200]);

        return [$p, $item];
    }

    public function test_cota_com_ia_acha_catmat_filtra_e_calcula(): void
    {
        $this->fakeFontes();
        [$p, $item] = $this->itemSemCodigo();
        $quem = $this->u['planejamento'];

        $this->actingAs($quem)->post("/processos/{$p->id}/cotacao-ia", ['escopo' => 'sem_preco', 'resultados' => 3])
            ->assertRedirect("/processos/{$p->id}?aba=itens")->assertSessionHas('sucesso');

        $item->refresh();
        $this->assertSame('461777', $item->codigo_catalogo, 'a IA escolheu o código do catálogo');
        $this->assertSame('cotado', $item->cotacao_ia_situacao);
        $cot = $item->cotacoes()->get();
        $this->assertCount(3, $cot);
        $this->assertTrue($cot->every(fn ($c) => $c->origem === 'ia'));
        $this->assertSame(['contratacoes_similares' => 1, 'painel_oficial' => 2], $cot->countBy('fonte')->sortKeys()->all(), 'Painel de Preços (inciso I) e licitação anterior no PNCP (inciso II)');
        $this->assertFalse($cot->contains(fn ($c) => (float) $c->valor_unitario === 240.0), 'caixa com 10 resmas ficou de fora');
        $this->assertTrue($cot->contains(fn ($c) => str_starts_with($c->referencia, 'Licitação anterior (PNCP)') && $c->url_fonte === 'https://pncp.gov.br/app/editais/99111222000133/2026/7'));
        $this->assertTrue($cot->every(fn ($c) => $c->url_fonte && $c->identificacao_fonte && $c->consultado_em), 'todo preço da IA tem fonte verificável');
        $this->assertTrue($cot->contains(fn ($c) => $c->identificacao_fonte === '99111222000133-1-000007/2026 · item 1'));
        $this->assertTrue($cot->contains(fn ($c) => $c->identificacao_fonte === 'Compra 2001 · UASG 999001' && str_starts_with($c->url_fonte, 'https://dadosabertos.compras.gov.br/modulo-pesquisa-preco/1_consultarMaterial?codigoItemCatalogo=461777')));
        $this->assertTrue($cot->contains(fn ($c) => $c->url_fonte === 'https://pncp.gov.br/app/editais/99222333000144/2026/15'), 'compra do Compras.gov.br com controle PNCP aponta para o PNCP');
        $this->assertSame(25.1, (float) $item->valor_unitario_estimado, 'mediana de 24,50, 25,10 e 26,00');
        $this->assertStringContainsString('art. 23', $item->justificativa_precos);
        $this->assertSame(['catalogo', 'cotar'], UsoIa::orderBy('id')->pluck('acao')->all());

        $lote = LoteCotacaoIa::sole();
        $this->assertSame(['concluido', 1, 1], [$lote->situacao, $lote->itens_processados, $lote->itens_cotados]);
        Notification::assertSentTo($quem, CotacaoIaConcluida::class);

        $this->actingAs($quem)->get("/processos/{$p->id}?aba=itens")->assertOk()
            ->assertSee('Cotar com IA')->assertSee('Média pond.')->assertSee('Fonte da coleta')->assertSee('Ver o registro original no portal')->assertSee('Cotado pela IA')->assertSee('Justificativa da pesquisa')->assertSee('Painel de Preços do governo federal')->assertSee('Depende de convênio');
    }

    public function test_nova_cotacao_troca_so_as_da_ia_e_mantem_as_manuais(): void
    {
        $this->fakeFontes();
        [$p, $item] = $this->itemSemCodigo();
        $item->cotacoes()->create(['orgao_id' => $p->orgao_id, 'fonte' => 'fornecedor', 'referencia' => 'Orçamento Papelaria Fictícia', 'documento_fonte' => '99.000.555/0001-10', 'valor_unitario' => 25, 'data_cotacao' => today()->subDays(5)]);
        $quem = $this->u['planejamento'];

        $this->actingAs($quem)->post("/processos/{$p->id}/cotacao-ia", ['escopo' => 'todos', 'resultados' => 3]);
        $this->actingAs($quem)->post("/processos/{$p->id}/cotacao-ia", ['escopo' => 'todos', 'resultados' => 5]);

        $this->assertSame(1, $item->cotacoes()->where('origem', 'manual')->count());
        $this->assertSame(3, $item->cotacoes()->where('origem', 'ia')->count(), 'só 3 compatíveis, mesmo pedindo 5');
        $this->assertStringContainsString('menos que os 5 pedidos', $item->fresh()->cotacao_ia_mensagem);
    }

    public function test_sem_preco_compativel_registra_aviso_no_item(): void
    {
        Http::fake(['dadosabertos.compras.gov.br/*' => Http::response(['resultado' => []]), 'pncp.gov.br/*' => Http::response('erro', 503)]);
        [$p, $item] = $this->itemSemCodigo();

        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/cotacao-ia", ['escopo' => 'sem_preco', 'resultados' => 3]);

        $item->refresh();
        $this->assertSame('sem_resultado', $item->cotacao_ia_situacao);
        $this->assertStringContainsString('PNCP respondeu com erro 503', $item->cotacao_ia_mensagem);
        $this->assertSame(0, $item->cotacoes()->count());
        $this->assertSame('concluido', LoteCotacaoIa::sole()->situacao);
    }

    public function test_notas_fiscais_entram_so_com_convenio_configurado(): void
    {
        $this->fakeFontes();
        [$p, $item] = $this->itemSemCodigo();
        $quem = $this->u['planejamento'];

        // Sem convênio (padrão), a base de NF-e nem é consultada.
        $this->actingAs($quem)->post("/processos/{$p->id}/cotacao-ia", ['escopo' => 'todos', 'resultados' => 5]);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'nfe.exemplo.test'));
        $this->assertSame(0, $item->cotacoes()->where('fonte', 'notas_fiscais')->count());

        config(['nexplangov.notas_fiscais.url' => 'https://nfe.exemplo.test/precos', 'nexplangov.notas_fiscais.token' => 'token-ficticio']);
        Http::fake(['nfe.exemplo.test/*' => Http::response(['itens' => [
            ['chaveAcesso' => str_repeat('9', 44), 'dataEmissao' => today()->subMonth()->toDateString(), 'valorUnitario' => 24.9, 'quantidade' => 20, 'unidade' => 'RESMA', 'descricao' => 'PAPEL A4 75G 500 FOLHAS', 'cnpjEmitente' => '99000666000177', 'nomeEmitente' => 'Distribuidora Fictícia', 'uf' => 'RR'],
            ['chaveAcesso' => '123', 'dataEmissao' => today()->toDateString(), 'valorUnitario' => 1], // chave inválida
        ]])]);

        $this->actingAs($quem)->post("/processos/{$p->id}/cotacao-ia", ['escopo' => 'todos', 'resultados' => 5]);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'nfe.exemplo.test') && $r->hasHeader('Authorization', 'Bearer token-ficticio'));
        $nf = $item->cotacoes()->where('fonte', 'notas_fiscais')->sole();
        $this->assertSame(24.9, (float) $nf->valor_unitario);
        $this->assertStringStartsWith('NF-e chave 9999 9999', $nf->identificacao_fonte);
        $this->assertStringStartsWith('https://www.nfe.fazenda.gov.br/', $nf->url_fonte, 'a chave se confere no portal da NF-e');
        $this->assertSame(['contratacoes_similares', 'notas_fiscais', 'painel_oficial'], $item->cotacoes()->pluck('fonte')->unique()->sort()->values()->all());
    }

    public function test_exige_permissao_ia_ligada_e_fase_preparatoria(): void
    {
        Http::fake();
        [$p] = $this->itemSemCodigo();
        $this->actingAs($this->u['requisitante'])->post("/processos/{$p->id}/cotacao-ia", ['escopo' => 'todos', 'resultados' => 3])->assertForbidden();

        config(['nexplangov.ia.habilitada' => false]);
        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/cotacao-ia", ['escopo' => 'todos', 'resultados' => 3])->assertSessionHas('erro');
        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/cotacao-ia", ['escopo' => 'todos', 'resultados' => 7])->assertSessionHasErrors('resultados');
        $this->assertSame(0, LoteCotacaoIa::count());
        Http::assertNothingSent();
    }

    public function test_importa_itens_da_planilha_modelo(): void
    {
        $p = $this->processo();
        $quem = $this->u['planejamento'];
        $this->actingAs($quem)->get("/processos/{$p->id}/itens/modelo.xlsx")->assertOk()->assertDownload('modelo-itens-cotacao.xlsx');

        $arq = sys_get_temp_dir().'/itens-'.uniqid().'.xlsx';
        Planilha::xlsx($arq, ['Itens' => ['cabecalho' => ['tipo', 'codigo_catmat_catser', 'descricao', 'unidade_fornecimento', 'quantidade'], 'linhas' => [
            ['M', '', 'Papel A4 75 g/m², branco, pacote com 500 folhas', 'RESMA', 200],
            ['M', '', 'Caneta esferográfica azul, ponta média 1,0 mm', 'UN', '1.500,00'],
        ]]]);
        $this->actingAs($quem)->post("/processos/{$p->id}/itens/importar", ['planilha' => new UploadedFile($arq, 'itens.xlsx', null, null, true)])
            ->assertRedirect("/processos/{$p->id}?aba=itens")->assertSessionHas('sucesso', fn ($m) => str_starts_with($m, '2 item(ns)'));
        $this->assertSame([[1, 'RESMA', 200.0], [2, 'UN', 1500.0]], ItemProcesso::orderBy('numero')->get()->map(fn ($i) => [$i->numero, $i->unidade_medida, (float) $i->quantidade])->all());

        Planilha::xlsx($arq, ['Itens' => ['cabecalho' => ['tipo', 'descricao', 'unidade_fornecimento', 'quantidade'], 'linhas' => [
            ['M', 'Grampeador de mesa para 25 folhas', 'UN', 10],
            ['M', 'Clipe', 'CX', 0],
        ]]]);
        $this->actingAs($quem)->post("/processos/{$p->id}/itens/importar", ['planilha' => new UploadedFile($arq, 'itens.xlsx', null, null, true)])->assertSessionHas('erro', fn ($m) => str_contains($m, 'Linha 3'));
        $this->assertSame(2, ItemProcesso::count(), 'tudo ou nada');
    }

    public function test_mapa_de_precos_em_pdf(): void
    {
        $this->fakeFontes();
        [$p] = $this->itemSemCodigo();
        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/cotacao-ia", ['escopo' => 'todos', 'resultados' => 3]);

        $r = $this->actingAs($this->u['planejamento'])->get("/processos/{$p->id}/mapa-de-precos.pdf?configurado=1&colunas[]=mediana&justificativa=1");
        $r->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $r->getContent());
        $this->actingAs($this->u['planejamento'])->get("/processos/{$p->id}/mapa-de-precos.pdf")->assertOk();
    }
}
