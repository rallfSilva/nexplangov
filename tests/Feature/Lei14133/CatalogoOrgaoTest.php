<?php

namespace Tests\Feature\Lei14133;

use App\Models\CatalogoItem;
use App\Models\ItemCatalogoOrgao;
use App\Models\Orgao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Catálogo do órgão com atributos, imagem, trava de descrição vaga e de duplicidade (7.1, 7.2 e 11.3). */
class CatalogoOrgaoTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        Storage::fake('local');
    }

    private function dados(array $extra = []): array
    {
        return $extra + ['tipo' => 'M', 'descricao' => 'Papel sulfite A4 branco 75 g/m², resma com 500 folhas', 'unidade_fornecimento' => 'resma',
            'natureza_despesa' => '3.3.90.30', 'atributos' => [['nome' => 'Gramatura', 'valor' => '75 g/m²'], ['nome' => 'Cor', 'valor' => 'branca'], ['nome' => '', 'valor' => '']]];
    }

    public function test_cadastra_item_com_atributos_e_imagem(): void
    {
        $this->actingAs($this->u['planejamento'])->post('/catalogo/orgao', $this->dados(['imagem' => UploadedFile::fake()->image('papel.png', 200, 200)]))
            ->assertRedirect('/catalogo/orgao')->assertSessionHasNoErrors();

        $i = ItemCatalogoOrgao::firstOrFail();
        $this->assertSame([['nome' => 'Gramatura', 'valor' => '75 g/m²'], ['nome' => 'Cor', 'valor' => 'branca']], $i->atributos);
        $this->assertSame($this->orgao->id, $i->orgao_id);
        $this->assertSame(64, strlen($i->imagem_hash));
        Storage::disk('local')->assertExists($i->imagem_caminho);

        $this->actingAs($this->u['requisitante'])->get('/catalogo/orgao')->assertOk()->assertSee('Papel sulfite A4')->assertSee('Gramatura')->assertDontSee('Novo item');
        $this->actingAs($this->u['requisitante'])->get("/catalogo/orgao/{$i->id}/imagem")->assertOk();
        $this->actingAs($this->u['requisitante'])->post('/catalogo/orgao', $this->dados())->assertForbidden();
        $this->actingAs($this->u['planejamento'])->getJson('/catalogo/orgao/buscar?q=sulfite')->assertOk()->assertJsonPath('0.unidade', 'resma');
    }

    public function test_barra_descricao_vaga_duplicada_e_parecida(): void
    {
        $quem = $this->u['planejamento'];
        $this->actingAs($quem)->post('/catalogo/orgao', $this->dados(['descricao' => 'Materiais diversos de escritório']))->assertSessionHasErrors('descricao');
        $this->actingAs($quem)->post('/catalogo/orgao', $this->dados(['atributos' => []]))->assertSessionHasErrors('descricao');

        $this->actingAs($quem)->post('/catalogo/orgao', $this->dados())->assertSessionHasNoErrors();
        $this->actingAs($quem)->post('/catalogo/orgao', $this->dados(['descricao' => 'PAPEL SULFITE A4 BRANCO 75 g/m2, resma com 500 folhas']))->assertSessionHasErrors('descricao');

        $parecido = $this->dados(['descricao' => 'Papel sulfite A4 branco 75 g/m², pacote com 500 folhas']);
        $this->actingAs($quem)->post('/catalogo/orgao', $parecido)->assertSessionHasErrors('descricao');
        $this->assertStringContainsString('parecido', session('errors')->first('descricao'));
        $this->actingAs($quem)->post('/catalogo/orgao', $parecido + ['confirmo_diferente' => 1])->assertSessionHasNoErrors();
        $this->assertSame(2, ItemCatalogoOrgao::count());

        // A mesma trava vale para a descrição do item no processo.
        $p = $this->processo();
        $this->actingAs($quem)->post("/processos/{$p->id}/itens", ['descricao' => 'Materiais diversos', 'material_servico' => 'M', 'unidade_medida' => 'UN', 'quantidade' => '10', 'tipo_beneficio' => 4])
            ->assertSessionHasErrors('descricao');
    }

    public function test_codigo_nacional_conferido_inativacao_com_motivo_e_isolamento(): void
    {
        CatalogoItem::create(['tipo' => 'M', 'codigo' => '150123', 'descricao' => 'Papel A4', 'ativo' => true]);
        $quem = $this->u['agente-contratacao'];
        $this->actingAs($quem)->post('/catalogo/orgao', $this->dados(['codigo_catalogo' => '999999']))->assertSessionHasErrors('codigo_catalogo');
        $this->actingAs($quem)->post('/catalogo/orgao', $this->dados(['codigo_catalogo' => '150123']))->assertSessionHasNoErrors();
        $i = ItemCatalogoOrgao::firstOrFail();

        $this->actingAs($quem)->patch("/catalogo/orgao/{$i->id}/ativo", ['motivo' => 'curto'])->assertSessionHasErrors('motivo');
        $this->actingAs($quem)->patch("/catalogo/orgao/{$i->id}/ativo", ['motivo' => 'Substituído por papel reciclado'])->assertSessionHasNoErrors();
        $this->assertFalse($i->fresh()->ativo);
        $this->actingAs($quem)->getJson('/catalogo/orgao/buscar?q=sulfite')->assertOk()->assertJsonCount(0);

        $outro = $this->usuario('planejamento', Orgao::factory()->create());
        $this->actingAs($outro)->get("/catalogo/orgao/{$i->id}/editar")->assertNotFound();
    }

    public function test_administrador_geral_importa_o_catalogo_nacional_pela_tela(): void
    {
        $admin = $this->usuario('administrador-geral', $this->orgao);
        $csv = UploadedFile::fake()->createWithContent('catmat.csv', "codigo;descricao;classe_codigo;classe_nome;unidade;situacao\n150123;Papel A4 75 g;7510;Papelaria;resma;ativo\n");

        $this->actingAs($admin)->post('/catalogo/importar', ['tipo' => 'M', 'arquivo' => $csv])->assertRedirect('/catalogo')->assertSessionHas('sucesso');
        $this->assertSame('Papel A4 75 g', CatalogoItem::where('codigo', '150123')->value('descricao'));
        $this->actingAs($this->u['planejamento'])->post('/catalogo/importar', ['tipo' => 'M', 'arquivo' => $csv])->assertForbidden();
    }
}
