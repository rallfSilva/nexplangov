<?php

namespace App\Licitacao\Enums;

/**
 * Amparos legais mais usados, com os códigos do PNCP (tabela 5.15 do manual v1.0.0).
 * A lista completa fica na tabela de domínio do PNCP; confira o Swagger vigente antes de ampliar.
 */
enum AmparoLegal: int
{
    case Art28I = 1;
    case Art28II = 2;
    case Art28III = 3;
    case Art28IV = 4;
    case Art28V = 5;
    case Art74I = 6;
    case Art74II = 7;
    case Art74IIIa = 8;
    case Art74IIIb = 9;
    case Art74IIIc = 10;
    case Art74IV = 16;
    case Art74V = 17;
    case Art75I = 18;
    case Art75II = 19;

    public function rotulo(): string
    {
        return match ($this) {
            self::Art28I => 'Art. 28, I (pregão)',
            self::Art28II => 'Art. 28, II (concorrência)',
            self::Art28III => 'Art. 28, III (concurso)',
            self::Art28IV => 'Art. 28, IV (leilão)',
            self::Art28V => 'Art. 28, V (diálogo competitivo)',
            self::Art74I => 'Art. 74, I (fornecedor exclusivo)',
            self::Art74II => 'Art. 74, II (artista consagrado)',
            self::Art74IIIa => 'Art. 74, III, a (estudos técnicos)',
            self::Art74IIIb => 'Art. 74, III, b (pareceres, perícias e avaliações)',
            self::Art74IIIc => 'Art. 74, III, c (assessorias ou consultorias técnicas)',
            self::Art74IV => 'Art. 74, IV (credenciamento)',
            self::Art74V => 'Art. 74, V (aquisição ou locação de imóvel)',
            self::Art75I => 'Art. 75, I (dispensa por valor: obras e serviços de engenharia)',
            self::Art75II => 'Art. 75, II (dispensa por valor: outros serviços e compras)',
        };
    }

    public function modalidadesCompativeis(): array
    {
        return match ($this) {
            self::Art28I => [Modalidade::PregaoEletronico, Modalidade::PregaoPresencial],
            self::Art28II => [Modalidade::ConcorrenciaEletronica, Modalidade::ConcorrenciaPresencial],
            self::Art28III => [Modalidade::Concurso],
            self::Art28IV => [],
            self::Art28V => [Modalidade::DialogoCompetitivo],
            self::Art74IV => [Modalidade::Credenciamento, Modalidade::Inexigibilidade],
            self::Art75I, self::Art75II => [Modalidade::Dispensa],
            default => [Modalidade::Inexigibilidade],
        };
    }

    public function ehDispensaPorValor(): bool
    {
        return in_array($this, [self::Art75I, self::Art75II], true);
    }

    /** @return array<int, string> */
    public static function opcoes(): array
    {
        return array_combine(array_map(fn ($c) => $c->value, self::cases()), array_map(fn ($c) => $c->rotulo(), self::cases()));
    }
}
