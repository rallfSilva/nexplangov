<?php

namespace Tests\Feature\Lei14133;

use App\Models\Contrato;
use App\Models\Fornecedor;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Painéis por secretaria, perfil de fornecedores, fomento local e exportações em PDF, CSV, TXT e XLSX (10.1 a 10.3). */
class PaineisGerenciaisTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->orgao->update(['municipio' => 'Vale Verde', 'uf' => 'RR']);
        $semed = Unidade::factory()->for($this->orgao)->create(['sigla' => 'SEMED']);

        $local = Fornecedor::create(['orgao_id' => $this->orgao->id, 'tipo_pessoa' => 'PJ', 'documento' => '99.000.333/0001-88', 'razao_social' => 'Papelaria Vale Verde (fictícia)', 'porte' => 'ME', 'municipio' => 'Vale Verde', 'uf' => 'RR']);
        $fora = Fornecedor::create(['orgao_id' => $this->orgao->id, 'tipo_pessoa' => 'PJ', 'documento' => '99.000.444/0001-95', 'razao_social' => 'Distribuidora Rio Claro (fictícia)', 'porte' => 'DEMAIS', 'municipio' => 'Rio Claro', 'uf' => 'AM']);
        $this->contrato($this->processo(['unidade_id' => $semed->id], 'contratado')->id, $local->id, '1', 30000);
        $this->contrato($this->processo([], 'contratado')->id, $fora->id, '2', 70000);
        $this->processo(['unidade_id' => $semed->id]);
    }

    private function contrato(int $processo, int $fornecedor, string $numero, float $valor): void
    {
        Contrato::create(['orgao_id' => $this->orgao->id, 'processo_id' => $processo, 'fornecedor_id' => $fornecedor, 'gestor_id' => $this->u['gestor-contrato']->id, 'fiscal_id' => $this->u['fiscal']->id,
            'numero' => $numero, 'ano' => 2026, 'tipo' => 1, 'objeto' => 'Fornecimento de teste (fictício).', 'categoria' => 2, 'valor_inicial' => $valor, 'valor_global' => $valor,
            'assinatura' => '2026-03-02', 'vigencia_inicio' => '2026-03-02', 'vigencia_fim' => '2026-12-31', 'situacao' => 'vigente']);
    }

    public function test_painel_mostra_secretarias_perfil_e_fomento(): void
    {
        $this->actingAs($this->u['autoridade'])->get('/paineis?ano=2026')->assertOk()
            ->assertSee('SEMED')->assertSee('SEMAD')
            ->assertSee('Microempresa')->assertSee('ME e EPP: 30,0%')
            ->assertSee('Vale Verde/RR')->assertSee('R$ 30.000,00');
        $this->actingAs($this->u['requisitante'])->get('/paineis')->assertForbidden();
    }

    public function test_exporta_em_todos_os_formatos(): void
    {
        $quem = $this->u['auditor'];
        $csv = $this->actingAs($quem)->get('/paineis/fomento.csv?ano=2026')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
        $this->assertStringContainsString('"Mesmo município do órgão";1;30.000,00;30,00', $csv);

        $txt = $this->actingAs($quem)->get('/paineis/secretarias.txt?ano=2026')->assertOk()->getContent();
        $this->assertStringContainsString('Contratações por secretaria · 2026', $txt);
        $this->assertStringContainsString('SEMED', $txt);

        $this->assertStringStartsWith('%PDF', $this->actingAs($quem)->get('/paineis/fornecedores.pdf?ano=2026')->assertOk()->getContent());
        $this->actingAs($quem)->get('/paineis/fornecedores.xlsx?ano=2026')->assertOk()->assertDownload('fornecedores-2026.xlsx');
        $this->actingAs($quem)->get('/paineis/outro.csv')->assertNotFound();
    }
}
