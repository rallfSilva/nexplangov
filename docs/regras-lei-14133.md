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

## Sessão pública e recursos (arts. 17, 71, 165 a 168)

- **Sessão pública.** Enquanto aberta, recebe propostas, lances e intenções de recurso. O encerramento lavra a **ata da sessão em PDF** (propostas, lances em ordem cronológica, adjudicações e intenções de recurso) com hash SHA-256; a ata fica pública na página do edital. Reabrir exige motivo registrado.
- **Pregão ou concorrência presencial** (art. 17, § 2º): o encerramento exige a **gravação em áudio e vídeo** (link https ou arquivo, com hash SHA-256). Sem sessão encerrada e gravação, o processo não é homologado.
- **Intenção de recurso** (art. 165, § 1º, I): registrada durante a sessão, por licitante com proposta; depois do encerramento há preclusão.
- **Prazos em dias úteis** (`PrazosRecurso`): razões em 3 dias úteis do encerramento (art. 165, I); contrarrazões em 3 dias úteis do fim do prazo das razões (art. 165, § 4º); reconsideração em 3 dias úteis (art. 165, § 2º); decisão da autoridade superior em 10 dias úteis (art. 165, § 2º).
- **Efeito suspensivo** (art. 168): recurso pendente impede a adjudicação do item e a homologação do processo. A decisão superior exige a permissão `processos.autorizar` e não pode ser tomada por quem negou a reconsideração.

## Carona: adesão de órgão não participante (art. 86, §§ 2º a 8º)

- **Pedido público, sem conta** (`/carona`): o órgão de fora acha a ata vigente, escolhe o item e envia o pedido com o **ofício em PDF** (hash SHA-256). Recebe um **protocolo** (`ADS-AAAA-NNNNNN`) e acompanha em `/carona/consulta` com o protocolo e o CNPJ. O gerenciador também pode registrar um pedido recebido por ofício.
- **Aceite do fornecedor** (§ 2º, III): o gerenciador envia ao fornecedor um link assinado (válido por 15 dias) e ele aceita ou recusa sem conta. Ou o servidor registra a resposta escrita do fornecedor, com o documento anexado. Sem aceite, não há autorização.
- **Vedação federal** (§ 8º): órgão federal não adere a ata estadual, distrital ou municipal. Pelo § 3º, com a redação da Lei nº 14.770/2023, ata municipal admite carona.
- **Limites** (§§ 4º e 5º): até 50% do registrado por aderente e o dobro no total, conferidos no pedido e de novo na autorização.
- **Prazo para contratar**: 90 dias corridos da autorização, como no regulamento federal, nunca além da vigência da ata. Ajuste em `NEXPLANGOV_CARONA_PRAZO` conforme o regulamento do ente. Depois do prazo, o consumo do aderente é recusado.
- **Ofício de autorização em PDF** com **QR Code** e código de verificação. A página pública `/carona/verificar/{código}` confirma a autenticidade e mostra o hash do PDF.

## Processo sancionador (arts. 155 a 163, 166 a 168)

- **Instauração**: fatos, infrações do art. 155 (I a XII) e sanção em tese. Impedimento e inidoneidade exigem **comissão de 2 ou mais servidores estáveis** (art. 158).
- **Compatibilidade** (`Regras\Sancoes`): advertência só para o inciso I (art. 156, § 2º); impedimento para II a VII (§ 4º); inidoneidade para VIII a XII ou, justificadamente, II a VII (§ 5º); multa de 0,5% a 30% do contrato (§ 3º), cumulável (§ 7º). Duração: impedimento de 1 a 36 meses; inidoneidade de 36 a 72 meses.
- **Intimação** com notificação em PDF e **defesa em 15 dias úteis** (arts. 157 e 158). Defesa fora do prazo é recusada. Sem defesa, a autoridade decide depois do prazo. Com pedido de provas, há instrução e **alegações finais em 15 dias úteis** (art. 158, § 2º).
- **Análise jurídica** obrigatória antes da inidoneidade (art. 156, § 6º). **Decisão** pela autoridade (`processos.autorizar`), com dosimetria (art. 156, § 1º).
- **Recurso em 15 dias úteis** (art. 166): quem decidiu reconsidera ou mantém em 5 dias úteis. Se mantiver, a autoridade superior decide em 20 dias úteis, e não pode ser quem aplicou ou manteve a sanção. Na inidoneidade, cabe **pedido de reconsideração** decidido em 20 dias úteis (art. 167). Há efeito suspensivo (art. 168): a sanção só vira definitiva e só bloqueia o fornecedor depois do prazo ou da decisão do recurso.
- **CEIS/CNEP** (art. 161): prazo de 15 dias úteis a partir da sanção definitiva, com alerta na lista. Há uma planilha CSV com os dados do cadastro e o registro da data e do comprovante do lançamento. A geração da planilha fica na auditoria.
- **Reabilitação** (art. 163): exige no mínimo 1 ano (impedimento) ou 3 anos (inidoneidade), reparação do dano, multa paga, condições cumpridas e parecer jurídico. Para os incisos VIII e XII, também programa de integridade.

## Medição, liquidação e ordem cronológica de pagamentos (arts. 140 e 141)

