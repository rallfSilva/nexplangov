<?php

namespace App\Licitacao\Regras;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Regras do processo de responsabilização de licitante ou contratado (arts. 155 a 163 e 166 a 168).
 *
 * - Infrações: art. 155, I a XII.
 * - Advertência só para a infração do inciso I (art. 156, § 2º).
 * - Impedimento, até 3 anos, para os incisos II a VII (art. 156, § 4º), no âmbito do ente federativo.
 * - Inidoneidade, de 3 a 6 anos, para os incisos VIII a XII ou, justificadamente, II a VII (art. 156, § 5º),
 *   com análise jurídica prévia (§ 6º) e efeito em todos os entes.
 * - Multa de 0,5% a 30% do valor do contrato ou da contratação (art. 156, § 3º), cumulável (§ 7º).
 * - Defesa em 15 dias úteis da intimação (arts. 157 e 158); alegações finais em 15 dias úteis se houver provas (art. 158, § 2º).
 * - Impedimento e inidoneidade: comissão de 2 ou mais servidores estáveis (art. 158).
 * - Recurso em 15 dias úteis; reconsideração em 5 dias úteis; decisão superior em 20 dias úteis (art. 166).
 * - Inidoneidade: pedido de reconsideração em 15 dias úteis, decidido em 20 dias úteis (art. 167).
 * - Informar o CEIS/CNEP em até 15 dias úteis da aplicação (art. 161).
 * - Reabilitação: no mínimo 1 ano (impedimento) ou 3 anos (inidoneidade) da aplicação (art. 163, III).
 */
final class Sancoes
{
    public const INFRACOES = [
        'I' => 'Dar causa à inexecução parcial do contrato',
        'II' => 'Dar causa à inexecução parcial que cause grave dano à Administração, ao funcionamento dos serviços públicos ou ao interesse coletivo',
        'III' => 'Dar causa à inexecução total do contrato',
        'IV' => 'Deixar de entregar a documentação exigida para o certame',
        'V' => 'Não manter a proposta, salvo em decorrência de fato superveniente devidamente justificado',
        'VI' => 'Não celebrar o contrato ou não entregar a documentação exigida para a contratação, quando convocado dentro do prazo de validade da proposta',
        'VII' => 'Ensejar o retardamento da execução ou da entrega do objeto sem motivo justificado',
        'VIII' => 'Apresentar declaração ou documentação falsa ou prestar declaração falsa durante a licitação ou a execução do contrato',
        'IX' => 'Fraudar a licitação ou praticar ato fraudulento na execução do contrato',
        'X' => 'Comportar-se de modo inidôneo ou cometer fraude de qualquer natureza',
        'XI' => 'Praticar atos ilícitos com vistas a frustrar os objetivos da licitação',
        'XII' => 'Praticar ato lesivo previsto no art. 5º da Lei nº 12.846/2013',
    ];

    private const GRUPO_IMPEDIMENTO = ['II', 'III', 'IV', 'V', 'VI', 'VII'];

    private const GRUPO_INIDONEIDADE = ['VIII', 'IX', 'X', 'XI', 'XII'];

    public const PRAZO_DEFESA = 15;

    public const PRAZO_ALEGACOES = 15;

    public const PRAZO_RECURSO = 15;

    public const PRAZO_RECONSIDERACAO = 5;

    public const PRAZO_DECISAO_RECURSO = 20;

    public const PRAZO_CEIS = 15;

    public function __construct(private DiasUteis $dias) {}

    /**
     * Confere se a sanção proposta ou aplicada cabe nas infrações apontadas.
     *
     * @param  list<string>  $infracoes
     * @return list<string>
     */
    public function validarSancao(array $infracoes, string $tipo, ?string $justificativaAgravamento = null): array
    {
        $m = [];
        $invalidas = array_diff($infracoes, array_keys(self::INFRACOES));
        if ($infracoes === [] || $invalidas !== []) {
            return ['Aponte ao menos uma infração do art. 155 (incisos I a XII).'];
        }

        $soLeve = array_diff($infracoes, ['I']) === [];
        $temGrave = array_intersect($infracoes, self::GRUPO_INIDONEIDADE) !== [];
        $justificou = mb_strlen(trim((string) $justificativaAgravamento)) >= 30;

        if ($tipo === 'advertencia' && ! $soLeve) {
            $m[] = 'Advertência só cabe para a inexecução parcial do inciso I (art. 156, § 2º).';
        } elseif ($tipo === 'impedimento' && $temGrave && ! $justificou) {
            $m[] = 'As infrações dos incisos VIII a XII levam à declaração de inidoneidade (art. 156, § 5º). Para impedimento, justifique.';
        } elseif ($tipo === 'impedimento' && $soLeve && ! $justificou) {
            $m[] = 'Para o inciso I, impedimento só com justificativa de maior gravidade (art. 156, § 4º).';
        } elseif ($tipo === 'inidoneidade' && ! $temGrave && ! $justificou) {
            $m[] = 'Inidoneidade para os incisos I a VII exige justificativa de maior gravidade (art. 156, § 5º).';
        } elseif (! in_array($tipo, ['advertencia', 'multa', 'impedimento', 'inidoneidade'], true)) {
            $m[] = 'Tipo de sanção inválido (art. 156).';
        }

        return $m;
    }

    /** Impedimento e inidoneidade exigem comissão de 2 ou mais servidores estáveis (art. 158). */
    public function exigeComissao(string $tipo): bool
    {
        return in_array($tipo, ['impedimento', 'inidoneidade'], true);
    }

