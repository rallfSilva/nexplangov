<?php

namespace App\Licitacao\Enums;

/** Modalidades e formas de contratação, com os códigos da tabela de domínio do PNCP (manual v1.0.0, 5.2). */
enum Modalidade: int
{
    case DialogoCompetitivo = 2;
    case Concurso = 3;
    case ConcorrenciaEletronica = 4;
    case ConcorrenciaPresencial = 5;
    case PregaoEletronico = 6;
    case PregaoPresencial = 7;
    case Dispensa = 8;
    case Inexigibilidade = 9;
    case ManifestacaoInteresse = 10;
    case PreQualificacao = 11;
    case Credenciamento = 12;

    public function rotulo(): string
    {
        return match ($this) {
            self::DialogoCompetitivo => 'Diálogo competitivo',
            self::Concurso => 'Concurso',
            self::ConcorrenciaEletronica => 'Concorrência eletrônica',
            self::ConcorrenciaPresencial => 'Concorrência presencial',
            self::PregaoEletronico => 'Pregão eletrônico',
            self::PregaoPresencial => 'Pregão presencial',
            self::Dispensa => 'Dispensa de licitação',
            self::Inexigibilidade => 'Inexigibilidade',
            self::ManifestacaoInteresse => 'Procedimento de manifestação de interesse',
            self::PreQualificacao => 'Pré-qualificação',
            self::Credenciamento => 'Credenciamento',
        };
    }

    /** Contratação direta (arts. 72 a 75): instrução própria, sem fase de disputa de licitação. */
    public function ehContratacaoDireta(): bool
    {
        return in_array($this, [self::Dispensa, self::Inexigibilidade], true);
    }

    /** Presencial só com justificativa (art. 17, § 2º): a regra é a forma eletrônica. */
    public function ehPresencial(): bool
    {
        return in_array($this, [self::ConcorrenciaPresencial, self::PregaoPresencial], true);
    }

    /** Instrumento convocatório que vai ao PNCP (tabela 5.1). */
    public function instrumentoConvocatorio(bool $comDisputa = false): int
    {
        if ($this === self::Dispensa) {
            return $comDisputa ? 2 : 3; // 2 = aviso de contratação direta; 3 = ato que autoriza a contratação direta
        }

        return $this === self::Inexigibilidade ? 3 : 1; // 1 = edital
    }

    /** @return array<int, string> */
    public static function opcoes(): array
    {
        return array_combine(array_map(fn ($c) => $c->value, self::cases()), array_map(fn ($c) => $c->rotulo(), self::cases()));
    }
}
