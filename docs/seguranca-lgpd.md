# Segurança e LGPD

## Acesso

- Fortify com verificação em duas etapas (TOTP) obrigatória para administradores (`NEXPLANGOV_EXIGIR_2FA`).
- Limite de 5 tentativas de entrada por minuto; usuário ou órgão inativo é desconectado na próxima requisição.
- Isolamento entre órgãos por escopo global (`BelongsToOrgao`): endereço de outro órgão responde 404.
- Permissões por ação (`can:`) em todas as rotas; segregação de funções entre requisitante, planejamento, agente, jurídico e autoridade.

## Trilha de auditoria

- Toda criação, alteração (valor antigo e novo), exclusão, entrada, saída e download é registrada.
- Gatilho no PostgreSQL impede UPDATE e DELETE em `registros_auditoria`.
- Cada registro guarda `hash_anterior` e `hash` (SHA-256), em cadeia, com trava consultiva para não haver corrida. `Auditoria::verificarCadeia()` aponta o primeiro registro adulterado.

## Dados pessoais

| Medida | Implementação | Base LGPD |
|---|---|---|
| CPF de fornecedor pessoa física cifrado (AES-256 via `APP_KEY`) | `Fornecedor::documento_cifrado` | art. 46 |
| Busca por CPF sem decifrar | HMAC em `documento_hash` | art. 46 |
| Exibição mascarada (`***.982.247-**`) | `Documento::mascararCpf()` | art. 6º, III |
| Dado pessoal nunca vai para a trilha de auditoria | `camposSensiveisDoModelo()` | art. 6º, III |
| Canal público do titular, com protocolo LGPD-AAAA-XXXXXXXX e prazo de 15 dias | `/lgpd/solicitar` | arts. 18 e 19, II |
| Registro das operações de tratamento (ROPA) | `operacoes_tratamento` | art. 37 |
| Aviso de privacidade público | `/privacidade` | art. 9º |

Base legal típica do tratamento nas contratações: cumprimento de obrigação legal e execução de políticas públicas (LGPD art. 7º, II e III; art. 23), com a publicidade exigida pela Lei 14.133 (art. 174).

## Infraestrutura

- Cabeçalhos de segurança, cookies `secure` e `httponly`, sessão com expiração.
- Sem chamadas a servidores externos na interface (fonte hospedada no próprio sistema).
- Credenciais (banco, e-mail, PNCP) só no `.env`, nunca no repositório.
- Backup diário com soma de verificação (`scripts/backup.sh`).