    /**
     * Multa de 0,5% a 30% do valor do contrato ou da contratação (art. 156, § 3º).
     *
     * @return list<string>
     */
    public function validarMulta(float $valor, float $base): array
    {
        if ($base <= 0) {
            return ['Informe o valor do contrato ou da contratação, base de cálculo da multa (art. 156, § 3º).'];
        }
        $min = round($base * 0.005, 2);
        $max = round($base * 0.30, 2);
        if ($valor < $min || $valor > $max) {
            return [sprintf('A multa deve ficar entre 0,5%% (R$ %s) e 30%% (R$ %s) do valor do contrato (art. 156, § 3º).', $this->n($min), $this->n($max))];
        }

        return [];
    }

    /**
     * Duração: impedimento até 3 anos; inidoneidade de 3 a 6 anos (art. 156, §§ 4º e 5º).
     *
     * @return list<string>
     */
    public function validarDuracao(string $tipo, ?int $meses): array
    {
        return match ($tipo) {
            'impedimento' => $meses !== null && $meses >= 1 && $meses <= 36 ? [] : ['O impedimento dura de 1 a 36 meses (art. 156, § 4º).'],
            'inidoneidade' => $meses !== null && $meses >= 36 && $meses <= 72 ? [] : ['A inidoneidade dura de 3 a 6 anos, ou seja, de 36 a 72 meses (art. 156, § 5º).'],
            default => [],
        };
    }

    public function prazoDefesa(CarbonInterface $intimacao): CarbonImmutable
    {
        return $this->dias->somar($intimacao, self::PRAZO_DEFESA);
    }

    public function prazoAlegacoes(CarbonInterface $intimacao): CarbonImmutable
    {
        return $this->dias->somar($intimacao, self::PRAZO_ALEGACOES);
    }

    public function prazoRecurso(CarbonInterface $intimacao): CarbonImmutable
    {
        return $this->dias->somar($intimacao, self::PRAZO_RECURSO);
    }

    public function prazoReconsideracao(CarbonInterface $recebimento): CarbonImmutable
    {
        return $this->dias->somar($recebimento, self::PRAZO_RECONSIDERACAO);
    }

    public function prazoDecisaoRecurso(CarbonInterface $recebimento): CarbonImmutable
    {
        return $this->dias->somar($recebimento, self::PRAZO_DECISAO_RECURSO);
    }

    public function prazoCeis(CarbonInterface $aplicacao): CarbonImmutable
    {
        return $this->dias->somar($aplicacao, self::PRAZO_CEIS);
    }

    /** Fim dos efeitos: o último dia do período (início + meses - 1 dia). */
    public function fimDosEfeitos(CarbonInterface $inicio, int $meses): CarbonImmutable
    {
        return CarbonImmutable::parse($inicio->toDateString())->addMonthsNoOverflow($meses)->subDay();
    }

    /** Recurso para advertência, multa e impedimento (art. 166); reconsideração para inidoneidade (art. 167). */
    public function meioDeImpugnacao(string $tipo): string
    {
        return $tipo === 'inidoneidade' ? 'reconsideracao' : 'recurso';
    }

    /**
     * Reabilitação (art. 163): reparação integral, multa paga, prazo mínimo, condições cumpridas e análise jurídica.
     * Para os incisos VIII e XII, também a implantação ou o aperfeiçoamento de programa de integridade.
     *
     * @param  list<string>  $infracoes
     * @param  array{reparacao: bool, multa_paga: bool, condicoes: bool, integridade: bool, parecer: bool}  $requisitos
     * @return list<string>
     */
    public function validarReabilitacao(string $tipo, CarbonInterface $aplicacao, CarbonInterface $hoje, array $infracoes, array $requisitos): array
    {
        $m = [];
        if (! in_array($tipo, ['impedimento', 'inidoneidade'], true)) {
            return ['Só se reabilita quem foi punido com impedimento ou inidoneidade (art. 163).'];
        }
        $minimo = CarbonImmutable::parse($aplicacao->toDateString())->addYears($tipo === 'impedimento' ? 1 : 3);
        if ($hoje->lt($minimo)) {
            $m[] = sprintf('A reabilitação só pode ser pedida a partir de %s: %s da aplicação (art. 163, III).', $minimo->format('d/m/Y'), $tipo === 'impedimento' ? '1 ano' : '3 anos');
        }
        if (! $requisitos['reparacao']) {
            $m[] = 'Falta a reparação integral do dano causado à Administração (art. 163, I).';
        }
        if (! $requisitos['multa_paga']) {
            $m[] = 'Falta o pagamento da multa (art. 163, II).';
        }
        if (! $requisitos['condicoes']) {
            $m[] = 'Falta o cumprimento das condições de reabilitação definidas no ato punitivo (art. 163, IV).';
        }
        if (! $requisitos['parecer']) {
            $m[] = 'Falta a análise jurídica prévia, com posicionamento conclusivo (art. 163, V).';
        }
        if (array_intersect($infracoes, ['VIII', 'XII']) !== [] && ! $requisitos['integridade']) {
            $m[] = 'Para as infrações dos incisos VIII e XII, a reabilitação exige programa de integridade (art. 163, parágrafo único).';
        }

        return $m;
    }

    private function n(float $v): string
    {
        return number_format($v, 2, ',', '.');
    }
}
