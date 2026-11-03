<?php

namespace App\Licitacao\Ia;

/** Quem responde ao assistente. Em produção é o Claude; nos testes, um cliente falso. */
interface ClienteIa
{
    /**
     * @return array{texto: string, tokens_entrada: int, tokens_saida: int}
     *
     * @throws IaIndisponivel
     */
    public function responder(string $sistema, string $pedido): array;
}
