<?php

namespace Database\Seeders;

use App\Models\Orgao;
use Illuminate\Support\Facades\DB;

/**
 * Biblioteca-base de minutas da NexMind. Cada órgão recebe a própria cópia, que pode editar à vontade:
 * ao reinstalar, só entram os modelos que ainda não existem (pelo código), sem sobrescrever edições.
 *
 * Os textos são ponto de partida: precisam de revisão da assessoria jurídica do órgão antes de uso.
 * Os campos entre chaves duplas, como {{objeto}}, são trocados pelos dados do processo.
 */
final class ModelosPadrao
{
    private const AVISO = ' Modelo-base da NexMind: revise com a assessoria jurídica do órgão antes de usar.';

    public static function instalarPara(Orgao $orgao): int
    {
        $existentes = DB::table('modelos_documento')->where('orgao_id', $orgao->id)->pluck('codigo')->all();
        $novos = 0;

        foreach (self::catalogo() as $m) {
            if (in_array($m['codigo'], $existentes, true)) {
                continue;
            }

            DB::table('modelos_documento')->insert([
                'orgao_id' => $orgao->id, 'codigo' => $m['codigo'], 'tipo' => $m['tipo'], 'titulo' => $m['titulo'],
                'descricao' => ($m['descricao'] ?? '').self::AVISO, 'aplicacao' => $m['aplicacao'] ?? 'todos', 'categoria' => $m['categoria'] ?? null,
                'conteudo' => json_encode($m['conteudo'], JSON_UNESCAPED_UNICODE), 'versao' => 1, 'padrao_nexmind' => true, 'ativo' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $novos++;
        }

        return $novos;
    }

    /** @return list<array{codigo: string, tipo: string, titulo: string, descricao?: string, aplicacao?: string, categoria?: int, conteudo: array<string, string>}> */
    public static function catalogo(): array
    {
        return array_merge(
            self::dfds(), self::etps(), self::mapasDeRiscos(), self::termosDeReferencia(), self::dotacoes(),
            self::editais(), self::contratos(), self::diversos(), self::pareceresEAutorizacoes(),
        );
    }

    /** Categorias (códigos do PNCP) com o vocabulário de cada uma. */
    private const CATEGORIAS = [
        2 => ['nome' => 'compras', 'rotulo' => 'Compras', 'objeto' => 'bens', 'entrega' => 'entrega dos bens', 'recebimento' => 'recebimento provisório e definitivo dos bens, com conferência de quantidade, especificação e prazo de validade ou garantia (art. 140, II)'],
        8 => ['nome' => 'servicos', 'rotulo' => 'Serviços comuns', 'objeto' => 'serviços', 'entrega' => 'execução dos serviços', 'recebimento' => 'recebimento provisório e definitivo dos serviços, com ateste do fiscal e verificação do cumprimento do que foi contratado (art. 140, II)'],
        6 => ['nome' => 'mao-de-obra', 'rotulo' => 'Serviços contínuos com dedicação exclusiva de mão de obra', 'objeto' => 'serviços contínuos com dedicação exclusiva de mão de obra', 'entrega' => 'prestação contínua dos serviços', 'recebimento' => 'ateste mensal dos serviços e conferência do cumprimento das obrigações trabalhistas e previdenciárias (art. 121, § 3º)'],
        3 => ['nome' => 'tic', 'rotulo' => 'Tecnologia da informação', 'objeto' => 'soluções de tecnologia da informação', 'entrega' => 'implantação e operação da solução', 'recebimento' => 'recebimento provisório e definitivo com testes de aceitação e verificação dos níveis de serviço acordados (art. 140, II)'],
        7 => ['nome' => 'obras', 'rotulo' => 'Obras', 'objeto' => 'obra', 'entrega' => 'execução da obra', 'recebimento' => 'recebimento provisório pelo responsável pelo acompanhamento e definitivo por servidor ou comissão designados, após vistoria (art. 140, I)'],
        9 => ['nome' => 'engenharia', 'rotulo' => 'Serviços de engenharia', 'objeto' => 'serviço de engenharia', 'entrega' => 'execução do serviço de engenharia', 'recebimento' => 'recebimento provisório e definitivo, após vistoria e verificação do atendimento às especificações técnicas (art. 140, I)'],
    ];

    private static function m(string $codigo, string $tipo, string $titulo, array $conteudo, array $extra = []): array
    {
        return ['codigo' => $codigo, 'tipo' => $tipo, 'titulo' => $titulo, 'conteudo' => $conteudo] + $extra;
    }

    /** @return list<array> */
    private static function dfds(): array
    {
        $base = [
            'necessidade' => 'A {{unidade}} do {{orgao}} precisa contratar {{objeto}}. Descreva aqui o problema ou a necessidade pública a atender, os serviços prejudicados sem a contratação e o benefício esperado para a população ou para o órgão.',
            'quantidade_justificada' => 'Quantidade estimada: [informe]. Memória de cálculo: [consumo histórico, número de usuários, área, frequência ou outro critério]. Valor estimado de referência: {{valor_estimado}}.',
            'previsao_pca' => 'Contratação prevista no Plano de Contratações Anual de {{ano}}, item [informe o número do item]. Se não estiver prevista, justifique a necessidade superveniente.',
            'responsavel' => '{{responsavel}}, {{unidade}}.',
        ];

        return [
            self::m('dfd-geral', 'dfd', 'DFD: compras e serviços comuns', $base, ['descricao' => 'Documento de formalização da demanda (art. 12, VII).']),
            self::m('dfd-continuo', 'dfd', 'DFD: serviço contínuo', array_merge($base, ['necessidade' => 'O {{orgao}} depende de {{objeto}} de forma permanente, e a interrupção prejudicaria o funcionamento de {{unidade}}. Descreva a atividade que depende do serviço e as consequências de sua descontinuidade.', 'quantidade_justificada' => 'Postos, horas ou unidades de serviço por mês: [informe]. Duração prevista: 12 meses, com possibilidade de prorrogação (arts. 106 e 107). Valor estimado anual: {{valor_estimado}}.']), ['categoria' => 6]),
            self::m('dfd-engenharia', 'dfd', 'DFD: obra e serviço de engenharia', array_merge($base, ['necessidade' => 'É necessário executar {{objeto}} para [reforma, ampliação, construção ou manutenção] de [local]. Descreva o estado atual do bem, os riscos de não intervir e o benefício da obra.', 'quantidade_justificada' => 'Área ou extensão a intervir: [informe]. Referência de custo: SINAPI/SICRO ou outra fonte oficial (art. 23, § 2º). Valor estimado: {{valor_estimado}}.']), ['categoria' => 7]),
            self::m('dfd-tic', 'dfd', 'DFD: tecnologia da informação', array_merge($base, ['necessidade' => 'A {{unidade}} precisa de {{objeto}} para [processo ou serviço público a apoiar]. Descreva a solução atual, suas limitações e o ganho esperado. Informe se há dados pessoais tratados (LGPD).', 'quantidade_justificada' => 'Usuários, licenças, equipamentos ou volume de dados: [informe]. Prazo de licenciamento ou suporte: [informe]. Valor estimado: {{valor_estimado}}.']), ['categoria' => 3]),
        ];
    }

    /** @return list<array> */
    private static function etps(): array
    {
        $comum = [
            'i_necessidade' => 'O {{orgao}} precisa contratar {{objeto}} para atender à demanda da {{unidade}}. Descreva o problema a resolver e o interesse público envolvido (art. 18, § 1º, I).',
            'ii_alinhamento_pca' => 'A contratação está prevista no Plano de Contratações Anual de {{ano}}, item [informe] (art. 18, § 1º, II).',
            'iv_quantidades' => 'Quantidades: [informe]. Memória de cálculo e documentos que a sustentam: [consumo histórico, população atendida, dimensionamento]. Inclua as contratações anteriores como referência (art. 18, § 1º, IV).',
            'vi_estimativa_valor' => 'Valor estimado: {{valor_estimado}}, apurado pela pesquisa de preços do processo {{numero_processo}} (art. 23), pelo método [mediana, média ou menor preço], com memória de cálculo anexa (art. 18, § 1º, VI).',
            'viii_parcelamento' => 'Justifique se o objeto será parcelado em itens ou lotes ou se será contratado em item único, considerando a viabilidade técnica e econômica, o ganho de escala e a divisibilidade (art. 18, § 1º, VIII, e art. 40, V, b).',
            'ix_resultados' => 'Resultados pretendidos: economicidade, melhor aproveitamento dos recursos humanos, materiais e financeiros e atendimento da necessidade com qualidade (art. 18, § 1º, IX).',
            'x_providencias' => 'Providências prévias ao contrato: designação de gestor e fiscal, adequação do local, capacitação de servidores e demais medidas necessárias (art. 18, § 1º, X).',
            'xi_correlatas' => 'Contratações correlatas ou interdependentes: [informe ou declare que não há] (art. 18, § 1º, XI).',
            'xiii_viabilidade' => 'Com base nos elementos deste estudo, a contratação é [viável ou inviável] técnica e economicamente e deve [prosseguir ou não]. Declare a conclusão de forma expressa (art. 18, § 1º, XIII).',
            'justificativa_ausencias' => 'Se algum inciso não obrigatório ficou em branco, explique por que ele não se aplica a esta contratação (art. 18, § 2º).',
        ];

        $especifico = [
            2 => [
                'iii_requisitos' => 'Requisitos: especificações técnicas dos bens, garantia, prazo de validade mínimo, padrões de qualidade e eventuais exigências de sustentabilidade, como logística reversa e critérios do art. 11, IV (art. 18, § 1º, III).',
                'v_levantamento_mercado' => 'Levantamento de mercado: bens equivalentes disponíveis, fornecedores, contratações de outros órgãos e possibilidade de adesão a ata de registro de preços (art. 18, § 1º, V).',
                'vii_solucao' => 'Solução: aquisição dos bens descritos, com entrega parcelada conforme demanda, se for o caso (art. 18, § 1º, VII).',
                'xii_impactos_ambientais' => 'Impactos ambientais: descarte de embalagens e resíduos, logística reversa e uso racional de recursos (art. 18, § 1º, XII).',
            ],
            8 => [
                'iii_requisitos' => 'Requisitos: padrões de desempenho e qualidade, qualificação mínima da equipe, local e horário de execução e obrigações de segurança (art. 18, § 1º, III).',
                'v_levantamento_mercado' => 'Levantamento de mercado: formas de prestação do serviço, empresas que o ofertam, contratações similares de outros órgãos e alternativas, como execução direta (art. 18, § 1º, V).',
                'vii_solucao' => 'Solução: contratação de empresa especializada para a execução dos serviços descritos, com medição por resultado quando possível (art. 18, § 1º, VII).',
                'xii_impactos_ambientais' => 'Impactos ambientais: uso de produtos e equipamentos, descarte de resíduos e práticas de sustentabilidade exigidas da contratada (art. 18, § 1º, XII).',
            ],
            6 => [
                'iii_requisitos' => 'Requisitos: perfil e quantidade dos postos, jornada, uniformes e equipamentos, convenção coletiva aplicável e obrigações trabalhistas, previdenciárias e de segurança do trabalho (art. 18, § 1º, III).',
                'v_levantamento_mercado' => 'Levantamento de mercado: modelos de contratação por posto ou por resultado, empresas atuantes na região, contratos similares e comparação com a execução direta (art. 18, § 1º, V).',
                'vii_solucao' => 'Solução: contratação continuada de empresa para fornecer mão de obra com dedicação exclusiva, com planilha de custos e formação de preços por posto (art. 18, § 1º, VII).',
                'xii_impactos_ambientais' => 'Impactos ambientais: consumo de insumos, descarte de resíduos e orientações ambientais à equipe (art. 18, § 1º, XII).',
            ],
            3 => [
                'iii_requisitos' => 'Requisitos: funcionais e não funcionais, integrações, segurança da informação, níveis de serviço, proteção de dados pessoais (LGPD), portabilidade e propriedade dos dados, e suporte (art. 18, § 1º, III).',
                'v_levantamento_mercado' => 'Levantamento de mercado: soluções existentes, modelos de licenciamento (compra, assinatura, nuvem), soluções públicas ou de software livre e contratações de outros órgãos (art. 18, § 1º, V).',
                'vii_solucao' => 'Solução: descreva a solução escolhida de ponta a ponta, incluindo implantação, migração de dados, treinamento, suporte e encerramento com devolução dos dados (art. 18, § 1º, VII).',
                'xii_impactos_ambientais' => 'Impactos ambientais: consumo de energia, descarte de equipamentos e eventual uso de nuvem com datacenter eficiente (art. 18, § 1º, XII).',
            ],
            7 => [
                'iii_requisitos' => 'Requisitos: normas técnicas aplicáveis, licenças e autorizações, prazos, segurança do trabalho, acessibilidade (NBR 9050) e exigências de sustentabilidade (art. 18, § 1º, III).',
                'v_levantamento_mercado' => 'Levantamento de mercado: métodos construtivos alternativos, regimes de execução possíveis (art. 46), obras similares e custos de referência SINAPI ou SICRO (art. 18, § 1º, V).',
                'vii_solucao' => 'Solução: execução da obra conforme projeto básico e executivo, no regime de [empreitada por preço global, unitário, integral, contratação semi-integrada ou integrada], com justificativa (art. 18, § 1º, VII, e art. 46).',
                'xii_impactos_ambientais' => 'Impactos ambientais: licenciamento, gerenciamento de resíduos da construção civil, supressão de vegetação, ruído e medidas mitigadoras e compensatórias (art. 18, § 1º, XII).',
            ],
            9 => [
                'iii_requisitos' => 'Requisitos: normas técnicas aplicáveis, responsabilidade técnica (ART/RRT), qualificação da equipe, prazos e segurança do trabalho (art. 18, § 1º, III).',
                'v_levantamento_mercado' => 'Levantamento de mercado: soluções técnicas alternativas, contratações similares e custos de referência SINAPI ou SICRO (art. 18, § 1º, V).',
                'vii_solucao' => 'Solução: execução do serviço de engenharia descrito, com projeto básico ou termo de referência, no regime de execução [informe] (art. 18, § 1º, VII).',
                'xii_impactos_ambientais' => 'Impactos ambientais: resíduos, ruído, interferências e medidas de mitigação (art. 18, § 1º, XII).',
            ],
        ];

        $saida = [];
        foreach (self::CATEGORIAS as $cod => $c) {
            $saida[] = self::m("etp-{$c['nome']}", 'etp', "ETP: {$c['rotulo']}", array_merge($comum, $especifico[$cod]), ['aplicacao' => 'licitacao', 'categoria' => $cod, 'descricao' => 'Estudo técnico preliminar com os 13 incisos do art. 18, § 1º. São obrigatórios os incisos I, IV, VI, VIII e XIII (§ 2º).']);
        }

        $saida[] = self::m('etp-locacao-imovel', 'etp', 'ETP: locação de imóvel', array_merge($comum, [
            'iii_requisitos' => 'Requisitos do imóvel: área mínima, localização, acessibilidade, estacionamento, segurança, adequação às atividades e documentação regular (art. 74, V, e art. 51).',
            'v_levantamento_mercado' => 'Levantamento de mercado: imóveis disponíveis na região, valores de aluguel praticados e laudo de avaliação prévia, que é exigido na locação (art. 51).',
            'vii_solucao' => 'Solução: locação do imóvel que atenda às características definidas, com justificativa da singularidade de localização e instalações (art. 74, V).',
            'xii_impactos_ambientais' => 'Impactos ambientais: eficiência energética, adequações necessárias e condições de devolução do imóvel (art. 18, § 1º, XII).',
        ]), ['categoria' => 5, 'descricao' => 'Locação de imóvel, em regra contratação direta por inexigibilidade (art. 74, V).']);

        return $saida;
    }

    /** @return list<array> */
    private static function mapasDeRiscos(): array
    {
        $tratamento = 'Para cada risco, registre a ação preventiva, a ação de contingência e o responsável: [risco, ação preventiva, contingência, responsável].';
        $geral = 'Riscos de planejamento: especificação inadequada, estimativa de preço divergente. Riscos da seleção: licitação deserta ou fracassada, impugnações. Riscos da execução: atraso na entrega, entrega fora da especificação, inadimplemento. Para cada um, informe probabilidade (baixa, média, alta) e impacto (baixo, médio, alto) (art. 18, X).';

        return [
            self::m('riscos-geral', 'mapa_riscos', 'Mapa de riscos: compras e serviços comuns', ['riscos' => $geral, 'tratamento' => $tratamento], ['descricao' => 'Mapa de riscos do art. 18, X.']),
            self::m('riscos-compras', 'mapa_riscos', 'Mapa de riscos: aquisição de bens', ['riscos' => $geral.' Riscos próprios de bens: desabastecimento no mercado, variação de preço do insumo, produto com defeito ou fora da validade, entrega em local errado.', 'tratamento' => $tratamento], ['categoria' => 2]),
            self::m('riscos-mao-de-obra', 'mapa_riscos', 'Mapa de riscos: mão de obra com dedicação exclusiva', ['riscos' => $geral.' Riscos próprios: inadimplemento trabalhista e previdenciário da contratada, com risco de responsabilização subsidiária ou solidária do órgão (art. 121), rotatividade de pessoal, mudança de convenção coletiva e greve.', 'tratamento' => $tratamento.' Previstos: conta vinculada, retenção de pagamento, verificação mensal de recolhimentos e garantia contratual (art. 121, § 3º).'], ['categoria' => 6]),
            self::m('riscos-tic', 'mapa_riscos', 'Mapa de riscos: tecnologia da informação', ['riscos' => $geral.' Riscos próprios de TIC: indisponibilidade do serviço, vazamento ou perda de dados pessoais (LGPD), dependência do fornecedor (lock-in), falha na migração de dados e obsolescência.', 'tratamento' => $tratamento.' Previstos: níveis de serviço com sanção, backup, cláusulas de proteção de dados, plano de saída com devolução dos dados.'], ['categoria' => 3]),
            self::m('riscos-obras', 'mapa_riscos', 'Mapa de riscos: obras', ['riscos' => $geral.' Riscos próprios de obras: projeto deficiente, sondagem incompleta, licenças atrasadas, interferências, variação de custo, paralisação, vícios construtivos e acidentes.', 'tratamento' => $tratamento, 'matriz_alocacao' => 'Matriz de alocação de riscos entre contratante e contratado, com os riscos sobre os quais o contratado responde, os que ficam com a Administração e os compartilhados. É obrigatória em obras e serviços de grande vulto e nos regimes de contratação integrada e semi-integrada (art. 22, § 3º, e art. 103).'], ['categoria' => 7]),
            self::m('riscos-engenharia', 'mapa_riscos', 'Mapa de riscos: serviços de engenharia', ['riscos' => $geral.' Riscos próprios: projeto básico insuficiente, interferências não mapeadas, variação de quantitativos, responsabilidade técnica e segurança do trabalho.', 'tratamento' => $tratamento, 'matriz_alocacao' => 'Matriz de alocação de riscos entre as partes, quando exigida (art. 22, § 3º, e art. 103).'], ['categoria' => 9]),
        ];
    }

    /** @return list<array> */
    private static function termosDeReferencia(): array
    {
        $comum = [
            'b_fundamentacao' => 'A contratação está fundamentada no Estudo Técnico Preliminar do processo {{numero_processo}}, que concluiu pela viabilidade (art. 6º, XXIII, b).',
            'f_gestao' => 'A execução será acompanhada por gestor e fiscal designados formalmente, que serão pessoas diferentes (art. 7º, § 1º, e art. 117). Descreva a forma de comunicação, o registro de ocorrências e as sanções aplicáveis (arts. 155 e 156) (art. 6º, XXIII, f).',
            'g_pagamento' => 'Pagamento em até [30] dias após o recebimento definitivo e a apresentação da nota fiscal, observada a ordem cronológica de exigibilidade (art. 141). Critérios de medição: [informe] (art. 6º, XXIII, g).',
            'h_selecao' => 'Seleção por {{modalidade}}, critério de julgamento {{criterio_julgamento}}. Habilitação conforme arts. 62 a 70, com as exigências proporcionais ao objeto (art. 6º, XXIII, h).',
            'i_estimativa' => 'Valor estimado: {{valor_estimado}}, conforme pesquisa de preços do processo {{numero_processo}} (art. 23), com memória de cálculo anexa (art. 6º, XXIII, i).',
            'j_orcamento' => 'Os recursos são da dotação [informe], natureza da despesa {{natureza_despesa}}, com adequação orçamentária atestada pela unidade competente (art. 6º, XXIII, j).',
        ];

        $porCategoria = [
            2 => [
                'a_objeto' => 'Aquisição de {{objeto}}, conforme especificações e quantitativos do Anexo [informe]. Trata-se de bens comuns, com padrões de desempenho e qualidade definidos objetivamente (art. 6º, XIII) (art. 6º, XXIII, a).',
                'c_solucao' => 'Aquisição dos bens com entrega [única ou parcelada], incluindo transporte, descarga e garantia, sem necessidade de serviços posteriores (art. 6º, XXIII, c).',
                'd_requisitos' => 'Requisitos: especificação técnica, marca e modelo somente se justificados, garantia mínima de [12] meses, validade mínima de [informe] e exigências de sustentabilidade (art. 11, IV) (art. 6º, XXIII, d).',
                'e_execucao' => 'Prazo de entrega: [informe] dias após a ordem de fornecimento, no local [informe]. Recebimento: provisório e definitivo, com conferência (art. 140, II). Substituição dos bens recusados em [informe] dias (art. 6º, XXIII, e).',
            ],
            8 => [
                'a_objeto' => 'Contratação de empresa especializada em {{objeto}}, nos termos e quantitativos do Anexo [informe]. Serviço comum, com padrões de desempenho definidos objetivamente (art. 6º, XIII) (art. 6º, XXIII, a).',
                'c_solucao' => 'Execução dos serviços descritos, com fornecimento de pessoal, equipamentos e materiais pela contratada, sem vínculo de subordinação com o órgão (art. 6º, XXIII, c).',
                'd_requisitos' => 'Requisitos: qualificação técnica da equipe, cumprimento de normas de segurança, sustentabilidade (art. 11, IV) e sigilo quando aplicável (art. 6º, XXIII, d).',
                'e_execucao' => 'Início em até [informe] dias da ordem de serviço, no local [informe], conforme cronograma. Recebimento provisório e definitivo (art. 140, II) (art. 6º, XXIII, e).',
            ],
            6 => [
                'a_objeto' => 'Contratação de empresa para a prestação de serviços contínuos com dedicação exclusiva de mão de obra: {{objeto}}, nos postos do Anexo [informe]. Serviço contínuo (art. 6º, XV) com vigência inicial de 12 meses, prorrogável até 10 anos (arts. 106 e 107) (art. 6º, XXIII, a).',
                'c_solucao' => 'Fornecimento de pessoal pela contratada, com materiais e equipamentos [incluídos ou fornecidos pelo órgão], sem pessoalidade nem subordinação direta (art. 6º, XXIII, c).',
                'd_requisitos' => 'Requisitos: perfil dos postos, jornada, uniformes, convenção coletiva da categoria, cumprimento das obrigações trabalhistas e previdenciárias e reserva de vagas para mulheres vítimas de violência doméstica e egressos (art. 25, § 9º) (art. 6º, XXIII, d).',
                'e_execucao' => 'Execução contínua nos locais do Anexo [informe]. Substituição de empregado ausente ou afastado em até [informe] horas. Planilha de custos e formação de preços por posto (art. 6º, XXIII, e).',
                'f_gestao' => 'Fiscalização técnica e administrativa, com conferência mensal de salários, FGTS, INSS e demais encargos antes de cada pagamento. Conta vinculada e retenção em caso de inadimplência (art. 121, §§ 2º e 3º). Gestor e fiscal diferentes (art. 7º, § 1º) (art. 6º, XXIII, f).',
            ],
            3 => [
                'a_objeto' => 'Contratação de {{objeto}}, com requisitos, níveis de serviço e quantitativos do Anexo [informe] (art. 6º, XXIII, a).',
                'c_solucao' => 'Solução completa: fornecimento, implantação, migração de dados, treinamento, suporte e manutenção durante a vigência, com devolução dos dados ao final (art. 6º, XXIII, c).',
                'd_requisitos' => 'Requisitos: especificações funcionais e técnicas, integrações, segurança da informação, proteção de dados pessoais (Lei 13.709/2018), tratamento de dados somente conforme instruções do órgão, portabilidade e níveis de serviço com glosa por descumprimento (art. 6º, XXIII, d).',
                'e_execucao' => 'Cronograma de implantação em [informe] dias, ambiente de homologação e teste de aceitação antes do recebimento definitivo (art. 140, II). Suporte: [informe] horas, prazos de atendimento por criticidade (art. 6º, XXIII, e).',
                'g_pagamento' => 'Pagamento mensal ou conforme marcos de entrega, com glosa proporcional por descumprimento dos níveis de serviço, observada a ordem cronológica (art. 141) (art. 6º, XXIII, g).',
            ],
            7 => [
                'a_objeto' => 'Execução da obra de {{objeto}}, conforme projeto básico e executivo e memorial descritivo anexos (art. 6º, XXV e XXIII, a). Classificação: obra de engenharia (art. 6º, XII).',
                'c_solucao' => 'Execução completa da obra pelo regime de [empreitada por preço global, unitário, integral, semi-integrada ou integrada], com justificativa do regime (art. 46) (art. 6º, XXIII, c).',
                'd_requisitos' => 'Requisitos: responsabilidade técnica (ART/RRT), registro no CREA/CAU, equipe técnica mínima, licenciamento ambiental, normas ABNT, segurança do trabalho e acessibilidade (art. 6º, XXIII, d).',
                'e_execucao' => 'Prazo de execução: [informe] dias, conforme cronograma físico-financeiro. Diário de obra. Recebimento provisório e definitivo após vistoria (art. 140, I). Prazo de garantia da obra de 5 anos pela solidez e segurança (Código Civil, art. 618) (art. 6º, XXIII, e).',
                'g_pagamento' => 'Pagamento por medição, conforme cronograma físico-financeiro, após atesto do fiscal e observada a ordem cronológica (art. 141). Retenções e garantia contratual (arts. 96 a 99) (art. 6º, XXIII, g).',
                'i_estimativa' => 'Orçamento detalhado em planilha com preços unitários pelo SINAPI (ou SICRO), BDI e encargos sociais explicitados, conforme art. 23, §§ 2º e 3º. Valor estimado: {{valor_estimado}} (art. 6º, XXIII, i).',
            ],
            9 => [
                'a_objeto' => 'Execução de {{objeto}}, conforme projeto básico ou termo de referência e especificações técnicas anexos (art. 6º, XXV e XXIII, a). Serviço de engenharia (art. 6º, XXI).',
                'c_solucao' => 'Execução do serviço com fornecimento de mão de obra, materiais e equipamentos, no regime de [informe] (art. 46) (art. 6º, XXIII, c).',
                'd_requisitos' => 'Requisitos: responsável técnico com ART/RRT, qualificação técnica da equipe, normas ABNT e segurança do trabalho (art. 6º, XXIII, d).',
                'e_execucao' => 'Prazo de execução de [informe] dias, conforme cronograma. Recebimento provisório e definitivo após vistoria (art. 140, I) (art. 6º, XXIII, e).',
                'i_estimativa' => 'Orçamento detalhado com preços unitários pelo SINAPI (ou SICRO), BDI e encargos explicitados (art. 23, §§ 2º e 3º). Valor estimado: {{valor_estimado}} (art. 6º, XXIII, i).',
            ],
        ];

        $saida = [];
        foreach (self::CATEGORIAS as $cod => $c) {
            $saida[] = self::m("tr-{$c['nome']}", 'termo_referencia', ($cod === 7 ? 'Termo de referência ou projeto básico: ' : 'Termo de referência: ').$c['rotulo'], array_merge($comum, $porCategoria[$cod]), ['categoria' => $cod, 'descricao' => 'Termo de referência com as alíneas a a j do art. 6º, XXIII.']);
        }

        $saida[] = self::m('tr-locacao-imovel', 'termo_referencia', 'Termo de referência: locação de imóvel', array_merge($comum, [
            'a_objeto' => 'Locação de imóvel para {{objeto}}, com as características do Anexo [informe] (art. 74, V) (art. 6º, XXIII, a).',
            'c_solucao' => 'Locação do imóvel com adequações a cargo do locador ou do órgão conforme negociado, sem direito a indenização por benfeitorias não autorizadas (art. 6º, XXIII, c).',
            'd_requisitos' => 'Requisitos: área, localização, acessibilidade, segurança, documentação regular do imóvel e do locador, laudo de avaliação prévia do valor de mercado (art. 51) (art. 6º, XXIII, d).',
            'e_execucao' => 'Vigência de [informe] meses, entrega das chaves, vistoria de entrada e de saída, devolução do imóvel nas condições recebidas (art. 6º, XXIII, e).',
        ]), ['categoria' => 5]);

        return $saida;
    }

    /** @return list<array> */
    private static function dotacoes(): array
    {
        $texto = [
            'dotacao' => 'Programa de trabalho: [informe]. Natureza da despesa: {{natureza_despesa}}. Elemento de despesa: [informe].',
            'fonte' => 'Fonte de recursos: [informe]. Exercício: {{ano}}.',
            'declaracao' => 'Declaro que a despesa de {{valor_estimado}} tem adequação orçamentária e financeira com a Lei Orçamentária Anual e compatibilidade com o Plano Plurianual e a Lei de Diretrizes Orçamentárias (art. 150 da Lei 14.133 e arts. 16 e 17 da Lei Complementar 101/2000). [Local], {{data}}.',
        ];

        return [
            self::m('dotacao-geral', 'dotacao', 'Indicação de recursos: contratação com licitação ou direta', $texto, ['descricao' => 'Indicação de recursos orçamentários (art. 72, IV, e art. 150).']),
            self::m('dotacao-srp', 'dotacao', 'Indicação de recursos: registro de preços', array_merge($texto, ['dotacao' => 'No registro de preços não há reserva de dotação na licitação: a indicação é feita a cada contratação decorrente da ata (art. 82, § 2º). Natureza da despesa prevista: {{natureza_despesa}}.', 'fonte' => 'Fonte de recursos prevista: [informe]. Exercício: {{ano}}.']), ['aplicacao' => 'licitacao']),
        ];
    }

    /** @return list<array> */
    private static function editais(): array
    {
        $partes = fn (string $modalidade, string $fundamento, string $sessao, string $extra = '') => implode("\n\n", array_filter([
            "EDITAL DE {$modalidade} Nº {{numero_processo}}\n{{orgao}}, CNPJ {{cnpj_orgao}}\nProcesso administrativo nº {{numero_processo}}",
            "1. DO OBJETO\n1.1. O objeto desta licitação é {{objeto}}, conforme condições, quantidades e exigências deste edital e de seus anexos.\n1.2. O valor estimado da contratação é {{valor_estimado}}. [Se orçamento sigiloso, informar o fundamento do art. 24.]\n1.3. A licitação será em item único ou por itens/lotes, conforme o Termo de Referência.",
            "2. DO FUNDAMENTO LEGAL\n2.1. {$fundamento}\n2.2. Critério de julgamento: {{criterio_julgamento}}.",
            "3. DA PARTICIPAÇÃO\n3.1. Podem participar as empresas do ramo do objeto que atendam às exigências deste edital.\n3.2. Não podem participar as pessoas e empresas impedidas pelo art. 14 da Lei 14.133/2021, as que estejam sancionadas com impedimento ou declaração de inidoneidade (art. 156, III e IV) e as demais vedadas pelo edital.\n3.3. A participação exclusiva ou com benefício para microempresas e empresas de pequeno porte segue a Lei Complementar 123/2006 (art. 4º da Lei 14.133).",
            "4. DA SESSÃO PÚBLICA E DO JULGAMENTO\n4.1. {$sessao}\n4.2. As propostas serão classificadas e julgadas conforme o critério indicado, com verificação da compatibilidade com o valor estimado e dos preços inexequíveis (art. 59).\n4.3. Ocorrendo empate, aplicam-se os critérios de desempate do art. 60.",
            "5. DA HABILITAÇÃO\n5.1. O licitante classificado em primeiro lugar apresentará os documentos de habilitação jurídica, fiscal, social e trabalhista, técnica e econômico-financeira exigidos, conforme os arts. 62 a 70.\n5.2. Documentos sem validade expressa são considerados válidos por 90 dias, quando não houver prazo previsto em lei.",
            $extra,
            "6. DOS RECURSOS E DAS IMPUGNAÇÕES\n6.1. Qualquer pessoa pode impugnar o edital ou pedir esclarecimentos até 3 dias úteis antes da data da abertura (art. 164).\n6.2. Cabe recurso, no prazo de 3 dias úteis, contra o julgamento das propostas, a habilitação ou inabilitação e a anulação ou revogação (art. 165), com a intenção de recorrer manifestada em sessão.",
            "7. DA ADJUDICAÇÃO E DA HOMOLOGAÇÃO\n7.1. Encerrada a fase recursal, o objeto será adjudicado ao vencedor e o processo encaminhado à autoridade competente para homologação (art. 71, IV).",
            "8. DO CONTRATO\n8.1. O vencedor será convocado para assinar o contrato (minuta anexa) em até [informe] dias úteis, sob pena das sanções do edital.\n8.2. Garantia de execução: [não exigida ou informe o percentual, até 5%, ou até 10% com justificativa] (arts. 96 a 99).\n8.3. Vigência e prorrogação conforme a minuta contratual (arts. 105 a 107).",
            "9. DAS SANÇÕES\n9.1. O licitante e o contratado estão sujeitos a advertência, multa, impedimento de licitar e contratar e declaração de inidoneidade, conforme arts. 155 a 163, assegurados o contraditório e a ampla defesa (art. 158).",
            "10. DISPOSIÇÕES FINAIS\n10.1. A licitação pode ser revogada por razões de interesse público ou anulada por ilegalidade, com motivação e contraditório (art. 71, II e III e § 3º).\n10.2. Integram este edital: Anexo I Termo de Referência; Anexo II Minuta do Contrato; Anexo III Modelos de declarações.\n[Local], {{data}}.\n\n{{responsavel}}\nAgente de contratação ou pregoeiro",
        ]));

        return [
            self::m('edital-pregao-eletronico', 'minuta_edital', 'Edital: pregão eletrônico', ['texto' => $partes('PREGÃO ELETRÔNICO', 'Esta licitação observa a Lei nº 14.133/2021, em especial os arts. 6º, XLI, e 29, e é destinada a bens e serviços comuns, com critério de julgamento de menor preço ou maior desconto (art. 6º, XLI, e art. 29).', 'A sessão pública será realizada em plataforma eletrônica, com fase de lances (modo de disputa [aberto, fechado e aberto ou aberto e fechado]), na data e hora indicadas no aviso (art. 17, §§ 2º e 5º).')], ['aplicacao' => 'licitacao', 'descricao' => 'Pregão eletrônico para bens e serviços comuns (art. 29).']),
            self::m('edital-pregao-presencial', 'minuta_edital', 'Edital: pregão presencial', ['texto' => $partes('PREGÃO PRESENCIAL', 'Esta licitação observa a Lei nº 14.133/2021, arts. 6º, XLI, e 29. A forma presencial é motivada nos autos, e a sessão é gravada em áudio e vídeo (art. 17, § 2º).', 'A sessão pública será realizada no endereço [informe], na data e hora indicadas, com registro em ata e gravação em áudio e vídeo (art. 17, § 2º).')], ['aplicacao' => 'licitacao', 'descricao' => 'Exige a justificativa da forma presencial (art. 17, § 2º).']),
            self::m('edital-concorrencia-eletronica', 'minuta_edital', 'Edital: concorrência eletrônica', ['texto' => $partes('CONCORRÊNCIA ELETRÔNICA', 'Esta licitação observa a Lei nº 14.133/2021, art. 6º, XXXVIII, para bens e serviços especiais e obras e serviços de engenharia, comuns ou especiais.', 'A sessão pública será realizada em plataforma eletrônica, na data e hora indicadas no aviso (art. 17, § 2º). Prazo mínimo entre a divulgação e a abertura: conforme o art. 55 para o objeto e o critério.')], ['aplicacao' => 'licitacao', 'descricao' => 'Concorrência para objetos especiais e obras (art. 6º, XXXVIII).']),
            self::m('edital-concorrencia-presencial', 'minuta_edital', 'Edital: concorrência presencial', ['texto' => $partes('CONCORRÊNCIA PRESENCIAL', 'Esta licitação observa a Lei nº 14.133/2021, art. 6º, XXXVIII. A forma presencial é motivada nos autos, e a sessão é gravada em áudio e vídeo (art. 17, § 2º).', 'A sessão pública será realizada no endereço [informe], com registro em ata e gravação em áudio e vídeo (art. 17, § 2º).')], ['aplicacao' => 'licitacao', 'descricao' => 'Exige a justificativa da forma presencial (art. 17, § 2º).']),
            self::m('edital-concorrencia-obras', 'minuta_edital', 'Edital: concorrência para obras e serviços de engenharia', ['texto' => $partes('CONCORRÊNCIA ELETRÔNICA PARA OBRAS', 'Esta licitação observa a Lei nº 14.133/2021, arts. 6º, XXXVIII, 18 e 46, para execução de obra ou serviço de engenharia, no regime de [informe].', 'A sessão pública será realizada em plataforma eletrônica. O orçamento detalhado, o BDI e os encargos sociais constam do Anexo [informe] (art. 23, §§ 2º e 3º).', "5-A. DA VISITA TÉCNICA E DOS REQUISITOS ESPECÍFICOS\n5-A.1. A visita técnica ao local da obra é [facultativa ou obrigatória, neste caso com justificativa], podendo ser substituída por declaração do licitante (art. 63, § 2º).\n5-A.2. Qualificação técnica: registro no CREA/CAU, atestados de responsabilidade técnica e capacidade operacional compatíveis (art. 67).\n5-A.3. Seguro-garantia: [informe] conforme art. 99, § 4º, para obras de grande vulto.")], ['aplicacao' => 'licitacao', 'categoria' => 7, 'descricao' => 'Inclui visita técnica, qualificação técnica de engenharia e orçamento detalhado.']),
        ];
    }

    /** @return list<array> */
    private static function contratos(): array
    {
        $clausulas = fn (string $objetoClausula, string $execucao, string $extra = '') => implode("\n\n", array_filter([
            "CONTRATO Nº [informe]/{{ano}}\nProcesso nº {{numero_processo}}\n\nCONTRATANTE: {{orgao}}, CNPJ {{cnpj_orgao}}. CONTRATADA: [razão social], CNPJ [informe]. Têm entre si justo e acordado o seguinte, nos termos da Lei nº 14.133/2021 (art. 89 e seguintes).",
            "CLÁUSULA 1ª, DO OBJETO (art. 92, I)\n{$objetoClausula}",
            "CLÁUSULA 2ª, DA VINCULAÇÃO (art. 92, II)\nEste contrato vincula-se ao edital ou ao aviso de contratação direta, ao Termo de Referência e à proposta da contratada.",
            "CLÁUSULA 3ª, DA EXECUÇÃO (art. 92, VII)\n{$execucao}",
            $extra,
            "CLÁUSULA 4ª, DO PREÇO (art. 92, V)\nO valor total é de [informe], conforme a proposta vencedora. Está incluída toda despesa necessária, como tributos, encargos e transporte.",
            "CLÁUSULA 5ª, DO PAGAMENTO (art. 92, V e art. 141)\nO pagamento será feito em até [30] dias após o recebimento definitivo e a apresentação da nota fiscal, respeitada a ordem cronológica de exigibilidade, por crédito em conta da contratada.",
            "CLÁUSULA 6ª, DO REAJUSTE (art. 92, V e art. 25, § 7º)\nO preço será reajustado após 12 meses da data-base da proposta, pelo índice [informe], mediante pedido da contratada. O reajuste é formalizado por apostilamento (art. 136, I).",
            "CLÁUSULA 7ª, DA VIGÊNCIA (art. 92, IV, e arts. 105 a 107)\nA vigência é de [12] meses, contados da assinatura. Em serviço ou fornecimento contínuo, pode ser prorrogada por períodos sucessivos até 10 anos, com demonstração de vantagem econômica (art. 107).",
            "CLÁUSULA 8ª, DA GARANTIA (art. 92, XII, e arts. 96 a 99)\n[Não exigida ou informe a modalidade e o percentual, de até 5% do valor do contrato, ou até 10% quando justificada pela complexidade.]",
            "CLÁUSULA 9ª, DA GESTÃO E DA FISCALIZAÇÃO (art. 117)\nO gestor do contrato é [informe] e o fiscal é [informe], pessoas diferentes (art. 7º, § 1º). A fiscalização não exclui nem reduz a responsabilidade da contratada.",
            "CLÁUSULA 10ª, DAS ALTERAÇÕES (arts. 124 a 136)\nO contrato pode ser alterado nos casos previstos em lei. A Administração pode acrescer ou suprimir até 25% do valor inicial atualizado, e até 50% nos acréscimos de reforma de edifício ou equipamento (art. 125). Alterações por termo aditivo; reajuste e atualização por apostilamento.",
            "CLÁUSULA 11ª, DAS OBRIGAÇÕES DA CONTRATADA (art. 92, XIV)\nManter, durante a execução, as condições de habilitação; responder pelos danos causados; não subcontratar sem autorização (art. 122); cumprir a legislação trabalhista, previdenciária e ambiental; e guardar sigilo e proteger dados pessoais conforme a Lei 13.709/2018.",
            "CLÁUSULA 12ª, DAS OBRIGAÇÕES DO CONTRATANTE\nPagar nos prazos, fornecer as informações necessárias, fiscalizar a execução e aplicar as sanções cabíveis.",
            "CLÁUSULA 13ª, DAS SANÇÕES (art. 92, XIV, e arts. 155 a 163)\nO descumprimento sujeita a contratada a advertência, multa [informe o percentual], impedimento de licitar e contratar e declaração de inidoneidade, após processo com contraditório e ampla defesa (art. 158).",
            "CLÁUSULA 14ª, DA EXTINÇÃO (arts. 137 a 139)\nO contrato pode ser extinto nas hipóteses do art. 137, por ato unilateral da Administração, por acordo ou por decisão judicial ou arbitral, com os efeitos do art. 138.",
            "CLÁUSULA 15ª, DA PUBLICIDADE (art. 94)\nO contrato será divulgado no Portal Nacional de Contratações Públicas (PNCP) como condição de eficácia.",
            "CLÁUSULA 16ª, DO FORO (art. 92, § 1º)\nFica eleito o foro de [informe] para dirimir as questões deste contrato.\n\n[Local], {{data}}.\n\nPelo CONTRATANTE: {{responsavel}}\nPela CONTRATADA: [nome e cargo]",
        ]));

        $saida = [];
        foreach (self::CATEGORIAS as $cod => $c) {
            $extra = match ($cod) {
                6 => "CLÁUSULA 3ª-A, DAS OBRIGAÇÕES TRABALHISTAS (art. 121, §§ 2º e 3º)\nO contratante pode exigir comprovação mensal do pagamento de salários, FGTS e contribuições previdenciárias e reterá o pagamento ou usará a conta vinculada em caso de inadimplência. Vagas reservadas conforme o art. 25, § 9º.",
                3 => "CLÁUSULA 3ª-A, DOS NÍVEIS DE SERVIÇO E DA PROTEÇÃO DE DADOS\nA contratada cumprirá os níveis de serviço do Termo de Referência, com glosa proporcional por descumprimento. Tratará dados pessoais apenas conforme instruções do contratante (Lei 13.709/2018), adotará medidas de segurança e devolverá todos os dados ao final da vigência.",
                7, 9 => "CLÁUSULA 3ª-A, DA RESPONSABILIDADE TÉCNICA E DO RECEBIMENTO (art. 140, I)\nA execução tem responsável técnico com ART/RRT. O recebimento provisório ocorre em até [informe] dias do aviso de conclusão, e o definitivo após vistoria, em até [informe] dias. A contratada responde pela solidez e segurança da obra por 5 anos (Código Civil, art. 618).",
                default => '',
            };
            $saida[] = self::m("contrato-{$c['nome']}", 'minuta_contrato', "Contrato: {$c['rotulo']}", ['texto' => $clausulas('Execução de {{objeto}}.', ucfirst($c['entrega']).' conforme o Termo de Referência, com '.$c['recebimento'].'.', $extra)], ['aplicacao' => 'licitacao', 'categoria' => $cod, 'descricao' => 'Cláusulas necessárias do art. 92.']);
        }

        $saida[] = self::m('contrato-direta', 'minuta_contrato', 'Termo de contrato: contratação direta', ['texto' => $clausulas('Contratação de {{objeto}}, por {{modalidade}}, com fundamento em {{amparo_legal}}.', 'Execução conforme o Termo de Referência e a proposta, no prazo [informe].')], ['aplicacao' => 'direta', 'descricao' => 'Para contratação direta. Nas compras com entrega imediata e valor de pequena monta o termo pode ser substituído por nota de empenho (art. 95, I).']);

        return $saida;
    }

    /** @return list<array> */
    private static function diversos(): array
    {
        return [
            self::m('presencial-geral', 'justificativa_presencial', 'Justificativa da forma presencial', [
                'motivo' => 'A licitação não será eletrônica pelos seguintes motivos: [informe, como indisponibilidade comprovada de plataforma, característica do objeto ou limitação de acesso do mercado local], conforme o art. 17, § 2º, da Lei 14.133/2021. Processo {{numero_processo}}, {{objeto}}.',
                'gravacao' => 'A sessão pública será registrada em ata e gravada em áudio e vídeo, e a gravação será juntada aos autos após a sessão (art. 17, § 5º, e § 2º).',
            ], ['aplicacao' => 'licitacao', 'descricao' => 'Obrigatória quando a modalidade é presencial (art. 17, § 2º).']),

            self::m('razao-escolha-dispensa', 'razao_escolha', 'Razão da escolha do contratado: dispensa', [
                'fornecedor' => '[Razão social], CNPJ [informe].',
                'motivo' => 'O fornecedor foi escolhido por apresentar o menor preço entre as propostas obtidas, com proposta compatível com a pesquisa de preços, e por atender às condições de habilitação (art. 72, VI). Contratação por {{amparo_legal}}. Foram consultadas [número] empresas.',
            ], ['aplicacao' => 'direta', 'descricao' => 'Art. 72, VI.']),
            self::m('razao-escolha-inexigibilidade', 'razao_escolha', 'Razão da escolha do contratado: inexigibilidade', [
                'fornecedor' => '[Razão social], CNPJ [informe].',
                'motivo' => 'A competição é inviável porque [fornecedor exclusivo, profissional do setor artístico, serviço técnico especializado de natureza predominantemente intelectual ou outra hipótese do art. 74]. A escolha recai sobre o contratado por [notória especialização comprovada por currículo, publicações, prêmios e atestados, ou exclusividade atestada pelo órgão competente] (art. 72, VI, e art. 74).',
            ], ['aplicacao' => 'direta', 'descricao' => 'A inviabilidade de competição precisa estar comprovada (art. 74).']),

            self::m('preco-dispensa', 'justificativa_preco', 'Justificativa de preço: dispensa', [
                'justificativa' => 'O preço de [valor] é compatível com o de mercado, conforme pesquisa de preços do processo {{numero_processo}}, feita com [fontes do art. 23, § 1º], que resultou em {{valor_estimado}} pelo método [informe]. A proposta escolhida está dentro da faixa pesquisada (art. 72, II e VII, e art. 23).',
            ], ['aplicacao' => 'direta']),
            self::m('preco-inexigibilidade', 'justificativa_preco', 'Justificativa de preço: inexigibilidade', [
                'justificativa' => 'O preço é compatível com o praticado pelo contratado em contratações semelhantes com outros entes públicos e privados, comprovadas por [notas fiscais, contratos ou atestados] dos últimos [informe] meses, conforme o art. 23, § 4º, da Lei 14.133/2021.',
            ], ['aplicacao' => 'direta', 'descricao' => 'Art. 23, § 4º.']),

            self::m('habilitacao-pj', 'habilitacao', 'Comprovação de habilitação: pessoa jurídica', [
                'juridica' => 'Ato constitutivo vigente e documentos dos representantes: [conferidos em (data)]. Registro comercial ou inscrição no órgão competente (art. 66).',
                'fiscal' => 'Inscrição no CNPJ, regularidade com a Fazenda federal e a previdência (certidão conjunta), FGTS, Justiça do Trabalho (CNDT), e as certidões estadual e municipal do domicílio (art. 68). Consulta ao CEIS, CNEP e ao SICAF: [resultado].',
                'tecnica' => 'Quando exigida: atestados de capacidade técnico-operacional e técnico-profissional, registro em entidade profissional (art. 67).',
                'economica' => 'Quando exigida: balanço patrimonial, certidão negativa de falência e índices contábeis (art. 69).',
            ], ['aplicacao' => 'direta', 'descricao' => 'Art. 72, V, e arts. 62 a 70.']),
            self::m('habilitacao-pf', 'habilitacao', 'Comprovação de habilitação: pessoa física', [
                'juridica' => 'Documento de identificação, CPF e comprovante de inscrição profissional quando aplicável (art. 66).',
                'fiscal' => 'Regularidade fiscal federal, estadual e municipal e quitação de contribuição previdenciária, quando aplicável (art. 68).',
                'tecnica' => 'Quando exigida: currículo, diplomas e atestados que comprovem a capacidade técnica (art. 67).',
                'economica' => '',
            ], ['aplicacao' => 'direta', 'descricao' => 'Os dados pessoais do contratado são protegidos conforme a LGPD.']),
        ];
    }

    /** @return list<array> */
    private static function pareceresEAutorizacoes(): array
    {
        $cabecalho = "Processo {{numero_processo}}. Objeto: {{objeto}}. Valor estimado: {{valor_estimado}}.\n\n";

        return [
            self::m('parecer-licitacao', 'parecer_juridico', 'Parecer jurídico: licitação', [
                'analise' => $cabecalho."Trata-se de análise jurídica prévia da fase preparatória, nos termos do art. 53 da Lei 14.133/2021.\n\n1. Relatório: [resumo dos autos].\n2. Análise: verificação do ETP, do mapa de riscos, do termo de referência, da pesquisa de preços (art. 23), da adequação orçamentária (art. 150), da modalidade e do critério de julgamento e da minuta de edital e de contrato (art. 53, § 1º, I e II). [Registre as observações em linguagem simples e compreensível.]\n3. Cumprimento do art. 18: [confirmar].",
                'conclusao' => 'Favorável, com as ressalvas indicadas, desde que [informe]. [ou: Desfavorável, pelos motivos: informe.]',
            ], ['aplicacao' => 'licitacao', 'descricao' => 'Controle prévio de legalidade (art. 53).']),
            self::m('parecer-dispensa', 'parecer_juridico', 'Parecer jurídico: dispensa', [
                'analise' => $cabecalho."Análise jurídica da contratação direta por dispensa, nos termos do art. 53 e do art. 72, III.\n\n1. Enquadramento: {{amparo_legal}}. Natureza da despesa: {{natureza_despesa}}.\n2. Limite de valor e fracionamento: o valor, somado ao das dispensas do exercício na mesma natureza, está dentro do limite do art. 75 [confirmar o valor e o decreto vigente].\n3. Instrução: documento de formalização da demanda, termo de referência, pesquisa de preços, razão da escolha, habilitação e disponibilidade orçamentária (art. 72).",
                'conclusao' => 'Favorável à contratação direta, nos termos do art. 75, [inciso], da Lei 14.133/2021. [ou: Desfavorável: informe os motivos.]',
            ], ['aplicacao' => 'direta']),
            self::m('parecer-inexigibilidade', 'parecer_juridico', 'Parecer jurídico: inexigibilidade', [
                'analise' => $cabecalho."Análise jurídica da contratação direta por inexigibilidade, nos termos do art. 53 e do art. 74.\n\n1. Enquadramento: {{amparo_legal}}.\n2. Inviabilidade de competição: [analisar a comprovação: exclusividade, singularidade do objeto, notória especialização].\n3. Razão da escolha e justificativa de preço (art. 72, VI e VII, e art. 23, § 4º).\n4. Instrução e habilitação do contratado.",
                'conclusao' => 'Favorável à inexigibilidade, com fundamento no art. 74, [inciso]. [ou: Desfavorável: informe os motivos.]',
            ], ['aplicacao' => 'direta']),
            self::m('parecer-credenciamento', 'parecer_juridico', 'Parecer jurídico: credenciamento', [
                'analise' => $cabecalho."Análise jurídica do credenciamento, nos termos do art. 53 e do art. 79.\n\n1. Hipótese: [paralela e não excludente, com seleção a critério de terceiros ou em mercado fluido].\n2. Regulamento: edital de chamamento público, critérios objetivos, valores e rodízio ou livre escolha.\n3. Condições de credenciamento aberto a todos os interessados.",
                'conclusao' => 'Favorável ao credenciamento, nos termos do art. 79 da Lei 14.133/2021, com as ressalvas [informe].',
            ], ['aplicacao' => 'direta']),

            self::m('autorizacao-licitacao', 'autorizacao', 'Autorização: abertura de licitação', [
                'decisao' => 'Considerando a instrução do processo {{numero_processo}}, o estudo técnico preliminar, o termo de referência, a pesquisa de preços, a disponibilidade orçamentária e o parecer jurídico favorável, AUTORIZO a abertura da licitação para {{objeto}}, na modalidade {{modalidade}}, critério {{criterio_julgamento}}, com valor estimado de {{valor_estimado}}, e determino a publicação do edital no Portal Nacional de Contratações Públicas (art. 54). [Local], {{data}}.',
            ], ['aplicacao' => 'licitacao', 'descricao' => 'Decisão da autoridade competente (art. 18 e art. 8º).']),
            self::m('autorizacao-direta', 'autorizacao', 'Autorização: contratação direta', [
                'decisao' => 'Considerando a instrução do processo {{numero_processo}}, a justificativa da contratação, a razão da escolha do contratado, a justificativa de preço, a comprovação da habilitação, a disponibilidade orçamentária e o parecer jurídico favorável, AUTORIZO a contratação direta de {{objeto}}, com fundamento em {{amparo_legal}}, pelo valor de {{valor_estimado}} (art. 72, VIII). Determino a divulgação do ato no Portal Nacional de Contratações Públicas, condição de eficácia do contrato (art. 72, parágrafo único). [Local], {{data}}.',
            ], ['aplicacao' => 'direta']),
            self::m('autorizacao-inexigibilidade', 'autorizacao', 'Ratificação: inexigibilidade', [
                'decisao' => 'RATIFICO o reconhecimento da inexigibilidade de licitação para {{objeto}}, com fundamento em {{amparo_legal}}, reconhecendo a inviabilidade de competição apontada nos autos do processo {{numero_processo}}, pelo valor de {{valor_estimado}}. Determino a divulgação no Portal Nacional de Contratações Públicas (art. 72, parágrafo único). [Local], {{data}}.',
            ], ['aplicacao' => 'direta']),
        ];
    }
}
