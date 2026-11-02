<?php

namespace App\Pncp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente da API do PNCP (manual de integração v1.0.0). Autentica com o login da plataforma,
 * guarda o JWT por 55 minutos (ele vale 1 hora) e repete a chamada uma vez se receber 401.
 */
class PncpCliente
{
    public function urlBase(string $ambiente): string
    {
        return config("nexplangov.pncp.urls.{$ambiente}") ?? throw new RuntimeException("Ambiente do PNCP desconhecido: {$ambiente}");
    }

    public function credenciaisConfiguradas(): bool
    {
        return filled(config('nexplangov.pncp.login')) && filled(config('nexplangov.pncp.senha'));
    }

    public function token(string $ambiente, bool $renovar = false): string
    {
        $chave = "pncp.token.{$ambiente}";

        if ($renovar) {
            Cache::forget($chave);
        }

        return Cache::remember($chave, now()->addMinutes((int) config('nexplangov.pncp.token_minutos', 55)), function () use ($ambiente) {
            if (! $this->credenciaisConfiguradas()) {
                throw new RuntimeException('Credenciais da plataforma no PNCP não configuradas (PNCP_LOGIN e PNCP_SENHA).');
            }

            $resposta = Http::baseUrl($this->urlBase($ambiente))
                ->timeout((int) config('nexplangov.pncp.timeout', 30))
                ->acceptJson()
                ->post('/v1/usuarios/login', ['login' => config('nexplangov.pncp.login'), 'senha' => config('nexplangov.pncp.senha')]);

            $token = trim(str_ireplace('Bearer', '', (string) $resposta->header('Authorization')));

            if (! $resposta->successful() || $token === '') {
                throw new RuntimeException('Login no PNCP recusado (HTTP '.$resposta->status().').');
            }

            return $token;
        });
    }

    /** @param  array<string, mixed>  $corpo */
    public function enviar(string $ambiente, string $metodo, string $rota, array $corpo = []): Response
    {
        $resposta = $this->requisicao($ambiente, $this->token($ambiente))->send($metodo, $rota, $corpo === [] ? [] : ['json' => $corpo]);

        if ($resposta->status() === 401) {
            $resposta = $this->requisicao($ambiente, $this->token($ambiente, renovar: true))->send($metodo, $rota, $corpo === [] ? [] : ['json' => $corpo]);
        }

        return $resposta;
    }

    private function requisicao(string $ambiente, string $token): PendingRequest
    {
        return Http::baseUrl($this->urlBase($ambiente))
            ->timeout((int) config('nexplangov.pncp.timeout', 30))
            ->acceptJson()
            ->withToken($token);
    }
}