- **Medição**: o fiscal registra o período executado e o valor medido, dentro da vigência. As medições somadas não passam do valor atualizado do contrato (inicial com reajustes, mais acréscimos, menos supressões; art. 125). O gestor aprova ou recusa com motivo, e nunca quem mediu (segregação de funções, art. 7º, § 1º). Medição não se apaga.
- **Liquidação**: medição aprovada, ou ordem de fornecimento com recebimento definitivo, até o valor recebido e ainda não liquidado. Registra nota fiscal, retenções, atesto, **fonte de recursos** e **categoria do art. 141** (bens, locações, serviços, obras). O crédito entra na fila na data da liquidação, com vencimento no prazo de pagamento (`NEXPLANGOV_PRAZO_PAGAMENTO`, padrão de 30 dias, art. 92, V).
- **Ordem cronológica** (art. 141): uma fila por fonte e categoria. Só se paga o primeiro da fila. Para pagar outro, a autoridade (`processos.autorizar`) registra antes a hipótese do § 1º (I a V) e a justificativa. Depois do pagamento, o sistema cobra o registro da comunicação ao controle interno e ao tribunal de contas.
- **Atraso**: créditos vencidos ficam marcados. Atraso acima de 2 meses mostra o alerta do art. 137, § 2º, IV (direito do contratado à extinção).
- **Divulgação mensal** (art. 141, § 3º): a página pública `/transparencia/{órgão}/pagamentos?mes=AAAA-MM` mostra os pagos no mês, as filas em aberto e as justificativas de pagamento fora da ordem.

## Importação de planilha do PCA (art. 12, VII)

- **Formatos**: XLSX (primeira aba) ou CSV com `;` ou `,`, até 5 MB e 2.000 itens. O botão "Baixar modelo" traz as colunas na ordem, uma linha de ajuda e um exemplo.
- **Cabeçalho flexível**: sem acento e sem diferença de maiúsculas, com sinônimos comuns ("qtd", "valor unitário", "secretaria", "código"). Categoria por código PNCP ou nome; prioridade Alta/Média/Baixa; data dd/mm/aaaa ou data do Excel; valores "1.234,56" ou numéricos.
- **Pré-visualização obrigatória**: cada linha passa pelas mesmas regras de um item digitado (`RegrasItemPca`, inclusive a conferência no catálogo CATMAT/CATSER) e aparece como "Será importada", "Com erro" (com os motivos) ou "Duplicada" (mesmo código, descrição e unidade já no plano ou repetidos na planilha). Nada é gravado antes de confirmar.
- **Confirmação**: grava só as linhas válidas, em lotes, numa transação, numerando em sequência; revalida duplicidade no momento da gravação. Plano aprovado não recebe itens por importação.
- **Histórico**: cada importação guarda o nome do arquivo, o SHA-256, quem enviou, as linhas lidas e os totais; a confirmação entra na trilha de auditoria (evento `importacao`) e cada item criado também.

## Versões e revisão do PCA (art. 12, VII e § 1º)

- **Versão a cada aprovação**: aprovar o plano grava uma cópia imutável dos itens (`versoes_pca`), com quem aprovou, quando, quantidade de itens e valor total. Planos já aprovados antes desta mudança ganham a versão 1 na migração.
- **Revisão justificada**: plano aprovado não aceita alteração direta. "Abrir revisão" exige justificativa (mínimo de 15 caracteres, `VersoesPca::JUSTIFICATIVA_MINIMA`), volta o plano para edição e registra o evento `revisao` na auditoria. A versão vigente continua valendo até a nova aprovação da autoridade competente. Na União, o Decreto nº 10.947/2022 (arts. 12 e 13) também exige justificativa e aprovação para alterar o PCA.
- **Descartar revisão**: devolve os itens da versão vigente e o plano volta a aprovado.
- **Número de item não volta**: item excluído não tem o número reaproveitado (`VersoesPca::proximoNumero`), para o histórico e o PNCP não confundirem itens diferentes.
- **O que mudou**: a tela "Versões e alterações" compara quaisquer duas versões (ou a vigente com a revisão em andamento) e mostra itens incluídos, excluídos e alterados, campo a campo, e a variação do valor total. A regra pura de comparação fica em `App\Licitacao\Regras\VersoesPca` (testada em `tests/Unit/Lei14133/VersoesPcaTest.php`).
- Depois de aprovar a nova versão, use "Atualizar no PNCP" para publicar o plano revisado.

## Exportações editáveis (XLSX, DOCX e CSV)

- **PCA**: menu "Exportar" na tela do plano. XLSX com as abas Itens, Por categoria, Por unidade e Versões (quando houver); DOCX com resumo e tabela de itens, pronto para divulgação (art. 12, § 1º); CSV em UTF-8 com `;`.
- **Peças do processo**: botão "Baixar em Word (DOCX)" em cada peça feita no editor (DFD, ETP, TR, mapa de riscos, edital, minuta...), com todos os campos, as assinaturas registradas e o SHA-256 da peça. É cópia de trabalho: a peça com validade é a do sistema.
- **Listas**: processos (com os filtros da tela) e contratos em XLSX.
- **Segurança**: textos sempre gravados como texto na planilha (um `=HYPERLINK(...)` vindo de um campo nunca vira fórmula); no CSV, células que começam com `=`, `+`, `-` ou `@` ganham apóstrofo. Toda exportação entra na trilha de auditoria (evento `exportacao`) e respeita o isolamento do órgão e as permissões de cada tela.
