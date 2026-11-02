<?php

namespace App\Licitacao;

/**
 * Peças da instrução do processo, com os campos de cada uma e a base legal.
 * Os campos marcados como obrigatórios precisam estar preenchidos para concluir a peça.
 */
final class TiposDocumento
{
    /**
     * @return array<string, array{rotulo: string, base: string, tipo_pncp: ?int, campos: array<string, array{rotulo: string, obrigatorio: bool, ajuda?: string}>}>
     */
    public static function todos(): array
    {
        return [
            'dfd' => [
                'rotulo' => 'Documento de formalização da demanda (DFD)',
                'base' => 'Art. 12, VII, e art. 72, I',
                'tipo_pncp' => 10,
                'campos' => [
                    'necessidade' => ['rotulo' => 'Necessidade da contratação', 'obrigatorio' => true],
                    'quantidade_justificada' => ['rotulo' => 'Quantidade estimada e como foi calculada', 'obrigatorio' => true],
                    'previsao_pca' => ['rotulo' => 'Item do PCA ou justificativa de não estar no plano', 'obrigatorio' => true],
                    'responsavel' => ['rotulo' => 'Responsável pela demanda', 'obrigatorio' => true],
                ],
            ],
            'etp' => [
                'rotulo' => 'Estudo técnico preliminar (ETP)',
                'base' => 'Art. 18, I e § 1º',
                'tipo_pncp' => 7,
                // Os incisos I, IV, VI, VIII e XIII são obrigatórios (art. 18, § 2º). Os demais podem ficar
                // em branco, desde que a ausência seja justificada no próprio estudo.
                'campos' => [
                    'i_necessidade' => ['rotulo' => 'I. Descrição da necessidade e problema a resolver', 'obrigatorio' => true],
                    'ii_alinhamento_pca' => ['rotulo' => 'II. Previsão no plano de contratações anual', 'obrigatorio' => false],
                    'iii_requisitos' => ['rotulo' => 'III. Requisitos da contratação', 'obrigatorio' => false],
                    'iv_quantidades' => ['rotulo' => 'IV. Estimativas das quantidades, com memórias de cálculo', 'obrigatorio' => true],
                    'v_levantamento_mercado' => ['rotulo' => 'V. Levantamento de mercado e alternativas possíveis', 'obrigatorio' => false],
                    'vi_estimativa_valor' => ['rotulo' => 'VI. Estimativa do valor da contratação', 'obrigatorio' => true],
                    'vii_solucao' => ['rotulo' => 'VII. Descrição da solução como um todo', 'obrigatorio' => false],
                    'viii_parcelamento' => ['rotulo' => 'VIII. Justificativas para o parcelamento ou não da contratação', 'obrigatorio' => true],
                    'ix_resultados' => ['rotulo' => 'IX. Resultados pretendidos (economicidade e aproveitamento de recursos)', 'obrigatorio' => false],
                    'x_providencias' => ['rotulo' => 'X. Providências prévias ao contrato, inclusive capacitação', 'obrigatorio' => false],
                    'xi_correlatas' => ['rotulo' => 'XI. Contratações correlatas ou interdependentes', 'obrigatorio' => false],
                    'xii_impactos_ambientais' => ['rotulo' => 'XII. Impactos ambientais e medidas mitigadoras', 'obrigatorio' => false],
                    'xiii_viabilidade' => ['rotulo' => 'XIII. Posicionamento conclusivo sobre a viabilidade', 'obrigatorio' => true],
                    'justificativa_ausencias' => ['rotulo' => 'Justificativa dos elementos não preenchidos (art. 18, § 2º)', 'obrigatorio' => false],
                ],
            ],
            'mapa_riscos' => [
                'rotulo' => 'Mapa de riscos',
                'base' => 'Art. 18, X, e art. 22',
                'tipo_pncp' => 9,
                'campos' => [
                    'riscos' => ['rotulo' => 'Riscos identificados, probabilidade e impacto', 'obrigatorio' => true],
                    'tratamento' => ['rotulo' => 'Ações de prevenção e contingência e responsáveis', 'obrigatorio' => true],
                    'matriz_alocacao' => ['rotulo' => 'Matriz de alocação de riscos (obrigatória em obras de grande vulto, art. 22, § 3º)', 'obrigatorio' => false],
                ],
            ],
            'termo_referencia' => [
                'rotulo' => 'Termo de referência ou projeto básico',
                'base' => 'Art. 6º, XXIII e XXV, e art. 40, § 1º',
                'tipo_pncp' => 4,
                'campos' => [
                    'a_objeto' => ['rotulo' => 'a) Definição do objeto, natureza, quantitativos e prazo', 'obrigatorio' => true],
                    'b_fundamentacao' => ['rotulo' => 'b) Fundamentação (referência ao ETP)', 'obrigatorio' => true],
                    'c_solucao' => ['rotulo' => 'c) Descrição da solução como um todo', 'obrigatorio' => true],
                    'd_requisitos' => ['rotulo' => 'd) Requisitos da contratação', 'obrigatorio' => true],
                    'e_execucao' => ['rotulo' => 'e) Modelo de execução do objeto', 'obrigatorio' => true],
                    'f_gestao' => ['rotulo' => 'f) Modelo de gestão do contrato', 'obrigatorio' => true],
                    'g_pagamento' => ['rotulo' => 'g) Critérios de medição e pagamento', 'obrigatorio' => true],
                    'h_selecao' => ['rotulo' => 'h) Forma e critérios de seleção do fornecedor', 'obrigatorio' => true],
                    'i_estimativa' => ['rotulo' => 'i) Estimativas do valor, com memórias de cálculo', 'obrigatorio' => true],
                    'j_orcamento' => ['rotulo' => 'j) Adequação orçamentária', 'obrigatorio' => true],
                ],
            ],
            'dotacao' => [
                'rotulo' => 'Indicação de recursos orçamentários',
                'base' => 'Art. 72, IV, e art. 150',
                'tipo_pncp' => null,
                'campos' => [
                    'dotacao' => ['rotulo' => 'Dotação orçamentária (programa de trabalho e natureza da despesa)', 'obrigatorio' => true],
                    'fonte' => ['rotulo' => 'Fonte de recursos', 'obrigatorio' => true],
                    'declaracao' => ['rotulo' => 'Declaração de compatibilidade com a LOA e a LDO', 'obrigatorio' => true],
                ],
            ],
            'minuta_edital' => [
                'rotulo' => 'Minuta do edital',
                'base' => 'Art. 18, V, e art. 25',
                'tipo_pncp' => 2,
                'campos' => [
                    'texto' => ['rotulo' => 'Texto ou referência ao arquivo da minuta', 'obrigatorio' => true],
                ],
            ],
            'minuta_contrato' => [
                'rotulo' => 'Minuta do contrato',
                'base' => 'Art. 18, VI, e art. 92',
                'tipo_pncp' => 3,
                'campos' => [
                    'texto' => ['rotulo' => 'Texto ou referência ao arquivo da minuta, com as cláusulas do art. 92', 'obrigatorio' => true],
                ],
            ],
            'justificativa_presencial' => [
                'rotulo' => 'Justificativa da forma presencial',
                'base' => 'Art. 17, § 2º',
                'tipo_pncp' => null,
                'campos' => [
                    'motivo' => ['rotulo' => 'Por que a licitação não será eletrônica', 'obrigatorio' => true],
                    'gravacao' => ['rotulo' => 'Como a sessão pública será registrada em ata e gravada em áudio e vídeo', 'obrigatorio' => true],
                ],
            ],
            'razao_escolha' => [
                'rotulo' => 'Razão da escolha do contratado',
                'base' => 'Art. 72, VI',
                'tipo_pncp' => null,
                'campos' => [
                    'fornecedor' => ['rotulo' => 'Fornecedor escolhido (razão social e CNPJ)', 'obrigatorio' => true],
                    'motivo' => ['rotulo' => 'Motivo da escolha', 'obrigatorio' => true],
                ],
            ],
            'justificativa_preco' => [
                'rotulo' => 'Justificativa de preço',
                'base' => 'Art. 72, VII, e art. 23, § 4º',
                'tipo_pncp' => null,
                'campos' => [
                    'justificativa' => ['rotulo' => 'Por que o preço é compatível com o de mercado', 'obrigatorio' => true],
                ],
            ],
            'habilitacao' => [
                'rotulo' => 'Comprovação de habilitação do contratado',
                'base' => 'Art. 72, V, e arts. 62 a 70',
                'tipo_pncp' => null,
                'campos' => [
                    'juridica' => ['rotulo' => 'Habilitação jurídica', 'obrigatorio' => true],
                    'fiscal' => ['rotulo' => 'Regularidade fiscal, social e trabalhista', 'obrigatorio' => true],
                    'tecnica' => ['rotulo' => 'Qualificação técnica (quando exigida)', 'obrigatorio' => false],
                    'economica' => ['rotulo' => 'Qualificação econômico-financeira (quando exigida)', 'obrigatorio' => false],
                ],
            ],
            'parecer_juridico' => [
                'rotulo' => 'Parecer jurídico',
                'base' => 'Art. 53 e art. 72, III',
                'tipo_pncp' => 16,
                'campos' => [
                    'analise' => ['rotulo' => 'Análise jurídica, em linguagem simples e compreensível (art. 53, § 1º, II)', 'obrigatorio' => true],
                    'conclusao' => ['rotulo' => 'Conclusão (favorável, favorável com ressalvas ou desfavorável)', 'obrigatorio' => true, 'ajuda' => 'Escreva começando por "favorável", "favorável com ressalvas" ou "desfavorável".'],
                ],
            ],
            'autorizacao' => [
                'rotulo' => 'Autorização da autoridade competente',
                'base' => 'Art. 72, VIII',
                'tipo_pncp' => 16,
                'campos' => [
                    'decisao' => ['rotulo' => 'Decisão e fundamentação', 'obrigatorio' => true],
                ],
            ],
        ];
    }

    /** @return array{rotulo: string, base: string, tipo_pncp: ?int, campos: array<string, array{rotulo: string, obrigatorio: bool}>} */
    public static function get(string $tipo): array
    {
        return self::todos()[$tipo] ?? throw new \InvalidArgumentException("Tipo de documento desconhecido: {$tipo}");
    }

    /** @return list<string> campos obrigatórios em branco */
    public static function camposFaltando(string $tipo, array $conteudo): array
    {
        $faltando = [];

        foreach (self::get($tipo)['campos'] as $campo => $def) {
            if ($def['obrigatorio'] && trim((string) ($conteudo[$campo] ?? '')) === '') {
                $faltando[] = $def['rotulo'];
            }
        }

        return $faltando;
    }
}
