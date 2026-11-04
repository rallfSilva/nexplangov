<?php

namespace App\Support;

use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;

/**
 * Monta documentos .docx editáveis (Word, LibreOffice, Google Docs) nas cores da paleta.
 * O arquivo é uma cópia de trabalho: não tem valor de assinatura eletrônica, que fica no sistema.
 */
final class DocumentoWord
{
    private PhpWord $word;

    private Section $secao;

    public function __construct(string $titulo, ?string $orgao = null)
    {
        Settings::setOutputEscapingEnabled(true);
        $this->word = new PhpWord;
        $this->word->setDefaultFontName('Arial');
        $this->word->setDefaultFontSize(11);
        $this->word->getDocInfo()->setTitle($titulo)->setCreator('NexPlanGov')->setCompany($orgao ?? '');
        $this->secao = $this->word->addSection(['marginTop' => 1134, 'marginBottom' => 1134, 'marginLeft' => 1134, 'marginRight' => 1134]);

        $rodape = $this->secao->addFooter();
        $rodape->addPreserveText('Gerado pelo NexPlanGov em '.now()->format('d/m/Y H:i').' · página {PAGE} de {NUMPAGES}', ['size' => 8, 'color' => '4A5B68'], ['alignment' => 'center']);

        if ($orgao) {
            $this->secao->addText($orgao, ['size' => 10, 'color' => '34729C', 'bold' => true]);
        }
        $this->secao->addText($titulo, ['size' => 16, 'bold' => true, 'color' => '1E5470'], ['spaceAfter' => 120]);
    }

    public function subtitulo(string $texto): self
    {
        $this->secao->addText($texto, ['size' => 9, 'color' => '4A5B68'], ['spaceAfter' => 200]);

        return $this;
    }

    public function secao(string $titulo): self
    {
        $this->secao->addText($titulo, ['size' => 12, 'bold' => true, 'color' => '1E5470'], ['spaceBefore' => 240, 'spaceAfter' => 80]);

        return $this;
    }

    /** Texto em parágrafos (cada quebra de linha vira um parágrafo). Vazio mostra o aviso em itálico. */
    public function paragrafos(?string $texto, string $vazio = 'Não preenchido.'): self
    {
        $texto = trim((string) $texto);
        if ($texto === '') {
            $this->secao->addText($vazio, ['italic' => true, 'color' => '4A5B68']);

            return $this;
        }
        foreach (preg_split('/\R/', $texto) as $linha) {
            $this->secao->addText($linha, null, ['spaceAfter' => 80, 'alignment' => 'both']);
        }

        return $this;
    }

    /**
     * @param  list<string>  $cabecalho
     * @param  iterable<list<string|int|float|null>>  $linhas
     * @param  list<int>  $larguras  em twips (1 cm ≈ 567)
     * @param  list<int>  $numericas  índices das colunas alinhadas à direita
     */
    public function tabela(array $cabecalho, iterable $linhas, array $larguras = [], array $numericas = []): self
    {
        $t = $this->secao->addTable(['borderSize' => 6, 'borderColor' => 'CBEAEC', 'cellMargin' => 60, 'width' => 100 * 50, 'unit' => 'pct']);
        $t->addRow(null, ['tblHeader' => true]);
        foreach ($cabecalho as $i => $c) {
            $t->addCell($larguras[$i] ?? null, ['bgColor' => '1E5470'])->addText($c, ['bold' => true, 'color' => 'FFFFFF', 'size' => 9]);
        }
        foreach ($linhas as $linha) {
            $t->addRow();
            foreach (array_values($linha) as $i => $v) {
                $t->addCell($larguras[$i] ?? null)->addText((string) $v, ['size' => 9], in_array($i, $numericas, true) ? ['alignment' => 'right'] : null);
            }
        }

        return $this;
    }

    public function nota(string $texto): self
    {
        $this->secao->addText($texto, ['size' => 8, 'color' => '4A5B68', 'italic' => true], ['spaceBefore' => 200]);

        return $this;
    }

    public function salvar(string $destino): string
    {
        IOFactory::createWriter($this->word, 'Word2007')->save($destino);

        return $destino;
    }
}
