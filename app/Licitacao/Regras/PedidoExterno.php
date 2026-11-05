<?php

namespace App\Licitacao\Regras;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Pedidos a pessoas de fora do órgão por e-mail: notificar (com registro de ciência), colher assinatura em peça e
 * pedir documentos. Garante a publicidade e o contraditório dos atos que afetam terceiros (art. 5º e art. 12, I,
 * da Lei 14.133) e deixa tudo registrado nos autos.
 *
 * Regra pura.
 */
final class PedidoExterno
{
    public const TIPOS = [
        'notificacao' => 'Notificação com ciência',
        'assinatura' => 'Assinatura de peça',
        'documentos' => 'Envio de documentos',
    ];

    public const PRAZO_MAXIMO_DIAS = 60;

    /** @return list<string> */
    public static function motivosCriacao(string $tipo, bool $documentoAguardaAssinatura, CarbonInterface $prazo, CarbonInterface $hoje, ?string $mensagem): array
    {
        $m = [];
        if (! array_key_exists($tipo, self::TIPOS)) {
            return ['Tipo de pedido inválido.'];
        }
        if ($tipo === 'assinatura' && ! $documentoAguardaAssinatura) {
            $m[] = 'Escolha uma peça que esteja aguardando assinatura.';
        }
        $prazo = CarbonImmutable::parse($prazo)->startOfDay();
        $hoje = CarbonImmutable::parse($hoje)->startOfDay();
        if ($prazo->lessThan($hoje)) {
            $m[] = 'O prazo não pode estar no passado.';
        } elseif ($prazo->greaterThan($hoje->addDays(self::PRAZO_MAXIMO_DIAS))) {
            $m[] = 'O prazo pode ser de no máximo '.self::PRAZO_MAXIMO_DIAS.' dias.';
        }
        if (mb_strlen(trim((string) $mensagem)) < 10) {
            $m[] = 'Escreva a mensagem que vai no e-mail, com pelo menos 10 caracteres.';
        }

        return $m;
    }

    /** O link vale até o fim do dia do prazo. @return list<string> */
    public static function motivosResposta(bool $cancelada, bool $respondida, CarbonInterface $prazo, CarbonInterface $agora): array
    {
        return match (true) {
            $cancelada => ['Este pedido foi cancelado pelo órgão.'],
            $respondida => ['Este pedido já foi respondido.'],
            CarbonImmutable::parse($agora)->startOfDay()->greaterThan(CarbonImmutable::parse($prazo)->startOfDay()) => ['O prazo deste pedido terminou em '.CarbonImmutable::parse($prazo)->format('d/m/Y').'. Fale com o órgão para receber um novo link.'],
            default => [],
        };
    }

    /** Quem assina por fora confirma o próprio nome como o órgão cadastrou (sem diferença de caixa ou acento). */
    public static function nomeConfere(string $cadastrado, ?string $digitado): bool
    {
        $n = fn (string $s) => preg_replace('/\s+/', ' ', trim(Str::lower(Str::ascii($s))));

        return $digitado !== null && $n($cadastrado) !== '' && $n($cadastrado) === $n($digitado);
    }
}
