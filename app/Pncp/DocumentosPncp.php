<?php

namespace App\Pncp;

use App\Licitacao\Enums\TipoDocumentoPncp;
use App\Licitacao\TiposDocumento;
use App\Models\Ata;
use App\Models\Contrato;
use App\Models\Processo;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Documento que acompanha cada publicação no PNCP (art. 174, § 2º): o edital ou o aviso de contratação
 * direta na compra, a ata de registro de preços e o contrato assinado. Peça enviada como PDF vai como
 * está; peça escrita no sistema vira PDF aqui.
 *
 * @phpstan-type Doc array{caminho: string, titulo: string, tipo: int}
 */
final class DocumentosPncp
{
    /** @return Doc|null */
    public function daCompra(Processo $p): ?array
    {
        $direta = $p->modalidadeEnum()->ehContratacaoDireta();
        $tipoPeca = $direta ? 'autorizacao' : 'minuta_edital';
        $tipo = $direta ? TipoDocumentoPncp::AvisoContratacaoDireta : TipoDocumentoPncp::Edital;
        $titulo = ($direta ? 'Aviso de contratação direta' : 'Edital').' · '.$p->identificacao();

        $peca = $p->documentos()->where('tipo', $tipoPeca)->where('situacao', 'concluido')->orderByDesc('versao')->first();

        if (! $direta) {
            if (! $peca) {
                return null;
            }
            if ($peca->origem === 'arquivo' && $peca->arquivo_caminho && Str::endsWith(Str::lower((string) $peca->arquivo_nome), '.pdf')) {
                return ['caminho' => $peca->arquivo_caminho, 'titulo' => $titulo, 'tipo' => $tipo->value];
            }
            $def = TiposDocumento::get('minuta_edital');
            $secoes = collect($def['campos'])->mapWithKeys(fn ($d, $c) => [$d['rotulo'] => $peca->conteudo[$c] ?? ''])->all();

            return $this->gerar($p->orgao, 'Edital', $p->identificacao().' · '.$p->modalidadeEnum()->rotulo(), ['Objeto' => $p->objeto] + $secoes, $this->itens($p), $titulo, $tipo, "orgaos/{$p->orgao_id}/pncp");
        }

        // Contratação direta: o aviso é montado com os dados do processo e a decisão da autoridade (art. 72, parágrafo único).
        return $this->gerar($p->orgao, 'Aviso de contratação direta', $p->identificacao().' · '.$p->modalidadeEnum()->rotulo(), [
            'Objeto' => $p->objeto,
            'Fundamento legal' => $p->amparoEnum()->rotulo(),
            'Decisão da autoridade competente' => $peca?->conteudo['decisao'] ?? '',
        ], $this->itens($p), $titulo, $tipo, "orgaos/{$p->orgao_id}/pncp");
    }

    /** @return Doc */
    public function daAta(Ata $a): array
    {
        $a->loadMissing(['processo', 'fornecedor', 'itens.item']);

        return $this->gerar($a->orgao, 'Ata de registro de preços nº '.$a->identificacao(), $a->processo?->identificacao(), [
            'Objeto' => $a->processo?->objeto,
            'Fornecedor' => $a->fornecedor->razao_social.' · '.$a->fornecedor->documentoCompleto(),
            'Vigência' => $a->vigencia_inicio->format('d/m/Y').' a '.$a->vigencia_fim->format('d/m/Y').' (art. 84)',
            'Assinatura' => $a->assinatura?->format('d/m/Y'),
        ], [
            'titulo' => 'Itens registrados', 'colunas' => ['Item', 'Descrição', 'Quantidade', 'Valor unitário (R$)'],
            'linhas' => $a->itens->map(fn ($i) => [$i->item?->numero, $i->item?->descricao, number_format((float) $i->quantidade_registrada, 2, ',', '.'), number_format((float) $i->valor_unitario, 2, ',', '.')])->all(),
        ], 'Ata '.$a->identificacao(), TipoDocumentoPncp::AtaRegistroPrecos, "orgaos/{$a->orgao_id}/pncp");
    }

    /** Contrato: só o arquivo assinado, enviado pelo gestor. Sem ele, não há documento a publicar. @return Doc|null */
    public function doContrato(Contrato $c): ?array
    {
        if (! $c->arquivo_caminho || ! Storage::disk('local')->exists($c->arquivo_caminho)) {
            return null;
        }

        return ['caminho' => $c->arquivo_caminho, 'titulo' => 'Contrato '.$c->identificacao(), 'tipo' => TipoDocumentoPncp::Contrato->value];
    }

    private function itens(Processo $p): array
    {
        return [
            'titulo' => 'Itens', 'colunas' => ['Item', 'Descrição', 'Unidade', 'Quantidade'],
            'linhas' => $p->itens->map(fn ($i) => [$i->numero, $i->descricao, $i->unidade_medida, number_format((float) $i->quantidade, 2, ',', '.')])->all(),
        ];
    }

    /** @return Doc */
    private function gerar($orgao, string $titulo, ?string $subtitulo, array $secoes, ?array $tabela, string $tituloPncp, TipoDocumentoPncp $tipo, string $pasta): array
    {
        $html = view('pncp.documento-pdf', ['orgao' => $orgao, 'titulo' => $titulo, 'subtitulo' => $subtitulo, 'secoes' => $secoes, 'tabela' => $tabela, 'rodape' => $tipo->rotulo()])->render();

        $opcoes = new Options;
        $opcoes->setIsRemoteEnabled(false);
        $opcoes->setDefaultFont('DejaVu Sans');
        $pdf = new Dompdf($opcoes);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        $caminho = $pasta.'/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($caminho, $pdf->output());

        return ['caminho' => $caminho, 'titulo' => Str::limit($tituloPncp, 250, ''), 'tipo' => $tipo->value];
    }
}
