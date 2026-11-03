<?php

namespace Tests\Feature\Lei14133;

use App\Models\Ata;
use App\Models\Contrato;
use App\Models\EnvioPncp;
use App\Models\ItemPca;
use App\Models\PlanoContratacao;
use App\Models\Processo;
use App\Pncp\NumeroControle;
use App\Pncp\PncpCliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Integração com o PNCP além da compra: resultado por item, ata e PDF da ata, PCA, contrato assinado,
 * exclusão com justificativa e o comando de homologação. Tudo com respostas simuladas da API (Http::fake).
 */
class PncpIntegracaoTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    private const BASE = 'https://treina.pncp.gov.br/api/pncp/v1/orgaos/11222333000181';

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        config(['nexplangov.pncp.login' => 'plataforma', 'nexplangov.pncp.senha' => 'segredo-de-teste-123']);
        Storage::fake('local');
    }

    private function fakePncp(array $extra = []): void
    {
        Http::fake($extra + [
            '*/v1/usuarios/login' => Http::response(null, 200, ['Authorization' => 'Bearer t']),
            '*' => Http::response([], 200),
        ]);
    }

    /** Processo SRP publicado no PNCP, homologável, com um item vencido. */
    private function processoPublicado(string $situacao = 'em_julgamento'): Processo
    {
        $p = $this->processo(['srp' => true], $situacao);
        $p->forceFill(['numero_controle_pncp' => '2026/15'])->save();
        $f = $this->fornecedor();
        $p->itens()->create([
            'orgao_id' => $p->orgao_id, 'numero' => 1, 'material_servico' => 'M', 'descricao' => 'Papel A4', 'unidade_medida' => 'RESMA', 'quantidade' => 100,
            'tipo_beneficio' => 4, 'situacao' => 'homologado', 'fornecedor_vencedor_id' => $f->id, 'valor_unitario_homologado' => 22.5,
        ])->forceFill(['valor_unitario_estimado' => 25, 'valor_total_estimado' => 2500])->save();

        return $p;
    }

    public function test_numero_da_ata_e_do_resultado_vem_do_fim_do_location(): void
    {
        $this->assertSame(3, NumeroControle::ultimoSequencial(self::BASE.'/compras/2026/15/atas/3'));
        $this->assertSame(1, NumeroControle::ultimoSequencial(self::BASE.'/compras/2026/15/itens/1/resultados/1/'));
        $this->assertNull(NumeroControle::ultimoSequencial(null));
        $titulo = PncpCliente::tituloDocumento('Aviso de contratação direta · Dispensa 0012/2026 da Secretaria de Administração');
        $this->assertSame('Aviso de contratacao direta - Dispensa 0012/2026 d', $titulo);
        $this->assertSame(50, strlen($titulo));
    }

    public function test_resultado_de_cada_item_vai_ao_pncp_na_homologacao(): void
    {
        $this->fakePncp([
            '*/compras/2026/15/itens/1/resultados' => Http::response([], 201, ['location' => self::BASE.'/compras/2026/15/itens/1/resultados/1']),
        ]);
        $p = $this->processoPublicado('homologado');

        $this->actingAs($this->u['agente-contratacao'])->post(route('pncp.resultados', $p))->assertSessionHas('sucesso');

        $envio = EnvioPncp::where('recurso', 'resultado')->firstOrFail();
        $this->assertSame('publicado', $envio->estado);
        $this->assertEquals(2250.0, $envio->payload['valorTotalHomologado']);
        $this->assertEquals(10.0, $envio->payload['percentualDesconto']);
        $this->assertSame('99000333000188', $envio->payload['niFornecedor']);
        $this->assertSame('1', $p->itens()->first()->sequencial_resultado_pncp);

        // Segundo envio do mesmo item vira retificação no sequencial guardado.
        $this->actingAs($this->u['agente-contratacao'])->post(route('pncp.resultados', $p));
        $ret = EnvioPncp::where('recurso', 'resultado')->latest('id')->first();
        $this->assertSame(['PUT', '/v1/orgaos/11222333000181/compras/2026/15/itens/1/resultados/1'], [$ret->metodo, $ret->rota]);
    }

    public function test_resultado_sem_compra_publicada_fica_com_pendencia(): void
    {
        $this->fakePncp();
        $p = $this->processoPublicado('homologado');
        $p->forceFill(['numero_controle_pncp' => null])->save();

        $this->actingAs($this->u['agente-contratacao'])->post(route('pncp.resultados', $p))->assertSessionHas('erro');
        $this->assertStringContainsString('compra precisa estar publicada', EnvioPncp::firstOrFail()->erro);
        Http::assertNothingSent();
    }

    public function test_ata_publicada_guarda_o_numero_e_envia_o_pdf_em_seguida(): void
    {
        $this->fakePncp([
            '*/compras/2026/15/atas' => Http::response([], 201, ['location' => self::BASE.'/compras/2026/15/atas/4']),
            '*/compras/2026/15/atas/4/arquivos' => Http::response([], 201),
        ]);
        $p = $this->processoPublicado('homologado');
        $this->actingAs($this->u['gestor-contrato'])->post('/atas', ['processo_id' => $p->id, 'assinatura' => today()->toDateString(), 'vigencia_inicio' => today()->toDateString(), 'vigencia_fim' => today()->addYear()->subDay()->toDateString()])->assertSessionHas('sucesso');
        $ata = Ata::firstOrFail();

        $this->actingAs($this->u['agente-contratacao'])->post(route('pncp.ata', $ata))->assertSessionHas('sucesso');

        $this->assertSame('4', $ata->fresh()->numero_controle_pncp);
        $arquivo = EnvioPncp::where('recurso', 'arquivo_ata')->firstOrFail();
        $this->assertSame('publicado', $arquivo->estado);
        $this->assertSame(11, $arquivo->tipo_documento_id);
        Storage::disk('local')->assertExists($arquivo->arquivo_caminho);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($arquivo->arquivo_caminho));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/atas/4/arquivos') && $r->isMultipart()
            && collect($r->data())->pluck('name')->all() === ['arquivo'] && $r->hasHeader('Tipo-Documento-Id', '11'));
    }

    public function test_pca_so_vai_ao_pncp_depois_de_aprovado(): void
    {
        $this->fakePncp(['*/v1/orgaos/11222333000181/pca' => Http::response([], 201, ['location' => self::BASE.'/pca/2027/2'])]);
        $plano = PlanoContratacao::create(['orgao_id' => $this->orgao->id, 'ano' => 2027, 'situacao' => 'em_aprovacao']);
        ItemPca::create(['orgao_id' => $this->orgao->id, 'plano_contratacao_id' => $plano->id, 'unidade_id' => $this->unidade->id, 'numero' => 1, 'material_servico' => 'M', 'categoria' => 1,
            'descricao' => 'Papel A4', 'unidade_fornecimento' => 'RESMA', 'quantidade' => 500, 'valor_unitario_estimado' => 25, 'data_desejada' => '2027-03-01', 'prioridade' => 2]);

        $this->actingAs($this->u['agente-contratacao'])->post(route('pncp.pca', $plano))->assertSessionHas('erro');
        $this->assertSame(0, EnvioPncp::count());

        $plano->forceFill(['situacao' => 'aprovado'])->save();
        $this->actingAs($this->u['agente-contratacao'])->post(route('pncp.pca', $plano))->assertSessionHas('sucesso');

        $envio = EnvioPncp::firstOrFail();
        $this->assertSame('publicado', $envio->estado);
        $this->assertSame(2027, $envio->payload['anoPca']);
        $this->assertEquals(12500.0, $envio->payload['itensPlano'][0]['valorTotal']);
        $this->assertSame('2027/2', $plano->fresh()->numero_controle_pncp);
    }

    private function contrato(): Contrato
    {
        return Contrato::create([
            'orgao_id' => $this->orgao->id, 'processo_id' => $this->processoPublicado('homologado')->id, 'fornecedor_id' => $this->fornecedor('99.000.444/0001-44')->id, 'gestor_id' => $this->u['gestor-contrato']->id, 'fiscal_id' => $this->u['fiscal']->id,
            'numero' => '12', 'ano' => 2026, 'tipo' => array_key_first(Contrato::TIPOS), 'objeto' => 'Fornecimento de papel A4 para as secretarias.', 'categoria' => 1,
            'valor_inicial' => 2250, 'valor_global' => 2250, 'assinatura' => today(), 'vigencia_inicio' => today(), 'vigencia_fim' => today()->addYear(), 'situacao' => 'vigente',
        ]);
    }

    public function test_contrato_assinado_so_aceita_pdf_e_guarda_o_hash(): void
    {
        $c = $this->contrato();

        $this->actingAs($this->u['agente-contratacao'])->post(route('pncp.contrato.arquivo', $c), ['arquivo' => UploadedFile::fake()->create('contrato.docx', 10)])->assertSessionHasErrors('arquivo');

        $pdf = UploadedFile::fake()->createWithContent('contrato-12-2026.pdf', "%PDF-1.4\nassinado\n");
        $this->actingAs($this->u['agente-contratacao'])->post(route('pncp.contrato.arquivo', $c), ['arquivo' => $pdf])->assertSessionHas('sucesso');

        $c->refresh();
        Storage::disk('local')->assertExists($c->arquivo_caminho);
        $this->assertSame(hash('sha256', "%PDF-1.4\nassinado\n"), $c->arquivo_hash);
        $this->assertSame('contrato-12-2026.pdf', $c->arquivo_nome);
        $this->assertSame(0, EnvioPncp::count()); // sem número no PNCP ainda: o arquivo espera a publicação

        $this->actingAs($this->u['auditor'])->get(route('contratos.assinado', $c))->assertOk();
    }

    public function test_publicar_contrato_com_arquivo_envia_o_pdf_assinado_depois(): void
    {
        $this->fakePncp([
            '*/v1/orgaos/11222333000181/contratos' => Http::response([], 201, ['location' => self::BASE.'/contratos/2026/8']),
            '*/contratos/2026/8/arquivos' => Http::response([], 201),
        ]);
        $c = $this->contrato();
        $this->actingAs($this->u['agente-contratacao'])->post(route('pncp.contrato.arquivo', $c), ['arquivo' => UploadedFile::fake()->createWithContent('c.pdf', '%PDF-1.4 x')]);

        $this->actingAs($this->u['agente-contratacao'])->post(route('pncp.contrato', $c));

        $this->assertSame('2026/8', $c->fresh()->numero_controle_pncp);
        $arquivo = EnvioPncp::where('recurso', 'arquivo_contrato')->firstOrFail();
        $this->assertSame(['publicado', 12], [$arquivo->estado, $arquivo->tipo_documento_id]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/contratos/2026/8/arquivos') && $r->isMultipart());
    }

    public function test_exclusao_exige_justificativa_e_a_envia_ao_pncp(): void
    {
        $this->fakePncp(['*/v1/orgaos/11222333000181/contratos' => Http::response([], 201, ['location' => self::BASE.'/contratos/2026/8'])]);
        $c = $this->contrato();
        $this->actingAs($this->u['agente-contratacao'])->post(route('pncp.contrato', $c));
        $publicado = EnvioPncp::firstOrFail();

        $this->actingAs($this->u['agente-contratacao'])->post(route('pncp.excluir', $publicado), ['justificativa' => 'x'])->assertSessionHasErrors('justificativa');
        $this->actingAs($this->u['agente-contratacao'])->post(route('pncp.excluir', $publicado), ['justificativa' => 'Contrato cadastrado em duplicidade por engano.'])->assertSessionHas('sucesso');

        $exclusao = EnvioPncp::where('operacao', 'excluir')->firstOrFail();
        $this->assertSame(['DELETE', '/v1/orgaos/11222333000181/contratos/2026/8'], [$exclusao->metodo, $exclusao->rota]);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r['justificativa'] === 'Contrato cadastrado em duplicidade por engano.');
    }

    public function test_quem_nao_envia_ao_pncp_nao_publica_nem_anexa(): void
    {
        $c = $this->contrato();
        $this->actingAs($this->u['requisitante'])->post(route('pncp.contrato.arquivo', $c), ['arquivo' => UploadedFile::fake()->createWithContent('c.pdf', '%PDF')])->assertForbidden();
        $this->actingAs($this->u['auditor'])->post(route('pncp.resultados', $c->processo))->assertForbidden();
    }

    public function test_comando_de_homologacao_confere_orgao_e_unidades(): void
    {
        $this->fakePncp([
            '*/v1/orgaos/11222333000181/unidades' => Http::response([['codigoUnidade' => '1', 'nomeUnidade' => 'SEMAD']]),
            '*/v1/orgaos/11222333000181' => Http::response(['razaoSocial' => 'Município Fictício']),
        ]);
        $this->orgao->forceFill(['pncp_ambiente' => 'homologacao'])->save();

        $this->artisan('nexplangov:pncp-homologar', ['--orgao' => 'PMX'])
            ->expectsOutputToContain('Conferência concluída')
            ->assertSuccessful();
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET' && ! str_ends_with($r->url(), '/usuarios/login'));
    }

    public function test_comando_aponta_unidade_que_falta_no_pncp(): void
    {
        $this->fakePncp([
            '*/v1/orgaos/11222333000181/unidades' => Http::response([['codigoUnidade' => '999']]),
            '*/v1/orgaos/11222333000181' => Http::response(['razaoSocial' => 'Município Fictício']),
        ]);

        $this->artisan('nexplangov:pncp-homologar', ['--orgao' => 'PMX'])->assertFailed();
    }

    public function test_ciclo_de_teste_nunca_roda_em_producao(): void
    {
        $this->fakePncp([
            '*/v1/orgaos/11222333000181/unidades' => Http::response([['codigoUnidade' => '1']]),
            '*/v1/orgaos/11222333000181' => Http::response(['razaoSocial' => 'Município Fictício']),
        ]);
        $this->orgao->forceFill(['pncp_ambiente' => 'producao'])->save();
        $p = $this->processo();

        $this->artisan('nexplangov:pncp-homologar', ['--orgao' => 'PMX', '--ciclo' => true, '--processo' => $p->id])->assertFailed();
        $this->assertSame(0, EnvioPncp::count());
    }
}
