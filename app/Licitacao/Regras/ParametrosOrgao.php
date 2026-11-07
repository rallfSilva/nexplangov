<?php

namespace App\Licitacao\Regras;

/**
 * Parâmetros que cada órgão ajusta pela tela, dentro de limites (11.2 e 11.5). O padrão vem de config/nexplangov.php.
 * Valores da lei atualizados por decreto (art. 182) continuam na tela "Valores da lei", fora daqui. Os limites
 * impedem configurar algo que contrarie a Lei 14.133 ou esvazie um controle.
 */
final class ParametrosOrgao
{
    /** @var array<string, array{rotulo: string, grupo: string, tipo: string, config: string, min: int, max: int, unidade: string, base: string, ajuda?: string}> */
    public const DEFINICOES = [
        'pagamento_prazo_dias' => ['rotulo' => 'Prazo para pagar depois da liquidação', 'grupo' => 'Pagamentos', 'tipo' => 'inteiro', 'config' => 'nexplangov.pagamento_prazo_dias', 'min' => 1, 'max' => 30, 'unidade' => 'dias', 'base' => 'art. 92, V, e art. 141', 'ajuda' => 'Vale quando o contrato não fixar outro prazo.'],
        'alertas_vigencia' => ['rotulo' => 'Avisos antes do fim da vigência do contrato', 'grupo' => 'Contratos', 'tipo' => 'lista', 'config' => 'nexplangov.alertas_vigencia', 'min' => 1, 'max' => 365, 'unidade' => 'dias antes', 'base' => 'art. 107', 'ajuda' => 'Até 8 marcos, separados por vírgula. Ex.: 120, 90, 60, 30.'],
        'irp_prazo_minimo_dias_uteis' => ['rotulo' => 'Prazo mínimo de manifestação na IRP', 'grupo' => 'Registro de preços', 'tipo' => 'inteiro', 'config' => 'nexplangov.irp_prazo_minimo_dias_uteis', 'min' => 1, 'max' => 30, 'unidade' => 'dias úteis', 'base' => 'art. 86', 'ajuda' => 'Confira o regulamento do seu ente.'],
        'carona_prazo_contratacao_dias' => ['rotulo' => 'Prazo do aderente para contratar depois da autorização', 'grupo' => 'Registro de preços', 'tipo' => 'inteiro', 'config' => 'nexplangov.carona_prazo_contratacao_dias', 'min' => 1, 'max' => 90, 'unidade' => 'dias', 'base' => 'art. 86, § 2º'],
        'carona_link_aceite_dias' => ['rotulo' => 'Validade do link de aceite do fornecedor na carona', 'grupo' => 'Registro de preços', 'tipo' => 'inteiro', 'config' => 'nexplangov.carona_link_aceite_dias', 'min' => 1, 'max' => 30, 'unidade' => 'dias', 'base' => 'art. 86, § 2º'],
        'preco_tolerancia_pct' => ['rotulo' => 'Tolerância para preço destoante na pesquisa', 'grupo' => 'Pesquisa de preços', 'tipo' => 'inteiro', 'config' => 'nexplangov.preco_tolerancia_pct', 'min' => 10, 'max' => 90, 'unidade' => '% da mediana', 'base' => 'art. 23', 'ajuda' => 'Cotação acima ou abaixo da mediana nessa proporção é descartada como inexequível ou excessiva.'],
        'ceis_validade_horas' => ['rotulo' => 'Validade da consulta ao CEIS e ao CNEP', 'grupo' => 'Fornecedores', 'tipo' => 'inteiro', 'config' => 'nexplangov.transparencia.validade_horas', 'min' => 1, 'max' => 72, 'unidade' => 'horas', 'base' => 'art. 161', 'ajuda' => 'Passado esse tempo, a consulta é refeita antes de contratar.'],
        'capacitacao_validade_meses' => ['rotulo' => 'Validade da capacitação dos agentes', 'grupo' => 'Governança', 'tipo' => 'inteiro', 'config' => 'nexplangov.capacitacao_validade_meses', 'min' => 6, 'max' => 60, 'unidade' => 'meses', 'base' => 'art. 7º, II, e art. 173'],
        'ia_limite_diario' => ['rotulo' => 'Usos da NexMind IA por servidor por dia', 'grupo' => 'Governança', 'tipo' => 'inteiro', 'config' => 'nexplangov.ia.limite_diario_por_usuario', 'min' => 0, 'max' => 200, 'unidade' => 'usos', 'base' => 'controle de custo', 'ajuda' => 'Zero desliga a IA para o órgão.'],
    ];

    /**
     * Lê e confere o valor digitado. Devolve o valor normalizado ou os motivos da recusa.
     *
     * @return array{0: int|list<int>|null, 1: list<string>}
     */
    public static function validar(string $chave, mixed $entrada): array
    {
        $d = self::DEFINICOES[$chave] ?? null;
        if (! $d) {
            return [null, ['Parâmetro desconhecido.']];
        }
        $fora = fn (int $v) => $v < $d['min'] || $v > $d['max'];
        $msg = sprintf('%s: use de %d a %d %s (%s).', $d['rotulo'], $d['min'], $d['max'], $d['unidade'], $d['base']);

        if ($d['tipo'] === 'lista') {
            $partes = array_values(array_filter(array_map('trim', explode(',', (string) $entrada)), fn ($p) => $p !== ''));
            if (! $partes || count($partes) > 8 || array_filter($partes, fn ($p) => ! ctype_digit($p))) {
                return [null, [$d['rotulo'].': informe de 1 a 8 números inteiros separados por vírgula.']];
            }
            $v = array_map('intval', $partes);
            if (array_filter($v, $fora)) {
                return [null, [$msg]];
            }
            $v = array_values(array_unique($v));
            rsort($v);

            return [$v, []];
        }

        if (! is_numeric($entrada) || (string) (int) $entrada !== trim((string) $entrada)) {
            return [null, [$d['rotulo'].': informe um número inteiro.']];
        }

        return $fora((int) $entrada) ? [null, [$msg]] : [(int) $entrada, []];
    }

    /** Valor guardado no banco (texto) de volta ao tipo do parâmetro. */
    public static function ler(string $chave, ?string $guardado, mixed $padrao): mixed
    {
        if ($guardado === null) {
            return $padrao;
        }

        return (self::DEFINICOES[$chave]['tipo'] ?? 'inteiro') === 'lista' ? array_map('intval', json_decode($guardado, true) ?: []) : (int) $guardado;
    }
}
