<?php

namespace App\Licitacao\Regras;

/**
 * Nota de empenho e liquidação (6.4). A despesa só se realiza com prévio empenho, que reserva a dotação para o credor
 * (Lei 4.320/1964, arts. 58 a 61); a liquidação apura o direito do credor contra o empenho (art. 63) e o pagamento
 * segue a ordem cronológica da Lei 14.133 (art. 141). O contrato indica o crédito pelo qual a despesa corre (art. 92, VIII).
 */
final class NotaEmpenho
{
    /** Modalidades de empenho (Lei 4.320, art. 60, §§ 2º e 3º). */
    public const TIPOS = [
        'ordinario' => 'Ordinário (valor certo, pago de uma vez)',
        'estimativo' => 'Estimativo (montante não previsível, como consumo)',
        'global' => 'Global (contrato com parcelas)',
    ];

    public static function saldo(float $valor, float $anulado, float $liquidado): float
    {
        return round($valor - $anulado - $liquidado, 2);
    }

    /**
     * Confere o cadastro da nota de empenho.
     *
     * @param  array{numero: string, tipo: string, valor: float, emitido_em: string, exercicio: int, natureza_despesa: string, fonte_recurso: string}  $e
     * @return list<string>
     */
    public static function motivosCadastro(array $e, string $hoje, ?float $saldoContrato = null): array
    {
        $m = [];
        if (! preg_match('/^[0-9A-Za-z\/.\- ]{3,30}$/', trim($e['numero']))) {
            $m[] = 'Informe o número da nota de empenho como está no sistema orçamentário (3 a 30 caracteres).';
        }
        if (! isset(self::TIPOS[$e['tipo']])) {
            $m[] = 'Escolha a modalidade do empenho: ordinário, estimativo ou global (Lei 4.320, art. 60).';
        }
        if ($e['valor'] <= 0) {
            $m[] = 'O valor empenhado precisa ser maior que zero.';
        }
        if ($e['emitido_em'] > $hoje) {
            $m[] = 'A nota de empenho não pode ter data futura.';
        }
        if ((int) substr($e['emitido_em'], 0, 4) !== $e['exercicio']) {
            $m[] = 'O exercício do empenho é o ano da emissão (Lei 4.320, art. 35, II).';
        }
        if (! preg_match('/^\d(\.\d){3}(\.\d{2}){0,2}$|^\d\.\d\.\d{2}\.\d{2}(\.\d{2})?$/', trim($e['natureza_despesa']))) {
            $m[] = 'Informe a natureza da despesa no formato do plano de contas (ex.: 3.3.90.30 ou 3.3.90.39.99).';
        }
        if (mb_strlen(trim($e['fonte_recurso'])) < 3) {
            $m[] = 'Informe a fonte de recursos do empenho.';
        }
        if ($saldoContrato !== null && $e['valor'] > $saldoContrato + 0.001) {
            $m[] = sprintf('O empenho passaria do valor do contrato ainda não empenhado (R$ %s). Formalize o aditivo antes (art. 125).', number_format(max(0, $saldoContrato), 2, ',', '.'));
        }

        return $m;
    }

    /**
     * Confere o empenho escolhido para a liquidação.
     *
     * @param  array{fornecedor_id: int, contrato_id: ?int, ata_id?: ?int, fonte_recurso: string, exercicio: int, anulado: bool, saldo: float}  $empenho
     * @return list<string>
     */
    public static function motivosLiquidacao(array $empenho, int $fornecedorId, ?int $contratoId, string $fonte, float $valor, int $anoLiquidacao, ?int $ataId = null): array
    {
        $m = [];
        if ($empenho['anulado']) {
            $m[] = 'Este empenho foi anulado: não há crédito reservado para liquidar.';
        }
        if ($empenho['fornecedor_id'] !== $fornecedorId) {
            $m[] = 'O empenho é de outro credor: a liquidação só vale para quem foi empenhado (Lei 4.320, art. 58).';
        }
        if ($empenho['contrato_id'] !== null && $empenho['contrato_id'] !== $contratoId) {
            $m[] = 'O empenho está ligado a outro contrato.';
        }
        if (($empenho['ata_id'] ?? null) !== null && $empenho['ata_id'] !== $ataId) {
            $m[] = 'O empenho está ligado a outra ata de registro de preços.';
        }
        if (mb_strtolower(trim($empenho['fonte_recurso'])) !== mb_strtolower(trim($fonte))) {
            $m[] = sprintf('A fonte da liquidação precisa ser a do empenho (%s): cada fonte tem a sua ordem cronológica (art. 141).', trim($empenho['fonte_recurso']));
        }
        if ($empenho['exercicio'] > $anoLiquidacao) {
            $m[] = 'Empenho de exercício futuro não se liquida agora.';
        }
        if ($valor > $empenho['saldo'] + 0.001) {
            $m[] = sprintf('O valor passa do saldo do empenho (R$ %s). Reforce o empenho antes de liquidar (Lei 4.320, art. 59).', number_format(max(0, $empenho['saldo']), 2, ',', '.'));
        }

        return $m;
    }
}
