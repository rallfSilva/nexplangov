<?php

namespace App\Pncp;

use App\Licitacao\Enums\Modalidade;
use App\Models\Contrato;
use App\Models\ItemProcesso;
use App\Models\Processo;
use App\Models\TermoContrato;
use App\Support\Documento;
use Illuminate\Database\Eloquent\Model;

/**
 * Monta método, rota e corpo das chamadas ao PNCP a partir dos registros do sistema.
 * Nomes de campos conforme o manual v1.0.0; confira o Swagger vigente ao subir de versão.
 */
class MontadorPayload
{
    /** @return array{metodo: string, rota: string, corpo: array<string, mixed>, pendencias: list<string>} */
    public function montar(Model $alvo, string $recurso, string $operacao): array
    {
        return match ($recurso) {
            'compra' => $this->compra($alvo, $operacao),
            'contrato' => $this->contrato($alvo, $operacao),
            'termo' => $this->termo($alvo, $operacao),
            default => throw new \InvalidArgumentException("Recurso do PNCP não suportado: {$recurso}"),
        };
    }

    private function compra(Processo $p, string $operacao): array
    {
        $cnpj = Documento::digitos((string) $p->orgao->cnpj);
        $modalidade = $p->modalidadeEnum();
        $unidade = $p->unidade?->codigo_unidade_pncp;
        $pendencias = $this->pendenciasOrgao($p->orgao->cnpj, $unidade);

        $corpo = [
            'codigoUnidadeCompradora' => $unidade,
            'tipoInstrumentoConvocatorioId' => $modalidade->instrumentoConvocatorio($p->com_disputa),
            'modalidadeId' => $modalidade->value,
            'modoDisputaId' => $modalidade === Modalidade::Dispensa && $p->com_disputa ? 4 : ($modalidade->ehContratacaoDireta() ? 5 : $p->modo_disputa),
            'numeroCompra' => $p->numero_compra ?: $p->numero,
            'anoCompra' => $p->ano,
            'numeroProcesso' => $p->identificacao(),
            'situacaoCompraId' => $p->situacaoEnum()->situacaoPncp() ?? 1,
            'objetoCompra' => mb_substr($p->objeto, 0, 5120),
            'srp' => (bool) $p->srp,
            'orcamentoSigiloso' => (bool) $p->orcamento_sigiloso,
            'dataAberturaProposta' => $p->abertura_propostas_em?->format('Y-m-d\TH:i:s'),
            'dataEncerramentoProposta' => $p->encerramento_propostas_em?->format('Y-m-d\TH:i:s'),
            'amparoLegalId' => $p->amparo_legal,
            'itensCompra' => $p->itens->map(fn (ItemProcesso $i) => [
                'numeroItem' => $i->numero,
                'materialOuServico' => $i->material_servico,
                'tipoBeneficioId' => $i->tipo_beneficio,
                'incentivoProdutivoBasico' => false,
                'descricao' => $i->descricao,
                'quantidade' => (float) $i->quantidade,
                'unidadeMedida' => $i->unidade_medida,
                // Orçamento sigiloso (art. 24): o valor não vai a público antes do julgamento.
                'orcamentoSigiloso' => (bool) $p->orcamento_sigiloso,
                'valorUnitarioEstimado' => (float) $i->valor_unitario_estimado,
                'valorTotal' => (float) $i->valor_total_estimado,
                'criterioJulgamentoId' => $p->criterio_julgamento,
                'situacaoCompraItemId' => $i->situacaoPncp(),
            ])->values()->all(),
        ];

        if (! $modalidade->ehContratacaoDireta() && (! $p->abertura_propostas_em || ! $p->encerramento_propostas_em)) {
            $pendencias[] = 'Informe as datas de abertura e encerramento das propostas.';
        }

        if ($p->itens->isEmpty()) {
            $pendencias[] = 'O processo não tem itens.';
        }

        [$metodo, $rota] = $this->rota($operacao, "/v1/orgaos/{$cnpj}/compras", $p->numero_controle_pncp);

        return ['metodo' => $metodo, 'rota' => $rota, 'corpo' => $corpo, 'pendencias' => $pendencias];
    }

