# Integrações (Etapa G)

## Compras.gov.br: pesquisa de preços

- Tela: processo › Itens e preços › "Buscar preços no Compras.gov.br" (só itens com código CATMAT/CATSER).
- Fonte: API de dados abertos do Compras.gov.br, módulo de pesquisa de preço (`COMPRASGOV_URL`, `COMPRASGOV_CAMINHO_MATERIAL`, `COMPRASGOV_CAMINHO_SERVICO`). Resposta guardada em cache por 6 horas.
- Entram só compras do último ano, como **contratações similares** (art. 23, § 1º, II). O servidor escolhe quais são comparáveis; valor e data vêm da API, nunca do navegador.
- **A confirmar na homologação**: os caminhos e os nomes dos campos (`precoUnitario`, `dataResultado`, `nomeOrgao`, `codigoUasg`, `niFornecedor`, `idCompra`). O mapeamento aceita variações conhecidas; o ambiente desta construção não tinha acesso à API para testar ao vivo.

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
