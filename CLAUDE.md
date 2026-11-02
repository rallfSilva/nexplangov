# NexPlanGov: convenções do repositório

- Laravel 13, PHP 8.3, PostgreSQL 16. Interface e textos em **português do Brasil**; nomes de classes, tabelas e métodos de domínio também em português (`Processo`, `itens_pca`, `valorInicialAtualizado`).
- Base legal única: **Lei nº 14.133/2021**. Toda regra cita o artigo (`<span class="lei">art. 75, § 1º</span>` nas telas; comentário no código).
- Regras de negócio puras ficam em `app/Licitacao/Regras` (sem acesso a banco, testadas em `tests/Unit/Lei14133`). Enums da lei em `app/Licitacao/Enums`, com códigos do PNCP. Violação de regra lança `RegraViolada` com a lista de motivos.
- Tramitação do processo só por `TramitacaoProcesso::transitar()`. Nunca alterar `situacao` direto.
- Valores de limite por ano ficam na tabela `valores_limite` (art. 182). Nunca fixar valor no código.
- PNCP: só pela `FilaPncp` / job `EnviarAoPncp`. Credenciais só no `.env`.
- Modelos usam `#[Fillable]` / `#[Hidden]` e `casts()`. Todo modelo de órgão usa `BelongsToOrgao` e `Auditable`. Nunca `withoutGlobalScopes()` em código de tela.
- Dado pessoal (CPF): cast `encrypted` + coluna `*_hash` (HMAC) para busca + máscara para exibição. Declare em `camposSensiveisDoModelo()` para não ir à auditoria.
- Perfis (PerfisSeeder, idempotente): administrador-geral, administrador-orgao, autoridade, agente-contratacao, planejamento, juridico, gestor-contrato, requisitante, auditor. Rotas com `can:<permissao>`.
- Telas: `x-layouts.app`, `x-campo`, `x-icone`, `x-rodape`; classes em `resources/css/app.css` (ver docs/design-system.md). Paleta #1E5470, #34729C, #6CB1DA, #6EC1D1, #D1ECFF, #CBEAEC. Rodapé sempre "Desenvolvido por" + logo NexMind com link para https://nexmind.net.br/.
- **Dados fictícios** em seeders, testes e demonstrações (CNPJs 99.xxx, "Vale Verde (fictícia)"). Nunca nomes, CPFs ou dados reais.
- Antes de commitar: `vendor/bin/pint` e `php artisan test` (banco `nexplangov_test`).
