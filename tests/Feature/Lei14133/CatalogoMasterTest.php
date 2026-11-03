<?php

namespace Tests\Feature\Lei14133;

use App\Models\CatalogoItem;
use App\Models\PlanoContratacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Catálogo CATMAT/CATSER (art. 19, I) e painel master. */
class CatalogoMasterTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
    }

    private function catalogo(): void
    {
        CatalogoItem::create(['tipo' => 'M', 'codigo' => '1001', 'descricao' => 'Papel sulfite A4 75 g/m2 (item de demonstração)', 'unidade_padrao' => 'RESMA', 'fonte' => 'demonstracao']);
        CatalogoItem::create(['tipo' => 'M', 'codigo' => '1002', 'descricao' => 'Caneta esferográfica azul (item de demonstração)', 'ativo' => false, 'fonte' => 'demonstracao']);
        CatalogoItem::create(['tipo' => 'S', 'codigo' => '2001', 'descricao' => 'Serviço de limpeza predial (item de demonstração)', 'fonte' => 'demonstracao']);
    }

    public function test_busca_por_codigo_e_por_todas_as_palavras(): void
    {
        $this->catalogo();
        $this->assertSame(['1001'], CatalogoItem::buscar('100')->where('ativo', true)->pluck('codigo')->all());
        $this->assertSame(['1001'], CatalogoItem::buscar('papel a4')->pluck('codigo')->all());
        $this->assertSame([], CatalogoItem::buscar('papel caneta')->pluck('codigo')->all());
        $this->assertSame([], CatalogoItem::buscar('papel', 'S')->pluck('codigo')->all());
    }

    public function test_busca_ignora_curingas_do_usuario(): void
    {
        $this->catalogo();
        $this->assertSame([], CatalogoItem::buscar('%%')->pluck('codigo')->all());
    }

    public function test_tela_e_endpoint_de_busca(): void
    {
        $this->catalogo();
        $u = $this->u['requisitante'];
        $this->actingAs($u)->get('/catalogo?q=papel')->assertOk()->assertSee('1001');
        $this->actingAs($u)->getJson('/catalogo/buscar?q=caneta')->assertOk()->assertJsonCount(0); // inativo fica de fora
        $this->actingAs($u)->getJson('/catalogo/buscar?q=papel')->assertOk()->assertJsonPath('0.codigo', '1001');
        $this->actingAs($u)->getJson('/catalogo/buscar?q=p')->assertStatus(422);
    }

    public function test_importacao_e_idempotente_e_ignora_linhas_ruins(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'cat');
        file_put_contents($f, "codigo;descricao;classe_codigo;classe_nome;unidade;situacao\n3001;Item de teste um;10;Classe de teste;UN;ativo\n3002;Item de teste dois;;;;inativo\nabc;Linha ruim;;;;\n");

        $this->artisan('nexplangov:catalogo-importar', ['arquivo' => $f, '--tipo' => 'M'])->expectsOutputToContain('2 item(ns) importado(s), 1 linha(s) ignorada(s)')->assertSuccessful();
        $this->artisan('nexplangov:catalogo-importar', ['arquivo' => $f, '--tipo' => 'M'])->assertSuccessful();
        unlink($f);

        $this->assertSame(2, CatalogoItem::count());
        $this->assertFalse(CatalogoItem::where('codigo', '3002')->first()->ativo);
        $this->artisan('nexplangov:catalogo-importar', ['arquivo' => '/nao/existe', '--tipo' => 'M'])->assertFailed();
    }

    public function test_codigo_do_pca_e_conferido_quando_o_catalogo_esta_carregado(): void
    {
        $plano = PlanoContratacao::create(['orgao_id' => $this->orgao->id, 'ano' => 2027, 'situacao' => 'elaboracao']);
        $dados = fn ($cod) => ['material_servico' => 'M', 'categoria' => 2, 'codigo_catalogo' => $cod, 'descricao' => 'Papel A4', 'unidade_fornecimento' => 'RESMA', 'quantidade' => '10', 'valor_unitario_estimado' => '30,00', 'prioridade' => 1];
        $p = $this->u['planejamento'];

        $this->actingAs($p)->post("/pca/{$plano->id}/itens", $dados('9999'))->assertSessionDoesntHaveErrors(); // sem catálogo, não confere

        $this->catalogo();
        $this->actingAs($p)->post("/pca/{$plano->id}/itens", $dados('9999'))->assertSessionHasErrors('codigo_catalogo');
        $this->actingAs($p)->post("/pca/{$plano->id}/itens", $dados('1002'))->assertSessionHasErrors('codigo_catalogo'); // inativo
        $this->actingAs($p)->post("/pca/{$plano->id}/itens", $dados('2001'))->assertSessionHasErrors('codigo_catalogo'); // é de serviço
        $this->actingAs($p)->post("/pca/{$plano->id}/itens", $dados('1001'))->assertSessionDoesntHaveErrors();
    }

    public function test_painel_master_so_para_administrador_geral(): void
    {
        $geral = $this->usuario('administrador-geral', null);
        $this->actingAs($geral)->get('/master')->assertOk()->assertSee('PMX')->assertSee('Íntegra');
        $this->actingAs($this->u['planejamento'])->get('/master')->assertForbidden();
        $adm = $this->usuario('administrador-orgao', $this->orgao);
        $this->actingAs($adm)->get('/master')->assertForbidden();
    }
}
