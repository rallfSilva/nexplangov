<?php

namespace App\Pncp;

use App\Licitacao\Enums\CategoriaProcesso;
use App\Licitacao\Enums\Modalidade;
use App\Models\Ata;
use App\Models\Contrato;
use App\Models\ItemPca;
use App\Models\ItemProcesso;
use App\Models\PlanoContratacao;
use App\Models\Processo;
use App\Models\TermoContrato;
use App\Support\Documento;
use Illuminate\Database\Eloquent\Model;

/**
 * Monta método, rota e corpo das chamadas ao PNCP a partir dos registros do sistema.
 * Nomes de campos conforme o manual de integração do PNCP; confira o Swagger vigente ao subir de versão
 * (o comando nexplangov:pncp-homologar faz o ciclo completo no ambiente de treinamento do PNCP).
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
            'resultado' => $this->resultado($alvo, $operacao),
            'ata' => $this->ata($alvo, $operacao),
            'pca' => $this->pca($alvo, $operacao),
            'arquivo_contrato' => $this->arquivoContrato($alvo),
            'arquivo_ata' => $this->arquivoAta($alvo),
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

    /** Resultado de um item homologado (fornecedor, quantidade e valor). */
    private function resultado(ItemProcesso $i, string $operacao): array
    {
        $p = $i->processo;
        $cnpj = Documento::digitos((string) $p->orgao->cnpj);
        [$ano, $seq] = NumeroControle::anoSequencial($p->numero_controle_pncp);
        $f = $i->fornecedorVencedor;
        $pendencias = $seq ? [] : ['A compra precisa estar publicada no PNCP antes do resultado.'];
        if (! $f) {
            $pendencias[] = "O item {$i->numero} não tem fornecedor vencedor.";
        }
        $unit = (float) ($i->valor_unitario_homologado ?? 0);
        $estimado = (float) $i->valor_unitario_estimado;

        $corpo = [
            'quantidadeHomologada' => (float) $i->quantidade,
            'valorUnitarioHomologado' => $unit,
            'valorTotalHomologado' => round($unit * (float) $i->quantidade, 2),
            'percentualDesconto' => $estimado > 0 ? round(max(0, ($estimado - $unit) / $estimado * 100), 4) : 0,
            'tipoPessoaId' => $f?->tipo_pessoa,
            'niFornecedor' => $f?->documentoCompleto(),
            'nomeRazaoSocialFornecedor' => $f?->razao_social,
            'porteFornecedorId' => ['ME' => 1, 'EPP' => 2, 'DEMAIS' => 3][$f?->porte] ?? 3,
            'codigoPais' => 'BRA',
            'indicadorSubcontratacao' => false,
            'dataResultado' => ($i->adjudicado_em ?? now())->format('Y-m-d'),
        ];

        $colecao = "/v1/orgaos/{$cnpj}/compras/{$ano}/{$seq}/itens/{$i->numero}/resultados";
        $metodo = $operacao === 'inserir' || ! $i->sequencial_resultado_pncp ? 'POST' : 'PUT';

        return ['metodo' => $metodo, 'rota' => $metodo === 'POST' ? $colecao : "{$colecao}/{$i->sequencial_resultado_pncp}", 'corpo' => $corpo, 'pendencias' => $pendencias];
    }

    /** Ata de registro de preços vinculada à compra (art. 82). */
    private function ata(Ata $a, string $operacao): array
    {
        $p = $a->processo;
        $cnpj = Documento::digitos((string) $a->orgao->cnpj);
        [$ano, $seq] = NumeroControle::anoSequencial($p?->numero_controle_pncp);
        $pendencias = $seq ? [] : ['A compra precisa estar publicada no PNCP antes da ata.'];

        $corpo = [
            'numeroAtaRegistroPreco' => $a->numero,
            'anoAta' => $a->ano,
            'dataAssinatura' => ($a->assinatura ?? $a->vigencia_inicio)->format('Y-m-d'),
            'dataVigenciaInicio' => $a->vigencia_inicio->format('Y-m-d'),
            'dataVigenciaFim' => $a->vigencia_fim->format('Y-m-d'),
        ];
        $colecao = "/v1/orgaos/{$cnpj}/compras/{$ano}/{$seq}/atas";

        if ($operacao === 'inserir' || ! $a->numero_controle_pncp) {
            return ['metodo' => 'POST', 'rota' => $colecao, 'corpo' => $corpo, 'pendencias' => $pendencias];
        }

        return ['metodo' => $operacao === 'excluir' ? 'DELETE' : 'PUT', 'rota' => "{$colecao}/{$a->numero_controle_pncp}", 'corpo' => $corpo, 'pendencias' => $pendencias];
    }

    /** Plano de contratações anual (art. 12, VII, e § 1º). */
    private function pca(PlanoContratacao $pl, string $operacao): array
    {
        $cnpj = Documento::digitos((string) $pl->orgao->cnpj);
        $pl->loadMissing('itens.unidade');
        $unidade = $pl->itens->first()?->unidade?->codigo_unidade_pncp ?? $pl->orgao->unidades()->whereNotNull('codigo_unidade_pncp')->value('codigo_unidade_pncp');
        $pendencias = $this->pendenciasOrgao($pl->orgao->cnpj, $unidade);
        if ($pl->itens->isEmpty()) {
            $pendencias[] = 'O plano não tem itens.';
        }

        $corpo = [
            'codigoUnidade' => $unidade,
            'anoPca' => $pl->ano,
            'itensPlano' => $pl->itens->map(fn (ItemPca $i) => [
                'numeroItem' => $i->numero,
                'categoriaItemPca' => self::categoriaItemPca((int) $i->categoria, $i->material_servico),
                'catalogo' => $i->codigo_catalogo ? 1 : 2, // 1 = CATMAT/CATSER (compras.gov.br), 2 = outros
                'classificacaoCatalogo' => $i->material_servico === 'S' ? 2 : 1,
                'codigoItem' => $i->codigo_catalogo,
                'descricao' => mb_substr($i->descricao, 0, 2048),
                'unidadeFornecimento' => $i->unidade_fornecimento,
                'quantidade' => (float) $i->quantidade,
                'valorUnitario' => (float) $i->valor_unitario_estimado,
                'valorTotal' => round((float) $i->quantidade * (float) $i->valor_unitario_estimado, 2),
                'valorOrcamentoExercicio' => round((float) $i->quantidade * (float) $i->valor_unitario_estimado, 2),
                'unidadeRequisitante' => $i->unidade?->nome,
                'dataDesejada' => $i->data_desejada?->format('Y-m-d'),
            ])->values()->all(),
        ];

        [$metodo, $rota] = $this->rota($operacao, "/v1/orgaos/{$cnpj}/pca", $pl->numero_controle_pncp);

        return ['metodo' => $metodo, 'rota' => $rota, 'corpo' => $corpo, 'pendencias' => $pendencias];
    }

    /** Arquivo do contrato assinado, enviado depois de o contrato ter número no PNCP. */
    private function arquivoContrato(Contrato $c): array
    {
        $cnpj = Documento::digitos((string) $c->orgao->cnpj);
        [$ano, $seq] = NumeroControle::anoSequencial($c->numero_controle_pncp);

        return ['metodo' => 'POST', 'rota' => "/v1/orgaos/{$cnpj}/contratos/{$ano}/{$seq}/arquivos", 'corpo' => [],
            'pendencias' => $seq ? [] : ['O contrato precisa estar publicado no PNCP antes do arquivo.']];
    }

    private function arquivoAta(Ata $a): array
    {
        $cnpj = Documento::digitos((string) $a->orgao->cnpj);
        [$ano, $seq] = NumeroControle::anoSequencial($a->processo?->numero_controle_pncp);

        return ['metodo' => 'POST', 'rota' => "/v1/orgaos/{$cnpj}/compras/{$ano}/{$seq}/atas/{$a->numero_controle_pncp}/arquivos", 'corpo' => [],
            'pendencias' => $seq && $a->numero_controle_pncp ? [] : ['A ata precisa estar publicada no PNCP antes do arquivo.']];
    }

    /** Categoria do item no PCA (tabela do PNCP), a partir da categoria do processo. Conferir no Swagger. */
    public static function categoriaItemPca(int $categoria, ?string $materialServico): int
    {
        return match (CategoriaProcesso::tryFrom($categoria)) {
            CategoriaProcesso::Compras => 1,
            CategoriaProcesso::Servicos, CategoriaProcesso::MaoDeObra, CategoriaProcesso::ServicosSaude => 2,
            CategoriaProcesso::Obras => 3,
            CategoriaProcesso::ServicosEngenharia => 4,
            CategoriaProcesso::Informatica => 5,
            CategoriaProcesso::LocacaoImoveis => 6,
            CategoriaProcesso::Cessao => 7,
            default => $materialServico === 'S' ? 2 : 1,
        };
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
