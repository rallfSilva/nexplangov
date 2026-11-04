<?php

namespace App\Support;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Gera planilha XLSX com uma ou mais abas, cabeçalho em destaque nas cores da paleta e larguras de coluna.
 * Datas e números entram como valores nativos, para o órgão poder somar e filtrar no Excel ou no LibreOffice.
 */
final class Planilha
{
    /**
     * @param  array<string, array{cabecalho: list<string>, linhas: iterable<list<mixed>>, larguras?: list<float>, ajuda?: list<string>}>  $abas
     */
    public static function xlsx(string $destino, array $abas): string
    {
        $escritor = new Writer;
        $escritor->openToFile($destino);
        $titulo = (new Style)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('1E5470');
        $data = (new Style)->setFormat('dd/mm/yyyy');
        $ajuda = (new Style)->setFontSize(9)->setFontColor('34729C')->setBackgroundColor('D1ECFF')->setShouldWrapText();

        $primeira = true;
        foreach ($abas as $nome => $aba) {
            $folha = $primeira ? $escritor->getCurrentSheet() : $escritor->addNewSheetAndMakeItCurrent();
            $primeira = false;
            $folha->setName(mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/', ' ', $nome), 0, 31));
            foreach ($aba['larguras'] ?? [] as $i => $largura) {
                $folha->setColumnWidth($largura, $i + 1);
            }

            $escritor->addRow(Row::fromValues($aba['cabecalho'], $titulo));
            if (! empty($aba['ajuda'])) {
                $escritor->addRow(Row::fromValues($aba['ajuda'], $ajuda));
            }
            foreach ($aba['linhas'] as $linha) {
                $escritor->addRow(new Row(array_map(function ($v) use ($data) {
                    $v = self::celula($v);

                    // Texto sempre como texto: "=HYPERLINK(...)" vindo de um campo nunca vira fórmula.
                    return is_string($v) ? new StringCell($v, null) : Cell::fromValue($v, $v instanceof \DateTimeInterface ? $data : null);
                }, array_values($linha))));
            }
        }
        $escritor->close();

        return $destino;
    }

    private static function celula(mixed $v): mixed
    {
        return match (true) {
            $v instanceof \DateTimeInterface => \DateTimeImmutable::createFromInterface($v),
            $v instanceof \BackedEnum => $v->value,
            is_bool($v) => $v ? 'Sim' : 'Não',
            is_array($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            is_object($v) => (string) $v,
            is_string($v) && is_numeric($v) && ! preg_match('/^0\d/', $v) && strlen($v) < 16 => $v + 0,
            default => $v,
        };
    }
}
