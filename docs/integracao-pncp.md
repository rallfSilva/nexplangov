# Guia técnico de integração com o PNCP — NexPlanGov

Base: *Manual de Integração PNCP – Versão 1.0.0* (pasta do projeto no Drive). Antes de implementar, baixe a versão vigente do manual e o Swagger: versões novas acrescentam serviços (PCA, por exemplo) e mudam campos. Onde houver diferença, vale o Swagger vigente.

## 1. Ambientes

| Ambiente | Portal | Base da API (`BASE_URL`) | Swagger |
|---|---|---|---|
| Homologação | https://treina.pncp.gov.br | https://treina.pncp.gov.br/api/pncp | https://treina.pncp.gov.br/api/pncp/swagger-ui/index.html |
| Produção | https://pncp.gov.br | https://pncp.gov.br/api/pncp | https://pncp.gov.br/api/pncp/swagger-ui/index.html |

Protocolo: REST/HTTP 1.1 com JSON. Consultas são públicas. Inclusão, retificação e exclusão exigem autenticação.

## 2. Credenciamento e autenticação

- A NexMind precisa se credenciar como **plataforma digital** junto ao ministério gestor do PNCP para receber login e senha. A plataforma é juridicamente responsável pelos dados que envia.
- A senha tem de 16 a 64 caracteres. Não pode conter o login, o nome do usuário ou partes do e-mail, nem sequências de 3 ou mais caracteres iguais ou de 4 ou mais caracteres em ordem crescente ou decrescente.
- Login: `POST /v1/usuarios/login`. O JWT volta no cabeçalho `Authorization` (`Bearer <token>`) e vale **1 hora**. Use um único token enquanto ele for válido e renove antes de expirar.
- Para associar à plataforma os CNPJs dos órgãos que ela representa, use `PUT /v1/usuarios/{id}`, enviando a lista de CNPJs autorizados.

**Implementação:**
- Credenciais da plataforma ficam em cofre de segredos (variável de ambiente cifrada ou secret manager), nunca no banco do cliente.
- Crie um serviço `PncpAuthService` com cache do token por 55 minutos.
- Uma resposta 401 dispara um novo login e repete a chamada uma vez.

## 3. Sequência obrigatória antes do primeiro envio de um órgão

1. Fazer login.
2. Consultar o órgão: `GET /v1/orgaos/{cnpj}`. Se não existir, incluir com `POST /v1/orgaos`.
3. Associar o CNPJ ao usuário da plataforma (`PUT /v1/usuarios/{id}`).
4. Cadastrar as unidades compradoras: `POST /v1/orgaos/{cnpj}/unidades`. Para consultar, use `GET /v1/orgaos/{cnpj}/unidades` ou `GET /v1/orgaos/{cnpj}/unidades/{codigo}`.
5. Só então enviar compras, atas e contratos.

No NexPlanGov, essa sequência faz parte do assistente de implantação de cada cliente (tenant).

## 4. Serviços

| Recurso | Operação | Método e rota |
|---|---|---|
| Compra/Edital/Aviso | Inserir | `POST /v1/orgaos/{cnpj}/compras` |
| | Retificar (reenviar todos os campos) | `PUT /v1/orgaos/{cnpj}/compras/{ano}/{sequencial}` |
| | Remover | `DELETE /v1/orgaos/{cnpj}/compras/{ano}/{sequencial}` |
| | Consultar | `GET /v1/orgaos/{cnpj}/compras/{ano}/{sequencial}` |
| Documentos da compra | Inserir (multipart) / listar / consultar / remover | `…/compras/{ano}/{sequencial}/arquivos[/{sequencialDocumento}]` |
| Itens | Inserir / retificar / consultar | `…/compras/{ano}/{sequencial}/itens[/{numeroItem}]` |
| Resultados do item | Inserir / retificar / consultar | `…/itens/{numeroItem}/resultados[/{sequencialResultado}]` |
| Ata de registro de preços | Inserir / retificar / remover / consultar | `…/compras/{ano}/{sequencial}/atas[/{sequencialAta}]` |
| Documentos da ata | Inserir / listar / consultar / remover | `…/atas/{sequencialAta}/arquivos[/{sequencialDocumento}]` |
| Contrato | Inserir | `POST /v1/orgaos/{cnpj}/contratos` |
| | Retificar / remover / consultar | `/v1/orgaos/{cnpj}/contratos/{ano}/{sequencial}` |
| Documentos do contrato | Inserir / listar / consultar / remover | `…/contratos/{ano}/{sequencial}/arquivos` |
| Termo de contrato (aditivo, apostila, rescisão) | Inserir / retificar / remover / consultar | `…/contratos/{ano}/{sequencial}/termos` (confirmar no Swagger) |
| PCA | Incluir plano e itens, retificar, excluir, consultar | **Rotas não verificadas.** Fonte oficial indicada pelo cliente: https://pncp.gov.br/manual/pt-br/latest/plano_de_contratacao/index.html. Copiar daí os endpoints, campos e domínios. O estudo inicial citava `/v1/orgaos/{cnpj}/pca…`, sem confirmação |

