# Qualidade: segurança, acessibilidade e desempenho

Este documento reúne o que o NexPlanGov verifica de forma automática e o que cada órgão deve conferir na implantação.

## 1. Segurança

### Cabeçalhos de resposta (`App\Http\Middleware\CabecalhosSeguranca`)

| Cabeçalho | Valor | Para quê |
|---|---|---|
| Content-Security-Policy | `default-src 'self'`, `frame-ancestors 'none'`, `object-src 'none'`, `form-action 'self'`, `base-uri 'self'` | Só carrega recursos do próprio sistema; bloqueia injeção de script de terceiros e embutir a tela em outro site |
| X-Frame-Options | `DENY` | Contra clickjacking em navegadores antigos |
| X-Content-Type-Options | `nosniff` | O navegador não "adivinha" o tipo do arquivo |
| Referrer-Policy | `same-origin` | Endereços internos não vazam para outros sites |
| Permissions-Policy | câmera, microfone, localização, pagamento e USB desligados | |
| Cross-Origin-Opener-Policy / Resource-Policy | `same-origin` | Isola a janela e os recursos |
| Strict-Transport-Security | `max-age=31536000` (só em HTTPS) | Força HTTPS |
| Cache-Control | `no-store, private` para usuário logado e telas de login | Dado de processo não fica no cache do navegador nem de proxy |

Com o Vite em modo de desenvolvimento (`npm run dev`), a CSP fica de fora para não bloquear a recarga automática.
O `'unsafe-inline'` em script e estilo cobre os scripts curtos das telas; a remoção completa (com nonce) está no plano de evolução.

### Arquivos

O disco `local` não tem rota pública (`serve => false`). Peças, anexos, dossiês e remessas só saem pelos controllers, com permissão do perfil, isolamento do órgão e registro na auditoria.

### Testes automáticos (`tests/Feature/SegurancaTest.php`)

- **Toda rota autenticada exige `can:<permissao>`**. Rota nova sem permissão quebra o teste. Exceções: sair, a tela "Segurança da conta" e as rotas `user/*` do Fortify (senha e 2FA da própria conta).
- **Toda tela exige conta ativa** (middleware `ativo`).
- **Visitante sem login** é levado à tela de entrada em todas as telas.
- **CSRF**: formulário sem token é recusado (419).
- **Limite de tentativas** de login (429 após 5 erros).
- **Cabeçalhos** acima presentes.
- **Sem rota pública** para arquivos privados.

Os demais testes já cobrem isolamento entre órgãos, segregação de funções, trilha de auditoria encadeada, CPF cifrado e mascarado e bloqueio da IA em parecer jurídico e autorização.

## 2. Acessibilidade (eMAG 3.1 e WCAG 2.1 AA)

### Teste no HTML (`tests/Feature/AcessibilidadeTest.php`)

Passa por cerca de 35 telas (internas e públicas) e verifica:

- `lang="pt-BR"`, `<title>`, `<h1>` e um único `<main>`;
- atalho "Pular para o conteúdo";
- todo campo com rótulo (`label for`, rótulo envolvendo o campo, `aria-label` ou `title`);
- toda imagem com `alt`, todo botão e link com nome acessível;
- `id` sem repetição e tabelas com cabeçalho `<th>`.

### Verificação com axe-core em navegador real (`scripts/qualidade/acessibilidade.mjs`)

Roda o axe-core 4.10 no Chromium, com as regras WCAG 2.0/2.1 A e AA, incluindo contraste de cor.

```bash
npm install
# demonstração HTML (passa por todas as telas do menu)
npm run acessibilidade -- docs/demo/nexplangov-demo.html
# sistema rodando, com usuário de teste
BASE=http://localhost:8000 EMAIL=planejamento@nexplangov.local SENHA='NexPlan@demo2026' \
  npm run acessibilidade -- /painel /processos /contratos /pca
```

Resultado em 03/11/2026: **zero violações** na demonstração (11 telas) e no sistema (20 telas internas e públicas).

Correções feitas nesta etapa: rótulos ligados aos campos nas telas de contrato, ata, IRP, impugnações e fluxos; paginação própria em português (sem `aria-label` proibido); link de texto sublinhado (não só pela cor); `<main>` nas telas de entrada; campo-armadilha anti-robô escondido também do leitor de tela.

## 3. Desempenho e carga

### Dados fictícios em volume

```bash
php artisan db:seed --class=DemoSeeder
php artisan nexplangov:carga-demo --processos=2000   # órgão PMVV (fictício); nunca em produção
```

Gera fornecedores (CNPJ fictício série 99.9xx), itens do PCA, demandas, processos em várias fases e contratos.

### Orçamento de consultas (`tests/Feature/DesempenhoTest.php`)

Abre 12 telas de lista com 5 processos e com 35. Falha se:
- alguma tela passar de 60 consultas ao banco; ou
- o número de consultas crescer mais de 3 com o volume (sinal de consulta repetida por linha, o "N+1").

### Tempo de resposta medido

Servidor de desenvolvimento (`php artisan serve`, um processo PHP, sem cache de configuração), PostgreSQL 16 local, órgão com **2.300 processos**, 2.300 itens de PCA e 460 contratos. Mediana de 8 aberturas:

| Tela | Mediana | Pior |
|---|---|---|
| Painel | 93 ms | 113 ms |
| Processos | 61 ms | 64 ms |
| Processos (com busca) | 75 ms | 86 ms |
| PCA | 56 ms | 70 ms |
| Demandas | 73 ms | 93 ms |
| Contratos | 67 ms | 83 ms |
| Fornecedores | 72 ms | 89 ms |
| Governança | 116 ms | 129 ms |

Os tempos ficaram iguais com 300 e com 2.300 processos, o que confirma que as listas paginam no banco.

### Teste de carga com k6 (`scripts/carga/k6-navegacao.js`)

Simula até 50 usuários navegando pelas telas mais usadas, com metas de p95 < 800 ms e erro < 1%:

```bash
k6 run -e BASE=https://homologacao.exemplo.gov.br -e EMAIL=... -e SENHA=... scripts/carga/k6-navegacao.js
```

Rode no ambiente de homologação do órgão, com `php artisan optimize`, OPcache ligado e a fila em processo separado. Nunca contra produção.

## 4. Lista de conferência na implantação

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, HTTPS com certificado válido
- [ ] `php artisan optimize` e OPcache ligado
- [ ] Fila (`php artisan queue:work`) e agendador (`php artisan schedule:work` ou cron) rodando
- [ ] Backup diário testado com `scripts/restaurar.sh`
- [ ] 2FA ativo para todos os administradores
- [ ] Teste de carga k6 em homologação dentro das metas
- [ ] Verificação axe sem violações sérias nas telas do órgão
