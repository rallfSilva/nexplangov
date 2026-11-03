# Regras da Lei 14.133/2021 implementadas

Cada regra está em `app/Licitacao/Regras` e tem teste em `tests/Unit/Lei14133` ou `tests/Feature/Lei14133`.

## Planejamento e instrução

| Regra | Onde | Base |
|---|---|---|
| PCA por exercício, itens por natureza de despesa, aprovação antes de publicar | `PcaController` | art. 12, VII |
| Checklist da fase preparatória: DFD, ETP, mapa de riscos, termo de referência, pesquisa de preços, dotação, minutas, parecer jurídico, autorização | `InstrucaoProcesso` | art. 18; art. 53 |
| Checklist da contratação direta (incisos I a VIII) | `InstrucaoProcesso` | art. 72 |
| ETP só conclui com os incisos I, IV, VI, VIII e XIII preenchidos | `TiposDocumento`, `DocumentoProcessoController` | art. 18, § 2º |
| Modalidade presencial exige justificativa | `InstrucaoProcesso` | art. 17, § 2º |
| Parecer jurídico só pelo perfil jurídico, na etapa de análise jurídica, com conclusão favorável ou desfavorável | `DocumentoProcessoController` | art. 53 |
| Autorização só pela autoridade competente | `DocumentoProcessoController` | art. 72, VIII |
| Amparo legal compatível com a modalidade | `AmparoLegal::modalidadesCompativeis()` | arts. 74 e 75 |
| Revogação e anulação exigem justificativa (mín. 20 caracteres) | `TramitacaoProcesso` | art. 71 |
| Peça concluída fica imutável, com hash SHA-256 do conteúdo; nova versão para corrigir | `DocumentoProcesso` | art. 12, VI |

## Pesquisa de preços (art. 23)

- Fontes: painel/contratações similares (validade de 1 ano), fornecedores (6 meses), mídia especializada, notas fiscais.
- Preço fora da validade é descartado automaticamente.
- Preços acima de 50% da mediana são marcados como fora da curva e excluídos do cálculo, com o motivo registrado.
- Método: mediana (padrão), média ou menor preço. Mínimo de 3 preços válidos; abaixo disso, exige justificativa. Coeficiente de variação exibido.

## Dispensa por valor (art. 75, I e II)

- Limites do ano na tabela `valores_limite`, atualizados por decreto (art. 182).
  - 2025: R$ 125.451,15 (obras e serviços de engenharia) e R$ 62.725,59 (demais), Decreto 12.343/2024.
  - 2026: R$ 130.984,20 e R$ 65.492,11, Decreto nº 12.807/2025 (vigente desde 1º/01/2026). Os valores foram conferidos em fonte secundária; **confirme no DOU** e marque como conferidos na tela de configurações.
- Soma das dispensas do mesmo exercício e da mesma natureza de despesa no órgão (§ 1º, fracionamento).
- Limite em dobro para consórcio público e agência executiva (§ 2º), configurado no cadastro do órgão.

## Contratos

| Regra | Onde | Base |
|---|---|---|
| Gestor e fiscal devem ser pessoas diferentes | `ContratoController` | art. 7º, § 1º |
| Garantia até 5%, até 10% com justificativa, até 30% em grande vulto | `Garantia` | arts. 96 a 99 |
| Serviço contínuo até 5 anos, prorrogação até 10 | `VigenciaContratual` | arts. 106 e 107 |
| Acréscimo e supressão até 25% do valor inicial atualizado, apurados em separado; 50% em reforma | `AlteracaoContratual` | art. 125 |
| Apostilamento entra na base do valor atualizado | `Contrato::valorInicialAtualizado()` | art. 136 |
| Fornecedor impedido (sanção vigente) não pode ser contratado | `Fornecedor::impedidoEm()` | art. 156 |
| Alertas de vencimento em 120, 90, 60 e 30 dias | `config/nexplangov.php` | art. 105 |

## Publicidade (PNCP)

Publicação do edital ou aviso de contratação direta enfileira a compra; homologação, revogação, anulação, deserto e fracassado enfileiram a retificação. Contratos e termos aditivos também vão à fila. Ver [integracao-pncp.md](integracao-pncp.md).

## Fluxo de documentos, modelos e assinatura eletrônica