    private function contrato(Contrato $c, string $operacao): array
    {
        $cnpj = Documento::digitos((string) $c->orgao->cnpj);
        $processo = $c->processo;
        $unidade = $processo?->unidade?->codigo_unidade_pncp;
        $pendencias = $this->pendenciasOrgao($c->orgao->cnpj, $unidade);
        [$anoCompra, $seqCompra] = NumeroControle::anoSequencial($processo?->numero_controle_pncp);

        if (! $seqCompra) {
            $pendencias[] = 'A compra que originou o contrato precisa estar publicada no PNCP antes.';
        }

        $corpo = [
            'cnpjCompra' => $cnpj,
            'anoCompra' => $anoCompra,
            'sequencialCompra' => $seqCompra,
            'tipoContratoId' => $c->tipo,
            'numeroContratoEmpenho' => $c->numero,
            'anoContrato' => $c->ano,
            'processo' => $processo?->identificacao(),
            'categoriaProcessoId' => $c->categoria,
            'receita' => false,
            'codigoUnidade' => $unidade,
            'niFornecedor' => $c->fornecedor->documentoCompleto(),
            'tipoPessoaFornecedor' => $c->fornecedor->tipo_pessoa,
            'nomeRazaoSocialFornecedor' => $c->fornecedor->razao_social,
            'objetoContrato' => mb_substr($c->objeto, 0, 5120),
            'valorInicial' => (float) $c->valor_inicial,
            'numeroParcelas' => 1,
            'valorParcela' => (float) $c->valor_inicial,
            'valorGlobal' => (float) $c->valor_global,
            'dataAssinatura' => $c->assinatura->format('Y-m-d'),
            'dataVigenciaInicio' => $c->vigencia_inicio->format('Y-m-d'),
            'dataVigenciaFim' => $c->vigencia_fim->format('Y-m-d'),
        ];

        [$metodo, $rota] = $this->rota($operacao, "/v1/orgaos/{$cnpj}/contratos", $c->numero_controle_pncp);

        return ['metodo' => $metodo, 'rota' => $rota, 'corpo' => $corpo, 'pendencias' => $pendencias];
    }

    private function termo(TermoContrato $t, string $operacao): array
    {
        $c = $t->contrato;
        $cnpj = Documento::digitos((string) $c->orgao->cnpj);
        [$ano, $seq] = NumeroControle::anoSequencial($c->numero_controle_pncp);
        $pendencias = $seq ? [] : ['O contrato precisa estar publicado no PNCP antes do termo.'];
        $tipo = $t->tipoEnum();

        $corpo = [
            'tipoTermoContratoId' => $tipo->codigoPncp(),
            'numeroTermoContrato' => $t->numero,
            'objetoTermoContrato' => mb_substr($t->justificativa, 0, 5120),
            'dataAssinatura' => $t->assinatura->format('Y-m-d'),
            'qualificacaoAcrescimoSupressao' => (float) $t->valor_acrescimo > 0 || (float) $t->valor_supressao > 0,
            'qualificacaoVigencia' => $t->nova_vigencia_fim !== null,
            'qualificacaoReajuste' => (float) $t->valor_reajuste > 0,
            'qualificacaoFornecedor' => false,
            'valorAcrescido' => round((float) $t->valor_acrescimo - (float) $t->valor_supressao + (float) $t->valor_reajuste, 2),
            'dataVigenciaFim' => $t->nova_vigencia_fim?->format('Y-m-d'),
        ];

        [$metodo, $rota] = $this->rota($operacao, "/v1/orgaos/{$cnpj}/contratos/{$ano}/{$seq}/termos", $t->numero_controle_pncp);

        return ['metodo' => $metodo, 'rota' => $rota, 'corpo' => $corpo, 'pendencias' => $pendencias];
    }

    /** @return array{0: string, 1: string} */
    private function rota(string $operacao, string $colecao, ?string $numeroControle): array
    {
        if ($operacao === 'inserir') {
            return ['POST', $colecao];
        }

        [$ano, $seq] = NumeroControle::anoSequencial($numeroControle);

        return [$operacao === 'excluir' ? 'DELETE' : 'PUT', "{$colecao}/{$ano}/{$seq}"];
    }

    /** @return list<string> */
    private function pendenciasOrgao(?string $cnpj, ?string $unidade): array
    {
        $p = [];

        if (! $cnpj || ! Documento::cnpjValido($cnpj)) {
            $p[] = 'O órgão precisa ter CNPJ válido cadastrado.';
        }

        if (! $unidade) {
            $p[] = 'A unidade do processo precisa ter o código de unidade compradora no PNCP.';
        }

        return $p;
    }
}
