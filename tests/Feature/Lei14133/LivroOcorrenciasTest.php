<?php

namespace Tests\Feature\Lei14133;

use App\Licitacao\Contratos\LivroDeOcorrencias;
use App\Models\Contrato;
use App\Models\OcorrenciaContrato;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/** Livro de ocorrências inalterável e designação formal de gestor e fiscal (art. 117). */
class LivroOcorrenciasTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    private Contrato $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $p = $this->processo([], 'contratado');
        $this->c = Contrato::create([
            'orgao_id' => $this->orgao->id, 'processo_id' => $p->id, 'fornecedor_id' => $this->fornecedor()->id, 'gestor_id' => $this->u['gestor-contrato']->id, 'fiscal_id' => $this->u['fiscal']->id,
            'numero' => '8', 'ano' => 2026, 'tipo' => 1, 'objeto' => 'Limpeza predial (fictícia).', 'categoria' => 8, 'valor_inicial' => 12000, 'valor_global' => 12000,
            'assinatura' => '2026-01-02', 'vigencia_inicio' => '2026-01-02', 'vigencia_fim' => '2026-12-31', 'situacao' => 'vigente',
        ]);
    }

    private function anotar($quem, array $extra = [])
    {
        return $this->actingAs($quem)->post("/contratos/{$this->c->id}/ocorrencias", $extra + [
            'tipo' => 'ocorrencia', 'ocorrida_em' => today()->toDateString(), 'descricao' => 'Equipe de limpeza chegou com duas horas de atraso na sede.',
        ]);
    }

    public function test_fiscal_anota_e_o_livro_fica_encadeado(): void
    {
        $this->anotar($this->u['fiscal'])->assertSessionHas('sucesso');
        $this->anotar($this->u['gestor-contrato'], ['tipo' => 'determinacao', 'providencias' => 'Notificar a contratada.', 'prazo_regularizacao' => today()->addDays(5)->toDateString()])->assertSessionHas('sucesso');

        [$a, $b] = OcorrenciaContrato::orderBy('numero')->get();
        $this->assertSame(['fiscal', 'gestor'], [$a->autor_papel, $b->autor_papel]);
        $this->assertSame($a->hash, $b->hash_anterior);
        $this->assertTrue(app(LivroDeOcorrencias::class)->integro($this->c));
    }

    public function test_quem_nao_e_fiscal_nem_gestor_do_contrato_nao_anota(): void
    {
        $outroGestor = $this->usuario('gestor-contrato', $this->orgao);

        $this->anotar($outroGestor)->assertSessionHas('motivos', fn ($m) => str_contains($m[0], 'art. 117'));
        $this->assertSame(0, OcorrenciaContrato::count());
    }

    public function test_anotacao_nao_se_altera_nem_no_banco(): void
    {
        $this->anotar($this->u['fiscal']);
        $o = OcorrenciaContrato::firstOrFail();

        $this->expectException(LogicException::class);
        $o->update(['descricao' => 'Texto trocado depois.']);
    }

    public function test_gatilho_do_banco_impede_alterar_o_livro(): void
    {
        $this->anotar($this->u['fiscal']);

        $this->expectException(QueryException::class);
        DB::table('ocorrencias_contrato')->update(['descricao' => 'Texto trocado direto no banco.']);
    }

    public function test_retificacao_aponta_a_anotacao_corrigida(): void
    {
        $this->anotar($this->u['fiscal']);
        $primeira = OcorrenciaContrato::firstOrFail();

        $this->anotar($this->u['fiscal'], ['tipo' => 'retificacao', 'retifica_id' => $primeira->id, 'descricao' => 'Retifica: o atraso foi de uma hora, não de duas.'])->assertSessionHas('sucesso');
        $this->assertSame($primeira->id, OcorrenciaContrato::where('numero', 2)->value('retifica_id'));
        $this->actingAs($this->u['auditor'])->get("/contratos/{$this->c->id}/ocorrencias.pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_autoridade_designa_e_gera_a_portaria(): void
    {
        $this->actingAs($this->u['gestor-contrato'])->post("/contratos/{$this->c->id}/designacao", ['designacao_ato' => 'Portaria nº 12/2026', 'designacao_em' => today()->toDateString()])->assertForbidden();
        $this->actingAs($this->u['autoridade'])->post("/contratos/{$this->c->id}/designacao", ['designacao_ato' => 'Portaria nº 12/2026', 'designacao_em' => today()->toDateString()])->assertSessionHas('sucesso');

        $this->assertSame('Portaria nº 12/2026', $this->c->fresh()->designacao_ato);
        $this->actingAs($this->u['gestor-contrato'])->get("/contratos/{$this->c->id}/designacao.pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->u['gestor-contrato'])->get("/contratos/{$this->c->id}")->assertOk()->assertSee('Portaria nº 12/2026')->assertSee('Livro de ocorrências');
    }
}
