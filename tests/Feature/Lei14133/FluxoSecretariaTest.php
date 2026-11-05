<?php

namespace Tests\Feature\Lei14133;

use App\Models\Fluxo;
use App\Models\FluxoEtapa;
use App\Models\Processo;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Fluxo por tipo de processo e por secretaria (1.1), com roteiro de unidades. */
class FluxoSecretariaTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    private Unidade $saude;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->saude = Unidade::factory()->for($this->orgao)->create(['sigla' => 'SEMUS', 'nome' => 'Saúde (fictícia)']);
    }

    private function fluxo(string $nome, array $extra = []): Fluxo
    {
        $f = Fluxo::create($extra + ['orgao_id' => $this->orgao->id, 'nome' => $nome, 'aplicacao' => 'todos', 'padrao' => true]);
        FluxoEtapa::create(['orgao_id' => $this->orgao->id, 'fluxo_id' => $f->id, 'ordem' => 1, 'tipo' => 'dfd', 'depende_de' => [], 'origem' => 'formulario']);

        return $f;
    }

    private function abrir(int $unidade, int $modalidade = 6): Processo
    {
        $this->actingAs($this->u['planejamento'])->post('/processos', ['numero' => (string) random_int(100, 99999), 'ano' => 2026, 'objeto' => 'Aquisição de insumos para as secretarias municipais', 'unidade_id' => $unidade,
            'modalidade' => $modalidade, 'amparo_legal' => $modalidade === 4 ? 2 : 1, 'criterio_julgamento' => 1, 'modo_disputa' => 1, 'categoria' => 2, 'justificativa_fora_pca' => 'Contratação fora do plano, justificada para o teste.'])->assertSessionHasNoErrors();

        return Processo::latest('id')->firstOrFail();
    }

    public function test_processo_recebe_o_fluxo_da_secretaria_ou_da_modalidade(): void
    {
        $geral = $this->fluxo('Geral');
        $pregao = $this->fluxo('Pregão', ['modalidade' => 6]);
        $saude = $this->fluxo('Saúde', ['unidade_id' => $this->saude->id, 'roteiro_unidades' => [$this->saude->id, $this->unidade->id]]);

        $this->assertSame($saude->id, $this->abrir($this->saude->id)->fluxo_id);
        $this->assertSame($pregao->id, $this->abrir($this->unidade->id)->fluxo_id);
        $this->assertSame($geral->id, $this->abrir($this->unidade->id, 4)->fluxo_id);
    }

    public function test_roteiro_sugere_destino_na_remessa(): void
    {
        $this->fluxo('Saúde', ['unidade_id' => $this->saude->id, 'roteiro_unidades' => [$this->saude->id, $this->unidade->id]]);
        $this->u['planejamento']->update(['unidade_id' => $this->saude->id]);
        $p = $this->abrir($this->saude->id);

        $this->actingAs($this->u['planejamento'])->get("/processos/{$p->id}?aba=tramitacao")->assertOk()->assertSee('(próxima do roteiro)');
    }

    public function test_cadastro_do_fluxo_com_modalidade_secretaria_e_roteiro(): void
    {
        $admin = $this->usuario('administrador-orgao', $this->orgao);

        $this->actingAs($admin)->post('/fluxos', ['nome' => 'Saúde por pregão', 'aplicacao' => 'licitacao', 'modalidade' => 6, 'unidade_id' => $this->saude->id,
            'roteiro_unidades' => [$this->saude->id, '', $this->unidade->id], 'padrao' => 1, 'ativo' => 1, 'etapas' => []])->assertSessionHasNoErrors();

        $f = Fluxo::where('nome', 'Saúde por pregão')->firstOrFail();
        $this->assertSame([6, $this->saude->id, [$this->saude->id, $this->unidade->id]], [$f->modalidade, $f->unidade_id, $f->roteiro_unidades]);

        $this->actingAs($admin)->get('/fluxos')->assertOk()->assertSee('secretaria SEMUS')->assertSee('SEMUS → SEMAD');

        $outra = Unidade::factory()->create(['sigla' => 'OUTRA']);
        $this->actingAs($admin)->post('/fluxos', ['nome' => 'Invasor', 'aplicacao' => 'todos', 'unidade_id' => $outra->id, 'etapas' => []])->assertSessionHasErrors('unidade_id');
    }
}
