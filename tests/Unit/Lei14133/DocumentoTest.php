<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\TiposDocumento;
use App\Pncp\NumeroControle;
use App\Support\Documento;
use PHPUnit\Framework\TestCase;

class DocumentoTest extends TestCase
{
    public function test_valida_cnpj(): void
    {
        $this->assertTrue(Documento::cnpjValido('11.222.333/0001-81'));
        $this->assertFalse(Documento::cnpjValido('11.222.333/0001-82'));
        $this->assertFalse(Documento::cnpjValido('00.000.000/0000-00'));
    }

    public function test_valida_cpf_e_mascara(): void
    {
        $this->assertTrue(Documento::cpfValido('529.982.247-25'));
        $this->assertFalse(Documento::cpfValido('529.982.247-26'));
        $this->assertSame('***.982.247-**', Documento::mascararCpf('52998224725'));
    }

    public function test_etp_exige_incisos_obrigatorios_do_art_18_paragrafo_2(): void
    {
        $faltando = TiposDocumento::camposFaltando('etp', ['i_necessidade' => 'x', 'iv_quantidades' => 'x']);

        $this->assertCount(3, $faltando);
        $this->assertStringStartsWith('VI.', $faltando[0]);
    }

    public function test_numero_de_controle_pelo_location(): void
    {
        $this->assertSame('2026/15', NumeroControle::deLocation('https://treina.pncp.gov.br/api/pncp/v1/orgaos/11222333000181/compras/2026/15'));
        $this->assertSame([2026, 15], NumeroControle::anoSequencial('2026/15'));
        $this->assertSame([null, null], NumeroControle::anoSequencial(null));
    }
}
