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

## Devolução de dados do órgão (fim do contrato)

- **Quem gera**: administrador do órgão (permissão `devolucao.gerar`); o administrador-geral escolhe o órgão. A tela fica em Administração › Devolução de dados.
- **O que vai no pacote (ZIP)**: todas as tabelas do órgão em CSV (UTF-8 com BOM, `;`) e JSONL, o cadastro do órgão, os perfis dos usuários e todos os arquivos enviados (anexos, peças, comprovantes). Inclui `LEIA-ME.txt` e `manifesto.json` com o SHA-256 de cada parte, para o órgão conferir a integridade.
- **O que não vai**: senhas, tokens, segredos de dois fatores e as colunas `*_hash` de busca. CPFs e demais campos cifrados saem decifrados, porque o dado pertence ao órgão (controlador, LGPD art. 18, V); o LEIA-ME alerta para guardar o pacote com o mesmo cuidado.
- **Geração em fila** (`GerarPacoteDevolucao`); um pedido por vez. O pacote fica disponível por 7 dias e depois é apagado pela rotina diária `nexplangov:devolucao-limpar`. Pedido, geração e download entram na trilha de auditoria.

## Onda 1 dos requisitos funcionais

- **Trava do PCA na abertura (art. 12, VII, e art. 18, § 1º, II)**: o processo só abre vinculado a um item de PCA aprovado do mesmo exercício ou com justificativa para contratar fora do plano (mínimo de 30 caracteres). O formulário sugere itens parecidos com o objeto, e a previsão entra no checklist da instrução. Regra pura em `App\Licitacao\Regras\PrevisaoPca`.
- **Eficácia do contrato (art. 94)**: a divulgação no PNCP é condição de eficácia, em até 20 dias úteis da assinatura na licitação (inciso I) ou 10 na contratação direta (inciso II). O sistema alerta o gestor e quem envia ao PNCP enquanto o prazo corre, e o alerta fica urgente nos últimos 3 dias úteis ou quando o prazo vence. Antes da divulgação, o contrato não aceita medição, liquidação nem pagamento. Regra pura em `EficaciaContrato`.
- **CEIS e CNEP (arts. 14, III, 156 e 161)**: com `TRANSPARENCIA_CHAVE` no `.env`, o sistema consulta o fornecedor no cadastro, antes de adjudicar, antes de cadastrar o contrato e antes de emitir uma ordem de fornecimento. A consulta vale por 24 horas (`NEXPLANGOV_CEIS_VALIDADE_HORAS`). Sanção impeditiva vigente bloqueia. Quando a sanção é de impedimento ou suspensão de outro ente, a autoridade pode registrar a análise de abrangência com justificativa, e essa ressalva vale enquanto as sanções forem as mesmas. Inidoneidade (art. 156, § 5º) não admite ressalva. Se a API falhar, a consulta fica registrada como indisponível e o bloqueio se mantém até uma nova consulta. Regra pura em `ImpedimentoCadastral`.
- **Livro de ocorrências e portaria (art. 117, §§ 1º e 2º)**: o gestor e o fiscal designados registram ocorrências, determinações e comunicações a superiores. Os registros são numerados, encadeados por SHA-256 e não se alteram nem se excluem, nem mesmo pelo banco, por causa do gatilho no PostgreSQL. Correção se faz por retificação. A autoridade registra o ato de designação do gestor e do fiscal, e o sistema gera a portaria em PDF. Quem designa não pode ser o próprio gestor ou fiscal (art. 7º, § 1º).
- **DFD (art. 12, VII, e art. 18)**: quantidade, valor estimado e data pretendida são obrigatórios. Cada demanda pode ser vinculada a outras demandas do mesmo exercício para consolidar a contratação. O planejamento define a data limite de envio das demandas por exercício. Depois dela, só entram demandas sigilosas, de suprimento de fundos ou de pronto pagamento, e sempre com justificativa. Regra pura em `PrazoDemanda`.
- **Duplicar processo**: reaproveita a instrução de uma contratação deserta, fracassada ou recorrente. O novo processo começa na fase preparatória e recebe os dados, os itens, as cotações e as peças feitas no editor, como rascunho. Cotações vencidas saem do cálculo sozinhas (art. 23, § 1º). Não se copiam os atos próprios da origem: parecer jurídico, autorização, habilitação, arquivos enviados, propostas, resultado e números do PNCP. Em outro exercício, a previsão no PCA precisa ser indicada de novo.
- **Anotações internas**: rascunho de trabalho entre os servidores do processo. Quem instrui, emite parecer, autoriza ou conduz a licitação pode escrever, e só o autor edita ou exclui. As anotações não compõem os autos, então não entram no checklist, no dossiê, nas exportações nem no PNCP.

## Onda 2: tramitação e autos

