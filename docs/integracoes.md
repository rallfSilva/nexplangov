# Integrações (Etapa G)

## Compras.gov.br: pesquisa de preços

- Tela: processo › Itens e preços › "Buscar preços no Compras.gov.br" (só itens com código CATMAT/CATSER).
- Fonte: API de dados abertos do Compras.gov.br, módulo de pesquisa de preço (`COMPRASGOV_URL`, `COMPRASGOV_CAMINHO_MATERIAL`, `COMPRASGOV_CAMINHO_SERVICO`). Resposta guardada em cache por 6 horas.
- Entram só compras do último ano, como **contratações similares** (art. 23, § 1º, II). O servidor escolhe quais são comparáveis; valor e data vêm da API, nunca do navegador.
- **A confirmar na homologação**: os caminhos e os nomes dos campos (`precoUnitario`, `dataResultado`, `nomeOrgao`, `codigoUasg`, `niFornecedor`, `idCompra`). O mapeamento aceita variações conhecidas; o ambiente desta construção não tinha acesso à API para testar ao vivo.

## Cotação automática por IA (três fontes do art. 23, § 1º)

A pesquisa usa as três fontes de preços já praticados:

| Fonte | Inciso | Classe | Situação |
|---|---|---|---|
| Painel de Preços do governo federal (API de dados abertos do Compras.gov.br, por CATMAT/CATSER) | I (`painel_oficial`) | `PesquisaPrecosComprasGov` | Ativa, sem credencial |
| Licitações anteriores de outros órgãos (PNCP: busca, itens e resultado homologado) | II (`contratacoes_similares`) | `PrecosPncp` | Ativa, sem credencial |
| Notas fiscais eletrônicas (preços efetivamente pagos) | V (`notas_fiscais`) | `PrecosNotasFiscais` | Desligada até haver convênio |

**Notas fiscais:** não há API pública e aberta da base nacional de NF-e para pesquisa de preços. O acesso depende de convênio do órgão com a Receita Federal ou com a SEFAZ do estado, ou de serviço contratado que já tenha esse acesso. Com o convênio, informe `NFE_PRECOS_URL` e `NFE_PRECOS_TOKEN` (opcionais: `NFE_PRECOS_TIMEOUT`, `NFE_PORTAL_CONSULTA`). O conector chama `GET {url}?descricao=&catmat=&desde=AAAA-MM-DD` com `Authorization: Bearer {token}` e espera `{"itens":[{"chaveAcesso","dataEmissao","valorUnitario","quantidade","unidade","descricao","cnpjEmitente","nomeEmitente","uf","url"}]}`; um adaptador do convênio converte a resposta para esse formato. Nota sem chave de 44 dígitos não entra. O link é o da nota, se vier, ou o portal de consulta da NF-e, onde a chave se confere.

**Equilíbrio entre fontes:** os candidatos vão para a IA intercalados por fonte (`CotacaoAutomatica::intercalar`), e a escolha final pega um preço de cada fonte antes de repetir (`CotacaoAutomatica::selecionar`), sempre o mais recente primeiro.

### Funcionamento

