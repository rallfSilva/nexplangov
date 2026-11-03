<?php

namespace App\Licitacao\Regras;

use App\Licitacao\Enums\CategoriaProcesso;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Ordem cronológica de pagamentos (art. 141): uma fila para cada fonte de recursos, subdividida em
 * fornecimento de bens, locações, prestação de serviços e obras. A ordem só é alterada com justificativa
 * prévia da autoridade, numa das hipóteses do § 1º, e comunicação posterior ao controle interno e ao
 * tribunal de contas. A fila e as justificativas são divulgadas todo mês (§ 3º).
 */
final class OrdemCronologica
{
    public const CATEGORIAS = [
        'fornecimento' => 'Fornecimento de bens (art. 141, I)',
        'locacao' => 'Locações (art. 141, II)',
        'servicos' => 'Prestação de serviços (art. 141, III)',
        'obras' => 'Realização de obras (art. 141, IV)',
    ];

    public const HIPOTESES = [
        'I' => 'Grave perturbação da ordem, situação de emergência ou calamidade pública',
        'II' => 'Pagamento a microempresa, empresa de pequeno porte, agricultor familiar, produtor rural pessoa física, MEI ou cooperativa, com risco de descontinuidade do contrato',
        'III' => 'Serviços necessários ao funcionamento dos sistemas estruturantes, com risco de descontinuidade',
        'IV' => 'Direitos de contrato em caso de falência, recuperação judicial ou dissolução da contratada',
        'V' => 'Objeto imprescindível à integridade do patrimônio público ou às atividades finalísticas, com risco de descontinuidade ou prejuízo',
    ];

    /** Categoria sugerida a partir da categoria do processo (pode ser corrigida na liquidação). */
    public function categoriaSugerida(?int $categoriaProcesso): string
    {
        return match (CategoriaProcesso::tryFrom((int) $categoriaProcesso)) {
            CategoriaProcesso::Compras => 'fornecimento',
            CategoriaProcesso::LocacaoImoveis => 'locacao',
            CategoriaProcesso::Obras => 'obras',
            default => 'servicos',
        };
    }

    /**
     * Pagar fora da ordem exige justificativa aprovada; a lista são os créditos mais antigos ainda em aberto na mesma fila.
     *
     * @param  list<string>  $anterioresEmAberto  identificação dos créditos que estão à frente
     * @return list<string>
     */
    public function validarPagamento(array $anterioresEmAberto, bool $quebraAutorizada): array
    {
        if ($anterioresEmAberto === [] || $quebraAutorizada) {
            return [];
        }

        return [sprintf('Há %d crédito(s) à frente na ordem cronológica desta fonte e categoria (%s). Pague-os antes ou registre a justificativa da autoridade (art. 141, § 1º).',
            count($anterioresEmAberto), implode(', ', array_slice($anterioresEmAberto, 0, 5)))];
    }

    /** @return list<string> */
    public function validarJustificativa(string $hipotese, string $texto): array
    {
        $m = [];
        if (! array_key_exists($hipotese, self::HIPOTESES)) {
            $m[] = 'Escolha uma das hipóteses do art. 141, § 1º (incisos I a V).';
        }
        if (mb_strlen(trim($texto)) < 50) {
            $m[] = 'Demonstre o fato que justifica a alteração da ordem, inclusive o risco de descontinuidade quando for o caso (mínimo 50 caracteres).';
        }

        return $m;
    }

    /** Vencimento do pagamento: o prazo do contrato (art. 92, V), contado da liquidação. */
    public function vencimento(CarbonInterface $liquidacao, int $dias): CarbonImmutable
    {
        return CarbonImmutable::parse($liquidacao->toDateString())->addDays($dias);
    }

    /** Atraso superior a 2 meses dá ao contratado o direito de extinguir o contrato (art. 137, § 2º, IV). */
    public function atrasoPermiteExtincao(CarbonInterface $vencimento, CarbonInterface $hoje): bool
    {
        return CarbonImmutable::parse($vencimento->toDateString())->addMonthsNoOverflow(2)->lt(CarbonImmutable::parse($hoje->toDateString()));
    }
}
