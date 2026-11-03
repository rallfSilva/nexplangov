<?php

namespace Tests\Feature\Lei14133;

use App\Models\Fornecedor;
use App\Models\ItemProcesso;
use App\Models\Processo;
use App\Models\Proposta;
use App\Models\Recurso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sessão pública (ata e gravação, art. 17, §§ 2º e 5º) e fase recursal (arts. 165 a 168).
 * Datas fixas para os prazos em dias úteis: sessão na segunda 09/11/2026.
 */
class RecursosSessaoTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    private Processo $p;

    private ItemProcesso $item;

    private Fornecedor $f1;

    private Fornecedor $f2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 11, 9)->setTime(10, 0));
    }

    /** Pregão com duas propostas, a primeira habilitada. */
    private function sessao(int $modalidade = 6): void
    {
        $this->p = $this->processo(['modalidade' => $modalidade], 'em_julgamento');
        $this->item = $this->p->itens()->create(['orgao_id' => $this->p->orgao_id, 'numero' => 1, 'material_servico' => 'M', 'descricao' => 'Papel A4', 'unidade_medida' => 'RESMA', 'quantidade' => 100, 'tipo_beneficio' => 4]);
        $this->item->forceFill(['valor_unitario_estimado' => 30, 'valor_total_estimado' => 3000])->save();
        $this->f1 = $this->fornecedor('99.000.333/0001-88');
        $this->f2 = $this->fornecedor('99.000.444/0001-44');

        foreach ([[$this->f1, '25'], [$this->f2, '27']] as [$f, $v]) {
            $this->agente()->post(route('licitacao.proposta', [$this->p, $this->item]), ['fornecedor_id' => $f->id, 'valor' => $v])->assertSessionHas('sucesso');
        }
        $this->agente()->post(route('licitacao.habilitar', [$this->p, Proposta::where('fornecedor_id', $this->f1->id)->first()]))->assertSessionHas('sucesso');
    }

    private function agente()
    {
        return $this->actingAs($this->u['agente-contratacao']);
    }

    private function intencao(?Fornecedor $f = null)
    {
        return $this->agente()->post(route('recursos.intencao', $this->p), ['fornecedor_id' => ($f ?? $this->f2)->id, 'tipo' => 'habilitacao', 'item_id' => $this->item->id, 'motivo' => 'Atestado de capacidade técnica da vencedora não comprova o quantitativo.']);
    }

    private function homologar()
    {
        return $this->actingAs($this->u['autoridade'])->post("/processos/{$this->p->id}/tramitar", ['destino' => 'homologado']);
    }

    public function test_encerrar_lavra_ata_em_pdf_e_bloqueia_novos_lances(): void
    {
        $this->sessao();

        $this->agente()->post(route('licitacao.encerrar', $this->p))->assertSessionHas('sucesso');

        $this->p->refresh();
        $this->assertNotNull($this->p->sessao_encerrada_em);
        Storage::disk('local')->assertExists($this->p->ata_sessao_caminho);
        $pdf = Storage::disk('local')->get($this->p->ata_sessao_caminho);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(hash('sha256', $pdf), $this->p->ata_sessao_hash);

        $this->agente()->post(route('licitacao.lance', [$this->p, Proposta::first()]), ['valor' => '24'])->assertSessionHas('motivos');
        $this->agente()->get(route('licitacao.ata', $this->p))->assertOk();
        $this->get(route('edital.ata', $this->p))->assertOk(); // público, vista dos autos (art. 165, § 5º)
    }

    public function test_presencial_exige_gravacao_para_encerrar_e_para_homologar(): void
    {
        $this->sessao(7);
        $this->agente()->post(route('licitacao.adjudicar', [$this->p, $this->item]), ['proposta_id' => Proposta::where('fornecedor_id', $this->f1->id)->first()->id])->assertSessionHas('sucesso');

        $this->homologar()->assertSessionHas('motivos');
        $this->agente()->post(route('licitacao.encerrar', $this->p))->assertSessionHas('motivos');

        $this->agente()->post(route('licitacao.gravacao', $this->p), ['gravacao_link' => 'http://inseguro.exemplo'])->assertSessionHasErrors('gravacao_link');
        $video = UploadedFile::fake()->create('sessao.mp4', 2048, 'video/mp4');
        $this->agente()->post(route('licitacao.gravacao', $this->p), ['gravacao_arquivo' => $video])->assertSessionHas('sucesso');
        $this->assertNotNull($this->p->fresh()->gravacao_hash);

        $this->agente()->post(route('licitacao.encerrar', $this->p))->assertSessionHas('sucesso');
        $this->homologar()->assertSessionHas('sucesso');
    }

    public function test_intencao_so_na_sessao_aberta_e_de_quem_participou(): void
    {
        $this->sessao();
        $estranho = $this->fornecedor('99.000.555/0001-00');

        $this->intencao($estranho)->assertSessionHas('motivos');
        $this->intencao()->assertSessionHas('sucesso');
        $this->agente()->post(route('licitacao.encerrar', $this->p));
        $this->intencao($this->f1)->assertSessionHas('motivos'); // preclusão (art. 165, § 1º, I)

        $r = Recurso::firstOrFail();
        $this->assertSame('2026-11-12', $r->prazo_razoes->toDateString());
    }

    public function test_recurso_pendente_suspende_adjudicacao_e_homologacao(): void
    {
        $this->sessao();
        $this->intencao()->assertSessionHas('sucesso');

        $vencedora = Proposta::where('fornecedor_id', $this->f1->id)->first();
        $this->agente()->post(route('licitacao.adjudicar', [$this->p, $this->item]), ['proposta_id' => $vencedora->id])->assertSessionHas('motivos');
        $this->assertNull($this->item->fresh()->adjudicado_em);

        // Sem razões no prazo, o recurso não é conhecido e o processo segue.
        $this->agente()->post(route('licitacao.encerrar', $this->p));
        $this->travelTo(now()->setDate(2026, 11, 13));
        $this->assertSame('sem_razoes', Recurso::first()->situacaoAtual());
        $this->agente()->post(route('licitacao.adjudicar', [$this->p, $this->item]), ['proposta_id' => $vencedora->id])->assertSessionHas('sucesso');
        $this->homologar()->assertSessionHas('sucesso');
    }

    public function test_ciclo_completo_razoes_contrarrazoes_manutencao_e_decisao(): void
    {
        $this->sessao();
        $this->intencao();
        $this->agente()->post(route('licitacao.encerrar', $this->p));
        $r = Recurso::firstOrFail();

        $this->travelTo(now()->setDate(2026, 11, 10));
        $this->agente()->post(route('recursos.razoes', [$this->p, $r]), ['razoes' => 'curto'])->assertSessionHas('motivos');
        $this->agente()->post(route('recursos.razoes', [$this->p, $r]), ['razoes' => 'O atestado apresentado comprova só 20% do quantitativo exigido no item 9.2 do edital.'])->assertSessionHas('sucesso');
        $r->refresh();
        $this->assertSame(['2026-11-13', '2026-11-18'], [$r->prazo_contrarrazoes->toDateString(), $r->prazo_reconsideracao->toDateString()]);

        $this->agente()->post(route('recursos.contrarrazoes', [$this->p, $r]), ['fornecedor_id' => $this->f2->id, 'texto' => 'O próprio recorrente não pode contrarrazoar, este texto não deve entrar.'])->assertSessionHas('motivos');
        $this->agente()->post(route('recursos.contrarrazoes', [$this->p, $r]), ['fornecedor_id' => $this->f1->id, 'texto' => 'O edital admite a soma de atestados e os dois juntos superam o quantitativo.'])->assertSessionHas('sucesso');

        // Antes do fim das contrarrazões o agente não decide (contraditório).
        $this->agente()->post(route('recursos.reconsiderar', [$this->p, $r]), ['decisao' => 'manter', 'fundamento' => 'O edital admite soma de atestados, item 9.2.1, e o total supera o exigido.'])->assertSessionHas('motivos');

        $this->travelTo(now()->setDate(2026, 11, 16));
        $this->agente()->post(route('recursos.reconsiderar', [$this->p, $r]), ['decisao' => 'manter', 'fundamento' => 'O edital admite soma de atestados, item 9.2.1, e o total supera o exigido.'])->assertSessionHas('sucesso');
        $r->refresh();
        $this->assertSame(['superior', '2026-12-01'], [$r->situacao, $r->prazo_decisao->toDateString()]);
        $this->homologar()->assertSessionHas('motivos');

        // Só a autoridade decide; o agente não tem a permissão.
        $this->agente()->post(route('recursos.decidir', [$this->p, $r]), ['resultado' => 'improvido', 'fundamento' => str_repeat('x', 40)])->assertForbidden();
        $this->actingAs($this->u['autoridade'])->post(route('recursos.decidir', [$this->p, $r]), ['resultado' => 'improvido', 'fundamento' => 'Acolho a manifestação do agente: a soma de atestados é admitida pelo edital.'])->assertSessionHas('sucesso');
        $this->assertSame(['decidido', 'improvido'], [$r->fresh()->situacao, $r->fresh()->resultado]);

        $this->agente()->post(route('licitacao.adjudicar', [$this->p, $this->item]), ['proposta_id' => Proposta::where('fornecedor_id', $this->f1->id)->first()->id])->assertSessionHas('sucesso');
        $this->homologar()->assertSessionHas('sucesso');

        $this->get(route('edital.publico', $this->p))->assertOk()->assertSee('Não provido')->assertSee('soma de atestados');
    }

    public function test_razoes_fora_do_prazo_sao_recusadas(): void
    {
        $this->sessao();
        $this->intencao();
        $this->agente()->post(route('licitacao.encerrar', $this->p));

        $this->travelTo(now()->setDate(2026, 11, 13));
        $this->agente()->post(route('recursos.razoes', [$this->p, Recurso::first()]), ['razoes' => 'Razões apresentadas depois do prazo de três dias úteis da ata.'])->assertSessionHas('motivos');
    }

    public function test_reabrir_exige_justificativa_e_libera_lances(): void
    {
        $this->sessao();
        $this->agente()->post(route('licitacao.encerrar', $this->p));

        $this->agente()->post(route('licitacao.reabrir', $this->p), ['motivo' => 'curto'])->assertSessionHas('motivos');
        $this->agente()->post(route('licitacao.reabrir', $this->p), ['motivo' => 'Recurso provido: refazer a habilitação do item 1.'])->assertSessionHas('sucesso');
        $this->assertNull($this->p->fresh()->sessao_encerrada_em);
    }

    public function test_tela_da_sessao_mostra_recursos_e_foco_por_item(): void
    {
        $this->sessao();
        $this->intencao();

        $this->agente()->get(route('licitacao.show', [$this->p, 'item' => 1]))->assertOk()
            ->assertSee('Encerrar sessão e lavrar ata')->assertSee('Intenção registrada')->assertSee('Registrar intenção de recurso');
    }
}