- **Fluxo por órgão** (`fluxos`, `fluxo_etapas`): para cada peça do art. 18 e art. 53, o órgão define dependências, prazo em dias, revisão, aprovação, assinatura, responsável, substituto, revisor e aprovador. Ao abrir o processo o fluxo padrão é copiado para `etapas_processo` e fica congelado: mudar o fluxo depois não altera processos em andamento. Sem fluxo, a peça conclui direto (comportamento anterior).
- **Segregação**: quem escreveu a peça não a revisa nem a aprova, e quem revisou não aprova (segregação de funções, art. 5º e art. 7º, § 1º).
- **Estados da peça**: rascunho, em revisão, em aprovação, aguardando assinatura, concluída (e substituída quando uma peça assinada é devolvida: nasce nova versão).
- **Biblioteca de modelos** (`modelos_documento`): cerca de 53 modelos NexMind instalados por órgão (`php artisan nexplangov:modelos-padrao`), editáveis, com versões e campos dinâmicos `{{objeto}}`, `{{orgao}}` etc. Todo modelo avisa que precisa de revisão da assessoria jurídica.
- **Assinatura eletrônica**: confirmação da senha do signatário, hash SHA-256 do conteúdo (ordenado, porque o jsonb não guarda a ordem das chaves), selo HMAC com a chave da aplicação, código de 12 caracteres e QR Code. A página pública `/validar/{codigo}` mostra só metadados e avisa se a peça mudou depois de assinada. A tabela `assinaturas` é imutável.
- **Arquivo pronto**: PDF, DOCX, ODT, XLSX, ODS, PNG ou JPG até 30 MB, em disco privado, com SHA-256 e download auditado.

## NexLicita: julgamento, impugnações e IRP

- **Propostas e lances** (`propostas`, `lances`): por item e fornecedor. Fornecedor impedido não propõe (art. 14, III, e art. 156). Preço acima do orçamento estimado desclassifica (art. 59, III); em obras e serviços de engenharia, abaixo de 75% do orçado é inexequível (art. 59, § 4º). Lances só em disputa aberta ou aberta e fechada (art. 56), sempre melhores que o vigente. O lance não se altera nem se apaga.
- **Classificação** (`Regras/Julgamento`): menor preço, maior desconto e maior lance. Empate ficto da ME/EPP (até 10%, ou 5% no pregão) e desempate do art. 60 (disputa final, desempenho contratual, equidade, integridade) ficam registrados com justificativa.
- **Habilitação e adjudicação** (arts. 62 a 71): só a primeira colocada habilitada é adjudicada; inabilitar promove a seguinte. A homologação exige os itens adjudicados.
- **Impugnações** (art. 164): pedido até 3 dias úteis antes da abertura e resposta em até 3 dias úteis, limitada ao último dia útil antes da abertura. Página pública `/editais/{processo}` recebe os pedidos (limite de taxa, campo-isca, e-mail cifrado) e divulga só as respostas. Dias úteis desconsideram feriados nacionais; acrescente os locais em `NEXPLANGOV_FERIADOS_EXTRAS`.
- **IRP** (art. 86): abertura com prazo mínimo de manifestação (`NEXPLANGOV_IRP_PRAZO_MINIMO`, padrão 8 dias úteis; confirme no regulamento do ente), manifestação dos participantes, aceite ou recusa motivada e consolidação em itens do processo de registro de preços.

## Atas, reequilíbrio e extinção de contratos

- **Atas de registro de preços** (`atas`, `ata_itens`): uma ata por fornecedor vencedor, gerada do processo SRP homologado. Vigência de 1 ano, prorrogável uma vez por igual período (máximo de 2 anos) com a vantagem do preço comprovada (art. 84). O saldo por item é o registrado menos os consumos (empenho ou ordem de fornecimento), que não se alteram nem se apagam.
- **Adesão (carona)**: justificativa de vantagem e compatibilidade com o mercado, autorização do órgão gerenciador, limite de 50% do registrado por aderente e do dobro do registrado no total (art. 86, §§ 2º a 5º). O consumo da adesão não gasta o saldo do gerenciador.
- **Reequilíbrio** (art. 124, II, d): pedido fundamentado (caso fortuito, força maior, fato do príncipe, fato imprevisível ou previsível de consequências incalculáveis), decisão da autoridade que não é quem pediu. Deferido, cria termo aditivo que não conta no limite de 25% do art. 125, que vale para as alterações unilaterais.
- **Extinção** (arts. 137 a 139): sempre motivada nos autos. Na rescisão unilateral, comunicação formal e prazo de defesa; a autoridade só conclui depois da defesa ou do fim do prazo. Culpa da Administração exige informar o ressarcimento (art. 138, § 2º). Concluída, gera o termo de rescisão para o PNCP. O termo genérico não aceita mais "rescisão".
- Confirme com a assessoria jurídica a numeração dos parágrafos citados nas mensagens do sistema (art. 86, §§ 2º a 5º) antes do uso em produção.

