<?php

namespace App\Licitacao\Enums;

/** Parâmetros da pesquisa de preços (art. 23, § 1º, I a V). */
enum FontePreco: string
{
    case PainelOficial = 'painel_oficial';          // I: composição de custos do Painel de Preços / banco de preços em saúde
    case ContratacoesSimilares = 'contratacoes_similares'; // II: contratações similares feitas pela Administração em até 1 ano
    case MidiaEspecializada = 'midia_especializada'; // III: tabela de referência, mídia especializada, sítios eletrônicos
    case Fornecedor = 'fornecedor';                  // IV: pesquisa direta com no mínimo 3 fornecedores, orçamentos de até 6 meses
    case NotasFiscais = 'notas_fiscais';             // V: base nacional de notas fiscais eletrônicas

    public function rotulo(): string
    {
        return match ($this) {
            self::PainelOficial => 'Painel oficial de preços (art. 23, § 1º, I)',
            self::ContratacoesSimilares => 'Contratações similares (art. 23, § 1º, II)',
            self::MidiaEspecializada => 'Mídia especializada ou sítio eletrônico (art. 23, § 1º, III)',
            self::Fornecedor => 'Cotação direta com fornecedor (art. 23, § 1º, IV)',
            self::NotasFiscais => 'Notas fiscais eletrônicas (art. 23, § 1º, V)',
        };
    }

    /** Idade máxima da cotação, em meses, quando a lei fixa uma. */
    public function validadeMeses(): ?int
    {
        return match ($this) {
            self::ContratacoesSimilares => 12,
            self::Fornecedor => 6,
            default => null,
        };
    }

    /** @return array<string, string> */
    public static function opcoes(): array
    {
        return array_combine(array_map(fn ($c) => $c->value, self::cases()), array_map(fn ($c) => $c->rotulo(), self::cases()));
    }
}
