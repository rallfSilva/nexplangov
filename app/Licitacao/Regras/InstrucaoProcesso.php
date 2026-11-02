<?php

namespace App\Licitacao\Regras;

use App\Licitacao\Enums\Modalidade;
use App\Licitacao\TiposDocumento;
use App\Models\Processo;

/**
 * Checklist da instrução do processo, conforme a forma de contratação.
 *
 * Licitação: fase preparatória do art. 18 (ETP, riscos, TR, orçamento, minutas) e parecer do art. 53.
 * Contratação direta: incisos I a VIII do art. 72.
 */
final class InstrucaoProcesso
{
    /**
     * @return list<array{chave: string, rotulo: string, base: string, cumprido: bool, etapa: string, detalhe: ?string}>
     */
    public function checklist(Processo $processo): array
    {
        $modalidade = Modalidade::from($processo->modalidade);
        $concluidos = $processo->documentos()->where('situacao', 'concluido')->pluck('tipo')->unique()->flip();
        $temItens = $processo->itens()->exists();
        $semPreco = $processo->itens()->where('valor_unitario_estimado', '<=', 0)->count();

        $doc = fn (string $tipo, string $etapa = 'instrucao') => [
            'chave' => $tipo,
            'rotulo' => TiposDocumento::get($tipo)['rotulo'],
            'base' => TiposDocumento::get($tipo)['base'],
            'cumprido' => $concluidos->has($tipo),
            'etapa' => $etapa,
            'detalhe' => null,
        ];

        $itens = [
            $doc('dfd'),
        ];

        if ($modalidade->ehContratacaoDireta()) {
            // Art. 72, I: ETP, riscos e TR "se for o caso". O sistema pede o TR sempre e deixa o ETP opcional.
            $itens[] = $doc('termo_referencia');
        } else {
            $itens[] = $doc('etp');
            $itens[] = $doc('mapa_riscos');
            $itens[] = $doc('termo_referencia');
        }

        $itens[] = [
            'chave' => 'pesquisa_precos',
            'rotulo' => 'Pesquisa de preços e valor estimado',
            'base' => $modalidade->ehContratacaoDireta() ? 'Art. 72, II, e art. 23' : 'Art. 18, IV, e art. 23',
            'cumprido' => $temItens && $semPreco === 0,
            'etapa' => 'instrucao',
            'detalhe' => ! $temItens ? 'Cadastre os itens.' : ($semPreco ? "{$semPreco} item(ns) sem preço estimado." : null),
        ];

        $itens[] = $doc('dotacao');

        if ($modalidade->ehContratacaoDireta()) {
            $itens[] = $doc('habilitacao');
            $itens[] = $doc('razao_escolha');
            $itens[] = $doc('justificativa_preco');
        } else {
            $itens[] = $doc('minuta_edital');
            $itens[] = $doc('minuta_contrato');
        }

        if ($modalidade->ehPresencial()) {
            $itens[] = $doc('justificativa_presencial');
        }

        $itens[] = $doc('parecer_juridico', 'juridico');
        $itens[] = $doc('autorizacao', 'autorizacao');

        return $itens;
    }

    /** Pendências para mandar ao jurídico (tudo da etapa de instrução). */
    public function pendenciasInstrucao(Processo $processo): array
    {
        return array_values(array_filter($this->checklist($processo), fn ($i) => $i['etapa'] === 'instrucao' && ! $i['cumprido']));
    }
}
