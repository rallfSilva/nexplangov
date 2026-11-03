<?php

namespace Tests\Feature\Lei14133;

use App\Licitacao\Nexlicita\Sessao;
use App\Models\Fornecedor;
use App\Models\Impugnacao;
use App\Models\Irp;
use App\Models\ItemProcesso;
use App\Models\Lance;
use App\Models\Orgao;
use App\Models\Processo;
use App\Models\Proposta;
use App\Models\SancaoFornecedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/** NexLicita: propostas, lances, julgamento, habilitação, adjudicação, impugnações (art. 164) e IRP (art. 86). */
class NexlicitaTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
    }

    private function item(Processo $p, float $estimado = 10.0): ItemProcesso
    {
        $item = $p->itens()->create([
            'orgao_id' => $p->orgao_id, 'numero' => ((int) $p->itens()->max('numero')) + 1, 'material_servico' => 'M', 'descricao' => 'Caneta esferográfica azul',
            'unidade_medida' => 'UN', 'quantidade' => 100, 'tipo_beneficio' => 4,
        ]);
        $item->forceFill(['valor_unitario_estimado' => $estimado, 'valor_total_estimado' => $estimado * 100])->save();

        return $item;
    }

    private function forn(string $cnpj, string $porte = 'DEMAIS'): Fornecedor
    {
        $f = $this->fornecedor($cnpj);
        $f->update(['porte' => $porte]);

        return $f;
    }

    private function proposta(Processo $p, ItemProcesso $i, Fornecedor $f, float $valor)
    {
        return $this->actingAs($this->u['agente-contratacao'])->post("/processos/{$p->id}/itens/{$i->id}/propostas", ['fornecedor_id' => $f->id, 'valor' => (string) $valor]);
    }

    private function emJulgamento(array $dados = []): Processo
    {
        return $this->processo($dados, 'em_julgamento');
    }

    public function test_proposta_acima_do_orcamento_e_desclassificada(): void
    {
        $p = $this->emJulgamento();
        $i = $this->item($p, 10);

        $this->proposta($p, $i, $this->forn('99.000.333/0001-88'), 10.01)->assertSessionHas('sucesso');

        $this->assertSame('desclassificada', Proposta::first()->situacao);
        $this->assertStringContainsString('art. 59, III', Proposta::first()->motivo);
    }

    public function test_engenharia_abaixo_de_75_por_cento_e_inexequivel(): void
    {
        $p = $this->emJulgamento(['categoria' => 9, 'modalidade' => 5]);
        $i = $this->item($p, 100);

        $this->proposta($p, $i, $this->forn('99.000.333/0001-88'), 74)->assertSessionHas('sucesso');

        $this->assertStringContainsString('75%', Proposta::first()->motivo);
    }

    public function test_fornecedor_impedido_nao_registra_proposta(): void
    {
        $p = $this->emJulgamento();
        $i = $this->item($p);
        $f = $this->forn('99.000.333/0001-88');
        SancaoFornecedor::create(['orgao_id' => $this->orgao->id, 'fornecedor_id' => $f->id, 'tipo' => 'impedimento', 'inicio' => today()->subMonth(), 'fim' => today()->addYear(), 'fundamento' => 'Teste']);

        $this->proposta($p, $i, $f, 9)->assertSessionHas('motivos');
        $this->assertSame(0, Proposta::count());
    }

    public function test_proposta_duplicada_no_mesmo_item_e_recusada(): void
    {
        $p = $this->emJulgamento();
        $i = $this->item($p);
        $f = $this->forn('99.000.333/0001-88');
        $this->proposta($p, $i, $f, 9);
        $this->proposta($p, $i, $f, 8)->assertSessionHas('motivos');
        $this->assertSame(1, Proposta::count());
    }

    public function test_lance_precisa_ser_melhor_e_a_classificacao_usa_o_valor_vigente(): void
    {
        $p = $this->emJulgamento();
        $i = $this->item($p);
        $a = $this->forn('99.000.333/0001-88');
        $b = $this->forn('99.000.444/0001-44');
        $this->proposta($p, $i, $a, 9);
        $this->proposta($p, $i, $b, 8);
        [$pa, $pb] = [Proposta::where('fornecedor_id', $a->id)->first(), Proposta::where('fornecedor_id', $b->id)->first()];

        $this->actingAs($this->u['agente-contratacao'])->post("/processos/{$p->id}/propostas/{$pa->id}/lances", ['valor' => '8,50'])->assertSessionHas('motivos');
        $this->actingAs($this->u['agente-contratacao'])->post("/processos/{$p->id}/propostas/{$pa->id}/lances", ['valor' => '7,50'])->assertSessionHas('sucesso');

        $c = app(Sessao::class)->classificacao($i->refresh());
        $this->assertSame($pa->id, $c['ordem'][0]->id);
        $this->assertSame(1, Lance::count());
    }

    public function test_lance_nao_vale_em_disputa_fechada(): void
    {
        $p = $this->emJulgamento(['modo_disputa' => 2]);
        $i = $this->item($p);
        $this->proposta($p, $i, $this->forn('99.000.333/0001-88'), 9);

        $this->actingAs($this->u['agente-contratacao'])->post("/processos/{$p->id}/propostas/".Proposta::first()->id.'/lances', ['valor' => '8'])->assertSessionHas('motivos');
    }

    public function test_lance_e_imutavel(): void
    {
        $p = $this->emJulgamento();
        $i = $this->item($p);
        $this->proposta($p, $i, $this->forn('99.000.333/0001-88'), 9);
        $this->actingAs($this->u['agente-contratacao'])->post("/processos/{$p->id}/propostas/".Proposta::first()->id.'/lances', ['valor' => '8']);

        $this->expectException(LogicException::class);
        Lance::first()->update(['valor' => 1]);
    }

    public function test_adjudicacao_exige_habilitacao_e_primeira_colocada(): void
    {
        $p = $this->emJulgamento();
        $i = $this->item($p);
        $this->proposta($p, $i, $this->forn('99.000.333/0001-88'), 9);
        $this->proposta($p, $i, $this->forn('99.000.444/0001-44'), 8);
        $segunda = Proposta::where('valor', 9)->first();
        $primeira = Proposta::where('valor', 8)->first();
        $agente = $this->u['agente-contratacao'];

        $this->actingAs($agente)->post("/processos/{$p->id}/itens/{$i->id}/adjudicar", ['proposta_id' => $primeira->id])->assertSessionHas('motivos'); // sem habilitar
        $this->actingAs($agente)->post("/processos/{$p->id}/propostas/{$segunda->id}/habilitar")->assertSessionHas('sucesso');
        $this->actingAs($agente)->post("/processos/{$p->id}/itens/{$i->id}/adjudicar", ['proposta_id' => $segunda->id])->assertSessionHas('motivos'); // não é a primeira

        $this->actingAs($agente)->post("/processos/{$p->id}/propostas/{$primeira->id}/habilitar");
        $this->actingAs($agente)->post("/processos/{$p->id}/itens/{$i->id}/adjudicar", ['proposta_id' => $primeira->id])->assertSessionHas('sucesso');

        $i->refresh();
        $this->assertSame($primeira->fornecedor_id, $i->fornecedor_vencedor_id);
        $this->assertEquals(8.0, (float) $i->valor_unitario_homologado);
        $this->assertNotNull($i->adjudicado_em);
        $this->assertSame('vencedora', $primeira->refresh()->situacao);
    }

    public function test_inabilitar_a_primeira_promove_a_segunda(): void
    {
        $p = $this->emJulgamento();
        $i = $this->item($p);
        $this->proposta($p, $i, $this->forn('99.000.333/0001-88'), 9);
        $this->proposta($p, $i, $this->forn('99.000.444/0001-44'), 8);
        $primeira = Proposta::where('valor', 8)->first();
        $segunda = Proposta::where('valor', 9)->first();
        $agente = $this->u['agente-contratacao'];

        $this->actingAs($agente)->post("/processos/{$p->id}/propostas/{$primeira->id}/inabilitar", ['motivo' => 'x'])->assertSessionHas('motivos');
        $this->actingAs($agente)->post("/processos/{$p->id}/propostas/{$primeira->id}/inabilitar", ['motivo' => 'Certidão fiscal vencida e não regularizada.'])->assertSessionHas('sucesso');

        $this->assertSame($segunda->id, app(Sessao::class)->classificacao($i->refresh())['ordem'][0]->id);
    }

    public function test_empate_exige_criterio_de_desempate_do_art_60(): void
    {
        $p = $this->emJulgamento();
        $i = $this->item($p);
        $this->proposta($p, $i, $this->forn('99.000.333/0001-88'), 8);
        $this->proposta($p, $i, $this->forn('99.000.444/0001-44'), 8);
        $a = Proposta::orderBy('id')->first();
        $agente = $this->u['agente-contratacao'];
        $this->actingAs($agente)->post("/processos/{$p->id}/propostas/{$a->id}/habilitar");

        $this->actingAs($agente)->post("/processos/{$p->id}/itens/{$i->id}/adjudicar", ['proposta_id' => $a->id])->assertSessionHas('motivos', fn ($m) => collect($m)->contains(fn ($x) => str_contains($x, 'art. 60')));

        $this->actingAs($agente)->post("/processos/{$p->id}/propostas/{$a->id}/desempate", ['criterio' => 'desempenho_contratual', 'justificativa' => 'Histórico de execução sem ocorrências.'])->assertSessionHas('sucesso');
        $this->actingAs($agente)->post("/processos/{$p->id}/itens/{$i->id}/adjudicar", ['proposta_id' => $a->id])->assertSessionHas('sucesso');
    }

    public function test_empate_ficto_da_me_epp_exige_tratamento_antes_de_adjudicar(): void
    {
        $p = $this->emJulgamento(['modalidade' => 5]);
        $i = $this->item($p, 200);
        $grande = $this->forn('99.000.333/0001-88');
        $micro = $this->forn('99.000.444/0001-44', 'ME');
        $this->proposta($p, $i, $grande, 100);
        $this->proposta($p, $i, $micro, 108);
        $pg = Proposta::where('fornecedor_id', $grande->id)->first();
        $agente = $this->u['agente-contratacao'];
        $this->actingAs($agente)->post("/processos/{$p->id}/propostas/{$pg->id}/habilitar");

        $this->actingAs($agente)->post("/processos/{$p->id}/itens/{$i->id}/adjudicar", ['proposta_id' => $pg->id])->assertSessionHas('motivos');
        $this->actingAs($agente)->post("/processos/{$p->id}/itens/{$i->id}/adjudicar", ['proposta_id' => $pg->id, 'empate_ficto' => 'ME convocada, não cobriu a proposta.'])->assertSessionHas('sucesso');
    }

    public function test_adjudicacao_bloqueia_fornecedor_que_ficou_impedido_depois(): void
    {
        $p = $this->emJulgamento();
        $i = $this->item($p);
        $f = $this->forn('99.000.333/0001-88');
        $this->proposta($p, $i, $f, 8);
        $pr = Proposta::first();
        $this->actingAs($this->u['agente-contratacao'])->post("/processos/{$p->id}/propostas/{$pr->id}/habilitar");
        SancaoFornecedor::create(['orgao_id' => $this->orgao->id, 'fornecedor_id' => $f->id, 'tipo' => 'inidoneidade', 'inicio' => today(), 'fim' => null, 'fundamento' => 'Teste']);

        $this->actingAs($this->u['agente-contratacao'])->post("/processos/{$p->id}/itens/{$i->id}/adjudicar", ['proposta_id' => $pr->id])->assertSessionHas('motivos');
    }

    public function test_homologacao_exige_adjudicar_os_itens_com_propostas(): void
    {
        $p = $this->emJulgamento();
        $i = $this->item($p);
        $this->proposta($p, $i, $this->forn('99.000.333/0001-88'), 8);

        $this->actingAs($this->u['autoridade'])->post("/processos/{$p->id}/tramitar", ['destino' => 'homologado'])->assertSessionHas('motivos', fn ($m) => collect($m)->contains(fn ($x) => str_contains($x, 'Adjudique')));
    }

    public function test_revogar_adjudicacao_libera_o_item(): void
    {
        $p = $this->emJulgamento();
        $i = $this->item($p);
        $this->proposta($p, $i, $this->forn('99.000.333/0001-88'), 8);
        $pr = Proposta::first();
        $a = $this->u['agente-contratacao'];
        $this->actingAs($a)->post("/processos/{$p->id}/propostas/{$pr->id}/habilitar");
        $this->actingAs($a)->post("/processos/{$p->id}/itens/{$i->id}/adjudicar", ['proposta_id' => $pr->id]);

        $this->actingAs($a)->post("/processos/{$p->id}/itens/{$i->id}/revogar-adjudicacao", ['motivo' => 'Erro'])->assertSessionHas('motivos');
        $this->actingAs($a)->post("/processos/{$p->id}/itens/{$i->id}/revogar-adjudicacao", ['motivo' => 'Documento apresentado era de outra empresa.'])->assertSessionHas('sucesso');

        $this->assertNull($i->refresh()->adjudicado_em);
        $this->assertNull($i->fornecedor_vencedor_id);
    }

    public function test_so_quem_conduz_registra_proposta_e_contratacao_direta_nao_tem_sessao(): void
    {
        $p = $this->emJulgamento();
        $i = $this->item($p);
        $f = $this->forn('99.000.333/0001-88');

        $this->actingAs($this->u['requisitante'])->post("/processos/{$p->id}/itens/{$i->id}/propostas", ['fornecedor_id' => $f->id, 'valor' => '9'])->assertForbidden();
        $this->actingAs($this->u['juridico'])->post("/processos/{$p->id}/itens/{$i->id}/propostas", ['fornecedor_id' => $f->id, 'valor' => '9'])->assertForbidden();

        $direta = $this->processo(['modalidade' => 8, 'amparo_legal' => 2, 'modo_disputa' => 5, 'criterio_julgamento' => 7], 'publicado');
        $this->actingAs($this->u['agente-contratacao'])->get("/processos/{$direta->id}/licitacao")->assertNotFound();
    }

    public function test_sessao_e_isolada_por_orgao(): void
    {
        $p = $this->emJulgamento();
        $outro = $this->usuario('agente-contratacao', Orgao::factory()->create(['sigla' => 'OUT', 'cnpj' => '11.222.333/0002-62']));

        $this->actingAs($outro)->get("/processos/{$p->id}/licitacao")->assertNotFound();
    }

    public function test_tela_da_sessao_abre(): void
    {
        $p = $this->emJulgamento();
        $i = $this->item($p);
        $this->proposta($p, $i, $this->forn('99.000.333/0001-88'), 8);
        $this->proposta($p, $i, $this->forn('99.000.444/0001-44', 'EPP'), 8);

        $this->actingAs($this->u['agente-contratacao'])->get("/processos/{$p->id}/licitacao")->assertOk()->assertSee('empatadas')->assertSee('Registrar proposta');
        $this->actingAs($this->u['agente-contratacao'])->get("/processos/{$p->id}?aba=resultado")->assertOk()->assertSee('Sessão de julgamento');
    }

    // Impugnações (art. 164)

    public function test_pedido_publico_no_prazo_e_registrado_com_prazo_de_resposta(): void
    {
        $p = $this->processo(['abertura_propostas_em' => now()->addDays(30)->setTime(9, 0)], 'publicado');

        $this->get("/editais/{$p->id}")->assertOk()->assertSee('Pedir esclarecimento');
        $this->post("/editais/{$p->id}/pedidos", ['tipo' => 'impugnacao', 'autor_nome' => 'Empresa Teste (fictícia)', 'autor_email' => 'contato@exemplo.com.br', 'texto' => 'O item 3 restringe a competição sem justificativa.'])->assertSessionHas('sucesso');

        $i = Impugnacao::firstOrFail();
        $this->assertTrue($i->tempestiva);
        $this->assertSame('publico', $i->canal);
        $this->assertNotNull($i->prazo_resposta_em);
        // O e-mail fica cifrado no banco.
        $this->assertStringNotContainsString('contato@exemplo.com.br', (string) \DB::table('impugnacoes')->value('autor_email'));
    }

    public function test_pedido_fora_do_prazo_fica_marcado_como_intempestivo(): void
    {
        $p = $this->processo(['abertura_propostas_em' => now()->addDay()->setTime(9, 0)], 'publicado');
        $this->post("/editais/{$p->id}/pedidos", ['tipo' => 'esclarecimento', 'autor_nome' => 'Fulano (fictício)', 'autor_email' => 'f@exemplo.com.br', 'texto' => 'Qual o local de entrega dos materiais?']);

        $this->assertFalse(Impugnacao::firstOrFail()->tempestiva);
    }

    public function test_pagina_publica_so_para_edital_publicado_e_robo_e_ignorado(): void
    {
        $rascunho = $this->processo();
        $this->get("/editais/{$rascunho->id}")->assertNotFound();
        $this->post("/editais/{$rascunho->id}/pedidos", ['tipo' => 'impugnacao', 'autor_nome' => 'X', 'autor_email' => 'x@exemplo.com.br', 'texto' => 'texto longo o bastante'])->assertNotFound();

        $p = $this->processo([], 'publicado');
        $this->post("/editais/{$p->id}/pedidos", ['tipo' => 'impugnacao', 'autor_nome' => 'Robô', 'autor_email' => 'r@exemplo.com.br', 'texto' => 'texto longo o bastante', 'site' => 'http://spam'])->assertSessionHas('sucesso');
        $this->assertSame(0, Impugnacao::count());
    }

    public function test_resposta_e_divulgada_na_pagina_publica_sem_expor_dados_do_autor(): void
    {
        $p = $this->processo(['abertura_propostas_em' => now()->addDays(30)->setTime(9, 0)], 'publicado');
        $this->post("/editais/{$p->id}/pedidos", ['tipo' => 'esclarecimento', 'autor_nome' => 'Maria (fictícia)', 'autor_email' => 'maria@exemplo.com.br', 'texto' => 'O prazo de entrega é contado em dias corridos?']);
        $i = Impugnacao::firstOrFail();

        $this->actingAs($this->u['agente-contratacao'])->post("/processos/{$p->id}/impugnacoes/{$i->id}/responder", ['resultado' => 'improcedente', 'resposta' => 'Dias corridos, conforme o item 8 do termo de referência.'])->assertSessionHas('sucesso');
        $this->actingAs($this->u['agente-contratacao'])->post("/processos/{$p->id}/impugnacoes/{$i->id}/responder", ['resultado' => 'improcedente', 'resposta' => 'Outra resposta qualquer aqui.'])->assertSessionHas('motivos');

        auth()->logout();
        $this->get("/editais/{$p->id}")->assertOk()->assertSee('dias corridos', false)->assertDontSee('maria@exemplo.com.br')->assertDontSee('Maria (fictícia)');
    }

    public function test_impugnacao_de_outro_orgao_nao_e_acessivel(): void
    {
        $p = $this->processo(['abertura_propostas_em' => now()->addDays(30)], 'publicado');
        $outro = $this->usuario('agente-contratacao', Orgao::factory()->create(['sigla' => 'OUT', 'cnpj' => '11.222.333/0002-62']));
        $this->actingAs($outro)->get("/processos/{$p->id}/impugnacoes")->assertNotFound();
    }

    // IRP (art. 86)

    private function abrirIrp(array $d = []): Irp
    {
        $this->actingAs($this->u['planejamento'])->post('/irp', $d + ['objeto' => 'Material de expediente', 'manifestacao_ate' => now()->addDays(20)->toDateString()])->assertSessionHasNoErrors();

        return Irp::firstOrFail();
    }

    public function test_irp_exige_prazo_minimo_de_manifestacao(): void
    {
        $this->actingAs($this->u['planejamento'])->post('/irp', ['objeto' => 'Material', 'manifestacao_ate' => now()->addDay()->toDateString()])->assertSessionHas('motivos');
        $this->assertSame(0, Irp::count());

        $irp = $this->abrirIrp();
        $this->assertSame(now()->year.'-001', $irp->ano.'-'.$irp->numero);
    }

    public function test_manifestar_aceitar_e_consolidar_somando_quantidades(): void
    {
        $irp = $this->abrirIrp();
        $req = $this->u['requisitante'];

        foreach ([['SEMED', 'Caneta esferográfica azul', '100'], ['SEMUS', 'caneta esferografica azul', '50'], ['SEMAD', 'Papel A4', '10']] as [$part, $desc, $q]) {
            $this->actingAs($req)->post("/irp/{$irp->id}/manifestacoes", ['participante' => $part, 'descricao' => $desc, 'unidade_medida' => 'UN', 'quantidade' => $q])->assertSessionHas('sucesso');
        }
        $this->assertSame(3, $irp->manifestacoes()->count());

        $this->actingAs($req)->post("/irp/{$irp->id}/manifestacoes/".$irp->manifestacoes()->first()->id.'/decidir', ['acao' => 'aceitar'])->assertForbidden();

        foreach ($irp->manifestacoes as $m) {
            $this->actingAs($this->u['planejamento'])->post("/irp/{$irp->id}/manifestacoes/{$m->id}/decidir", ['acao' => 'aceitar'])->assertSessionHas('sucesso');
        }

        $p = $this->processo();
        $this->actingAs($this->u['planejamento'])->post("/irp/{$irp->id}/consolidar", ['processo_id' => $p->id])->assertRedirect();

        $this->assertSame(2, $p->itens()->count());
        $this->assertEquals(150.0, (float) $p->itens()->where('descricao', 'Caneta esferográfica azul')->value('quantidade'));
        $this->assertTrue($p->refresh()->srp);
        $this->assertSame('consolidada', $irp->refresh()->situacao);
    }

    public function test_recusar_manifestacao_exige_motivo_e_recusada_nao_entra_na_consolidacao(): void
    {
        $irp = $this->abrirIrp();
        $this->actingAs($this->u['requisitante'])->post("/irp/{$irp->id}/manifestacoes", ['participante' => 'SEMED', 'descricao' => 'Item', 'unidade_medida' => 'UN', 'quantidade' => '5']);
        $m = $irp->manifestacoes()->first();
        $pl = $this->u['planejamento'];

        $this->actingAs($pl)->post("/irp/{$irp->id}/manifestacoes/{$m->id}/decidir", ['acao' => 'recusar'])->assertSessionHas('motivos');
        $this->actingAs($pl)->post("/irp/{$irp->id}/manifestacoes/{$m->id}/decidir", ['acao' => 'recusar', 'motivo' => 'Quantidade incompatível com o consumo.'])->assertSessionHas('sucesso');
        $this->actingAs($pl)->post("/irp/{$irp->id}/consolidar", ['processo_id' => $this->processo()->id])->assertSessionHas('motivos');
    }

    public function test_irp_encerrada_nao_recebe_manifestacao(): void
    {
        $irp = $this->abrirIrp();
        $this->actingAs($this->u['planejamento'])->post("/irp/{$irp->id}/encerrar")->assertSessionHas('sucesso');

        $this->actingAs($this->u['requisitante'])->post("/irp/{$irp->id}/manifestacoes", ['participante' => 'SEMED', 'descricao' => 'Item', 'unidade_medida' => 'UN', 'quantidade' => '5'])->assertSessionHas('motivos');
    }

    public function test_telas_de_irp_abrem_e_respeitam_o_orgao(): void
    {
        $irp = $this->abrirIrp();
        $this->actingAs($this->u['requisitante'])->get('/irp')->assertOk()->assertSee($irp->identificacao());
        $this->actingAs($this->u['requisitante'])->get("/irp/{$irp->id}")->assertOk()->assertSee('Manifestar necessidade');
        $this->actingAs($this->u['auditor'])->post('/irp', ['objeto' => 'x', 'manifestacao_ate' => now()->addDays(30)->toDateString()])->assertForbidden();

        $outro = $this->usuario('planejamento', Orgao::factory()->create(['sigla' => 'OUT', 'cnpj' => '11.222.333/0002-62']));
        $this->actingAs($outro)->get("/irp/{$irp->id}")->assertNotFound();
    }
}