Códigos de retorno mais comuns: 200/201 (sucesso; o 201 devolve `location` com a URL do recurso criado, de onde se extraem ano e sequencial), 400 (requisição inválida), 401 (token), 422 (regra de negócio), 500 (erro do PNCP, reprocessar).

### Exemplo — inserir compra (v1.0.0)

Campos obrigatórios:

- `codigoUnidadeCompradora`, `tipoInstrumentoConvocatorioId`, `modalidadeId`, `modoDisputaId`
- `numeroCompra`, `anoCompra`, `numeroProcesso`, `situacaoCompraId`, `objetoCompra` (até 5.120 caracteres)
- `srp`, `orcamentoSigiloso`, `dataAberturaProposta`, `dataEncerramentoProposta`, `amparoLegalId`
- `itensCompra[]`, e em cada item: `numeroItem`, `materialOuServico` (M/S), `incentivoProdutivoBasico`, `descricao`, `quantidade`, `unidadeMedida`, `valorUnitarioEstimado`, `valorTotal`, `criterioJulgamentoId`. O campo `tipoBeneficioId` é opcional.

Em versões mais novas, a inclusão da compra pode exigir multipart com o documento principal (edital ou aviso). Confirme no Swagger.

### Envio de documentos

- `Content-Type: multipart/form-data`, campo `arquivo`.
- Cabeçalhos: `Titulo-Documento` (até 50 caracteres) e `Tipo-Documento-Id`.
- Limite de **30 MB** por arquivo.

## 5. Tabelas de domínio (v1.0.0)

Guarde-as em tabelas `pncp_dominios` versionadas e sincronize com o Swagger vigente.

| Domínio | Códigos |
|---|---|
| Instrumento convocatório | 1 Edital · 2 Aviso de Contratação Direta (dispensa com disputa) · 3 Ato que autoriza a Contratação Direta (dispensa sem disputa ou inexigibilidade) |
| Modalidade | 2 Diálogo Competitivo · 3 Concurso · 4 Concorrência Eletrônica · 5 Concorrência Presencial · 6 Pregão Eletrônico · 7 Pregão Presencial · 8 Dispensa · 9 Inexigibilidade · 10 Manifestação de Interesse · 11 Pré-qualificação · 12 Credenciamento |
| Modo de disputa | 1 Aberto · 2 Fechado · 3 Aberto-Fechado · 4 Dispensa com Disputa · 5 Não se aplica |
| Critério de julgamento | 1 Menor preço · 2 Maior desconto · 3 Melhor técnica ou conteúdo artístico · 4 Técnica e preço · 5 Maior lance · 6 Maior retorno econômico · 7 Não se aplica |
| Situação da compra | 1 Divulgada · 2 Revogada · 3 Anulada · 4 Suspensa |
| Situação do item | 1 Pendente · 2 Homologado · 3 Cancelado · 4 Deserto |
| Tipo de benefício | 1 Cota exclusiva ME/EPP · 2 Subcontratação ME/EPP · 3 Cota reservada ME/EPP · 4 Sem benefício · 5 Não se aplica |
| Situação do resultado | 1 Informado · 2 Cancelado |
| Tipo de contrato | 1 Contrato · 2 Comodato · 3 Arrendamento · 4 Concessão · 5 Termo de Adesão · 6 Convênio · 7 Empenho · 8 Outros · 9 TED · 10 ACT · 11 Termo de Compromisso · 12 Carta Contrato |
| Tipo de termo de contrato | 1 Rescisão · 2 Aditivo · 3 Apostilamento |
| Categoria do processo | 1 Cessão · 2 Compras · 3 Informática (TIC) · 4 Internacional · 5 Locação de Imóveis · 6 Mão de Obra · 7 Obras · 8 Serviços · 9 Serviços de Engenharia · 10 Serviços de Saúde |
| Tipo de documento | 1 Aviso de Contratação Direta · 2 Edital · 3 Minuta do Contrato · 4 Termo de Referência · 5 Anteprojeto · 6 Projeto Básico · 7 ETP · 8 Projeto Executivo · 9 Mapa de Riscos · 10 DOD/DFD · 11 Ata de Registro de Preço · 12 Contrato · 13 Termo de Rescisão · 14 Termo Aditivo · 15 Termo de Apostilamento · 16 Outros |
| Amparo legal (exemplos) | 1–5 Art. 28, I a V · 6 Art. 74, I · 7 Art. 74, II · 8–15 Art. 74, III, a–h · 16 Art. 74, IV · 17 Art. 74, V · 18 Art. 75, I · 19 Art. 75, II · 20–24 Art. 75, III e IV … (lista completa na seção 5.15 do manual) |
| Natureza jurídica | ex.: 1031 Executivo Municipal · 1066 Legislativo Municipal · 1120 Autarquia Municipal · 1155 Fundação Pública Municipal · 1210/1228 Consórcio Público · 1244 Município · 2011 Empresa Pública · 2038 Sociedade de Economia Mista |

