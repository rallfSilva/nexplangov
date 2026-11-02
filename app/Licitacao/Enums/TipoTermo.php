<?php

namespace App\Licitacao\Enums;

/** Termos de contrato, com os códigos do PNCP (tabela 5.10) e o tipo de documento (5.12). */
enum TipoTermo: string
{
    case AditivoValor = 'aditivo_valor';
    case AditivoPrazo = 'aditivo_prazo';
    case Apostilamento = 'apostilamento';
    case Rescisao = 'rescisao';

    public function rotulo(): string
    {
        return match ($this) {
            self::AditivoValor => 'Termo aditivo de valor (acréscimo ou supressão)',
            self::AditivoPrazo => 'Termo aditivo de prazo (prorrogação)',
            self::Apostilamento => 'Apostilamento (reajuste ou repactuação, art. 136)',
            self::Rescisao => 'Termo de rescisão (extinção, arts. 137 a 139)',
        };
    }

    public function codigoPncp(): int
    {
        return match ($this) {
            self::Rescisao => 1,
            self::AditivoValor, self::AditivoPrazo => 2,
            self::Apostilamento => 3,
        };
    }

    public function tipoDocumentoPncp(): int
    {
        return match ($this) {
            self::Rescisao => 13,
            self::AditivoValor, self::AditivoPrazo => 14,
            self::Apostilamento => 15,
        };
    }

    /** @return array<string, string> */
    public static function opcoes(): array
    {
        return array_combine(array_map(fn ($c) => $c->value, self::cases()), array_map(fn ($c) => $c->rotulo(), self::cases()));
    }
}
