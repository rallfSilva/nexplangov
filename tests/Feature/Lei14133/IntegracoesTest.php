<?php

namespace Tests\Feature\Lei14133;

use App\Models\Contrato;
use App\Models\Cotacao;
use App\Models\Fornecedor;
use App\Models\RegistroAuditoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

/** Compras.gov.br (art. 23, § 1º, II), remessa ao tribunal de contas (art. 169, III) e dossiê para o SEI. */
class IntegracoesTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        Cache::flush();
    }

    private function itemComCodigo()
    {
        $p = $this->processo();
        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/itens", ['descricao' => 'Papel A4', 'material_servico' => 'M', 'codigo_catalogo' => '461776', 'unidade_medida' => 'RESMA', 'quantidade' => '100', 'tipo_beneficio' => 4])->assertRedirect();

        return [$p, $p->itens()->first()];
    }

    private function fakeApi(): void
    {
        Http::fake(['dadosabertos.compras.gov.br/*' => Http::response(['totalPaginas' => 1, 'resultado' => [
            ['idCompra' => '1001', 'precoUnitario' => 24.5, 'dataResultado' => today()->subMonths(2)->toDateString(), 'nomeOrgao' => 'Prefeitura de Teste (fictícia)', 'codigoUasg' => '999001', 'nomeFornecedor' => 'Papelaria Ficticia', 'niFornecedor' => '99000333000188', 'quantidade' => 50, 'siglaUnidadeFornecimento' => 'RESMA'],
            ['idCompra' => '1002', 'precoUnitario' => 26.0, 'dataResultado' => today()->subMonths(5)->toDateString(), 'nomeOrgao' => 'Câmara de Teste (fictícia)', 'codigoUasg' => '999002'],
            ['idCompra' => '0999', 'precoUnitario' => 15.0, 'dataResultado' => today()->subMonths(18)->toDateString(), 'nomeOrgao' => 'Antiga'], // mais de 1 ano: fora
            ['idCompra' => '1003', 'precoUnitario' => 0, 'dataResultado' => today()->toDateString()], // sem preço: fora
        ]])]);
    }

    public function test_busca_mostra_so_compras_do_ultimo_ano(): void
    {
        $this->fakeApi();
        [$p, $item] = $this->itemComCodigo();

        $r = $this->actingAs($this->u['planejamento'])->get("/processos/{$p->id}/itens/{$item->id}/compras-gov")->assertOk();
        $r->assertSee('Prefeitura de Teste (fictícia)')->assertSee('Câmara de Teste (fictícia)')->assertDontSee('Antiga')->assertSee('99.000.333/0001-88');
        Http::assertSent(fn ($req) => str_contains($req->url(), 'codigoItemCatalogo=461776'));
    }

    public function test_importa_como_contratacao_similar_sem_aceitar_valor_do_navegador(): void
    {
        $this->fakeApi();
        [$p, $item] = $this->itemComCodigo();
        $chave = '1001|'.today()->subMonths(2)->toDateString().'|24.5';

        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/itens/{$item->id}/compras-gov", ['selecionados' => [$chave, '1001|2026-01-01|0.01']])->assertSessionHas('sucesso');
        $this->assertSame(1, Cotacao::count());
        $c = Cotacao::first();
        $this->assertSame('contratacoes_similares', $c->fonte);
        $this->assertEquals(24.5, (float) $c->valor_unitario);
        $this->assertStringStartsWith('Compras.gov.br', $c->referencia);
        $this->assertEquals(24.5, (float) $item->refresh()->valor_unitario_estimado);

        // Repetir não duplica.
        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/itens/{$item->id}/compras-gov", ['selecionados' => [$chave]]);
        $this->assertSame(1, Cotacao::count());
    }

    public function test_api_fora_do_ar_avisa_sem_quebrar(): void
    {
        Http::fake(['dadosabertos.compras.gov.br/*' => Http::response('erro', 503)]);
        [$p, $item] = $this->itemComCodigo();
        $this->actingAs($this->u['planejamento'])->get("/processos/{$p->id}/itens/{$item->id}/compras-gov")->assertOk()->assertSee('Compras.gov.br respondeu com erro 503');
    }

    public function test_item_sem_codigo_nao_consulta(): void
    {
        Http::fake();
        $p = $this->processo();
        $item = $p->itens()->create(['orgao_id' => $p->orgao_id, 'numero' => 1, 'material_servico' => 'M', 'descricao' => 'Item sem código', 'unidade_medida' => 'UN', 'quantidade' => 1]);
        $this->actingAs($this->u['planejamento'])->get("/processos/{$p->id}/itens/{$item->id}/compras-gov")->assertRedirect()->assertSessionHas('erro');
        Http::assertNothingSent();
    }

    public function test_remessa_ao_tce_gera_zip_com_manifesto_e_cpf_mascarado(): void
    {
        $p = $this->processo([], 'contratado');
        $pf = Fornecedor::create(['orgao_id' => $this->orgao->id, 'tipo_pessoa' => 'PF', 'documento' => '529.982.247-25', 'razao_social' => 'Pessoa Ficticia']);
        Contrato::create(['orgao_id' => $this->orgao->id, 'processo_id' => $p->id, 'fornecedor_id' => $pf->id, 'numero' => '7', 'ano' => 2026, 'tipo' => 1, 'objeto' => '=HYPERLINK("x") serviço', 'categoria' => 2,
            'valor_inicial' => 1000, 'valor_global' => 1000, 'assinatura' => today(), 'vigencia_inicio' => today(), 'vigencia_fim' => today()->addYear(), 'situacao' => 'vigente']);

        $adm = $this->usuario('administrador-orgao', $this->orgao);
        $r = $this->actingAs($adm)->post('/integracoes/tce', ['inicio' => today()->startOfYear()->toDateString(), 'fim' => today()->toDateString()]);
        $r->assertOk();
        $zipPath = $r->baseResponse->getFile()->getPathname();

        $z = new ZipArchive;
        $this->assertTrue($z->open($zipPath));
        $manifesto = json_decode($z->getFromName('manifesto.json'), true);
        $contratos = $z->getFromName('contratos.csv');
        $z->close();

        $this->assertSame('nexplangov-generico-1.0', $manifesto['layout']);
        $this->assertSame(1, $manifesto['arquivos']['contratos.csv']['registros']);
        $this->assertSame(hash('sha256', $contratos), $manifesto['arquivos']['contratos.csv']['sha256']);
        $this->assertStringNotContainsString('529.982.247-25', $contratos);
        $this->assertStringContainsString("'=HYPERLINK", $contratos); // fórmula neutralizada
        $this->assertTrue(RegistroAuditoria::where('evento', 'exportacao')->exists());
    }

    public function test_remessa_restrita_e_periodo_validado(): void
    {
        $this->actingAs($this->u['requisitante'])->post('/integracoes/tce', ['inicio' => '2026-01-01', 'fim' => '2026-02-01'])->assertForbidden();
        $adm = $this->usuario('administrador-orgao', $this->orgao);
        $this->actingAs($adm)->post('/integracoes/tce', ['inicio' => '2026-03-01', 'fim' => '2026-02-01'])->assertSessionHasErrors('fim');
        $this->actingAs($adm)->get('/integracoes')->assertOk()->assertSee('Remessa ao tribunal de contas');
    }

    public function test_comando_de_remessa(): void
    {
        $saida = sys_get_temp_dir().'/remessa-teste.zip';
        $this->artisan('nexplangov:exportar-tce', ['orgao' => 'pmx', 'inicio' => '2026-01-01', 'fim' => '2026-12-31', '--saida' => $saida])->assertSuccessful();
        $this->assertFileExists($saida);
        unlink($saida);
        $this->artisan('nexplangov:exportar-tce', ['orgao' => 'XYZ', 'inicio' => '2026-01-01', 'fim' => '2026-12-31'])->assertFailed();
    }

    public function test_dossie_para_o_sei_traz_pecas_concluidas_e_manifesto(): void
    {
        $p = $this->processo();
        $this->concluirDocumento($p, 'dfd', $this->u['planejamento'], ['necessidade' => 'Necessidade de <b>teste</b> do dossiê.']);
        $this->actingAs($this->u['planejamento'])->patch("/processos/{$p->id}/sei", ['numero_sei' => '0001234.000567/2026-89'])->assertSessionHas('sucesso');
        $this->actingAs($this->u['planejamento'])->patch("/processos/{$p->id}/sei", ['numero_sei' => '<script>'])->assertSessionHasErrors('numero_sei');

        $r = $this->actingAs($this->u['auditor'])->get("/processos/{$p->id}/dossie")->assertOk();
        $z = new ZipArchive;
        $this->assertTrue($z->open($r->baseResponse->getFile()->getPathname()));
        $nomes = array_map(fn ($i) => $z->getNameIndex($i), range(0, $z->numFiles - 1));
        $capa = $z->getFromName('00-capa.html');
        $dfd = $z->getFromName('01-dfd-v1.html');
        $manifesto = json_decode($z->getFromName('manifesto.json'), true);
        $z->close();

        $this->assertContains('01-dfd-v1.html', $nomes);
        $this->assertStringContainsString('0001234.000567/2026-89', $capa);
        $this->assertStringContainsString('&lt;b&gt;teste&lt;/b&gt;', $dfd); // texto escapado
        $this->assertSame(hash('sha256', $dfd), $manifesto['arquivos']['01-dfd-v1.html']);
        $this->assertSame('0001234.000567/2026-89', $manifesto['numero_sei']);
    }
}
