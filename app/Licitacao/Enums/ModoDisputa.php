<?php

namespace App\Licitacao\Enums;

/** Modos de disputa (art. 56), com os códigos do PNCP (tabela 5.3). */
enum ModoDisputa: int
{
    case Aberto = 1;
    case Fechado = 2;
    case AbertoFechado = 3;
    case DispensaComDisputa = 4;
    case NaoSeAplica = 5;

    public function rotulo(): string
    {
        return match ($this) {
            self::Aberto => 'Aberto',
            self::Fechado => 'Fechado',
            self::AbertoFechado => 'Aberto e fechado',
            self::DispensaComDisputa => 'Dispensa com disputa',
            self::NaoSeAplica => 'Não se aplica',
        };
    }

    /** @return array<int, string> */
    public static function opcoes(): array
    {
        return array_combine(array_map(fn ($c) => $c->value, self::cases()), array_map(fn ($c) => $c->rotulo(), self::cases()));
    }
}
