<?php

namespace Tests\Feature\Lei14133;

use App\Models\Demanda;
use App\Models\PrazoDfd;
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

        // Planejamento não aprova rascunho.
        $this->actingAs($this->u['planejamento'])->patch(route('demandas.analisar', $d), ['decisao' => 'aceita'])->assertStatus(422);

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
}
