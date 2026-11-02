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
  - 2026: R$ 130.984,20 e R$ 65.492,11. **Conferir com o decreto publicado antes de usar em produção.**
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
