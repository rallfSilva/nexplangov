<?php

namespace App\Support;

use Dompdf\Dompdf;
use Dompdf\Options;

/** Gera PDF a partir de uma view, com acesso remoto desligado (nenhum recurso externo é carregado). */
final class Pdf
{
    /** Com rodapé, cada página leva o texto e "fl. N de T" (paginação dos autos). */
    public static function daView(string $view, array $dados, ?string $rodape = null): string
    {
        $opcoes = new Options;
        $opcoes->setIsRemoteEnabled(false);
        $opcoes->setDefaultFont('DejaVu Sans');
        $pdf = new Dompdf($opcoes);
        $pdf->loadHtml(view($view, $dados)->render(), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        if ($rodape !== null) {
            $canvas = $pdf->getCanvas();
            $fonte = $pdf->getFontMetrics()->getFont('DejaVu Sans');
            $canvas->page_text(40, $canvas->get_height() - 28, $rodape.'  ·  fl. {PAGE_NUM} de {PAGE_COUNT}', $fonte, 7.5, [0.29, 0.36, 0.41]);
        }

        return $pdf->output();
    }
}
