<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\PedidoExterno as P;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Pedidos a externos por e-mail: criação, validade do link e conferência do nome de quem assina. */
class PedidoExternoTest extends TestCase
{
    public function test_criacao(): void
    {
        $hoje = CarbonImmutable::parse('2026-11-05');
        $this->assertSame([], P::motivosCriacao('documentos', false, $hoje->addDays(5), $hoje, 'Envie a certidão do FGTS.'));
        $this->assertSame(['Tipo de pedido inválido.'], P::motivosCriacao('outro', false, $hoje, $hoje, 'Mensagem qualquer aqui.'));
        $this->assertCount(1, P::motivosCriacao('assinatura', false, $hoje->addDay(), $hoje, 'Assine o termo, por favor.'));
        $this->assertCount(1, P::motivosCriacao('notificacao', false, $hoje->subDay(), $hoje, 'Ciência da decisão.'));
        $this->assertCount(1, P::motivosCriacao('notificacao', false, $hoje->addDays(61), $hoje, 'Ciência da decisão.'));
    }

    public function test_link_vale_ate_o_fim_do_dia_do_prazo(): void
    {
        $prazo = CarbonImmutable::parse('2026-11-10');
        $this->assertSame([], P::motivosResposta(false, false, $prazo, CarbonImmutable::parse('2026-11-10 23:59')));
        $this->assertStringContainsString('10/11/2026', P::motivosResposta(false, false, $prazo, CarbonImmutable::parse('2026-11-11 00:01'))[0]);
        $this->assertSame(['Este pedido já foi respondido.'], P::motivosResposta(false, true, $prazo, $prazo));
        $this->assertSame(['Este pedido foi cancelado pelo órgão.'], P::motivosResposta(true, true, $prazo, $prazo));
    }

    public function test_nome_confere_sem_caixa_nem_acento(): void
    {
        $this->assertTrue(P::nomeConfere('José da Conceição', '  jose  da conceicao '));
        $this->assertFalse(P::nomeConfere('José da Conceição', 'José Conceição'));
        $this->assertFalse(P::nomeConfere('José da Conceição', null));
    }
}