## 6. Mapeamento NexPlanGov → PNCP

| Evento no sistema | Chamada ao PNCP |
|---|---|
| Implantação do cliente | Órgão + associação do CNPJ + unidades |
| PCA aprovado | Inclusão do PCA e dos itens (manual vigente) |
| Publicação de edital ou aviso no NexLicita | Inserir compra + itens + documentos (edital ou aviso, TR, ETP, mapa de riscos, minuta) |
| Revogação, anulação ou suspensão | Retificar compra com a situação 2, 3 ou 4 e a justificativa |
| Homologação | Inserir resultados por item e retificar a situação do item para 2 (ou 4 se deserto) |
| Assinatura da ata | Inserir ata + documento tipo 11 |
| Assinatura do contrato | Inserir contrato + documento tipo 12 |
| Aditivo, apostila ou rescisão | Inserir termo de contrato + documento tipo 14, 15 ou 13 |

## 7. Requisitos de engenharia

- Fila (Laravel Queue + Redis) para todo envio. Cada envio passa pelos estados `pendente`, `enviando`, `publicado` ou `erro`. Reenvio com backoff para erros 500 e de rede. Erros 400 e 422 vão ao usuário com a mensagem do PNCP.
- Tabela `pncp_sync_logs` com requisição, resposta, código HTTP, usuário, tenant, data e hora e a URL `location`.
- Guarde o número de controle PNCP de cada objeto e nunca reenvie uma inclusão que já tem número. Nesse caso, faça retificação.
- Toda retificação envia o objeto completo.
- Separe homologação e produção por configuração do tenant. Um cliente novo começa em homologação.
- Nunca marque um objeto como publicado sem resposta 2xx do PNCP.
- Testes automatizados com mocks do PNCP e uma suíte de integração rodando contra `treina.pncp.gov.br`.

## 8. O que o NexPlanGov já faz (novembro de 2026)

| Evento no sistema | Envio | Documento anexado |
|---|---|---|
| Autoridade autoriza a publicação (licitação) | Inclusão da compra, multipart com a parte `compra` (JSON) e a parte `documento` | Edital (tipo 2): o PDF enviado na peça "Minuta do edital" ou, se a peça foi redigida no sistema, um PDF gerado dela |
| Autoridade autoriza a contratação direta | Inclusão da compra | Aviso de contratação direta (tipo 1), gerado com objeto, fundamento e decisão da autoridade (art. 72, parágrafo único) |
| Revogação, anulação, deserção, fracasso, homologação | Retificação da compra (objeto completo + `justificativa`) | |
| Homologação | Um resultado por item homologado com vencedor (`.../itens/{n}/resultados`); o sequencial devolvido fica no item e o reenvio vira retificação | |
| Botão "Publicar no PNCP" na ata | Inclusão da ata; em seguida, o PDF da ata (tipo 11) em `.../atas/{seq}/arquivos` | Gerado do registro da ata |
| Botão "Publicar no PNCP" no PCA aprovado | Inclusão do PCA com `itensPlano` | |
| Botão "Publicar no PNCP" no contrato | Inclusão do contrato; se houver contrato assinado anexado, em seguida o arquivo (tipo 12) | O PDF que o usuário anexou (SHA-256 guardado) |
| Termo de contrato | Inclusão do termo | |
| "Excluir no PNCP" na lista de envios | `DELETE` com `justificativa` obrigatória (mín. 10 caracteres), que fica pública | |

Regras implementadas: título do documento cortado em 50 caracteres e sem acento; a compra sem edital concluído não sai (pendência "Conclua a peça Minuta do edital", art. 54); arquivo de contrato só em PDF até 20 MB; órgão de turma de treinamento nunca envia (estado "Simulado").

Os PDFs gerados usam o Dompdf com acesso remoto desligado e ficam em `storage/app/private/orgaos/{id}/pncp/`.

### Homologação no ambiente de treinamento do PNCP

Feito uma vez por órgão, antes de mudar o ambiente para produção:

```bash
# 1. Só leitura: login, cadastro do órgão e das unidades compradoras no PNCP
php artisan nexplangov:pncp-homologar --orgao=SIGLA

# 2. Publicação de teste em treina.pncp.gov.br (recusado se o órgão estiver em produção)
php artisan nexplangov:pncp-homologar --orgao=SIGLA --ciclo --processo=ID
```

O servidor precisa alcançar `treina.pncp.gov.br` e `pncp.gov.br` (porta 443). Depois do ciclo, confira a compra no portal de treinamento e só então altere o ambiente do órgão para produção em Órgãos.

Pontos a conferir no Swagger vigente, porque o manual 1.0.0 não detalha: a tabela de categoria do item do PCA (`MontadorPayload::categoriaItemPca`) e os campos opcionais do resultado. O ciclo de teste mostra a mensagem do PNCP quando algum campo mudou.
