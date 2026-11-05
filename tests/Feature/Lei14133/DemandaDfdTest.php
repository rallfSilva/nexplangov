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
            'justificativa' => 'Os equipamentos atuais têm mais de oito anos e não rodam os sistemas da rede estadual.', 'quantidade' => '20', 'valor_estimado' => '90.000,00',
            'data_pretendida' => today()->addMonths(4)->toDateString(), 'prioridade' => 1];
    }

    public function test_quantidade_valor_e_data_sao_obrigatorios(): void
    {
        $this->actingAs($this->u['requisitante'])->post('/demandas', $this->dados(['quantidade' => '', 'valor_estimado' => '', 'data_pretendida' => '']))
            ->assertSessionHasErrors(['quantidade', 'valor_estimado', 'data_pretendida']);
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
