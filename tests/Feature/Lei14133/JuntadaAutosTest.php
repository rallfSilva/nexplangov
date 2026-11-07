<?php

namespace Tests\Feature\Lei14133;

use App\Models\JuntadaProcesso;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Juntada livre, árvore numerada dos autos, desentranhamento e PDF único paginado. */
class JuntadaAutosTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->montarCenario();
    }

    private function juntar($p, string $titulo = 'Certidão negativa de débitos (fictícia)', string $arquivo = 'certidao.pdf')
    {
        return $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/juntadas", [
            'tipo' => 'certidao', 'titulo' => $titulo, 'arquivo' => UploadedFile::fake()->createWithContent($arquivo, "%PDF-1.4\n<< /Type /Page >>\n%%EOF"),
        ]);
    }

    public function test_juntar_documento_aparece_numerado_na_arvore_e_no_pdf_unico(): void
    {
        $p = $this->processo();
        $this->concluirDocumento($p, 'dfd', $this->u['planejamento']);
        $this->juntar($p)->assertSessionHasNoErrors()->assertRedirect("/processos/{$p->id}?aba=autos");

        $j = JuntadaProcesso::firstOrFail();
        $this->assertSame(1, $j->paginas);
        $this->assertSame(64, strlen($j->arquivo_hash));
        Storage::disk('local')->assertExists($j->arquivo_caminho);

        $this->actingAs($this->u['planejamento'])->get("/processos/{$p->id}?aba=autos")->assertOk()
            ->assertSee('Árvore do processo')->assertSee('Documentos juntados')->assertSee('Certidão negativa de débitos (fictícia)')->assertSee('Fase preparatória');

        $pdf = $this->actingAs($this->u['auditor'])->get("/processos/{$p->id}/autos.pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->actingAs($this->u['auditor'])->get("/processos/{$p->id}/juntadas/{$j->id}")->assertOk();
    }

    public function test_quem_so_consulta_nao_junta_e_arquivo_invalido_e_recusado(): void
    {
        $p = $this->processo();
        $this->actingAs($this->u['auditor'])->post("/processos/{$p->id}/juntadas", ['tipo' => 'certidao', 'titulo' => 'X', 'arquivo' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf')])->assertForbidden();
        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/juntadas", ['tipo' => 'certidao', 'titulo' => 'Script', 'arquivo' => UploadedFile::fake()->create('x.exe', 5)])->assertSessionHasErrors('arquivo');
        $this->assertSame(0, JuntadaProcesso::count());
    }

    public function test_desentranhar_mantem_o_registro_e_tira_do_pdf(): void
    {
        $p = $this->processo();
        $this->juntar($p, 'Ofício juntado por engano');
        $j = JuntadaProcesso::firstOrFail();

        $this->actingAs($this->u['juridico'])->post("/processos/{$p->id}/juntadas/{$j->id}/desentranhar", ['motivo' => 'Documento de outro processo.'])->assertForbidden();
        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/juntadas/{$j->id}/desentranhar", ['motivo' => 'curto'])->assertSessionHas('erro');

        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/juntadas/{$j->id}/desentranhar", ['motivo' => 'Documento de outro processo.'])->assertSessionMissing('erro');
        $this->assertNotNull($j->fresh()->desentranhado_em);
        $this->assertSame(1, JuntadaProcesso::count(), 'o registro fica');

        $this->actingAs($this->u['planejamento'])->get("/processos/{$p->id}?aba=autos")->assertSee('desentranhado');
    }

    public function test_banco_impede_apagar_ou_alterar_juntada(): void
    {
        $p = $this->processo();
        $this->juntar($p);
        $id = JuntadaProcesso::firstOrFail()->id;

        try {
            DB::table('juntadas_processo')->where('id', $id)->update(['titulo' => 'Outro título']);
            $this->fail('o banco deveria recusar a alteração');
        } catch (QueryException $e) {
            $this->assertStringContainsString('não pode ser alterado', $e->getMessage());
        }
    }

    public function test_banco_impede_apagar_juntada(): void
    {
        $p = $this->processo();
        $this->juntar($p);

        $this->expectException(QueryException::class);
        DB::table('juntadas_processo')->delete();
    }
}
