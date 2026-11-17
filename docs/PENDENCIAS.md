# Pendências para finalizar o NexPlanGov

Levantamento de 17/11/2026, feito sobre a `main` (último merge: PR #30). Foram lidos o código (`app/`, `routes/`, `config/`, `docker/`, `scripts/`), os 72 arquivos de teste, o README, a pasta `docs/`, o guia de integração da nova proposta (`nova-proposta/guia-integracao-pncp.md`) e o relatório "Requisitos x sistema implementado" de 05/11/2026, que dividiu os 59 requisitos funcionais em cinco ondas.

## Resumo

O núcleo da Lei nº 14.133/2021 está pronto: PCA, DFD, instrução, pesquisa de preços, dispensa, julgamento, sessão presencial, recursos, contratos, aditivos, sanções, medição e pagamento, tramitação entre unidades, autos com PDF único, auditoria encadeada e publicação no PNCP pela fila. As ondas 1, 2 e 4 dos requisitos entraram (PRs #26, #27 e #28). Faltam três frentes:

1. **Operação em produção**: há lacunas que impedem rodar com segurança num servidor real (agendador, backup fora do Docker, chave da aplicação, e-mail, monitoramento).
2. **PNCP ao vivo**: o envio está pronto, mas nunca rodou contra `treina.pncp.gov.br`, e a implantação do órgão no PNCP (cadastro do órgão, unidades e associação do CNPJ) ainda é manual.
3. **Onda 3 dos requisitos** (SAGRES do TCE-RR, API aberta, ICP-Brasil, preços do PNCP) e **onda 5** (portal do fornecedor, credenciamento, sala de disputa).

Prioridades: **P0** bloqueia a produção; **P1** antes do primeiro órgão real; **P2** completa os requisitos do documento; **P3** evolução.

## P0: antes de colocar no ar

| # | Pendência | Onde está o problema | O que fazer |
|---|---|---|---|
| 1 | **O agendador não roda na instalação com Docker.** O `supervisord.conf` sobe só PHP-FPM e Nginx, e o `docker-compose.yml` não tem serviço para `schedule:work`. Com isso, o resumo diário de prazos (`nexplangov:alertas`, alertas do art. 94 e de vigência) e a limpeza dos pacotes de devolução (`nexplangov:devolucao-limpar`) nunca rodam. | `docker-compose.yml`, `docker/supervisord.conf`, `routes/console.php` | Acrescentar um serviço `agendador` com `php artisan schedule:work` (como o serviço `fila`). Na instalação sem Docker, o cron do guia de deploy resolve. |
| 2 | **Backup só funciona com Docker.** `scripts/backup.sh` e `restaurar.sh` usam `docker compose exec`. Numa VPS sem Docker não há cópia. A cópia fora do servidor também é manual. | `scripts/` | Usar o script nativo do [guia de deploy](DEPLOY-HOSTINGER.md#13-backup) e levar a cópia, cifrada, para fora da VPS (rclone + armazenamento externo). Testar a restauração antes de abrir para o órgão. |
| 3 | **A `APP_KEY` é chave única de tudo.** Ela cifra o CPF (`encrypted`), gera o HMAC da busca por CPF (`Documento::hash`) e o HMAC do código de validação das assinaturas (`Assinaturas`). Perder a chave perde os CPFs e invalida todos os códigos de validação. Trocar a chave tem o mesmo efeito, porque `APP_PREVIOUS_KEYS` só ajuda a decifrar, não o HMAC. | `app/Support/Documento.php:81`, `app/Licitacao/Fluxo/Assinaturas.php:99` | Guardar o `.env` de produção num cofre fora do servidor (gerenciador de senhas do órgão). Separar as chaves de HMAC (por exemplo, `NEXPLANGOV_CHAVE_HMAC`) e documentar a rotação. |
| 4 | **E-mail.** O `.env.example` vem com `MAIL_MAILER=log`. Sem SMTP real não chegam a troca de senha, os alertas de prazo, os pedidos a externos nem as respostas ao titular (LGPD). | `.env` | Configurar SMTP do órgão ou de um serviço transacional, com SPF, DKIM e DMARC no domínio. |
| 5 | **Cookie de sessão seguro.** `SESSION_SECURE_COOKIE` não está no `.env.example`. A lista de conferência de `docs/qualidade.md` pede `true`. | `.env.example` | Incluir `SESSION_SECURE_COOKIE=true` no exemplo de produção (o guia já põe). |
| 6 | **Monitoramento.** Não há aviso quando a fila para, quando um envio ao PNCP falha de vez (`failed_jobs`) ou quando o sistema cai. O `.env.example` traz `LOG_LEVEL=debug`. | operação | `LOG_LEVEL=warning` e `LOG_STACK=daily`; verificação externa de `/up` (UptimeRobot ou similar); um comando agendado que avise o administrador sobre `failed_jobs` e envios em `erro`. |

## P1: antes do primeiro órgão real

### PNCP (comparado com `guia-integracao-pncp.md`)

| # | Pendência | Situação no código | O que fazer |
|---|---|---|---|
| 7 | **Credenciamento da NexMind como plataforma digital** no PNCP, para ter `PNCP_LOGIN` e `PNCP_SENHA` (senha de 16 a 64 caracteres). | Não é código: é trâmite com o ministério gestor. | Iniciar já; nada vai ao PNCP sem isso. |
| 8 | **Homologação ao vivo nunca rodou.** O ambiente de construção não alcançava o PNCP. | `nexplangov:pncp-homologar` existe. | Rodar `php artisan nexplangov:pncp-homologar --orgao=SIGLA` e depois `--ciclo --processo=ID` contra `treina.pncp.gov.br`, conferir no portal de treinamento e corrigir o que o PNCP recusar. |
| 9 | **Implantação do órgão no PNCP** (seção 3 do guia): incluir o órgão (`POST /v1/orgaos`), associar o CNPJ ao usuário da plataforma (`PUT /v1/usuarios/{id}`) e cadastrar as unidades compradoras (`POST /v1/orgaos/{cnpj}/unidades`). | O sistema só **consulta** órgão e unidades. Não há inclusão nem associação. | Criar um assistente de implantação por órgão que faça essa sequência, com auditoria. |
| 10 | **Documentos complementares da compra**: TR (tipo 4), ETP (7), mapa de riscos (9), minuta do contrato (3) e DFD (10). | Vai só o edital (2) ou o aviso de contratação direta (1). | Enviar as peças concluídas como documentos da compra depois da inclusão (`.../compras/{ano}/{seq}/arquivos`). |
| 11 | **Documento do termo de contrato**: rescisão (13), aditivo (14) e apostilamento (15). | O termo é incluído sem arquivo. | Anexar o PDF do termo em `.../contratos/{ano}/{seq}/termos/.../arquivos` (rota a confirmar no Swagger). |
| 12 | **Rotas e campos a confirmar no Swagger vigente**: rotas do PCA, tabela de categoria do item do PCA (`MontadorPayload::categoriaItemPca`), campos opcionais do resultado e a rota de arquivos do termo. | Implementado pelo manual 1.0.0. | Conferir na homologação (item 8). |
| 13 | **Limite de arquivo**: o PNCP aceita até 30 MB, o sistema aceita 20 MB (`upload_max_filesize`, contrato em PDF até 20 MB). | `docker/php.ini`, validações | Manter 20 MB é seguro; só subir se o órgão precisar. |

O que já atende ao guia: login com cache do token por 55 minutos e novo login no 401; fila com estados e reenvio com espera crescente (60 s a 1 h) para erro 5xx; 400 e 422 vão ao usuário; número de controle guardado e reenvio vira retificação; retificação com objeto completo; ambiente por órgão começando em homologação; exclusão com justificativa.

### Outras integrações a confirmar ao vivo

| # | Pendência | O que fazer |
|---|---|---|
| 14 | Compras.gov.br (pesquisa de preços): caminhos e nomes de campos não testados ao vivo (`docs/integracoes.md`). | Testar na homologação com itens CATMAT e CATSER reais. |
| 15 | Portal da Transparência (CEIS e CNEP): nomes dos campos da resposta não testados ao vivo. | Cadastrar a chave gratuita (`TRANSPARENCIA_CHAVE`) e consultar um CNPJ com sanção conhecida. |

### Segurança

| # | Pendência | O que fazer |
|---|---|---|
| 16 | CSP com `'unsafe-inline'` em script e estilo (`docs/qualidade.md`). | Trocar por nonce nos scripts das telas. |
| 17 | Sem verificação de dependências vulneráveis no CI. | Acrescentar `composer audit` e `npm audit --omit=dev` ao `testes.yml` e ligar o Dependabot. |
| 18 | Arquivos enviados por pessoas de fora (pedidos a externos, impugnações) não passam por antivírus. | ClamAV no servidor e verificação antes de juntar aos autos. |
| 19 | Verificação em duas etapas obrigatória só para administradores. | Avaliar tornar obrigatória para autoridade, jurídico e agente de contratação, que assinam atos. |
| 20 | Nenhum teste de intrusão feito. | Rodar OWASP ZAP na homologação e, se o órgão exigir, contratar pentest externo antes da produção. |
| 21 | Endurecimento do servidor. | SSH só com chave, sem senha de root, UFW, fail2ban e atualizações automáticas (ver o guia de deploy). |

### LGPD

| # | Pendência | Base | O que fazer |
|---|---|---|---|
| 22 | **NexMind IA envia texto à API da Anthropic** (fora do Brasil). Há o `Anonimizador` e o bloqueio em parecer e autorização, mas a transferência internacional precisa estar declarada. | arts. 33 e 37 | Registrar a operação em `operacoes_tratamento`, citar no aviso de privacidade e manter a IA desligada (`NEXPLANGOV_IA=false`) até o órgão aprovar. |
| 23 | **Contrato de operador** entre a NexMind e cada órgão (o órgão é controlador). | arts. 37 a 39 | Cláusulas de LGPD no contrato de licença ou de SaaS. |
| 24 | **Relatório de impacto (RIPD)**. | art. 38 | Modelo de RIPD para o órgão preencher na implantação. |
| 25 | **Plano de resposta a incidente**, com comunicação à ANPD e aos titulares. | art. 48 e Resolução CD/ANPD nº 15/2024 | Procedimento escrito: quem avalia, prazos e modelo de comunicação. |
| 26 | **Retenção e eliminação**: não há prazo de guarda definido por tipo de dado. A trilha de auditoria é imutável por desenho. | arts. 15 e 16 | Adotar a tabela de temporalidade do órgão (Conarq) e documentar que a guarda decorre de obrigação legal (art. 16, I). |
| 27 | **Cópias de segurança com dado pessoal**. O dump guarda o CPF cifrado, mas os arquivos enviados vão sem cifra. | art. 46 | Cifrar a cópia que sai do servidor (rclone crypt ou gpg). |
| 28 | Encarregado (DPO) do órgão configurado na tela de privacidade. | art. 41 | Conferir na implantação de cada órgão. |

### Testes e qualidade

| # | Pendência | O que fazer |
|---|---|---|
| 29 | Suíte de integração contra `treina.pncp.gov.br` (pedida no guia, seção 7). Hoje só há testes com `Http::fake`. | Testes marcados como `@group pncp-ao-vivo`, rodados à mão com as credenciais de homologação. |
| 30 | Teste de ponta a ponta no navegador do fluxo principal (DFD → PCA → processo → parecer → autorização → publicação → contrato). | Playwright, já usado no `scripts/qualidade`. |
| 31 | Teste de carga k6 e verificação de acessibilidade no ambiente do órgão. | Rodar `scripts/carga/k6-navegacao.js` e `npm run acessibilidade` na homologação (lista de `docs/qualidade.md`). |
| 32 | Teste periódico de restauração de backup. | Restaurar uma vez por mês numa VPS de teste. |
| 33 | Cobertura de código não é medida. | Ligar `pcov` no CI e publicar o relatório (sem meta obrigatória no início). |

## P2: requisitos funcionais que faltam (onda 3)

| # | Entrega | Itens do documento | Situação | Dependência |
|---|---|---|---|---|
| 34 | **SAGRES do TCE-RR em XML** | 8.4 | Há a remessa genérica em CSV (`nexplangov-generico-1.0`); falta o leiaute do tribunal. | Leiaute oficial do TCE-RR. Pedir já. |
| 35 | **API aberta com tokens e documentação OpenAPI** | T1 | Não há rotas de API nem tokens. | Nenhuma (Sanctum + OpenAPI). |
| 36 | **Assinatura ICP-Brasil** por provedor externo | 1.2 | A assinatura atual é eletrônica com senha, selo e código de validação (avançada). | Contrato com provedor (BirdID, VIDaaS, SafeID ou similar). |
| 37 | **Preços de contratações do PNCP** como fonte da pesquisa | 4.1 a 4.3 | Já há mediana, média, menor preço e coeficiente de variação; falta a consulta pública ao PNCP como fonte (art. 23, § 1º, II). | Nenhuma (consulta pública). |
| 38 | **SEI por web service** | integrações | Há número SEI, link e dossiê ZIP; falta a inclusão automática. | Liberação do SEI do órgão. |
| 39 | **Integração contábil do empenho** | 6.4 | O empenho é lançado à mão. | Sistema contábil do órgão. |

## P3: evolução (onda 5)

| # | Entrega | Itens | Observação |
|---|---|---|---|
| 40 | Portal do fornecedor com cadastro homologado | 12, 11.3 | Base para o credenciamento e a sala de disputa. |
| 41 | Credenciamento (art. 79) com fluxo próprio | 5.3 | Hoje existem a modalidade, o amparo legal e o modelo de parecer, mas não o chamamento contínuo com inscrição de credenciados. |
| 42 | Sala de disputa eletrônica em tempo real | 5.1, 5.2 | Hoje há lances registrados pelo agente e sessão presencial. A sala aberta aos licitantes exige tempo real, cronômetros e disponibilidade 24 horas. Só vale se o edital do cliente pontuar. |
| 43 | SICAF | integrações | Consulta à situação do fornecedor. |
| 44 | e-Marketplace | 5.4 | Depois do portal do fornecedor e do credenciamento. |

## Ordem sugerida

1. Itens 1 a 6 (P0), junto com o deploy em homologação na VPS. Cerca de 1 semana.
2. Itens 7 e 8 (credenciamento e homologação no PNCP), que dependem de terceiros e devem começar já.
3. Itens 9 a 12 (PNCP completo) e 16 a 28 (segurança e LGPD). Cerca de 3 a 4 semanas.
4. Onda 3 (itens 34 a 39), começando pela API aberta e pelos preços do PNCP, que não dependem de ninguém. 8 a 12 semanas, como estimado no relatório de requisitos.
5. Onda 5, se o edital ou o cliente pedir.
