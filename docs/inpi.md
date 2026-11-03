# Registro do programa no INPI

O NexPlanGov é registrado como programa de computador (Lei nº 9.609/1998) pelo formulário e-Software do INPI.
O INPI não recebe o código: recebe o resumo digital (SHA-512) de um pacote que o titular guarda.

## Gerar o pacote

```bash
scripts/inpi/gerar-pacote.sh          # versão atual (HEAD)
scripts/inpi/gerar-pacote.sh v1.0.0   # ou uma tag
```

O script grava em `inpi/` (fora do git):

- `nexplangov-fonte-<data>-<commit>.tar`: o código-fonte daquele commit, gerado por `git archive`. Para o mesmo commit, o hash é sempre o mesmo.
- `nexplangov-declaracao-<data>-<commit>.txt`: commit, data, tamanho, número de arquivos e de linhas, SHA-512 e SHA-256.

## Guarda

- Guarde o `.tar` sem alterar nenhum byte, em pelo menos dois lugares (cofre da empresa e mídia fora da rede).
- Confira de tempos em tempos com `sha512sum` e compare com o hash declarado no pedido.
- Uma versão nova com mudança relevante pode ter um pedido novo. O registro anterior continua valendo para a versão dele.
