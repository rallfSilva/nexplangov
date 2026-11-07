<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\IndicadoresGestao as I;
use App\Support\Exportacao;
use PHPUnit\Framework\TestCase;

/** Indicadores gerenciais (art. 11, parágrafo único) e fomento local (art. 40, § 2º, II; art. 4º). */
class IndicadoresGestaoTest extends TestCase
{
    private array $contratos = [
        ['fornecedor' => 1, 'porte' => 'ME', 'uf' => 'RR', 'municipio' => 'Vale Verde', 'valor' => 30000.0],
        ['fornecedor' => 1, 'porte' => 'ME', 'uf' => 'RR', 'municipio' => 'VALE VERDE', 'valor' => 10000.0],
        ['fornecedor' => 2, 'porte' => 'EPP', 'uf' => 'rr', 'municipio' => 'Serra Azul', 'valor' => 20000.0],
        ['fornecedor' => 3, 'porte' => 'DEMAIS', 'uf' => 'AM', 'municipio' => 'Rio Claro', 'valor' => 40000.0],
        ['fornecedor' => 4, 'porte' => null, 'uf' => null, 'municipio' => null, 'valor' => 0.0],
    ];

    public function test_visao_por_secretaria(): void
    {
        $u = I::porUnidade([
            ['unidade' => 'SEMED', 'andamento' => true, 'valor' => 6000], ['unidade' => 'SEMED', 'andamento' => false, 'valor' => 2000],
            ['unidade' => 'SEMUS', 'andamento' => true, 'valor' => 2000], ['unidade' => 'SEMUS', 'itens_pca' => 1, 'itens_pca_usados' => 1], ['unidade' => 'SEMUS', 'itens_pca' => 1, 'itens_pca_usados' => 0],
            ['unidade' => 'SEMED', 'contratos' => 1, 'valor_contratos' => 1500.5],
        ]);

        $this->assertSame(['SEMED', 'SEMUS'], array_column($u, 'unidade'));
        $this->assertSame([2, 1, 1, 8000.0, 80.0], [$u[0]['processos'], $u[0]['andamento'], $u[0]['concluidos'], $u[0]['valor'], $u[0]['participacao']]);
        $this->assertSame([50, 2], [$u[1]['execucao_pca'], $u[1]['itens_pca']]);
        $this->assertSame([1, 1500.5], [$u[0]['contratos'], $u[0]['valor_contratos']]);
    }

    public function test_perfil_dos_fornecedores_e_fomento_local(): void
    {
        $p = I::perfilFornecedores($this->contratos);
        $this->assertSame(4, $p['fornecedores']);
        $this->assertSame(60.0, $p['pct_me_epp']);
        $this->assertSame(['DEMAIS', 'ME', 'EPP', ''], array_column($p['porte'], 'chave'));
        $this->assertSame(['rotulo' => 'RR', 'fornecedores' => 2], array_intersect_key($p['uf'][0], ['rotulo' => 1, 'fornecedores' => 1]));
        $this->assertSame('VALE VERDE/RR', $p['municipio'][1]['rotulo'], 'o município é agrupado sem diferença de maiúsculas');

        $f = I::fomentoLocal($this->contratos, 'Vale Verde', 'RR');
        $this->assertSame([40000.0, 40.0, 1], array_values($f['local']));
        $this->assertSame(20000.0, $f['regional']['valor']);
        $this->assertSame(40000.0, $f['fora']['valor']);
        $this->assertSame(1, $f['sem_endereco']['fornecedores']);
        $this->assertSame(40000.0, $f['me_epp_local']);
    }

    public function test_exportacao_csv_e_txt(): void
    {
        $csv = Exportacao::csv(['Nome', 'Valor'], [['=SOMA(A1)', 1234.5], ['Vale Verde (fictícia)', null]]);
        $this->assertStringStartsWith("\xEF\xBB\xBFNome;Valor\n", $csv);
        $this->assertStringContainsString("'=SOMA(A1);1.234,50", $csv, 'texto que vira fórmula ganha apóstrofo');

        $txt = Exportacao::txt('Relatório de teste', ['Secretaria', 'Valor'], [['SEMED', 10.0], ['SEMUS', 2500.25]]);
        $linhas = explode("\n", $txt);
        $this->assertSame('Relatório de teste', $linhas[0]);
        $this->assertSame('SEMED       10,00', $linhas[4]);
        $this->assertSame('SEMUS       2.500,25', $linhas[5]);
    }
}
