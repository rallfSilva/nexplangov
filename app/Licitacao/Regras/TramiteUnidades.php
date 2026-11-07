<?php

namespace App\Licitacao\Regras;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Tramitação dos autos entre unidades. A Lei 14.133 exige que o processo seja instruído e decidido por quem tem
 * competência (arts. 7º, 8º e 17), com os atos documentados e preservados (art. 12, I, e art. 6º, LVIII); por isso
 * cada remessa diz de onde, para onde, por quem e quando, e só a unidade de destino recebe.
 *
 * Regra pura: recebe fatos já apurados e devolve os motivos que impedem a ação (lista vazia libera).
 */
final class TramiteUnidades
{
    public const DESPACHO_MINIMO = 10;

    public const MOTIVO_MINIMO = 15;

    /** @return list<string> */
    public static function motivosRemessa(bool $arquivado, bool $remessaPendente, ?int $unidadeAtual, ?int $destino, bool $atuaNaUnidadeAtual, ?string $despacho): array
    {
        $m = [];

        if ($arquivado) {
            $m[] = 'O processo está arquivado. Desarquive antes de enviar.';
        }
        if ($remessaPendente) {
            $m[] = 'Já existe uma remessa aguardando recebimento. Espere o recebimento ou cancele a remessa.';
        }
        if (! $atuaNaUnidadeAtual) {
            $m[] = 'Só quem está lotado na unidade onde o processo está pode enviá-lo.';
        }
        if ($destino === null) {
            $m[] = 'Escolha a unidade de destino.';
        } elseif ($destino === $unidadeAtual) {
            $m[] = 'O processo já está nessa unidade.';
        }
        if (mb_strlen(trim((string) $despacho)) < self::DESPACHO_MINIMO) {
            $m[] = 'Escreva o despacho de encaminhamento, com pelo menos '.self::DESPACHO_MINIMO.' caracteres.';
        }

        return $m;
    }

    /** @return list<string> */
    public static function motivosRecebimento(bool $pendente, bool $atuaNoDestino): array
    {
        if (! $pendente) {
            return ['Esta remessa não está aguardando recebimento.'];
        }

        return $atuaNoDestino ? [] : ['Só quem está lotado na unidade de destino pode receber o processo.'];
    }

    /** Cancelar a remessa só antes do recebimento, por quem enviou ou por alguém da unidade de origem. @return list<string> */
    public static function motivosCancelamento(bool $pendente, bool $remetenteOuOrigem, ?string $motivo): array
    {
        $m = [];
        if (! $pendente) {
            $m[] = 'A remessa já foi recebida ou cancelada e não pode mais ser cancelada.';
        }
        if (! $remetenteOuOrigem) {
            $m[] = 'Só quem enviou ou alguém da unidade de origem pode cancelar a remessa.';
        }
        if (mb_strlen(trim((string) $motivo)) < self::DESPACHO_MINIMO) {
            $m[] = 'Informe o motivo do cancelamento, com pelo menos '.self::DESPACHO_MINIMO.' caracteres.';
        }

        return $m;
    }

    /** Arquivar só processo encerrado, na unidade onde ele está. @return list<string> */
    public static function motivosArquivamento(bool $encerrado, bool $arquivado, bool $remessaPendente, bool $atuaNaUnidadeAtual, ?string $motivo): array
    {
        $m = [];
        if ($arquivado) {
            $m[] = 'O processo já está arquivado.';
        }
        if (! $encerrado) {
            $m[] = 'Só se arquiva processo encerrado (contratado, deserto, fracassado, revogado ou anulado).';
        }
        if ($remessaPendente) {
            $m[] = 'Há uma remessa aguardando recebimento.';
        }
        if (! $atuaNaUnidadeAtual) {
            $m[] = 'Só quem está lotado na unidade onde o processo está pode arquivá-lo.';
        }
        if (mb_strlen(trim((string) $motivo)) < self::DESPACHO_MINIMO) {
            $m[] = 'Escreva o despacho de arquivamento, com pelo menos '.self::DESPACHO_MINIMO.' caracteres.';
        }

        return $m;
    }

    /** @return list<string> */
    public static function motivosDesarquivamento(bool $arquivado, bool $atuaNaUnidadeAtual, ?string $motivo): array
    {
        $m = [];
        if (! $arquivado) {
            $m[] = 'O processo não está arquivado.';
        }
        if (! $atuaNaUnidadeAtual) {
            $m[] = 'Só quem está lotado na unidade onde o processo foi arquivado pode desarquivá-lo.';
        }
        if (mb_strlen(trim((string) $motivo)) < self::MOTIVO_MINIMO) {
            $m[] = 'Explique por que o processo volta a tramitar, com pelo menos '.self::MOTIVO_MINIMO.' caracteres.';
        }

        return $m;
    }

    /**
     * Períodos de permanência dos autos: em cada unidade (do recebimento até a saída) e em trânsito (do envio ao
     * recebimento). Remessa cancelada devolve os autos à origem sem contar trânsito.
     *
     * @param  list<array{tipo: string, origem: ?int, destino: ?int, enviado_em: CarbonInterface, recebido_em: ?CarbonInterface, cancelado_em: ?CarbonInterface, recebido_por?: ?int}>  $tramites  em ordem de envio
     * @return list<array{unidade: ?int, transito: bool, inicio: CarbonImmutable, fim: ?CarbonImmutable, dias: float, responsavel: ?int}>
     */
    public static function periodos(CarbonInterface $abertura, ?int $unidadeInicial, ?int $responsavelInicial, array $tramites, CarbonInterface $agora): array
    {
        $agora = CarbonImmutable::parse($agora);
        $periodos = [];
        $unidade = $unidadeInicial;
        $inicio = CarbonImmutable::parse($abertura);
        $responsavel = $responsavelInicial;

        foreach ($tramites as $t) {
            if ($t['tipo'] !== 'remessa' || $t['cancelado_em'] !== null) {
                continue;
            }

            $envio = CarbonImmutable::parse($t['enviado_em']);
            $periodos[] = self::periodo($unidade, false, $inicio, $envio, $responsavel);

            $receb = $t['recebido_em'] ? CarbonImmutable::parse($t['recebido_em']) : null;
            $periodos[] = self::periodo(null, true, $envio, $receb, null, $agora);

            if ($receb === null) {
                return $periodos;
            }

            $unidade = $t['destino'];
            $inicio = $receb;
            $responsavel = $t['recebido_por'] ?? null;
        }

        $periodos[] = self::periodo($unidade, false, $inicio, null, $responsavel, $agora);

        return $periodos;
    }

    /**
     * Soma os dias de permanência por unidade (chave 0 = em trânsito).
     *
     * @param  list<array{unidade: ?int, transito: bool, dias: float}>  $periodos
     * @return array<int, float>
     */
    public static function diasPorUnidade(array $periodos): array
    {
        $total = [];
        foreach ($periodos as $p) {
            $chave = $p['transito'] ? 0 : (int) $p['unidade'];
            $total[$chave] = round(($total[$chave] ?? 0) + $p['dias'], 2);
        }
        arsort($total);

        return $total;
    }

    private static function periodo(?int $unidade, bool $transito, CarbonImmutable $inicio, ?CarbonImmutable $fim, ?int $responsavel, ?CarbonImmutable $agora = null): array
    {
        $ate = $fim ?? $agora ?? $inicio;

        return ['unidade' => $unidade, 'transito' => $transito, 'inicio' => $inicio, 'fim' => $fim, 'dias' => round(max(0, $inicio->diffInSeconds($ate)) / 86400, 2), 'responsavel' => $responsavel];
    }
}