## Etapa D: requisições, ordens de fornecimento e alertas

- **Requisição** (arts. 82 a 86): a unidade pede quantidade de um item de ata vigente, com justificativa. Não passa do saldo. Quem requisita não aprova a própria requisição (segregação de funções, art. 7º, § 1º).
- **Ordem de fornecimento**: só sai de requisição aprovada; fornecedor impedido não recebe ordem (art. 156). Ao emitir, o saldo da ata é reservado por um consumo. Cancelar (só sem recebimento) devolve o saldo por um lançamento de estorno, sem apagar nada.
- **Recebimento** (art. 140): provisório e definitivo. O definitivo não passa do provisório, e é feito por servidor diferente de quem emitiu a ordem e de quem recebeu provisoriamente. Lançamentos são imutáveis.
- **Alertas** (`php artisan nexplangov:alertas`, agendado às 07h): vigência de contrato (marcos 120, 90, 60, 30, 15 e 7 dias, e vencido), vigência de ata (60, 30, 15, 7), prazo de peça da instrução (véspera e vencido), impugnação a responder (art. 164) e entrega atrasada. Cada pessoa recebe um resumo e um livro (`alertas_enviados`) evita repetir o mesmo marco. O e-mail não leva dado pessoal. Os mesmos avisos aparecem no painel.

## Etapa E: catálogo CATMAT/CATSER e painel master

- **Catálogo** (art. 19, II): tabela `catalogo_itens`, única para todos os órgãos. Carregada com `php artisan nexplangov:catalogo-importar arquivo.csv --tipo=M|S` (CSV `;` com `codigo;descricao;classe_codigo;classe_nome;unidade;situacao`), idempotente. O sistema não traz dados prontos: use os arquivos oficiais do Compras.gov.br.
- **Conferência**: com o catálogo do tipo carregado, o PCA e os itens do processo recusam código inexistente, inativo ou de outro tipo. Sem catálogo carregado o código não é conferido (a tela avisa).
- **Painel master** (`/master`, só administrador geral sem órgão): por órgão, usuários ativos e sem 2FA, processos em andamento, contratos vigentes, erros do PNCP e última atividade; confere a cadeia de hash da auditoria. Não mostra o conteúdo dos processos.

## Etapa F: NexMind IA, capacitação e governança

- **NexMind IA** (desligada por padrão: `NEXPLANGOV_IA=true` e `ANTHROPIC_API_KEY` no `.env`; modelo `claude-opus-5-5` pela SDK oficial `anthropic-ai/sdk`, com fallback do servidor se o modelo recusar). Na tela da peça, sugere texto por campo e revisa a peça citando artigos. O texto só entra na peça se o usuário clicar em "Usar este texto" e salvar.
- **Limites**: não redige parecer jurídico nem autorização da autoridade (atos de quem tem a competência). Só quem pode elaborar a peça usa (`ia.usar`). Limite diário por usuário (`NEXPLANGOV_IA_LIMITE_DIARIO`, padrão 40).
- **LGPD**: CPF, e-mail, telefone e CEP são trocados por marcadores antes do envio (art. 6º, III, necessidade). O registro `usos_ia` guarda quem usou, onde e o consumo, com o hash do pedido e sem o texto; é imutável.
- **Capacitação** (art. 7º, II, e art. 173): cursos por agente, com tema, carga horária, escola de governo e certificado. Conta como atualizado por 24 meses (`NEXPLANGOV_CAPACITACAO_MESES`). Cada um registra o próprio; o administrador do órgão registra de todos.
- **Painel de governança** (art. 11, parágrafo único): execução do PCA, peso das contratações diretas (quantidade e valor), economia entre estimado e homologado, tempo médio da fase preparatória, prazos em atraso (peças, impugnações, entregas) e percentual de agentes capacitados.
