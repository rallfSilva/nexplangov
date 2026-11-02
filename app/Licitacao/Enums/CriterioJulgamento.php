<?php

namespace App\Licitacao\Enums;

/** Critérios de julgamento (art. 33), com os códigos do PNCP (tabela 5.4). */
enum CriterioJulgamento: int
{
    case MenorPreco = 1;
    case MaiorDesconto = 2;
    case MelhorTecnica = 3;
    case TecnicaPreco = 4;
    case MaiorLance = 5;
    case MaiorRetornoEconomico = 6;
    case NaoSeAplica = 7;

    public function rotulo(): string
    {
        return match ($this) {
            self::MenorPreco => 'Menor preço',
            self::MaiorDesconto => 'Maior desconto',
            self::MelhorTecnica => 'Melhor técnica ou conteúdo artístico',
            self::TecnicaPreco => 'Técnica e preço',
            self::MaiorLance => 'Maior lance (leilão)',
            self::MaiorRetornoEconomico => 'Maior retorno econômico',
            self::NaoSeAplica => 'Não se aplica',
        };
    }

    /** @return array<int, string> */
    public static function opcoes(): array
    {
        return array_combine(array_map(fn ($c) => $c->value, self::cases()), array_map(fn ($c) => $c->rotulo(), self::cases()));
    }
}
