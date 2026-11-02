<?php

namespace Tests\Feature\Lei14133;

use App\Licitacao\Fluxo\Assinaturas;
use App\Licitacao\Fluxo\FluxoDocumento;
use App\Licitacao\TiposDocumento;
use App\Models\Assinatura;
use App\Models\DocumentoProcesso;
use App\Models\Fluxo;
use App\Models\FluxoEtapa;
use App\Models\ModeloDocumento;
use App\Models\Orgao;
use Database\Seeders\FluxosPadrao;
use Database\Seeders\ModelosPadrao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

/** Fluxo configurável das peças (art. 18 e art. 53), biblioteca de modelos e assinatura eletrônica. */
class FluxoDocumentosTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
    }

    private function etapa(array $etapa, ?Fluxo $fluxo = null): Fluxo
    {
        $fluxo ??= Fluxo::create(['orgao_id' => $this->orgao->id, 'nome' => 'Fluxo de teste '.random_int(1, 99999), 'aplicacao' => 'todos', 'padrao' => true]);
        FluxoEtapa::create($etapa + ['orgao_id' => $this->orgao->id, 'fluxo_id' => $fluxo->id, 'ordem' => $fluxo->etapas()->count() + 1, 'origem' => 'formulario']);

        return $fluxo;
    }

    private function comFluxo(Fluxo $fluxo)
    {
        $p = $this->processo();
        app(FluxoDocumento::class)->aplicar($p, $fluxo);

        return $p->refresh();
    }

    public function test_sem_fluxo_a_peca_conclui_direto(): void
    {
        $p = $this->processo();
        $this->concluirDocumento($p, 'dfd', $this->u['planejamento']);

        $this->assertTrue($p->documentoConcluido('dfd'));
    }

    public function test_dependencia_bloqueia_a_peca_ate_a_outra_concluir(): void
    {
        $fluxo = $this->etapa(['tipo' => 'dfd', 'depende_de' => []]);
        $this->etapa(['tipo' => 'etp', 'depende_de' => ['dfd']], $fluxo);
        $p = $this->comFluxo($fluxo);

        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/documentos/etp", ['conteudo' => ['i_necessidade' => 'x'], 'acao' => 'rascunho'])
            ->assertSessionHas('erro')->assertSessionHas('motivos');
        $this->assertDatabaseMissing('documentos_processo', ['processo_id' => $p->id, 'tipo' => 'etp']);

        $this->concluirDocumento($p, 'dfd', $this->u['planejamento']);
        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/documentos/etp", ['conteudo' => ['i_necessidade' => 'x'], 'acao' => 'rascunho'])
            ->assertSessionHas('sucesso');
    }

    public function test_prazo_comeca_quando_a_peca_e_liberada(): void
    {
        $fluxo = $this->etapa(['tipo' => 'dfd', 'depende_de' => [], 'prazo_dias' => 3]);
        $this->etapa(['tipo' => 'etp', 'depende_de' => ['dfd'], 'prazo_dias' => 10], $fluxo);
        $p = $this->comFluxo($fluxo);

        $this->assertNotNull($p->etapas()->where('tipo', 'dfd')->first()->prazo_em);
        $this->assertNull($p->etapas()->where('tipo', 'etp')->first()->prazo_em);

        $this->concluirDocumento($p, 'dfd', $this->u['planejamento']);

        $this->assertSame(now()->addDays(10)->toDateString(), $p->etapas()->where('tipo', 'etp')->first()->prazo_em->toDateString());
    }

    public function test_quem_escreveu_nao_revisa_nem_aprova_a_propria_peca(): void
    {
        $fluxo = $this->etapa(['tipo' => 'dfd', 'depende_de' => [], 'exige_revisao' => true, 'exige_aprovacao' => true]);
        $p = $this->comFluxo($fluxo);
        $this->concluirDocumento($p, 'dfd', $this->u['planejamento']);

        $doc = $p->documentoAtual('dfd');
        $this->assertSame('em_revisao', $doc->situacao);

        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/documentos/dfd/decidir", ['acao' => 'aprovar'])->assertSessionHas('erro');
        $this->assertSame('em_revisao', $doc->refresh()->situacao);

        $revisor = $this->usuario('planejamento', $this->orgao);
        $this->actingAs($revisor)->post("/processos/{$p->id}/documentos/dfd/decidir", ['acao' => 'aprovar'])->assertSessionHas('sucesso');
        $this->assertSame('em_aprovacao', $doc->refresh()->situacao);

        // Quem revisou não aprova.
        $this->actingAs($revisor)->post("/processos/{$p->id}/documentos/dfd/decidir", ['acao' => 'aprovar'])->assertSessionHas('erro');

        $aprovador = $this->usuario('planejamento', $this->orgao);
        $this->actingAs($aprovador)->post("/processos/{$p->id}/documentos/dfd/decidir", ['acao' => 'aprovar'])->assertSessionHas('sucesso');
        $this->assertSame('concluido', $doc->refresh()->situacao);
    }

    public function test_devolver_exige_motivo_e_volta_para_rascunho(): void
    {
        $fluxo = $this->etapa(['tipo' => 'dfd', 'depende_de' => [], 'exige_revisao' => true]);
        $p = $this->comFluxo($fluxo);
        $this->concluirDocumento($p, 'dfd', $this->u['planejamento']);
        $revisor = $this->usuario('planejamento', $this->orgao);

        $this->actingAs($revisor)->post("/processos/{$p->id}/documentos/dfd/decidir", ['acao' => 'devolver', 'motivo' => 'curto'])->assertSessionHas('erro');
        $this->actingAs($revisor)->post("/processos/{$p->id}/documentos/dfd/decidir", ['acao' => 'devolver', 'motivo' => 'Faltou detalhar a quantidade.'])->assertSessionHas('sucesso');

        $doc = $p->documentoAtual('dfd');
        $this->assertSame('rascunho', $doc->situacao);
        $this->assertSame('Faltou detalhar a quantidade.', $doc->motivo_devolucao);
    }

    public function test_assinatura_exige_senha_conclui_a_peca_e_valida_publicamente(): void
    {
        $fluxo = $this->etapa(['tipo' => 'dfd', 'depende_de' => [], 'exige_assinatura' => true]);
        $p = $this->comFluxo($fluxo);
        $this->concluirDocumento($p, 'dfd', $this->u['planejamento']);
        $doc = $p->documentoAtual('dfd');
        $this->assertSame('aguardando_assinatura', $doc->situacao);

        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/documentos/dfd/assinar", ['senha' => 'errada'])->assertSessionHas('erro');
        $this->assertSame(0, Assinatura::count());

        // Quem não é signatário não assina.
        $this->actingAs($this->u['agente-contratacao'])->post("/processos/{$p->id}/documentos/dfd/assinar", ['senha' => 'password'])->assertSessionHas('erro');

        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/documentos/dfd/assinar", ['senha' => 'password'])->assertSessionHas('sucesso');
        $this->assertSame('concluido', $doc->refresh()->situacao);

        $a = Assinatura::firstOrFail();
        $this->get('/validar/'.$a->codigo)->assertOk()->assertSee('Assinatura válida')->assertDontSee('Conteúdo de teste');
        $this->get('/validar/'.Assinaturas::formatarCodigo($a->codigo))->assertOk()->assertSee('Assinatura válida');
        $this->get('/validar/NAOEXISTE123')->assertOk()->assertSee('Código não encontrado');
    }

    public function test_assinatura_coletiva_so_conclui_com_todos(): void
    {
        $outro = $this->usuario('planejamento', $this->orgao);
        $fluxo = $this->etapa(['tipo' => 'dfd', 'depende_de' => [], 'exige_assinatura' => true, 'assinantes' => [$this->u['planejamento']->id, $outro->id]]);
        $p = $this->comFluxo($fluxo);
        $this->concluirDocumento($p, 'dfd', $this->u['planejamento']);

        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/documentos/dfd/assinar", ['senha' => 'password']);
        $this->assertSame('aguardando_assinatura', $p->documentoAtual('dfd')->situacao);

        // Assinar duas vezes não vale.
        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/documentos/dfd/assinar", ['senha' => 'password'])->assertSessionHas('erro');

        $this->actingAs($outro)->post("/processos/{$p->id}/documentos/dfd/assinar", ['senha' => 'password']);
        $this->assertSame('concluido', $p->documentoAtual('dfd')->situacao);
        $this->assertSame(2, Assinatura::count());
    }

    public function test_adulteracao_invalida_a_assinatura_e_o_registro_e_imutavel(): void
    {
        $fluxo = $this->etapa(['tipo' => 'dfd', 'depende_de' => [], 'exige_assinatura' => true]);
        $p = $this->comFluxo($fluxo);
        $this->concluirDocumento($p, 'dfd', $this->u['planejamento']);
        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/documentos/dfd/assinar", ['senha' => 'password']);
        $a = Assinatura::firstOrFail();

        // A peça concluída não aceita alteração pelo modelo.
        $doc = $p->documentoAtual('dfd');
        try {
            $doc->update(['conteudo' => ['necessidade' => 'texto adulterado']]);
            $this->fail('A peça concluída não pode ser alterada.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        // Mesmo mexendo direto no banco, a validação percebe.
        \DB::table('documentos_processo')->where('id', $doc->id)->update(['conteudo' => json_encode(['necessidade' => 'texto adulterado'])]);
        $this->get('/validar/'.$a->codigo)->assertSee('Assinatura inválida');

        $this->expectException(LogicException::class);
        $a->update(['nome' => 'Outra Pessoa']);
    }

    public function test_selo_forjado_nao_confere(): void
    {
        $fluxo = $this->etapa(['tipo' => 'dfd', 'depende_de' => [], 'exige_assinatura' => true]);
        $p = $this->comFluxo($fluxo);
        $this->concluirDocumento($p, 'dfd', $this->u['planejamento']);
        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/documentos/dfd/assinar", ['senha' => 'password']);
        $a = Assinatura::firstOrFail();

        \DB::table('assinaturas')->where('id', $a->id)->update(['nome' => 'Outra Pessoa', 'selo' => str_repeat('a', 64)]);

        $this->get('/validar/'.$a->codigo)->assertSee('selo da assinatura não confere');
    }

    public function test_folha_de_assinaturas_traz_qr_code_e_codigo(): void
    {
        $fluxo = $this->etapa(['tipo' => 'dfd', 'depende_de' => [], 'exige_assinatura' => true]);
        $p = $this->comFluxo($fluxo);
        $this->concluirDocumento($p, 'dfd', $this->u['planejamento']);
        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/documentos/dfd/assinar", ['senha' => 'password']);
        $a = Assinatura::firstOrFail();

        $this->actingAs($this->u['planejamento'])->get("/processos/{$p->id}/documentos/dfd/folha")
            ->assertOk()->assertSee('<svg', false)->assertSee(Assinaturas::formatarCodigo($a->codigo));
    }

    public function test_arquivo_pronto_e_guardado_com_hash_e_baixado_com_auditoria(): void
    {
        Storage::fake('local');
        $p = $this->processo();
        $pdf = UploadedFile::fake()->create('etp.pdf', 200, 'application/pdf');

        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/documentos/etp", ['origem' => 'arquivo', 'arquivo' => $pdf, 'acao' => 'concluir'])->assertSessionHas('sucesso');

        $doc = $p->documentoAtual('etp');
        $this->assertSame('arquivo', $doc->origem);
        $this->assertSame(64, strlen($doc->arquivo_hash));
        Storage::disk('local')->assertExists($doc->arquivo_caminho);

        $this->actingAs($this->u['planejamento'])->get("/processos/{$p->id}/arquivos/{$doc->id}")->assertOk();
        $this->assertDatabaseHas('registros_auditoria', ['evento' => 'download']);
    }

    public function test_arquivo_com_tipo_proibido_ou_grande_demais_e_recusado(): void
    {
        Storage::fake('local');
        $p = $this->processo();

        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/documentos/etp", ['origem' => 'arquivo', 'arquivo' => UploadedFile::fake()->create('virus.exe', 10), 'acao' => 'concluir'])->assertSessionHasErrors('arquivo');
        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/documentos/etp", ['origem' => 'arquivo', 'arquivo' => UploadedFile::fake()->create('grande.pdf', 31000, 'application/pdf'), 'acao' => 'concluir'])->assertSessionHasErrors('arquivo');
    }

    public function test_download_de_arquivo_de_outro_orgao_e_negado(): void
    {
        Storage::fake('local');
        $p = $this->processo();
        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/documentos/etp", ['origem' => 'arquivo', 'arquivo' => UploadedFile::fake()->create('etp.pdf', 20, 'application/pdf'), 'acao' => 'concluir']);
        $doc = $p->documentoAtual('etp');

        $outro = $this->usuario('planejamento', Orgao::factory()->create(['sigla' => 'OUT', 'cnpj' => '11.222.333/0002-62']));
        $this->actingAs($outro)->get("/processos/{$p->id}/arquivos/{$doc->id}")->assertNotFound();
    }

    public function test_modelos_padrao_sao_instalados_uma_vez_e_cobrem_todos_os_tipos(): void
    {
        $novos = ModelosPadrao::instalarPara($this->orgao);
        $this->assertGreaterThan(40, $novos);
        $this->assertSame(0, ModelosPadrao::instalarPara($this->orgao));

        $tipos = ModeloDocumento::where('orgao_id', $this->orgao->id)->pluck('tipo')->unique();
        foreach (array_keys(TiposDocumento::todos()) as $tipo) {
            $this->assertTrue($tipos->contains($tipo), "Falta modelo para {$tipo}");
        }

        // Todo campo do modelo existe na peça e todo modelo traz o aviso de revisão jurídica.
        foreach (ModeloDocumento::where('orgao_id', $this->orgao->id)->get() as $m) {
            $campos = array_keys(TiposDocumento::get($m->tipo)['campos']);
            $this->assertSame([], array_diff(array_keys($m->conteudo), $campos), "Campos inválidos em {$m->codigo}");
            $this->assertStringContainsString('assessoria jurídica', $m->descricao);
        }
    }

    public function test_modelo_preenche_campos_dinamicos_com_dados_do_processo(): void
    {
        $m = ModeloDocumento::create([
            'orgao_id' => $this->orgao->id, 'codigo' => 'teste-1', 'tipo' => 'dfd', 'titulo' => 'Teste', 'aplicacao' => 'todos', 'versao' => 1,
            'conteudo' => ['necessidade' => 'O {{orgao}} precisa de: {{objeto}}. Valor {{inexistente}}.'],
        ]);
        $p = $this->processo();

        $r = $m->renderizar($p);

        $this->assertStringContainsString($this->orgao->nome, $r['necessidade']);
        $this->assertStringContainsString('material de expediente', $r['necessidade']);
        $this->assertStringContainsString('[preencher: inexistente]', $r['necessidade']);

        $this->actingAs($this->u['planejamento'])->get("/processos/{$p->id}/documentos/dfd?modelo={$m->id}")->assertOk()->assertSee('material de expediente');
    }

    public function test_edicao_do_modelo_guarda_versao_e_permite_restaurar(): void
    {
        $adm = $this->usuario('administrador-orgao', $this->orgao);
        $m = ModeloDocumento::create(['orgao_id' => $this->orgao->id, 'codigo' => 'teste-2', 'tipo' => 'dotacao', 'titulo' => 'Dotação', 'aplicacao' => 'todos', 'versao' => 1, 'ativo' => true, 'conteudo' => ['dotacao' => 'Texto 1', 'fonte' => '', 'declaracao' => '']]);

        $this->actingAs($adm)->put("/modelos/{$m->id}", ['titulo' => 'Dotação', 'aplicacao' => 'todos', 'ativo' => 1, 'conteudo' => ['dotacao' => 'Texto 2']])->assertRedirect();
        $this->assertSame(2, $m->refresh()->versao);
        $this->assertSame('Texto 2', $m->conteudo['dotacao']);

        $this->actingAs($adm)->post("/modelos/{$m->id}/restaurar/1")->assertRedirect();
        $m->refresh();
        $this->assertSame(3, $m->versao);
        $this->assertSame('Texto 1', $m->conteudo['dotacao']);
    }

    public function test_modelos_de_outro_orgao_nao_aparecem(): void
    {
        $outroOrgao = Orgao::factory()->create(['sigla' => 'OUT', 'cnpj' => '11.222.333/0002-62']);
        ModelosPadrao::instalarPara($outroOrgao);
        $adm = $this->usuario('administrador-orgao', $this->orgao);

        $this->actingAs($adm)->get('/modelos')->assertOk()->assertDontSee('Pregão eletrônico');
        $this->assertSame(0, ModeloDocumento::count());
    }

    public function test_requisitante_nao_gerencia_modelos_nem_fluxos(): void
    {
        $this->actingAs($this->u['requisitante'])->get('/modelos')->assertForbidden();
        $this->actingAs($this->u['requisitante'])->get('/fluxos')->assertForbidden();
    }

    public function test_fluxo_com_ciclo_e_recusado(): void
    {
        $adm = $this->usuario('administrador-orgao', $this->orgao);

        $this->actingAs($adm)->post('/fluxos', [
            'nome' => 'Com ciclo', 'aplicacao' => 'todos',
            'etapas' => [
                ['tipo' => 'dfd', 'incluir' => 1, 'depende_de' => ['etp'], 'origem' => 'formulario'],
                ['tipo' => 'etp', 'incluir' => 1, 'depende_de' => ['dfd'], 'origem' => 'formulario'],
            ],
        ])->assertSessionHasErrors('etapas');
        $this->assertSame(0, Fluxo::count());
    }

    public function test_fluxo_exige_signatario_e_substituto_diferente_do_responsavel(): void
    {
        $adm = $this->usuario('administrador-orgao', $this->orgao);

        $this->actingAs($adm)->post('/fluxos', [
            'nome' => 'Inválido', 'aplicacao' => 'todos',
            'etapas' => [['tipo' => 'dfd', 'incluir' => 1, 'exige_assinatura' => 1, 'origem' => 'formulario', 'responsavel_id' => $adm->id, 'substituto_id' => $adm->id]],
        ])->assertSessionHasErrors();
        $this->assertSame(0, Fluxo::count());
    }

    public function test_fluxo_aceita_so_usuarios_do_proprio_orgao(): void
    {
        $adm = $this->usuario('administrador-orgao', $this->orgao);
        $estranho = $this->usuario('planejamento', Orgao::factory()->create(['sigla' => 'OUT', 'cnpj' => '11.222.333/0002-62']));

        $this->actingAs($adm)->post('/fluxos', [
            'nome' => 'Com estranho', 'aplicacao' => 'todos',
            'etapas' => [['tipo' => 'dfd', 'incluir' => 1, 'origem' => 'formulario', 'responsavel_id' => $estranho->id]],
        ])->assertSessionHasErrors('etapas.0.responsavel_id');
    }

    public function test_fluxo_padrao_e_aplicado_ao_abrir_o_processo(): void
    {
        FluxosPadrao::instalarPara($this->orgao);
        $this->assertFalse(FluxosPadrao::instalarPara($this->orgao));

        $p = $this->processo();
        app(FluxoDocumento::class)->aplicar($p);

        $this->assertGreaterThan(5, $p->etapas()->count());
        $this->assertNotNull($p->refresh()->fluxo_id);
        // Parecer e autorização exigem assinatura.
        $this->assertTrue((bool) $p->etapas()->where('tipo', 'parecer_juridico')->first()->exige_assinatura);
    }

    public function test_peca_em_tramitacao_nao_pode_ser_editada(): void
    {
        $fluxo = $this->etapa(['tipo' => 'dfd', 'depende_de' => [], 'exige_revisao' => true]);
        $p = $this->comFluxo($fluxo);
        $this->concluirDocumento($p, 'dfd', $this->u['planejamento']);

        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/documentos/dfd", ['conteudo' => ['necessidade' => 'mudei'], 'acao' => 'rascunho'])->assertSessionHas('erro');
        $this->assertStringNotContainsString('mudei', json_encode($p->documentoAtual('dfd')->conteudo));
    }

    public function test_so_o_responsavel_designado_ou_o_substituto_elabora(): void
    {
        $outro = $this->usuario('planejamento', $this->orgao);
        $fluxo = $this->etapa(['tipo' => 'dfd', 'depende_de' => [], 'responsavel_id' => $this->u['planejamento']->id, 'substituto_id' => null]);
        $p = $this->comFluxo($fluxo);

        $this->actingAs($outro)->post("/processos/{$p->id}/documentos/dfd", ['conteudo' => ['necessidade' => 'x'], 'acao' => 'rascunho'])->assertForbidden();

        $p->etapas()->where('tipo', 'dfd')->update(['substituto_id' => $outro->id]);
        $this->actingAs($outro)->post("/processos/{$p->id}/documentos/dfd", ['conteudo' => ['necessidade' => 'x'], 'acao' => 'rascunho'])->assertSessionHas('sucesso');
    }

    public function test_documento_sem_hash_atual_igual_ao_gravado_na_conclusao(): void
    {
        $p = $this->processo();
        $this->concluirDocumento($p, 'dfd', $this->u['planejamento']);
        $doc = DocumentoProcesso::find($p->documentoAtual('dfd')->id);

        $this->assertSame($doc->hash_conteudo, $doc->hashAtual());
    }

    public function test_telas_de_fluxos_modelos_e_instrucao_abrem(): void
    {
        $adm = $this->usuario('administrador-orgao', $this->orgao);
        ModelosPadrao::instalarPara($this->orgao);
        FluxosPadrao::instalarPara($this->orgao);
        $fluxo = Fluxo::firstOrFail();
        $modelo = ModeloDocumento::firstOrFail();
        $p = $this->processo();
        app(FluxoDocumento::class)->aplicar($p);

        $this->actingAs($adm)->get('/fluxos')->assertOk()->assertSee('Fluxo padrão NexMind');
        $this->actingAs($adm)->get('/fluxos/novo')->assertOk();
        $this->actingAs($adm)->get("/fluxos/{$fluxo->id}")->assertOk()->assertSee('Exige assinatura eletrônica');
        $this->actingAs($adm)->get('/modelos')->assertOk();
        $this->actingAs($adm)->get('/modelos?tipo=etp')->assertOk();
        $this->actingAs($adm)->get('/modelos/novo?tipo=dfd')->assertOk();
        $this->actingAs($adm)->get("/modelos/{$modelo->id}")->assertOk()->assertSee('Campos dinâmicos');
        $this->actingAs($this->u['planejamento'])->get("/processos/{$p->id}?aba=instrucao")->assertOk()->assertSee('Aplicar fluxo')->assertSee('prazo');
        $this->actingAs($this->u['planejamento'])->get("/processos/{$p->id}/documentos/etp")->assertOk()->assertSee('depende de', false)->assertSee('Documento de formalização', false);
        $this->get('/validar')->assertOk();
    }

    public function test_aplicar_outro_fluxo_ao_processo(): void
    {
        $fluxo = $this->etapa(['tipo' => 'dfd', 'depende_de' => []]);
        $p = $this->processo();

        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/fluxo", ['fluxo_id' => $fluxo->id])->assertSessionHas('sucesso');
        $this->assertSame($fluxo->id, $p->refresh()->fluxo_id);
    }
}
