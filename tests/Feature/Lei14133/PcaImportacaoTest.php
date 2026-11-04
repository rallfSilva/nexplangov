<?php

namespace Tests\Feature\Lei14133;

use App\Licitacao\Pca\PlanilhaPca;
use App\Models\ImportacaoPca;
use App\Models\ItemPca;
use App\Models\Orgao;
use App\Models\PlanoContratacao;
use App\Support\Planilha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Importação de itens do PCA por planilha, com pré-visualização (art. 12, VII). */
class PcaImportacaoTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    private PlanoContratacao $plano;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->plano = PlanoContratacao::create(['orgao_id' => $this->orgao->id, 'ano' => 2027, 'situacao' => 'rascunho']);
    }

    private function csv(string $conteudo, string $nome = 'pca.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nome, $conteudo);
    }

    private function enviar(UploadedFile $arquivo): ImportacaoPca
    {
        $this->actingAs($this->u['planejamento'])->post(route('pca.importacoes.store', $this->plano), ['arquivo' => $arquivo])->assertRedirect();

        return ImportacaoPca::latest('id')->firstOrFail();
    }

    public function test_csv_vai_para_pre_visualizacao_com_erros_e_duplicidades_apontados(): void
    {
        ItemPca::create(['orgao_id' => $this->orgao->id, 'plano_contratacao_id' => $this->plano->id, 'numero' => 1, 'material_servico' => 'M', 'categoria' => 2,
            'descricao' => 'Toner para impressora (fictício)', 'unidade_fornecimento' => 'UN', 'quantidade' => 5, 'valor_unitario_estimado' => 300, 'prioridade' => 2]);

        $imp = $this->enviar($this->csv(implode("\n", [
            'Tipo;Categoria;Código;Descrição;Unidade;Qtd;Valor unitário;Secretaria;Data desejada;Prioridade;Natureza da despesa',
            'M;Compras;;Papel A4 (fictício);RESMA;200;28,90;SEMAD;15/03/2027;Alta;3.3.90.30.16',
            'Serviço;8;;Limpeza predial (fictícia);MÊS;12;"4.500,00";semad;;baixa;',
            'M;2;;Papel A4 (fictício);resma;10;30;;;;',
            'M;2;;Toner para impressora (fictício);UN;3;310;;;;',
            'M;Astronáutica;;Item sem quantidade;UN;;10;XYZ;31/02/2027;urgente;',
        ])));

        $this->assertSame('pre_visualizacao', $imp->situacao);
        $this->assertSame([5, 2, 1, 2], [$imp->total_linhas, $imp->validas, $imp->com_erro, $imp->duplicadas]);
        $this->assertSame(1, $this->plano->itens()->count(), 'nada é gravado antes da confirmação');

        [$papel, $limpeza, $repetida, $existente, $ruim] = $imp->linhas;
        $this->assertSame(['ok', 1, $this->unidade->id, '2027-03-15'], [$papel['situacao'], $papel['dados']['prioridade'], $papel['dados']['unidade_id'], $papel['dados']['data_desejada']]);
        $this->assertEquals(['S', 8, 4500.0, 3], [$limpeza['dados']['material_servico'], $limpeza['dados']['categoria'], $limpeza['dados']['valor_unitario_estimado'], $limpeza['dados']['prioridade']]);
        $this->assertSame('duplicada', $repetida['situacao']);
        $this->assertStringContainsString('Repete a linha 2', $repetida['motivos'][0]);
        $this->assertStringContainsString('Já existe no plano (item 1)', $existente['motivos'][0]);
        $this->assertSame('erro', $ruim['situacao']);
        $motivos = implode(' ', $ruim['motivos']);
        foreach (['Categoria "Astronáutica"', 'Unidade requisitante "XYZ"', 'Data desejada "31/02/2027"', 'quantidade', 'prioridade'] as $esperado) {
            $this->assertStringContainsString($esperado, $motivos);
        }

        $this->actingAs($this->u['auditor'])->get(route('pca.importacoes.show', [$this->plano, $imp]))->assertForbidden();
        $this->actingAs($this->u['planejamento'])->get(route('pca.importacoes.show', [$this->plano, $imp]))
            ->assertOk()->assertSee('Importar 2 item(ns)')->assertSee('Repete a linha 2');
    }

    public function test_confirmar_grava_so_as_validas_em_sequencia_e_registra_auditoria(): void
    {
        $imp = $this->enviar($this->csv("descricao,quantidade,valor_unitario,unidade,prioridade\nCaderno (fictício),100,\"12,50\",UN,media\nLápis (fictício),300,1.2,CX,\nBorracha (fictícia),0,1,UN,\n"));
        $this->assertSame([2, 1], [$imp->validas, $imp->com_erro]);

        $this->actingAs($this->u['planejamento'])->post(route('pca.importacoes.confirmar', [$this->plano, $imp]))
            ->assertRedirect(route('pca.show', $this->plano))->assertSessionHas('sucesso', '2 item(ns) importado(s) para o PCA 2027.');

        $itens = $this->plano->itens()->get();
        $this->assertSame([1, 2], $itens->pluck('numero')->all());
        $this->assertSame('1250.00', $itens[0]->valor_total_estimado);
        $this->assertSame(['confirmada', 2], [$imp->fresh()->situacao, $imp->fresh()->importadas]);
        $this->assertDatabaseHas('registros_auditoria', ['evento' => 'importacao', 'descricao' => '2 item(ns) importado(s) da planilha "pca.csv" para o PCA 2027']);

        // Confirmar de novo não duplica.
        $this->actingAs($this->u['planejamento'])->post(route('pca.importacoes.confirmar', [$this->plano, $imp]))->assertSessionHas('erro');
        $this->assertSame(2, $this->plano->itens()->count());
    }

    public function test_modelo_xlsx_baixa_e_volta_lido_pela_importacao(): void
    {
        $resposta = $this->actingAs($this->u['planejamento'])->get(route('pca.importacoes.modelo', $this->plano))->assertOk();
        $arquivo = $resposta->baseResponse->getFile()->getPathname();
        $linhas = PlanilhaPca::ler($arquivo, 'xlsx');
        $this->assertCount(1, $linhas, 'a linha de ajuda é ignorada e o exemplo é lido');
        $this->assertSame('RESMA', $linhas[0]['valores']['unidade_fornecimento']);

        $destino = tempnam(sys_get_temp_dir(), 'pca').'.xlsx';
        Planilha::xlsx($destino, ['Itens' => ['cabecalho' => array_keys(PlanilhaPca::COLUNAS), 'linhas' => [
            ['S', 'Serviços de engenharia', '', 'Manutenção predial (fictícia)', 'MÊS', 12, 8000.5, 'SEMAD', new \DateTimeImmutable('2027-05-01'), 'Alta', ''],
        ]]]);
        $imp = $this->enviar(new UploadedFile($destino, 'itens.xlsx', null, null, true));
        $this->assertSame(1, $imp->validas);
        $this->assertEquals([9, 8000.5, '2027-05-01'], [$imp->linhas[0]['dados']['categoria'], $imp->linhas[0]['dados']['valor_unitario_estimado'], $imp->linhas[0]['dados']['data_desejada']]);
        @unlink($destino);
    }

    public function test_plano_aprovado_e_arquivo_sem_cabecalho_sao_recusados(): void
    {
        $this->actingAs($this->u['planejamento'])->post(route('pca.importacoes.store', $this->plano), ['arquivo' => $this->csv("a;b\n1;2\n")])
            ->assertSessionHas('erro', fn ($m) => str_contains($m, 'cabeçalho'));
        $this->actingAs($this->u['planejamento'])->post(route('pca.importacoes.store', $this->plano), ['arquivo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('arquivo');

        $imp = $this->enviar($this->csv("descricao;quantidade;valor_unitario;unidade\nCaneta (fictícia);10;2;UN\n"));
        $this->plano->forceFill(['situacao' => 'aprovado'])->save();
        $this->actingAs($this->u['planejamento'])->post(route('pca.importacoes.confirmar', [$this->plano, $imp]))->assertSessionHas('erro');
        $this->assertSame(0, $this->plano->itens()->count());

        $this->actingAs($this->u['planejamento'])->post(route('pca.importacoes.store', $this->plano), ['arquivo' => $this->csv("descricao;quantidade\nX;1\n")])
            ->assertSessionHas('erro', fn ($m) => str_contains($m, 'revisão'));
    }

    public function test_outro_orgao_nao_ve_a_importacao(): void
    {
        $imp = $this->enviar($this->csv("descricao;quantidade;valor_unitario;unidade\nCaneta (fictícia);10;2;UN\n"));
        $outro = Orgao::factory()->create(['sigla' => 'OUT', 'cnpj' => '99.888.777/0001-00']);
        $this->actingAs($this->usuario('planejamento', $outro))->get(route('pca.importacoes.show', [$this->plano, $imp]))->assertNotFound();
    }
}
