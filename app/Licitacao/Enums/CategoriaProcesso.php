<?php

namespace App\Licitacao\Enums;

/** Categoria do processo, com os códigos do PNCP (tabela 5.11). */
enum CategoriaProcesso: int
{
    case Cessao = 1;
    case Compras = 2;
    case Informatica = 3;
    case Internacional = 4;
    case LocacaoImoveis = 5;
    case MaoDeObra = 6;
    case Obras = 7;
    case Servicos = 8;
    case ServicosEngenharia = 9;
    case ServicosSaude = 10;

    public function rotulo(): string
    {
        return match ($this) {
            self::Cessao => 'Cessão',
            self::Compras => 'Compras',
            self::Informatica => 'Informática (TIC)',
            self::Internacional => 'Internacional',
            self::LocacaoImoveis => 'Locação de imóveis',
            self::MaoDeObra => 'Mão de obra',
            self::Obras => 'Obras',
            self::Servicos => 'Serviços',
            self::ServicosEngenharia => 'Serviços de engenharia',
            self::ServicosSaude => 'Serviços de saúde',
        };
    }

    /** Obras e serviços de engenharia usam o inciso I do art. 75; o resto, o inciso II. */
    public function ehEngenharia(): bool
    {
        return in_array($this, [self::Obras, self::ServicosEngenharia], true);
    }

    /** @return array<int, string> */
    public static function opcoes(): array
    {
        return array_combine(array_map(fn ($c) => $c->value, self::cases()), array_map(fn ($c) => $c->rotulo(), self::cases()));
    }
}
