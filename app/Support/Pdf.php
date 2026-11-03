<?php

namespace App\Support;

use Dompdf\Dompdf;
use Dompdf\Options;

/** Gera PDF a partir de uma view, com acesso remoto desligado (nenhum recurso externo é carregado). */
final class Pdf
{
    public static function daView(string $view, array $dados): string
    {
        $opcoes = new Options;
        $opcoes->setIsRemoteEnabled(false);
        $opcoes->setDefaultFont('DejaVu Sans');
        $pdf = new Dompdf($opcoes);
        $pdf->loadHtml(view($view, $dados)->render(), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return $pdf->output();
    }
}
