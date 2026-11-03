<?php

namespace App\Licitacao\Ia;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use Throwable;

/**
 * Cliente do Claude pela SDK oficial. A chave fica só no .env (ANTHROPIC_API_KEY).
 * Usa o fallback do servidor: se o modelo principal recusar, outro modelo responde.
 */
final class ClienteClaude implements ClienteIa
{
    public function responder(string $sistema, string $pedido): array
    {
        $chave = config('nexplangov.ia.chave');
        if (! $chave) {
            throw new IaIndisponivel('A chave da IA não está configurada no servidor.');
        }

        try {
            $client = new Client(apiKey: $chave);
            $m = $client->beta->messages->create(
                maxTokens: 16000,
                messages: [['role' => 'user', 'content' => $pedido]],
                model: config('nexplangov.ia.modelo'),
                system: $sistema,
                outputConfig: ['effort' => config('nexplangov.ia.esforco')],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (APIException|Throwable $e) {
            report($e);
            throw new IaIndisponivel('A IA não respondeu agora. Tente de novo em instantes.');
        }

        if ($m->stopReason === 'refusal') {
            throw new IaIndisponivel('A IA não atendeu este pedido. Reformule o texto.');
        }

        $texto = '';
        foreach ($m->content as $bloco) {
            if ($bloco->type === 'text') {
                $texto .= $bloco->text;
            }
        }

        return ['texto' => trim($texto), 'tokens_entrada' => (int) $m->usage->inputTokens, 'tokens_saida' => (int) $m->usage->outputTokens];
    }
}
