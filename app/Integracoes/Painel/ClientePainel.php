<?php

namespace App\Integracoes\Painel;

use App\Integracoes\IntegracaoIndisponivel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Cliente da API de entrada do Painel de Compras (SIC/SELC). Endereço e token só no .env
 * (PAINEL_URL e PAINEL_TOKEN; o token é gerado no painel com `php artisan sic:token-integracao NEXPLANGOV`).
 */
final class ClientePainel
{
    public const LIMITE_POR_LOTE = 5000;

    public function habilitado(): bool
    {
        return filled(config('nexplangov.painel.url')) && filled(config('nexplangov.painel.token'));
    }

    /**
     * Envia um lote e devolve a resposta do painel (id e situação do lote).
     *
     * @param  list<array<string, mixed>>  $registros
     *
     * @throws IntegracaoIndisponivel falha de rede ou erro 5xx/429: vale tentar de novo
     * @throws LoteRecusado erro de conteúdo (4xx): tentar de novo não resolve
     */
    public function enviar(string $tipo, array $registros, string $chave, ?string $referencia = null): array
    {
        try {
            $r = Http::baseUrl(rtrim((string) config('nexplangov.painel.url'), '/').'/api/integracao/v1')
                ->withToken((string) config('nexplangov.painel.token'))
                ->acceptJson()->timeout((int) config('nexplangov.painel.timeout', 30))
                ->withHeaders(['Idempotency-Key' => $chave])
                ->post('/lotes', array_filter(['tipo' => $tipo, 'referencia' => $referencia, 'registros' => $registros]));
        } catch (ConnectionException $e) {
            throw new IntegracaoIndisponivel('Sem conexão com o Painel de Compras: '.$e->getMessage(), previous: $e);
        }
        if ($r->serverError() || $r->status() === 429) {
            throw new IntegracaoIndisponivel("Painel de Compras respondeu {$r->status()}.");
        }
        if ($r->failed()) {
            $erros = collect($r->json('errors', []))->flatten()->implode(' ');
            throw new LoteRecusado("Painel de Compras recusou o lote ({$r->status()}): ".trim(($r->json('message') ?? '').' '.$erros));
        }

        return (array) $r->json();
    }
}
