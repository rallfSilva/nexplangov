<?php

namespace App\Pncp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Cliente da API do PNCP (manual de integração). Autentica com o login da plataforma,
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

    /**
     * Envia JSON, ou multipart quando há documento: a inclusão da compra leva a parte "compra" (JSON) e a
     * parte "documento" (arquivo); os endpoints de arquivos levam só a parte "arquivo". O título e o tipo
     * do documento vão nos cabeçalhos Titulo-Documento e Tipo-Documento-Id.
     *
     * @param  array<string, mixed>  $corpo
     * @param  array{caminho: string, titulo: string, tipo: int}|null  $documento
     */
    public function enviar(string $ambiente, string $metodo, string $rota, array $corpo = [], ?array $documento = null, ?string $parteJson = null): Response
    {
        $chamar = function (string $token) use ($ambiente, $metodo, $rota, $corpo, $documento, $parteJson) {
            $req = $this->requisicao($ambiente, $token);

            if ($documento === null) {
                return $req->send($metodo, $rota, $corpo === [] ? [] : ['json' => $corpo]);
            }

            $req = $req->withHeaders(['Titulo-Documento' => self::tituloDocumento($documento['titulo']), 'Tipo-Documento-Id' => (string) $documento['tipo']]);
            if ($parteJson) {
                $req = $req->attach($parteJson, json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), null, ['Content-Type' => 'application/json']);
            }

            return $req->attach($parteJson ? 'documento' : 'arquivo', Storage::disk('local')->get($documento['caminho']), Str::slug($documento['titulo']).'.pdf', ['Content-Type' => 'application/pdf'])
                ->post($rota);
        };

        $resposta = $chamar($this->token($ambiente));

        if ($resposta->status() === 401) {
            $resposta = $chamar($this->token($ambiente, renovar: true));
        }

        return $resposta;
    }

    /** O cabeçalho aceita até 50 caracteres; sem acento para não depender da codificação do servidor. */
    public static function tituloDocumento(string $titulo): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', Str::ascii(str_replace('·', '-', $titulo)))), 0, 50);
    }

    /** Consulta (GET) usada na homologação: órgão, unidades e o que foi publicado. */
    public function consultar(string $ambiente, string $rota): Response
    {
        return $this->requisicao($ambiente, $this->token($ambiente))->get($rota);
    }

    private function requisicao(string $ambiente, string $token): PendingRequest
    {
        return Http::baseUrl($this->urlBase($ambiente))
            ->timeout((int) config('nexplangov.pncp.timeout', 30))
            ->acceptJson()
            ->withToken($token);
    }
}
