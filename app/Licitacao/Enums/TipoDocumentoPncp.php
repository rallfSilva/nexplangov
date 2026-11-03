<?php

namespace App\Licitacao\Enums;

/** Tipos de documento do PNCP (manual de integração, tabela "Tipo de Documento"). Conferir no Swagger vigente. */
enum TipoDocumentoPncp: int
{
    case AvisoContratacaoDireta = 1;
    case Edital = 2;
    case MinutaContrato = 3;
    case TermoReferencia = 4;
    case EstudoTecnicoPreliminar = 7;
    case MapaRiscos = 9;
    case Dfd = 10;
    case AtaRegistroPrecos = 11;
    case Contrato = 12;
    case TermoRescisao = 13;
    case TermoAditivo = 14;
    case TermoApostilamento = 15;
    case Outros = 16;

    public function rotulo(): string
    {
        return match ($this) {
            self::AvisoContratacaoDireta => 'Aviso de contratação direta',
            self::Edital => 'Edital',
            self::MinutaContrato => 'Minuta do contrato',
            self::TermoReferencia => 'Termo de referência',
            self::EstudoTecnicoPreliminar => 'Estudo técnico preliminar',
            self::MapaRiscos => 'Mapa de riscos',
            self::Dfd => 'Documento de formalização da demanda',
            self::AtaRegistroPrecos => 'Ata de registro de preços',
            self::Contrato => 'Contrato',
            self::TermoRescisao => 'Termo de rescisão',
            self::TermoAditivo => 'Termo aditivo',
            self::TermoApostilamento => 'Termo de apostilamento',
            self::Outros => 'Outros documentos',
        };
    }
}
