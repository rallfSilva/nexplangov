<?php

namespace App\Licitacao\Enums;

/** Situação do processo de contratação, da fase preparatória (art. 18) ao contrato. */
enum SituacaoProcesso: string
{
    case Planejamento = 'planejamento';
    case AnaliseJuridica = 'analise_juridica';
    case AguardandoAutorizacao = 'aguardando_autorizacao';
    case Publicado = 'publicado';
    case EmJulgamento = 'em_julgamento';
    case Homologado = 'homologado';
    case Contratado = 'contratado';
    case Deserto = 'deserto';
    case Fracassado = 'fracassado';
    case Revogado = 'revogado';
    case Anulado = 'anulado';

    public function rotulo(): string
    {
        return match ($this) {
            self::Planejamento => 'Fase preparatória',
            self::AnaliseJuridica => 'Análise jurídica',
            self::AguardandoAutorizacao => 'Aguardando autorização',
            self::Publicado => 'Publicado',
            self::EmJulgamento => 'Em julgamento',
            self::Homologado => 'Homologado',
            self::Contratado => 'Contratado',
            self::Deserto => 'Deserto',
            self::Fracassado => 'Fracassado',
            self::Revogado => 'Revogado',
            self::Anulado => 'Anulado',
        };
    }

    public function encerrado(): bool
    {
        return in_array($this, [self::Contratado, self::Deserto, self::Fracassado, self::Revogado, self::Anulado], true);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Planejamento, self::AnaliseJuridica, self::AguardandoAutorizacao => 'b-info',
            self::Publicado, self::EmJulgamento => 'b-warn',
            self::Homologado, self::Contratado => 'b-ok',
            default => 'b-mute',
        };
    }

    /** Situação da compra no PNCP (tabela 5.5): 1 divulgada, 2 revogada, 3 anulada, 4 suspensa. */
    public function situacaoPncp(): ?int
    {
        return match ($this) {
            self::Revogado => 2,
            self::Anulado => 3,
            self::Planejamento, self::AnaliseJuridica, self::AguardandoAutorizacao => null,
            default => 1,
        };
    }
}
