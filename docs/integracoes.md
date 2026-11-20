# Integrações (Etapa G)

## Compras.gov.br: pesquisa de preços

- Tela: processo › Itens e preços › "Buscar preços no Compras.gov.br" (só itens com código CATMAT/CATSER).
- Fonte: API de dados abertos do Compras.gov.br, módulo de pesquisa de preço (`COMPRASGOV_URL`, `COMPRASGOV_CAMINHO_MATERIAL`, `COMPRASGOV_CAMINHO_SERVICO`). Resposta guardada em cache por 6 horas.
- Entram só compras do último ano, como **contratações similares** (art. 23, § 1º, II). O servidor escolhe quais são comparáveis; valor e data vêm da API, nunca do navegador.
- **A confirmar na homologação**: os caminhos e os nomes dos campos (`precoUnitario`, `dataResultado`, `nomeOrgao`, `codigoUasg`, `niFornecedor`, `idCompra`). O mapeamento aceita variações conhecidas; o ambiente desta construção não tinha acesso à API para testar ao vivo.

## Cotação automática por IA (Compras.gov.br + PNCP)

- Tela: processo › Itens e preços › "Cotar com IA" (só itens sem preço ou todos, com 3, 5 ou 10 resultados por item), "Importar planilha" (modelo em `/processos/{id}/itens/modelo.xlsx`) e "Mapa de preços (PDF)" com colunas e seções escolhidas.
- Fluxo por item (job `CotarItemComIa`, um por item, na fila padrão): sem código CATMAT/CATSER, a IA escolhe entre os itens parecidos do catálogo local (`catalogo_itens`); busca preços do último ano no Compras.gov.br (pelo código) e no PNCP (pelas palavras do item); a IA marca os de objeto equivalente e escreve a justificativa; a regra `CotacaoAutomatica::selecionar` fica com os N mais recentes e a `PesquisaPrecos` calcula mínimo, média, mediana e média ponderada (pela quantidade da compra de origem), com alerta quando o coeficiente de variação passa de 25%.
- As cotações da IA entram como **contratações similares** (art. 23, § 1º, II), com `origem = ia`, a descrição e a unidade da compra de origem, o link do PNCP e o motivo da compatibilidade. Uma nova cotação por IA troca só as cotações anteriores da IA; as lançadas à mão ficam. Quem pediu recebe e-mail ao fim do lote.
- PNCP (API pública, sem credencial): `PNCP_BUSCA_URL` (padrão `https://pncp.gov.br/api/search/`, parâmetros `q`, `tipos_documento=edital`, `status=encerradas`) e `PNCP_CONSULTA_URL` (padrão `https://pncp.gov.br/api/pncp`, rotas `/v1/orgaos/{cnpj}/compras/{ano}/{sequencial}/itens` e `.../itens/{numero}/resultados`). Cache de 6 horas.
- Uso de IA: duas chamadas por item no máximo (`acao` = `catalogo` e `cotar` em `usos_ia`). Limite de itens por pedido em `NEXPLANGOV_COTACAO_IA_MAX_ITENS` (padrão 500).
- **A confirmar na homologação**: a busca do PNCP (`/api/search/`) é a mesma que o portal usa e não está no manual de consultas; os campos lidos são `items[].orgao_cnpj`, `ano`, `numero_sequencial`, `orgao_nome`, `municipio_nome`, `uf`, e nos resultados `valorUnitarioHomologado`, `quantidadeHomologada`, `dataResultado`, `niFornecedor`, `nomeRazaoSocialFornecedor`. No Compras.gov.br, a descrição do item vem de `descricaoItem`. O ambiente desta construção não tinha acesso às APIs para testar ao vivo.
- Banco de Preços (Negócios Públicos): sem API pública. Se houver parceria, entra como mais uma fonte em `CotadorIa::coletar()`.

## Portal da Transparência: CEIS e CNEP

- Para ligar, cadastre-se gratuitamente no Portal da Transparência e informe a chave em `TRANSPARENCIA_CHAVE`. Sem a chave, a consulta fica desligada. A chave vai no cabeçalho `chave-api-dados`.
- O sistema chama `GET {TRANSPARENCIA_URL}/ceis` e `/cnep` com o parâmetro `codigoSancionado` (CNPJ ou CPF, só números) e lê até 5 páginas de 15 registros.
- A consulta é automática no cadastro do fornecedor, antes da adjudicação, do cadastro do contrato e da emissão de ordem de fornecimento. Também é possível consultar na tela do fornecedor ("CEIS e CNEP"). Cada consulta guarda a data, quem consultou, o resumo das sanções e o SHA-256 da resposta, mas nunca o CPF.
- **A confirmar na homologação**: os nomes dos campos da resposta (`tipoSancao.descricaoResumida`, `orgaoSancionador.nome`, `dataInicioSancao`, `dataFimSancao`, com datas em dd/mm/aaaa). O mapeamento aceita as variações conhecidas. O ambiente desta construção não tinha acesso à API para testar ao vivo.

## Tribunal de contas: remessa

- Tela: Integrações › Remessa ao tribunal de contas (`exportacoes.gerar`), ou `php artisan nexplangov:exportar-tce SIGLA 2026-01-01 2026-12-31`.
- Pacote ZIP: `processos.csv`, `itens.csv`, `contratos.csv`, `termos_contrato.csv`, `atas.csv` (separador `;`, UTF-8 com BOM, decimal com vírgula) e `manifesto.json` (layout, órgão, período, quantidade e SHA-256 de cada arquivo).
- Layout genérico `nexplangov-generico-1.0`. Cada tribunal tem leiaute próprio; o conversor para o leiaute do TCE do órgão é a próxima peça, quando o leiaute oficial for entregue.
- LGPD: CPF de fornecedor pessoa física sai mascarado. Se o leiaute do tribunal exigir o CPF completo (obrigação legal, LGPD art. 7º, II), isso deve ser ligado de forma explícita.
- Células que começam com `=`, `+`, `-` ou `@` recebem apóstrofo para não virarem fórmula na planilha.
- Cada remessa fica na trilha de auditoria.

## SEI

- Número SEI do processo na aba Resumo; com `SEI_URL_CONSULTA` (ex.: `https://sei.exemplo.gov.br/...?numero={numero}`) aparece o botão "Abrir no SEI".
- "Dossiê para o SEI": ZIP com capa, cada peça concluída (HTML ou o arquivo original enviado), assinaturas com código de validação e `manifesto.json` com o SHA-256 de cada arquivo. No SEI, cada arquivo entra como documento externo.
- A integração automática pelos serviços do SEI (inclusão de documento por web service) depende da liberação do órgão (sigla do sistema e identificação do serviço no SEI) e fica para quando houver um órgão para homologar.