- **Tramitação entre unidades (arts. 7º, 8º e 12, I)**: cada processo tem uma unidade atual. Quem está lotado nela envia o processo a outra unidade, com despacho. Só a unidade de destino recebe. A remessa pode ser cancelada antes do recebimento, por quem enviou ou pela origem. Processo encerrado (contratado, deserto, fracassado, revogado ou anulado) pode ser arquivado na unidade e desarquivado com motivo. Nada disso muda a situação do processo, que continua só na `TramitacaoProcesso`. A aba Tramitação mostra o histórico e o tempo de permanência em cada unidade, com quem recebeu. A caixa "Meus processos" reúne o que a unidade tem a receber, o que está com ela, o que está sob a responsabilidade da pessoa, as remessas enviadas e os arquivados. A lotação fica no cadastro do usuário, e quem chefia uma unidade também atua nela. Regra pura em `TramiteUnidades`.
- **Juntada livre e árvore (art. 12, I, e art. 6º, LVIII)**: certidões, ofícios, declarações, comprovantes e outros documentos avulsos são juntados aos autos com SHA-256 e número de páginas. O documento juntado não se altera nem se apaga, e um gatilho do PostgreSQL também garante isso. Se entrou por engano, é desentranhado com motivo, e o registro fica. A árvore numera todos os documentos dos autos em ordem cronológica e os agrupa por fase. Regra pura em `AutosProcesso`.
- **PDF único**: o botão "PDF único dos autos" gera capa, índice, peças com assinaturas, contratos, ata da sessão e termos de juntada, com as imagens dentro. Cada página leva "fl. N de T", e o texto é pesquisável. PDFs e planilhas juntados entram pelo termo de juntada, com nome, tamanho, páginas e SHA-256, porque o gerador não incorpora outro PDF; o arquivo original continua no sistema e no dossiê do SEI.
- **Assinatura em lote**: a tela "Assinaturas pendentes" lista as peças que esperam a assinatura da pessoa. Basta marcar e confirmar a senha uma vez, e cada peça passa pelas regras da assinatura individual.
- **Pedidos a externos por e-mail**: quem instrui pode mandar a pessoas de fora do órgão uma notificação com ciência, um pedido de assinatura de peça ou um pedido de documentos. O link é pessoal e tem prazo (até 60 dias), e o banco guarda só o hash dele. Quem assina por fora confirma o nome como foi cadastrado e recebe selo e código de validação próprios. A peça só conclui quando o externo também assina. Os documentos recebidos entram nos autos como juntada, e quem fez o pedido recebe aviso da resposta. Regra pura em `PedidoExterno`.
- **Fluxo por tipo de processo e por secretaria**: o fluxo de documentos pode valer para uma modalidade e para a secretaria que abre o processo. O sistema aplica o mais específico: secretaria, depois modalidade, depois categoria, depois forma. O fluxo pode ter um roteiro de unidades, que sugere o destino de cada remessa. Regra pura em `EscolhaFluxo`.

## Onda 4: catálogo, PCA, riscos, painéis, parâmetros e empenho

- **Catálogo do órgão (art. 19, II, e art. 40, § 1º, I)**: cada item tem descrição padronizada, código CATMAT/CATSER, unidade, natureza da despesa, atributos (nome e valor) e imagem. Descrição vaga ("diversos", "conforme TR", "etc.") é recusada também no PCA e nos itens do processo. Item igual é bloqueado e item parecido (Jaccard ≥ 0,75) só entra com a confirmação de que é diferente. Inativar exige motivo. Regra pura em `DescricaoCatalogo`.
- **Consolidação do PCA (art. 12, VII, e art. 40, V, b)**: a tela sugere agrupar itens do mesmo código, classe ou descrição parecida, mostra compras por dispensa do mesmo objeto nos últimos 12 meses que somam acima do limite do art. 75, II (fracionamento), e monta o cronograma mensal com a cobertura por dotação orçamentária de cada natureza. Regras puras em `ConsolidacaoPca`, `FracionamentoHistorico` e `CronogramaPca`.
- **Matriz de riscos (art. 6º, XXVII, art. 18, X, e art. 22)**: riscos com probabilidade, impacto, nível, alocação (contratante, contratada ou compartilhado) e tratamento obrigatório a partir do nível 10. É obrigatória em obras e serviços de grande vulto. A NexMind IA sugere riscos e o servidor escolhe quais aceitar; a matriz vira a peça "Mapa de riscos" no editor. A IA também recomenda no PCA, na pesquisa de preços e no contrato, sempre como sugestão. Regra pura em `MatrizRiscos`.
- **Painéis gerenciais (art. 11, parágrafo único)**: contratações por secretaria, perfil dos fornecedores por porte, UF e município, e fomento local e regional (art. 4º). Exportação em PDF, CSV, TXT e XLSX; o CSV protege células que viram fórmula. Regra pura em `IndicadoresGestao`.
- **Parâmetros do órgão**: prazos e regras ajustáveis pela tela, sempre dentro de limites que não contrariam a lei (pagamento até 30 dias, art. 141; alertas de vigência; prazos da IRP e da carona, art. 86; tolerância de preço, art. 23; validade da consulta ao CEIS, art. 161; validade da capacitação, art. 173; limite de uso da IA). Cada ajuste exige justificativa e fica na auditoria; restaurar volta ao padrão de `config/nexplangov.php`. Regra pura em `ParametrosOrgao`.
- **Permissões extras (art. 7º, § 1º)**: o administrador do órgão concede uma permissão a um servidor, com justificativa, e revoga com motivo. Ninguém concede a si mesmo. Parecer jurídico (art. 53), autorização e homologação (art. 71), aprovação do PCA e administração do sistema só vêm do perfil. Funções incompatíveis não se acumulam (instruir e emitir parecer; conduzir e homologar; montar e aprovar o PCA), e o auditor só recebe permissões de consulta (art. 169). Regra pura em `ConcessaoPermissao`.
- **Nota de empenho e pedido de compra (Lei 4.320, arts. 58 a 63; Lei 14.133, art. 92, VIII, e art. 141)**: o financeiro registra as notas de empenho do contrato ou da ata, com modalidade (ordinário, estimativo ou global), natureza, fonte e valor, sem passar do valor do contrato ainda não empenhado. A liquidação exige um empenho do mesmo credor, da mesma fonte e com saldo; o saldo não liquidado pode ser anulado com motivo. A ordem de fornecimento é o pedido de compra da liquidação da ata, e o contrato aceita um número de pedido. `NEXPLANGOV_EMPENHO_OBRIGATORIO=false` só na implantação, enquanto os empenhos antigos são lançados. Regra pura em `NotaEmpenho`.
