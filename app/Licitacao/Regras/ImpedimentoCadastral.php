<?php

namespace App\Licitacao\Regras;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Sanções do fornecedor nos cadastros nacionais CEIS e CNEP (art. 161 da Lei 14.133) que impedem contratar.
 *
 * - Declaração de inidoneidade impede licitar e contratar com toda a Administração, em todos os entes
 *   (art. 156, IV e § 5º): bloqueia sempre.
 * - As demais sanções vigentes (impedimento, suspensão, proibição judicial) podem ter alcance limitado ao ente
 *   que as aplicou (art. 156, § 4º) ou à decisão judicial. Bloqueiam até a autoridade registrar a análise de
 *   abrangência, que fica nos autos.
 */
final class ImpedimentoCadastral
{
    public const JUSTIFICATIVA_MINIMA = 30;

    /**
     * @param  list<array{cadastro: string, tipo: string, orgao: string, inicio: ?string, fim: ?string}>  $registros
     * @return list<array{cadastro: string, tipo: string, orgao: string, inicio: ?string, fim: ?string}>
     */
    public static function vigentes(array $registros, CarbonInterface $hoje): array
    {
        $hoje = CarbonImmutable::parse($hoje)->startOfDay();

        return array_values(array_filter($registros, function ($r) use ($hoje) {
            $comecou = $r['inicio'] === null || CarbonImmutable::parse($r['inicio'])->startOfDay()->lessThanOrEqualTo($hoje);
            $acabou = $r['fim'] !== null && CarbonImmutable::parse($r['fim'])->startOfDay()->lessThan($hoje);

            return $comecou && ! $acabou;
        }));
    }

    /**
     * Sanção que restringe licitar ou contratar. Multa e advertência (comuns no CNEP, Lei 12.846/2013) aparecem
     * na consulta, mas sozinhas não impedem a contratação.
     *
     * @param  array{tipo: string}  $registro
     */
    public static function ehImpeditiva(array $registro): bool
    {
        return (bool) preg_match('/inidon|impedi|suspens|proibi/u', mb_strtolower($registro['tipo']));
    }

    /** @param array{tipo: string} $registro */
    public static function ehInidoneidade(array $registro): bool
    {
        return str_contains(mb_strtolower($registro['tipo']), 'inidon');
    }

    /** A análise de abrangência só cabe se nenhuma sanção vigente for de inidoneidade. */
    public static function podeRessalvar(array $vigentes): bool
    {
        $impeditivas = array_filter($vigentes, [self::class, 'ehImpeditiva']);

        return $impeditivas !== [] && ! array_filter($impeditivas, [self::class, 'ehInidoneidade']);
    }

    /**
     * Motivos que impedem contratar o fornecedor. Lista vazia: pode contratar.
     *
     * @param  list<array{cadastro: string, tipo: string, orgao: string, inicio: ?string, fim: ?string}>  $vigentes
     * @return list<string>
     */
    public static function motivos(array $vigentes, bool $ressalvado): array
    {
        $motivos = [];

        foreach (array_filter($vigentes, [self::class, 'ehImpeditiva']) as $r) {
            $periodo = ($r['fim'] ?? null) ? ' até '.CarbonImmutable::parse($r['fim'])->format('d/m/Y') : ' sem data de fim';
            $origem = strtoupper($r['cadastro']).": {$r['tipo']} aplicada por {$r['orgao']}{$periodo}";

            if (self::ehInidoneidade($r)) {
                $motivos[] = "Fornecedor declarado inidôneo ({$origem}). A inidoneidade impede contratar com toda a Administração Pública (art. 156, IV e § 5º).";
            } elseif (! $ressalvado) {
                $motivos[] = "Sanção vigente no cadastro nacional ({$origem}). Antes de contratar, a autoridade registra a análise de abrangência: se a sanção alcança este órgão (art. 156, § 4º).";
            }
        }

        return $motivos;
    }

    public static function justificativaValida(?string $texto): bool
    {
        return mb_strlen(trim((string) $texto)) >= self::JUSTIFICATIVA_MINIMA;
    }
}
