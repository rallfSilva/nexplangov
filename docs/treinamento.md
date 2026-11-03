# Acesso de treinamento

Uma **turma de treinamento** é um órgão fictício, isolado como qualquer outro, para capacitar as equipes sem risco para os dados reais.

| O que a turma tem | Como funciona |
|---|---|
| Um acesso por perfil | Administrador do órgão, autoridade, planejamento, agente de contratação, jurídico, gestor, fiscal, requisitante e controle interno |
| Dados de exemplo | 12 processos em várias fases, PCA, demandas, fornecedores (CNPJ fictício) e contratos |
| PNCP | O envio é montado e conferido como de verdade, mas fica como "Simulado (treinamento)" e nunca sai do sistema |
| Alertas | Aparecem no painel; nenhum e-mail é enviado |
| NexMind IA | Desligada, salvo `NEXPLANGOV_TREINAMENTO_IA=true` (cada uso tem custo) |
| Verificação em duas etapas | Opcional para os administradores da turma |
| Prazo | 30 dias por padrão (`NEXPLANGOV_TREINAMENTO_DIAS`); no fim, o acesso acaba sozinho |
| Encerramento | Desativa órgão e acessos. Nada é apagado: a trilha de auditoria continua íntegra |

## Pelo painel master

1. Entre como administrador geral e abra **Painel master**.
2. Em **Turmas de treinamento**, informe o nome da turma (até 8 letras ou números) e os dias de acesso.
3. Clique em **Preparar turma**. A tabela com e-mail e senha de cada participante aparece **uma única vez**: anote ou copie antes de sair da tela.
4. Para encerrar antes do prazo, use **Encerrar** na lista de turmas.

## Pelo servidor

```bash
php artisan nexplangov:treinamento preparar --turma=01 --dias=30
php artisan nexplangov:treinamento listar
php artisan nexplangov:treinamento encerrar --turma=01
php artisan nexplangov:treinamento encerrar-vencidas   # roda sozinho todo dia às 00h30
```

Para começar de novo com a mesma equipe, encerre a turma e prepare outra com novo nome (por exemplo, `01B`).

Os e-mails da turma usam o domínio `treinamento.nexplangov.local` (`NEXPLANGOV_TREINAMENTO_DOMINIO`), que não recebe mensagens.
