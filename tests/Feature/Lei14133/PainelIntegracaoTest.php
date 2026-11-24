<?php

namespace Tests\Feature\Lei14133;

use App\Integracoes\Painel\MapeadorPainel;
use App\Models\Contrato;
use App\Models\Processo;
use App\Models\TermoContrato;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Envio automático ao Painel de Compras (SIC/SELC) pela API de entrada dele. Tudo com dados fictícios. */
class PainelIntegracaoTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
    }

    private function ligar(): void
    {
        config(['nexplangov.painel.url' => 'https://painel.teste', 'nexplangov.painel.token' => '1|token-ficticio']);
    }

    /** Processo de pregão homologado, com um item vencido por fornecedor fictício. */
    private function processoHomologado(): Processo
    {
        $p = $this->processo(['srp' => true], 'homologado');
        $p->itens()->create([
            'orgao_id' => $p->orgao_id, 'numero' => 1, 'material_servico' => 'M', 'codigo_catalogo' => '150587', 'descricao' => 'Papel A4, resma',
            'unidade_medida' => 'RESMA', 'quantidade' => 100, 'tipo_beneficio' => 1, 'situacao' => 'homologado',
            'fornecedor_vencedor_id' => $this->fornecedor()->id, 'valor_unitario_homologado' => 25.5,
        ]);

        return $p->refresh();
    }

    private function contrato(Processo $p): Contrato
    {
        return Contrato::create([
            'orgao_id' => $p->orgao_id, 'processo_id' => $p->id, 'fornecedor_id' => $p->itens()->first()->fornecedor_vencedor_id,
            'gestor_id' => $this->u['gestor-contrato']->id, 'fiscal_id' => $this->u['fiscal']->id, 'numero' => '010', 'ano' => 2026, 'tipo' => 1,
            'objeto' => 'Fornecimento de papel A4', 'categoria' => 2, 'valor_inicial' => 2550, 'valor_global' => 2550,
            'assinatura' => '2026-04-10', 'vigencia_inicio' => '2026-04-10', 'vigencia_fim' => '2027-04-09', 'situacao' => 'vigente',
        ]);
    }

    /** @return array<string, array> tipo → corpo do último lote enviado desse tipo */
    private static function enviados(): array
    {
        $r = [];
        foreach (Http::recorded() as [$req]) {
            $r[$req['tipo']] = $req->data();
        }

        return $r;
    }

    public function test_desligado_sem_endereco_e_token_nada_e_enviado(): void
    {
        Http::fake();
        $this->contrato($this->processoHomologado());

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('envios_painel')->count());
    }

    public function test_contrato_vai_com_unidade_fornecedor_e_processo_na_ordem_e_com_os_codigos_do_painel(): void
    {
        $c = $this->contrato($this->processoHomologado()); // ainda desligado: nada sai
        $this->ligar();
        Http::fake(['painel.teste/*' => Http::response(['id' => 8, 'situacao' => 'NA_FILA'], 202)]);

        $c->touch(); // salvar o contrato agenda o envio (a fila é síncrona nos testes)
        Http::assertSent(fn (Request $r) => $r->url() === 'https://painel.teste/api/integracao/v1/lotes'
            && $r->hasHeader('Authorization', 'Bearer 1|token-ficticio') && str_starts_with($r->header('Idempotency-Key')[0], 'nexplangov-'));

        $tipos = array_map(fn ($x) => $x[0]['tipo'], Http::recorded()->all());
        $this->assertSame(['UNIDADES', 'FORNECEDORES', 'PROCESSOS', 'CONTRATOS'], array_values(array_unique($tipos)));

        $e = self::enviados();
        $this->assertSame(['orgao_sigla' => 'PMX', 'orgao_nome' => $this->orgao->nome, 'orgao_cnpj' => '11222333000181', 'unidade_codigo' => 'PMX-SEMAD',
            'unidade_nome' => 'PMX - '.$this->unidade->nome], $e['UNIDADES']['registros'][0]);
        $proc = $e['PROCESSOS']['registros'][0];
        $this->assertSame(['PREGAO', 'PREGAO_ELETRONICO', 'PREGAO_14133', 'L14133_ART28_I', 'L14133', true, 'Homologado', 'Exclusivo', 'PMX-SEMAD'],
            [$proc['sistematica'], $proc['forma'], $proc['modalidade'], $proc['amparo'], $proc['regime'], $proc['registro_precos'], $proc['situacao'], $proc['beneficio_mpe'], $proc['unidade']]);
        $this->assertEquals([2550, '99000333000188'], [$proc['valor_total_homologado'], $proc['fornecedor_documento']]);
        $ct = $e['CONTRATOS']['registros'][0];
        $this->assertSame(['010/2026', $proc['numero_publicacao'], 'Vigente', '2027-04-09', $this->u['gestor-contrato']->name],
            [$ct['numero'], $ct['numero_publicacao'], $ct['situacao'], $ct['vigencia_fim'], $ct['gestor']]);
        $this->assertSame(8, (int) DB::table('envios_painel')->where('tipo', 'CONTRATOS')->latest('id')->value('lote_painel_id'));
    }

    public function test_aditivo_de_prazo_e_valor_vira_misto(): void
    {
        $c = $this->contrato($this->processoHomologado());
        $t = TermoContrato::create(['orgao_id' => $c->orgao_id, 'contrato_id' => $c->id, 'tipo' => 'aditivo_valor', 'numero' => '1', 'assinatura' => '2026-08-15',
            'valor_acrescimo' => 637.5, 'nova_vigencia_fim' => '2028-04-09', 'justificativa' => 'Acréscimo e prorrogação de teste.']);

        $lotes = MapeadorPainel::para($t);
        $this->assertSame(['UNIDADES', 'FORNECEDORES', 'PROCESSOS', 'CONTRATOS', 'ADITIVOS'], array_keys($lotes));
        $this->assertSame(['contrato' => '010/2026', 'unidade' => 'PMX-SEMAD', 'numero' => '1', 'tipo' => 'Misto', 'valor' => 637.5, 'nova_vigencia_fim' => '2028-04-09',
            'data_assinatura' => '2026-08-15', 'justificativa' => 'Acréscimo e prorrogação de teste.'], $lotes['ADITIVOS'][0]);
    }

    public function test_recusa_do_painel_fica_registrada(): void
    {
        $this->ligar();
        Http::fake(['painel.teste/*' => Http::response(['message' => 'Dados inválidos.', 'errors' => ['registros' => ['Campos desconhecidos: x.']]], 422)]);
        $this->processoHomologado();
        $erro = DB::table('envios_painel')->where('estado', 'erro')->first();
        $this->assertNotNull($erro);
        $this->assertStringContainsString('Campos desconhecidos: x.', $erro->erro);
    }

    public function test_queda_do_painel_para_o_envio_e_volta_para_a_fila(): void
    {
        $this->ligar();
        Http::fake(['painel.teste/*' => Http::response([], 503)]);
        $this->processo();
        // Para no primeiro tipo (os seguintes dependem dele) e registra a indisponibilidade.
        $this->assertSame([['UNIDADES', 'indisponivel']], DB::table('envios_painel')->get(['tipo', 'estado'])->map(fn ($r) => [$r->tipo, $r->estado])->unique()->values()->all());
    }

    public function test_comando_de_carga_inicial(): void
    {
        $this->contrato($this->processoHomologado());
        $this->artisan('painel:enviar', ['--simular' => true])->expectsOutputToContain('PMX: UNIDADES 2 · FORNECEDORES 1 · PROCESSOS 1 · CONTRATOS 1')->assertSuccessful();
        $this->artisan('painel:enviar')->expectsOutputToContain('Configure PAINEL_URL')->assertFailed();

        $this->ligar();
        Http::fake(['painel.teste/*' => Http::response(['id' => 1], 202)]);
        $this->artisan('painel:enviar', ['--orgao' => 'PMX'])->expectsOutputToContain('CONTRATOS: lote 1 na fila do painel')->assertSuccessful();
        Http::assertSent(fn (Request $r) => $r['tipo'] === 'CONTRATOS' && $r['referencia'] === 'Carga inicial PMX');
    }
}
