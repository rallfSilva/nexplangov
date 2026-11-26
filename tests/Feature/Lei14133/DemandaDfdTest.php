<?php

namespace Tests\Feature\Lei14133;

use App\Models\Demanda;
use App\Models\ItemPca;
use App\Models\Orgao;
use App\Models\PlanoContratacao;
use App\Models\PrazoDfd;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** DFD com campos obrigatórios, demandas vinculadas e prazo de envio para o PCA (art. 12, VII). */
class DemandaDfdTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
    }

    private function dados(array $extra = []): array
    {
        return $extra + ['unidade_id' => $this->unidade->id, 'exercicio' => now()->year + 1, 'objeto' => 'Notebooks para a equipe da secretaria de educação',
            'justificativa' => 'Os equipamentos atuais têm mais de oito anos e não rodam os sistemas da rede estadual.',
            'itens' => [['material_servico' => 'M', 'categoria' => 3, 'codigo_catalogo' => '', 'descricao' => 'Notebook com 16 GB de memória e SSD de 512 GB', 'unidade_fornecimento' => 'un', 'quantidade' => '20', 'valor_unitario_estimado' => '4.500,00']],
            'data_pretendida' => today()->addMonths(4)->toDateString(), 'prioridade' => 1];
    }

    public function test_itens_e_data_sao_obrigatorios(): void
    {
        $this->actingAs($this->u['requisitante'])->post('/demandas', $this->dados(['itens' => [['descricao' => '', 'codigo_catalogo' => '']], 'data_pretendida' => '']))
            ->assertSessionHasErrors(['itens', 'data_pretendida']);

        $item = $this->dados()['itens'][0];
        $this->actingAs($this->u['requisitante'])->post('/demandas', $this->dados(['itens' => [array_merge($item, ['quantidade' => '', 'valor_unitario_estimado' => ''])]]))
            ->assertSessionHasErrors(['itens.0.quantidade', 'itens.0.valor_unitario_estimado']);
    }

    public function test_valor_e_quantidade_do_dfd_vem_dos_itens(): void
    {
        $item = $this->dados()['itens'][0];
        $this->actingAs($this->u['requisitante'])->post('/demandas', $this->dados(['itens' => [$item, ['descricao' => '', 'codigo_catalogo' => ''], array_merge($item, ['descricao' => 'Mouse sem fio', 'quantidade' => '20', 'valor_unitario_estimado' => '80,50'])]]))
            ->assertSessionHasNoErrors();

        $d = Demanda::firstOrFail();
        $this->assertSame('enviada', $d->situacao);
        $this->assertNotNull($d->enviada_em);
        $this->assertSame(2, $d->itens()->count(), 'a linha em branco é ignorada');
        $this->assertEquals(91610.0, (float) $d->valor_estimado);
        $this->assertSame('UN', $d->itens->first()->unidade_fornecimento);
    }

    public function test_rascunho_e_editado_e_enviado_depois_so_pelo_autor(): void
    {
        $this->actingAs($this->u['requisitante'])->post('/demandas', $this->dados(['acao' => 'rascunho']))->assertSessionHasNoErrors();
        $d = Demanda::firstOrFail();
        $this->assertSame('rascunho', $d->situacao);
        $this->assertNull($d->enviada_em);

        $this->actingAs($this->u['requisitante'])->get(route('demandas.edit', $d))->assertOk()->assertSee('Notebook com 16 GB');
        $this->actingAs($this->u['requisitante'])->put(route('demandas.update', $d), $this->dados(['acao' => 'rascunho', 'objeto' => 'Notebooks e mouses para a secretaria']))->assertSessionHasNoErrors();
        $this->assertSame('Notebooks e mouses para a secretaria', $d->fresh()->objeto);
        $this->assertSame(1, $d->itens()->count(), 'os itens são substituídos, não duplicados');

        // Planejamento não aprova rascunho: volta para a tela com a explicação, sem página de erro.
        $this->actingAs($this->u['planejamento'])->from('/demandas')->patch(route('demandas.analisar', $d), ['decisao' => 'aceita'])
            ->assertRedirect('/demandas')->assertSessionHas('erro', 'Só o DFD em análise recebe decisão.');
        $this->assertSame('rascunho', $d->fresh()->situacao);

        $this->actingAs($this->u['requisitante'])->post(route('demandas.enviar', $d))->assertSessionHas('sucesso');
        $this->assertSame('enviada', $d->fresh()->situacao);
        $this->actingAs($this->u['requisitante'])->get(route('demandas.edit', $d))->assertStatus(422);

        // Devolvido volta para a unidade corrigir.
        $this->actingAs($this->u['planejamento'])->patch(route('demandas.analisar', $d), ['decisao' => 'recusada', 'motivo_recusa' => 'Detalhe a configuração mínima.'])->assertSessionHas('sucesso');
        $this->actingAs($this->u['requisitante'])->get(route('demandas.edit', $d))->assertOk()->assertSee('Detalhe a configuração mínima.');
    }

    public function test_demanda_registra_as_vinculadas_e_o_exercicio(): void
    {
        $this->actingAs($this->u['requisitante'])->post('/demandas', $this->dados())->assertSessionHasNoErrors();
        $primeira = Demanda::firstOrFail();

        $this->actingAs($this->u['requisitante'])->post('/demandas', $this->dados(['objeto' => 'Instalação de rede elétrica para os notebooks', 'vinculadas' => [$primeira->id]]))->assertSessionHasNoErrors();

        $segunda = Demanda::latest('id')->firstOrFail();
        $this->assertSame([$primeira->id], $segunda->vinculadas->pluck('id')->all());
        $this->assertSame(now()->year + 1, $segunda->exercicio);
        $this->actingAs($this->u['planejamento'])->get('/demandas')->assertSee('Vinculada a '.$primeira->numero.'/'.$primeira->ano);
    }

    public function test_depois_do_prazo_so_entra_excecao_justificada(): void
    {
        $this->actingAs($this->u['planejamento'])->post('/demandas/prazos', ['exercicio' => now()->year + 1, 'data_limite' => today()->subDay()->toDateString()])->assertSessionHas('sucesso');
        $this->actingAs($this->u['requisitante'])->post('/demandas/prazos', ['exercicio' => now()->year + 1, 'data_limite' => today()->toDateString()])->assertForbidden();
        $this->assertSame(1, PrazoDfd::count());

        $this->actingAs($this->u['requisitante'])->post('/demandas', $this->dados())->assertSessionHasErrors('excecao_prazo');
        $this->actingAs($this->u['requisitante'])->post('/demandas', $this->dados(['excecao_prazo' => 'pronto_pagamento', 'justificativa_excecao' => 'curta']))->assertSessionHasErrors('justificativa_excecao');
        $this->actingAs($this->u['requisitante'])->post('/demandas', $this->dados(['excecao_prazo' => 'pronto_pagamento', 'justificativa_excecao' => 'Reposição imediata de cartuchos de pequeno valor, pagos à vista.']))->assertSessionHasNoErrors();

        $this->assertSame('pronto_pagamento', Demanda::firstOrFail()->excecao_prazo);
        // O prazo de um exercício não afeta o outro.
        $this->actingAs($this->u['requisitante'])->post('/demandas', $this->dados(['exercicio' => now()->year]))->assertSessionHasNoErrors();
    }

    public function test_formulario_avisa_o_prazo(): void
    {
        PrazoDfd::create(['orgao_id' => $this->orgao->id, 'exercicio' => now()->year + 1, 'data_limite' => today()->addDays(10)]);

        $this->actingAs($this->u['requisitante'])->get('/demandas/nova')->assertOk()->assertSee('envie até '.today()->addDays(10)->format('d/m/Y'));
    }

    /** Erro relatado: o administrador geral (sem órgão) recebia a página "422 Unprocessable Content" ao enviar o DFD. */
    public function test_administrador_geral_registra_dfd_no_orgao_da_unidade(): void
    {
        $outro = Orgao::factory()->create(['sigla' => 'PMY', 'cnpj' => '99.111.222/0001-00']);
        $gab = Unidade::factory()->for($outro)->create(['sigla' => 'GAB']);
        Demanda::create(['orgao_id' => $this->orgao->id, 'unidade_id' => $this->unidade->id, 'numero' => '0007', 'ano' => now()->year, 'objeto' => 'Outro órgão', 'justificativa' => 'Demanda de outro órgão com numeração própria.', 'situacao' => 'enviada']);
        $plano = PlanoContratacao::create(['orgao_id' => $outro->id, 'ano' => now()->year + 1, 'situacao' => 'rascunho']);
        $item = ItemPca::create(['orgao_id' => $outro->id, 'plano_contratacao_id' => $plano->id, 'unidade_id' => $gab->id, 'numero' => 2, 'material_servico' => 'M', 'categoria' => 1, 'descricao' => 'Pendrive de 16 GB', 'unidade_fornecimento' => 'UN', 'quantidade' => 100, 'valor_unitario_estimado' => 35, 'prioridade' => 2]);
        // O prazo de outro órgão não vale para este.
        PrazoDfd::create(['orgao_id' => $this->orgao->id, 'exercicio' => now()->year + 1, 'data_limite' => today()->subDay()]);
        $admin = $this->usuario('administrador-geral');

        $this->actingAs($admin)->get('/demandas/nova')->assertOk()->assertSee('PMY › GAB', false);

        $this->actingAs($admin)->post('/demandas', $this->dados(['unidade_id' => $gab->id, 'item_pca_id' => $item->id, 'itens' => [['material_servico' => 'M', 'categoria' => 1, 'codigo_catalogo' => '', 'descricao' => 'Pendrive de 16 GB', 'unidade_fornecimento' => 'un', 'quantidade' => '100', 'valor_unitario_estimado' => '35']]]))
            ->assertSessionHasNoErrors()->assertRedirect(route('demandas.index'));

        $d = Demanda::where('orgao_id', $outro->id)->firstOrFail();
        $this->assertSame('enviada', $d->situacao);
        $this->assertSame('0001', $d->numero, 'a numeração é por órgão');
        $this->assertSame($item->id, $d->item_pca_id);
        $this->assertSame($outro->id, $d->itens()->firstOrFail()->orgao_id);
    }

    public function test_administrador_geral_sem_unidade_ve_mensagem_no_campo(): void
    {
        $this->actingAs($this->usuario('administrador-geral'))->from('/demandas/nova')->post('/demandas', $this->dados(['unidade_id' => '']))
            ->assertRedirect('/demandas/nova')->assertSessionHasErrors('unidade_id');
        $this->assertSame(0, Demanda::count());
    }

    public function test_item_do_pca_precisa_ser_do_exercicio_escolhido(): void
    {
        $plano = PlanoContratacao::create(['orgao_id' => $this->orgao->id, 'ano' => now()->year, 'situacao' => 'aprovado']);
        $item = ItemPca::create(['orgao_id' => $this->orgao->id, 'plano_contratacao_id' => $plano->id, 'unidade_id' => $this->unidade->id, 'numero' => 1, 'material_servico' => 'M', 'categoria' => 1, 'descricao' => 'Notebook', 'unidade_fornecimento' => 'UN', 'quantidade' => 1, 'valor_unitario_estimado' => 10, 'prioridade' => 2]);

        $this->actingAs($this->u['requisitante'])->post('/demandas', $this->dados(['item_pca_id' => $item->id]))->assertSessionHasErrors('item_pca_id');
        $this->actingAs($this->u['requisitante'])->post('/demandas', $this->dados(['item_pca_id' => $item->id, 'exercicio' => now()->year]))->assertSessionHasNoErrors();
    }

    public function test_justificativas_curtas_voltam_com_mensagem_em_cada_campo(): void
    {
        PrazoDfd::create(['orgao_id' => $this->orgao->id, 'exercicio' => now()->year + 1, 'data_limite' => today()->subDay()]);

        $this->actingAs($this->u['requisitante'])->from('/demandas/nova')
            ->post('/demandas', $this->dados(['justificativa' => 'para a sala de ti', 'excecao_prazo' => 'pronto_pagamento', 'justificativa_excecao' => 'ajustado']))
            ->assertRedirect('/demandas/nova')->assertSessionHasErrors(['justificativa', 'justificativa_excecao']);
    }

    public function test_dfd_ja_enviado_nao_e_editado_e_a_tela_explica(): void
    {
        $this->actingAs($this->u['requisitante'])->post('/demandas', $this->dados())->assertSessionHasNoErrors();
        $d = Demanda::firstOrFail();

        $this->actingAs($this->u['requisitante'])->from(route('demandas.index'))->put(route('demandas.update', $d), $this->dados(['objeto' => 'Mudou']))
            ->assertRedirect(route('demandas.index'))->assertSessionHas('erro');
        $this->actingAs($this->u['requisitante'])->get(route('demandas.edit', $d))->assertStatus(422)->assertSee('já foi enviado ao planejamento');
        $this->assertNotSame('Mudou', $d->fresh()->objeto);
    }
}
