<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Ia\Anonimizador;
use PHPUnit\Framework\TestCase;

/** LGPD: nada de dado pessoal segue para a IA. */
class AnonimizadorTest extends TestCase
{
    public function test_remove_cpf_email_telefone_e_cep_e_mantem_cnpj(): void
    {
        $t = Anonimizador::limpar('Fiscal Fulano, CPF 123.456.789-09 (12345678909), fulano@vale.gov.br, fone (95) 99123-4567, CEP 69300-000. Empresa 99.000.333/0001-88.');

        $this->assertStringNotContainsString('123.456.789-09', $t);
        $this->assertStringNotContainsString('12345678909', $t);
        $this->assertStringNotContainsString('fulano@', $t);
        $this->assertStringNotContainsString('99123-4567', $t);
        $this->assertStringNotContainsString('69300-000', $t);
        $this->assertStringContainsString('99.000.333/0001-88', $t);
        $this->assertStringContainsString('[CPF]', $t);
    }
}
