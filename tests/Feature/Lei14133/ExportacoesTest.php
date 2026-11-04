<?php

namespace Tests\Feature\Lei14133;

use App\Models\Contrato;
use App\Models\DocumentoProcesso;
use App\Models\ItemPca;
use App\Models\Orgao;
use App\Models\PlanoContratacao;
use App\Models\Processo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;
use ZipArchive;

/** Exportações editáveis em XLSX, DOCX e CSV. */
class ExportacoesTest extends TestCase
{
    use CenarioContratacao, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
    }

    /** @return array<string, list<list<mixed>>> */
    private function abas(TestResponse $r): array
    {
        $leitor = new Reader;
        $leitor->open($r->baseResponse->getFile()->getPathname());
        $abas = [];
        foreach ($leitor->getSheetIterator() as $aba) {
            foreach ($aba->getRowIterator() as $linha) {
                $abas[$aba->getName()][] = $linha->toArray();
            }
        }
        $leitor->close();

        return $abas;
    }

    private function textoDocx(TestResponse $r): string
    {
        $z = new ZipArchive;
        $this->assertTrue($z->open($r->baseResponse->getFile()->getPathname()) === true, 'DOCX é um pacote ZIP válido');
        $xml = $z->getFromName('word/document.xml');
        $z->close();

        return html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $xml)));
    }

    private function plano(): PlanoContratacao
    {
        $plano = PlanoContratacao::create(['orgao_id' => $this->orgao->id, 'ano' => 2027, 'situacao' => 'rascunho']);
        ItemPca::create(['orgao_id' => $this->orgao->id, 'plano_contratacao_id' => $plano->id, 'unidade_id' => $this->unidade->id, 'numero' => 1, 'material_servico' => 'M', 'categoria' => 2,
            'descricao' => '=HYPERLINK("http://x") Papel A4 & cia <fictício>', 'unidade_fornecimento' => 'RESMA', 'quantidade' => 100, 'valor_unitario_estimado' => 28.9, 'prioridade' => 1, 'data_desejada' => '2027-03-15']);

        return $plano;
    }

    public function test_pca_em_xlsx_docx_e_csv(): void
    {
        $plano = $this->plano();
        $u = $this->u['auditor'];

        $abas = $this->abas($this->actingAs($u)->get(route('pca.exportar', [$plano, 'xlsx']))->assertOk());
        $this->assertSame(['Itens', 'Por categoria', 'Por unidade'], array_keys($abas));
        $this->assertSame('=HYPERLINK("http://x") Papel A4 & cia <fictício>', $abas['Itens'][1][4], 'texto continua texto, nunca fórmula');
        $this->assertEquals([100, 28.9, 2890], [$abas['Itens'][1][6], $abas['Itens'][1][7], $abas['Itens'][1][8]]);
        $this->assertSame('2027-03-15', $abas['Itens'][1][10]->format('Y-m-d'));
        $this->assertSame('SEMAD', $abas['Por unidade'][1][0]);

        $texto = $this->textoDocx($this->actingAs($u)->get(route('pca.exportar', [$plano, 'docx']))->assertOk());
        $this->assertStringContainsString('Plano de Contratações Anual 2027', $texto);
        $this->assertStringContainsString('Papel A4 & cia <fictício>', $texto);
        $this->assertStringContainsString('R$ 2.890,00', $texto);

        $csv = $this->actingAs($u)->get(route('pca.exportar', [$plano, 'csv']))->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString(";\"'=HYPERLINK(\"\"http://x\"\") Papel A4 & cia <fictício>\";RESMA;100,0000;28,9000;2.890,00;SEMAD;15/03/2027;Alta;", $csv);

        $this->actingAs($u)->get(route('pca.exportar', [$plano, 'pdf']))->assertNotFound();
        $this->assertSame(3, DB::table('registros_auditoria')->where('evento', 'exportacao')->count());
    }

    public function test_peca_do_processo_em_docx_com_campos_e_aviso_de_validade(): void
    {
        $p = $this->processo();
        DocumentoProcesso::create(['orgao_id' => $this->orgao->id, 'processo_id' => $p->id, 'tipo' => 'dfd', 'versao' => 1, 'situacao' => 'rascunho', 'autor_id' => $this->u['planejamento']->id, 'origem' => 'editor',
            'conteudo' => ['necessidade' => "Falta papel nas escolas.\nEstoque zerado em março.", 'quantidade_justificada' => '200 resmas pelo consumo de 2026']]);

        $r = $this->actingAs($this->u['juridico'])->get(route('documentos.docx', [$p, 'dfd']))->assertOk();
        $this->assertStringContainsString('.docx', $r->headers->get('content-disposition'));
        $texto = $this->textoDocx($r);
        $this->assertStringContainsString('Documento de formalização da demanda (DFD)', $texto);
        $this->assertStringContainsString("Falta papel nas escolas.\nEstoque zerado em março.", $texto);
        $this->assertStringContainsString('Não preenchido.', $texto);
        $this->assertStringContainsString('A peça com validade é a do sistema', $texto);

        $this->actingAs($this->u['juridico'])->get(route('documentos.docx', [$p, 'etp']))->assertNotFound();
        $this->actingAs($this->u['juridico'])->get(route('documentos.docx', [$p, 'inexistente']))->assertNotFound();
    }

    public function test_listas_de_processos_e_contratos_respeitam_filtro_e_orgao(): void
    {
        $this->processo(['objeto' => 'Aquisição de papel (fictício)']);
        $this->processo(['objeto' => 'Serviço de limpeza (fictício)']);
        $outro = Orgao::factory()->create(['sigla' => 'OUT', 'cnpj' => '99.777.666/0001-00']);
        Processo::create(['orgao_id' => $outro->id, 'numero' => '1', 'ano' => 2026, 'objeto' => 'Papel de outro órgão', 'modalidade' => 6, 'amparo_legal' => 1, 'criterio_julgamento' => 1, 'modo_disputa' => 1, 'categoria' => 2]);

        $abas = $this->abas($this->actingAs($this->u['auditor'])->get(route('processos.exportar', ['busca' => 'papel']))->assertOk());
        $this->assertCount(2, $abas['Processos'], 'cabeçalho + 1 processo do próprio órgão');
        $this->assertSame('Aquisição de papel (fictício)', $abas['Processos'][1][2]);

        Contrato::create(['orgao_id' => $this->orgao->id, 'fornecedor_id' => $this->fornecedor()->id, 'numero' => '9', 'ano' => 2026, 'tipo' => 1, 'objeto' => 'Limpeza predial (fictícia)', 'categoria' => 6,
            'valor_inicial' => 12000, 'valor_global' => 12000, 'assinatura' => today(), 'vigencia_inicio' => today(), 'vigencia_fim' => today()->addYear(), 'situacao' => 'vigente']);
        $abas = $this->abas($this->actingAs($this->u['gestor-contrato'])->get(route('contratos.exportar'))->assertOk());
        $this->assertSame(['9/2026', 'Contrato', 'Limpeza predial (fictícia)'], array_slice($abas['Contratos'][1], 0, 3));
        $this->assertEquals(12000, $abas['Contratos'][1][7]);

        $this->actingAs($this->u['requisitante'])->get(route('contratos.exportar'))->assertForbidden();
    }
}