- Tela: processo › Itens e preços › "Cotar com IA" (só itens sem preço ou todos, com 3, 5 ou 10 resultados por item), "Importar planilha" (modelo em `/processos/{id}/itens/modelo.xlsx`) e "Mapa de preços (PDF)" com colunas e seções escolhidas.
- Fluxo por item (job `CotarItemComIa`, um por item, na fila padrão): sem código CATMAT/CATSER, a IA escolhe entre os itens parecidos do catálogo local (`catalogo_itens`); busca preços do último ano no Compras.gov.br (pelo código) e no PNCP (pelas palavras do item); a IA marca os de objeto equivalente e escreve a justificativa; a regra `CotacaoAutomatica::selecionar` fica com os N mais recentes e a `PesquisaPrecos` calcula mínimo, média, mediana e média ponderada (pela quantidade da compra de origem), com alerta quando o coeficiente de variação passa de 25%.
- As cotações da IA entram com a fonte do inciso correspondente (I, II ou V), com `origem = ia`, a descrição e a unidade da compra de origem e o motivo da compatibilidade. **Transparência da fonte:** todo preço guarda o portal, o órgão, a identificação da compra (`identificacao_fonte`: número de controle do PNCP e item, ou compra e UASG no Compras.gov.br), a data do resultado, a data e hora da consulta (`consultado_em`) e o link do registro original (`url_fonte`: página da compra no PNCP; no Compras.gov.br, a compra no PNCP quando a API traz o número de controle, ou a própria consulta à API de dados abertos). Preço sem link e identificação não entra. A tela e o mapa de preços mostram esse bloco "Fonte da coleta" em cada preço. Uma nova cotação por IA troca só as cotações anteriores da IA; as lançadas à mão ficam. Quem pediu recebe e-mail ao fim do lote.
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

## Painel de Compras (SIC/SELC)

O NexPlanGov alimenta o Painel de Compras pela API de entrada dele (`/api/integracao/v1`, ver `docs/INTEGRACAO.md` no repositório do painel).

**Ligar**

1. No servidor do painel: `php artisan sic:token-integracao NEXPLANGOV`. Copie o token (aparece uma vez só).
2. No `.env` do NexPlanGov: `PAINEL_URL=https://endereco-do-painel` e `PAINEL_TOKEN=<token>`.
3. Rode um worker só para a fila do painel, para os lotes chegarem na ordem: `php artisan queue:work --queue=painel`.
4. Carga do que já existe: `php artisan painel:enviar` (ou `--orgao=SIGLA`; `--simular` só conta). Reenviar não duplica.

**O que vai, e quando.** A cada registro salvo (órgão, unidade, fornecedor, item do PCA, processo e itens, ata e itens, contrato, termo, empenho e liquidação), o estado atual vai para a fila e, 30 segundos depois, para o painel. Alterações seguidas do mesmo registro viram um envio só. Cada envio leva também aquilo de que o registro depende no painel (unidade, fornecedor, processo), na ordem: unidades, fornecedores, catálogo, PCA, processos, atas, contratos, aditivos, execução financeira.

| NexPlanGov | Painel |
|---|---|
| Órgão e unidade | Unidades (código `SIGLA-DO-ÓRGÃO-SIGLA-DA-UNIDADE`; a sede usa só a sigla do órgão). O órgão é encontrado pelo CNPJ |
| Fornecedor | Fornecedores (porte ME/EPP; "demais" vai sem porte) |
| Item do PCA | PCA |
| Processo e itens | Processos, uma linha por item, com vencedor e valor homologado. Modalidade e amparo do PNCP viram sistemática, forma, modalidade e amparo do painel |
| Ata e itens | Atas, uma linha por item, e o item vai ao catálogo do painel |
| Contrato | Contratos (número `numero/ano`) |
| Termo aditivo e apostilamento | Aditivos (prazo, valor, misto ou apostilamento). Rescisão vai como situação do contrato |
| Empenho, liquidação e pagamento | Execução financeira (ligada ao contrato, ou ao processo da ata) |

**Fica de fora, por enquanto:** município (o painel recusa o nome que não bate com o IBGE), consumos e adesões de ata, propostas perdedoras e documentos.

**Conferir.** Cada lote fica na tabela `envios_painel` (enviado, erro ou indisponível, com o número do lote no painel). No painel, em Importação de dados > Histórico, origem NexPlanGov, estão as inconsistências linha a linha. Recusa por conteúdo (4xx) não é reenviada; queda do painel (rede, 5xx, 429) volta para a fila com espera crescente, por até 6 tentativas.

**LGPD.** O CPF de fornecedor pessoa física vai ao painel (é dado de contratação pública, art. 7º, III, da Lei 13.709), que o guarda e exibe mascarado. Gestor e fiscal vão pelo nome.
