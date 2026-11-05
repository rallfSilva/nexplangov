<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\PrazoDemanda as P;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Prazo de envio do DFD para o PCA (art. 12, VII) e suas exceções. */
class PrazoDemandaTest extends TestCase
{
    public function test_sem_prazo_ou_dentro_do_prazo_libera(): void
    {
        $this->assertSame([], P::motivos(null, CarbonImmutable::parse('2026-08-01'), 2027, null, null));
        $this->assertSame([], P::motivos(CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-01 23:00'), 2027, null, null), 'o último dia ainda vale');
    }

    public function test_depois_do_prazo_so_com_excecao_justificada(): void
    {
        $limite = CarbonImmutable::parse('2026-08-01');
        $hoje = CarbonImmutable::parse('2026-08-02');

        $this->assertStringContainsString('01/08/2026', P::motivos($limite, $hoje, 2027, null, null)[0]);
        $this->assertCount(1, P::motivos($limite, $hoje, 2027, 'pronto_pagamento', 'curta'));
        $this->assertSame([], P::motivos($limite, $hoje, 2027, 'sigilosa', 'Aquisição de equipamento de segurança da guarda municipal.'));
        $this->assertSame(['Exceção ao prazo inválida.'], P::motivos($limite, $hoje, 2027, 'urgente', 'Qualquer justificativa com mais de trinta letras.'));
    }
}
