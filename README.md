# NexPlanGov

Planejamento, contratações e contratos públicos conforme a **Lei nº 14.133/2021**: Plano de Contratações Anual, demandas (DFD), instrução do processo (ETP, mapa de riscos, termo de referência, pesquisa de preços, parecer jurídico), dispensa e inexigibilidade, contratos e aditivos, sanções, publicação no PNCP e LGPD, com trilha de auditoria encadeada de tudo o que é feito.

Desenvolvido por [NexMind](https://nexmind.net.br/).

> **Teste sem instalar nada:** abra [`docs/demo/nexplangov-demo.html`](docs/demo/nexplangov-demo.html) no navegador. Funciona offline, com dados fictícios.

## Módulos

| Módulo | O que faz | Base legal |
|---|---|---|
| Plano de Contratações Anual | Itens por natureza de despesa, valores, aprovação e envio ao PNCP | art. 12, VII |
| Demandas (DFD) | Pedido das unidades requisitantes, vinculado a um item do PCA | art. 12, VII; art. 18 |
| Processos | Licitação e contratação direta, peças obrigatórias, checklist e tramitação controlada por perfil | arts. 17, 18, 53, 72 |
| ETP | Incisos obrigatórios I, IV, VI, VIII e XIII conferidos antes de concluir | art. 18, § 2º |
| Pesquisa de preços | Validade das fontes, descarte de preços fora da curva, mediana, média ou menor preço, mínimo de 3 preços | art. 23 |
| Dispensa por valor | Simulador com limites do ano, soma por natureza de despesa (fracionamento) e limite em dobro | art. 75, I e II, §§ 1º e 2º; art. 182 |
| Contratos | Vigência, prorrogação, garantia, gestor diferente do fiscal, alertas de vencimento | arts. 7º, 96 a 99, 105 a 107 |
| Aditivos e apostilamentos | Limite de 25% (50% em reforma), acréscimos e supressões apurados em separado | art. 125 |
| Fornecedores e sanções | CNPJ validado, CPF cifrado, impedimento bloqueia adjudicação e contrato | art. 156 |
| PNCP | Fila de envio com reenvio automático, número de controle, retificação | art. 174 |
| LGPD | Canal público do titular com protocolo e prazo de 15 dias, registro das operações de tratamento, aviso de privacidade | Lei 13.709, arts. 18, 19, 37 |
| Auditoria | Registro imutável, encadeado por hash, com verificação de integridade | |

## Segurança

- Verificação em duas etapas obrigatória para administradores, limite de tentativas, bloqueio de usuário ou órgão desativado.
- Vários órgãos no mesmo sistema, cada um enxergando só os próprios dados.
- Nove perfis: administrador geral, administrador do órgão, autoridade competente, agente de contratação, planejamento, jurídico, gestor de contrato, requisitante e controle interno (somente leitura). Cada ação sensível exige permissão própria (o parecer só pelo jurídico, a autorização só pela autoridade).
- CPF de pessoa física cifrado no banco, exibido mascarado e buscado por hash. Nada de dado pessoal na trilha de auditoria.
- Trilha de auditoria que ninguém altera nem apaga, nem direto no banco (gatilho no PostgreSQL), e cada registro carrega o hash do anterior.
- Peças concluídas ficam imutáveis, com hash do conteúdo. Revogar ou anular exige justificativa (art. 71).
- Credenciais do PNCP só no `.env`.

## Instalação com Docker (recomendado)

Precisa de: um servidor Linux com [Docker](https://docs.docker.com/engine/install/) e o plugin `docker compose`.

```bash
git clone https://github.com/rallfSilva/nexplangov.git
cd nexplangov
cp .env.example .env
```

Edite o `.env` e ajuste pelo menos:

| Variável | O que colocar |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | o endereço do sistema, ex.: `https://compras.prefeitura.gov.br` |
| `APP_KEY` | gere com `docker run --rm php:8.3-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"` |
| `DB_PASSWORD` | uma senha forte para o banco |
| `MAIL_*` | o servidor de e-mail do órgão (para os links de senha) |

Depois, um comando sobe tudo (sistema, fila e banco PostgreSQL):

```bash
docker compose up -d --build
```

E um comando cria o primeiro órgão e o administrador geral:

```bash
docker compose exec app php artisan nexplangov:instalar
```

O sistema fica em `http://<servidor>:8080` (mude a porta com `NEXPLANGOV_PORTA` no `.env`). Em produção, coloque um proxy com HTTPS na frente (Nginx, Caddy ou o balanceador do órgão) e defina `TRUSTED_PROXIES` com o IP dele.

### Para demonstração

Com `NEXPLANGOV_DEMO=true` no `.env` (e `APP_ENV` diferente de `production`), o sistema cria dados **fictícios**: a Prefeitura Municipal de Vale Verde, uma Câmara para mostrar o isolamento entre órgãos, PCA 2026, processos em várias etapas, contratos e fornecedores. A senha de todos é `NexPlan@demo2026`.

| E-mail | Perfil |
|---|---|
| `admin@nexplangov.local` | Administrador geral |
| `admin.pmvv@nexplangov.local` | Administrador do órgão |
| `prefeito@nexplangov.local` | Autoridade competente |
| `agente@nexplangov.local` | Agente de contratação |
| `planejamento@nexplangov.local` | Planejamento |
| `juridico@nexplangov.local` | Jurídico |
| `gestor@nexplangov.local` | Gestor de contrato |
| `fiscal@nexplangov.local` | Gestor de contrato (fiscal) |
| `educacao@nexplangov.local` | Requisitante |
| `controle@nexplangov.local` | Controle interno |
| `admin.cmvv@nexplangov.local` | Administrador de outro órgão |

Os administradores precisam ativar a verificação em duas etapas no primeiro acesso. Para uma demonstração rápida, dá para desligar com `NEXPLANGOV_EXIGIR_2FA=false` (nunca em produção).

### PNCP

Defina `PNCP_AMBIENTE` (`homologacao` ou `producao`), `PNCP_LOGIN` e `PNCP_SENHA` no `.env` e rode a fila (`php artisan queue:work`, já incluída no Docker). Comece sempre pela homologação. Detalhes em [docs/integracao-pncp.md](docs/integracao-pncp.md).

## Cópia de segurança (backup) e restauração

```bash
./scripts/backup.sh                 # grava em ./backups/nexplangov_AAAA-MM-DD_HHMMSS
./scripts/restaurar.sh ./backups/nexplangov_2026-10-22_020000
```

O backup copia o banco de dados e os arquivos enviados, confere tudo com soma de verificação e apaga cópias com mais de 30 dias (`NEXPLANGOV_BACKUP_DIAS`). Agende todo dia, por exemplo às 2h:

```
0 2 * * * cd /opt/nexplangov && ./scripts/backup.sh >> /var/log/nexplangov-backup.log 2>&1
```

Guarde uma cópia fora do servidor. A restauração pede que você digite `RESTAURAR` antes de substituir os dados atuais.

## Atualização

```bash
git pull
docker compose up -d --build
```

As mudanças no banco rodam sozinhas ao subir. Faça um backup antes.

## Desenvolvimento

Precisa de PHP 8.3 (com `pdo_pgsql`, `intl`, `zip`, `gd`), Composer, Node 22 e PostgreSQL 16.

```bash
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate
php artisan migrate --seed       # perfis + dados fictícios
php artisan serve
```

Testes (usam o banco `nexplangov_test`):

```bash
php artisan test
vendor/bin/pint        # padrão de código
```

## Documentação

- [Regras da Lei 14.133 implementadas](docs/regras-lei-14133.md)
- [Segurança e LGPD](docs/seguranca-lgpd.md)
- [Design system](docs/design-system.md)
- [Integração com o PNCP](docs/integracao-pncp.md)

## Tecnologia

Laravel 13, PHP 8.3, PostgreSQL 16, Fortify (autenticação e duas etapas), spatie/laravel-permission (perfis), Tailwind CSS 4. Fonte Plus Jakarta Sans hospedada no próprio sistema, sem chamadas a servidores externos.
