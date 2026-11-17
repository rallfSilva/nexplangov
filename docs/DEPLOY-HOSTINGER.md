# Deploy do NexPlanGov numa VPS da Hostinger (Ubuntu 24.04)

Passo a passo para instalar o NexPlanGov direto no servidor (sem Docker): Nginx, PHP-FPM 8.3, PostgreSQL 16, Node 22, fila no Supervisor, agendador no cron, HTTPS com Let's Encrypt, firewall e backup. No fim há a alternativa com Docker.

Nos exemplos, troque:

| Exemplo | Troque por |
|---|---|
| `compras.exemplo.gov.br` | o domínio do sistema |
| `203.0.113.10` | o IP da VPS (aparece no hPanel) |
| `deploy` | o usuário que vai administrar o servidor |
| `/var/www/nexplangov` | a pasta do sistema (pode manter) |

Todos os comandos rodam no terminal da VPS, por SSH. O que começa com `sudo` precisa de administrador.

## 1. Escolher e preparar a VPS no hPanel

1. **Plano.** Para produção, a partir do KVM 2 (2 vCPU, 8 GB de RAM). O KVM 1 serve para homologação ou demonstração. O banco e os arquivos crescem com o uso: acompanhe o disco.
2. **Sistema.** Em hPanel › VPS › Sistema operacional, escolha **Ubuntu 24.04 LTS** "limpo" (sem painel). O Ubuntu 24.04 já traz PHP 8.3 e PostgreSQL 16, as versões que o sistema usa.
3. **Acesso.** Defina a senha de root e, de preferência, cadastre sua chave SSH pública em hPanel › VPS › Chaves SSH.
4. **Firewall do hPanel.** A Hostinger tem um firewall próprio da VPS (hPanel › VPS › Segurança › Firewall). Se você ligar esse firewall, libere nele as portas 22, 80 e 443. O passo 4 deste guia liga também o UFW dentro do servidor.
5. **Backups da Hostinger.** Veja em hPanel › VPS › Backups quais cópias automáticas e snapshots o seu plano oferece. Eles ajudam, mas não substituem o backup do passo 13, que copia o banco de forma consistente e leva a cópia para fora da Hostinger.

## 2. Primeiro acesso e usuário administrador

No seu computador:

```bash
ssh root@203.0.113.10
```

Na VPS:

```bash
# Atualiza tudo
apt update && apt -y full-upgrade

# Fuso horário do sistema (o mesmo do .env)
timedatectl set-timezone America/Boa_Vista

# Usuário de administração (não use root no dia a dia)
adduser deploy
usermod -aG sudo,www-data deploy

# Copia a chave SSH do root para o novo usuário (se você cadastrou uma chave)
rsync --archive --chown=deploy:deploy ~/.ssh /home/deploy
```

Abra **outro terminal** e confirme que `ssh deploy@203.0.113.10` funciona. Só então desligue o login de root e por senha:

```bash
sudo sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin no/; s/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
# O Ubuntu 24.04 pode ter um arquivo que reativa a senha; confira:
sudo grep -r PasswordAuthentication /etc/ssh/sshd_config.d/ || true
sudo systemctl restart ssh
```

Se não usa chave SSH, deixe `PasswordAuthentication yes` e use uma senha longa. O fail2ban (passo 4) bloqueia as tentativas repetidas.

**Memória de troca.** Em VPS com até 4 GB, crie 2 GB de swap para o `npm run build` não faltar memória:

```bash
sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile
sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

## 3. Pacotes

```bash
# Nginx, PHP 8.3 com as extensões do sistema, PostgreSQL 16, Supervisor e utilitários
sudo apt -y install nginx postgresql postgresql-contrib supervisor git unzip curl acl \
  php8.3-fpm php8.3-cli php8.3-pgsql php8.3-intl php8.3-zip php8.3-gd php8.3-bcmath \
  php8.3-mbstring php8.3-xml php8.3-curl php8.3-readline php8.3-opcache

# Composer (instalador oficial)
curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer

# Node 22 (para compilar as telas)
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt -y install nodejs

# Confere as versões
php -v            # 8.3.x
psql --version    # 16.x
node -v           # v22.x
composer -V
```

As extensões batem com as do `Dockerfile` (`pdo_pgsql`, `intl`, `zip`, `gd`, `bcmath`, `opcache`) e com as que o Laravel exige (`mbstring`, `xml`, `curl`).

## 4. Firewall, fail2ban e atualizações automáticas

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'      # portas 80 e 443
sudo ufw enable
sudo ufw status

sudo apt -y install fail2ban     # já vem com a proteção do SSH ligada
sudo dpkg-reconfigure -plow unattended-upgrades   # responda "Sim"
```

O PostgreSQL fica só em `localhost` (padrão do Ubuntu). Não abra a porta 5432.

## 5. Banco de dados

```bash
# Gere uma senha forte e guarde-a no cofre de senhas
openssl rand -base64 24

sudo -u postgres psql -c "CREATE ROLE nexplangov LOGIN PASSWORD 'COLE-A-SENHA-AQUI';"
sudo -u postgres createdb -O nexplangov -E UTF8 nexplangov
```

O usuário `nexplangov` precisa ser **dono** do banco: as migrações criam os gatilhos que impedem alterar a trilha de auditoria, o livro de ocorrências e os documentos juntados.

## 6. Código

```bash
sudo mkdir -p /var/www/nexplangov
sudo chown deploy:www-data /var/www/nexplangov
cd /var/www/nexplangov
```

**Se o repositório for privado**, crie uma chave de implantação só de leitura:

```bash
ssh-keygen -t ed25519 -C "deploy-vps-nexplangov" -f ~/.ssh/nexplangov_deploy -N ""
cat ~/.ssh/nexplangov_deploy.pub
# No GitHub: repositório › Settings › Deploy keys › Add deploy key (sem marcar "Allow write access")
cat >> ~/.ssh/config <<'EOF'
Host github-nexplangov
  HostName github.com
  IdentityFile ~/.ssh/nexplangov_deploy
EOF
git clone git@github-nexplangov:rallfSilva/nexplangov.git .
```

**Se for público**: `git clone https://github.com/rallfSilva/nexplangov.git .`

Dependências e telas:

```bash
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build
```

## 7. Arquivo `.env` de produção

```bash
cp .env.example .env
nano .env
```

Ajuste estas linhas (as demais podem ficar como estão):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://compras.exemplo.gov.br
APP_TIMEZONE=America/Boa_Vista

LOG_STACK=daily
LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=nexplangov
DB_USERNAME=nexplangov
DB_PASSWORD=a-senha-do-passo-5

SESSION_DRIVER=database
SESSION_LIFETIME=60
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true

QUEUE_CONNECTION=database
CACHE_STORE=database

# E-mail (troca de senha, alertas de prazo, pedidos a externos, LGPD).
# Exemplo com e-mail da Hostinger; use o SMTP do órgão se houver.
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=465
MAIL_USERNAME=nao-responda@exemplo.gov.br
MAIL_PASSWORD=senha-da-caixa
MAIL_FROM_ADDRESS="nao-responda@exemplo.gov.br"

NEXPLANGOV_EXIGIR_2FA=true
NEXPLANGOV_DEMO=false
# Vazio: o Nginx está na mesma máquina. Preencha só se houver Cloudflare ou balanceador na frente.
TRUSTED_PROXIES=

# PNCP: sempre comece em homologação
PNCP_AMBIENTE=homologacao
PNCP_LOGIN=
PNCP_SENHA=

# IA desligada até o órgão aprovar o uso (ver docs/PENDENCIAS.md, LGPD)
NEXPLANGOV_IA=false
ANTHROPIC_API_KEY=

# CEIS e CNEP (chave gratuita do Portal da Transparência)
TRANSPARENCIA_CHAVE=
```

Gere a chave e proteja o arquivo:

```bash
php artisan key:generate
chmod 640 .env && sudo chown deploy:www-data .env
```

> **Guarde uma cópia do `.env` num cofre de senhas fora do servidor.** A `APP_KEY` cifra os CPFs e gera os códigos de validação das assinaturas. Sem ela, esses dados se perdem e não há como recuperar. Nunca troque a `APP_KEY` de um sistema em uso.

## 8. Permissões

O código fica com o usuário `deploy`. O PHP-FPM roda como `www-data` e só escreve em `storage` e `bootstrap/cache`.

```bash
cd /var/www/nexplangov
sudo chown -R deploy:www-data .
sudo find . -type d -exec chmod 755 {} \;
sudo find . -type f -exec chmod 644 {} \;
chmod 640 .env
sudo chmod -R ug+rwX storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod g+s {} \;
# Garante que arquivos novos criados por qualquer um dos dois continuem graváveis
sudo setfacl -R -m u:www-data:rwX -m u:deploy:rwX storage bootstrap/cache
sudo setfacl -dR -m u:www-data:rwX -m u:deploy:rwX storage bootstrap/cache
chmod +x artisan scripts/*.sh
```

A partir daqui, rode os comandos `php artisan` como `www-data`, para os arquivos de cache e de log nascerem com o dono certo:

```bash
alias art='sudo -u www-data php /var/www/nexplangov/artisan'
echo "alias art='sudo -u www-data php /var/www/nexplangov/artisan'" >> ~/.bashrc
```

## 9. Banco, perfis e primeiro acesso

```bash
art migrate --force
art db:seed --class='Database\Seeders\PerfisSeeder' --force
art nexplangov:instalar      # pergunta órgão, sigla, nome, e-mail e senha do administrador geral
art optimize                 # cache de configuração, rotas, eventos e telas
```

Não rode `migrate --seed` nem o `DemoSeeder` em produção: eles criam os dados fictícios de demonstração.

## 10. PHP-FPM e Nginx

Ajustes do PHP (os mesmos de `docker/php.ini`), só para o PHP-FPM:

```bash
sudo tee /etc/php/8.3/fpm/conf.d/99-nexplangov.ini >/dev/null <<'EOF'
expose_php = Off
memory_limit = 256M
upload_max_filesize = 20M
post_max_size = 24M
max_execution_time = 60
date.timezone = America/Boa_Vista
session.cookie_httponly = 1
opcache.enable = 1
opcache.validate_timestamps = 0
opcache.memory_consumption = 128
EOF
sudo systemctl restart php8.3-fpm
```

Com `opcache.validate_timestamps = 0` o PHP não relê os arquivos sozinho: toda atualização precisa de `sudo systemctl reload php8.3-fpm` (o script do passo 14 já faz).

Site no Nginx (baseado em `docker/nginx.conf`):

```bash
sudo tee /etc/nginx/sites-available/nexplangov >/dev/null <<'EOF'
server {
    listen 80;
    listen [::]:80;
    server_name compras.exemplo.gov.br;
    root /var/www/nexplangov/public;
    index index.php;
    client_max_body_size 24M;
    server_tokens off;

    access_log /var/log/nginx/nexplangov.access.log;
    error_log  /var/log/nginx/nexplangov.error.log;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 120;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
EOF
sudo ln -s /etc/nginx/sites-available/nexplangov /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

Os cabeçalhos de segurança (CSP, HSTS, X-Frame-Options e outros) já saem do próprio sistema (`CabecalhosSeguranca`); não repita no Nginx.

## 11. Fila e agendador

**Fila** (envios ao PNCP, pacotes de devolução, e-mails), no Supervisor:

```bash
sudo tee /etc/supervisor/conf.d/nexplangov-fila.conf >/dev/null <<'EOF'
[program:nexplangov-fila]
command=/usr/bin/php /var/www/nexplangov/artisan queue:work --sleep=3 --tries=3 --max-time=3600
directory=/var/www/nexplangov
user=www-data
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=120
redirect_stderr=true
stdout_logfile=/var/www/nexplangov/storage/logs/fila.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
EOF
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status          # nexplangov-fila RUNNING
```

O job `EnviarAoPncp` tem as próprias tentativas (5) e esperas (de 1 minuto a 1 hora); o `--tries=3` vale para os demais.

**Agendador** (resumo diário de prazos às 7h, alertas do art. 94 e limpeza dos pacotes de devolução às 3h10), no cron:

```bash
echo '* * * * * www-data cd /var/www/nexplangov && php artisan schedule:run >> /dev/null 2>&1' | sudo tee /etc/cron.d/nexplangov
sudo chmod 644 /etc/cron.d/nexplangov
art schedule:list                  # mostra nexplangov:alertas e nexplangov:devolucao-limpar
```

## 12. Domínio e HTTPS

**DNS.** No painel onde o domínio está (hPanel › Domínios › DNS, Registro.br ou o DNS do órgão), crie:

| Tipo | Nome | Valor |
|---|---|---|
| A | `compras` | `203.0.113.10` |
| AAAA | `compras` | o IPv6 da VPS (se houver no hPanel) |

Espere propagar (`dig +short compras.exemplo.gov.br` deve mostrar o IP).

**Certificado Let's Encrypt:**

```bash
sudo apt -y install certbot python3-certbot-nginx
sudo certbot --nginx -d compras.exemplo.gov.br --redirect --agree-tos -m ti@exemplo.gov.br
sudo certbot renew --dry-run       # confere a renovação automática (timer do systemd)
```

Abra `https://compras.exemplo.gov.br`, entre com o administrador do passo 9 e ative a verificação em duas etapas, que é obrigatória para administradores.

## 13. Backup

Os scripts de `scripts/backup.sh` usam Docker. Na instalação direta, use este:

```bash
sudo tee /usr/local/bin/nexplangov-backup >/dev/null <<'EOF'
#!/bin/sh
# Cópia do NexPlanGov: banco (pg_dump) + arquivos enviados, com soma de verificação.
set -eu
DESTINO=/var/backups/nexplangov
DATA="$(date +%Y-%m-%d_%H%M%S)"
PASTA="$DESTINO/nexplangov_$DATA"
MANTER_DIAS=30

umask 077
mkdir -p "$PASTA"
runuser -u postgres -- pg_dump -d nexplangov --format=custom --no-owner > "$PASTA/banco.dump"
tar -czf "$PASTA/arquivos.tar.gz" -C /var/www/nexplangov/storage app
( cd "$PASTA" && sha256sum banco.dump arquivos.tar.gz > SHA256SUMS )
find "$DESTINO" -maxdepth 1 -type d -name 'nexplangov_*' -mtime +"$MANTER_DIAS" -exec rm -rf {} +
echo "Pronto: $PASTA"
EOF
sudo chmod 750 /usr/local/bin/nexplangov-backup
sudo nexplangov-backup                       # primeira cópia, para testar

# Todo dia às 2h
echo '0 2 * * * root /usr/local/bin/nexplangov-backup >> /var/log/nexplangov-backup.log 2>&1' | sudo tee /etc/cron.d/nexplangov-backup
```

**Cópia fora da VPS (obrigatório).** Uma cópia que só existe no servidor some junto com ele. Use o rclone com um destino externo (Google Drive do órgão, S3, Backblaze B2) e uma camada `crypt`, porque os arquivos enviados podem ter dado pessoal:

```bash
sudo apt -y install rclone
sudo rclone config        # crie o destino (ex.: "externo") e um remoto "crypt" sobre ele (ex.: "cofre")
echo '30 2 * * * root rclone sync /var/backups/nexplangov cofre:nexplangov >> /var/log/nexplangov-backup.log 2>&1' | sudo tee -a /etc/cron.d/nexplangov-backup
```

**Restauração** (teste uma vez antes de abrir o sistema ao órgão, de preferência numa VPS de teste):

```bash
PASTA=/var/backups/nexplangov/nexplangov_2026-11-17_020000
( cd "$PASTA" && sha256sum -c SHA256SUMS )
cd /var/www/nexplangov
art down
sudo supervisorctl stop nexplangov-fila
sudo -u postgres dropdb --if-exists --force nexplangov
sudo -u postgres createdb -O nexplangov -E UTF8 nexplangov
sudo -u postgres pg_restore -d nexplangov --no-owner --role=nexplangov "$PASTA/banco.dump"
sudo rm -rf storage/app && sudo tar -xzf "$PASTA/arquivos.tar.gz" -C storage
sudo chown -R deploy:www-data storage/app && sudo chmod -R ug+rwX storage/app
art optimize
sudo supervisorctl start nexplangov-fila
art up
```

A restauração só funciona com a **mesma `APP_KEY`** do backup (ver passo 7).

## 14. Atualizar o sistema

Crie o script uma vez:

```bash
tee /home/deploy/atualizar-nexplangov.sh >/dev/null <<'EOF'
#!/bin/sh
# Atualiza o NexPlanGov a partir da main. Faz backup antes e põe o sistema em manutenção durante a troca.
set -eu
cd /var/www/nexplangov
ART="sudo -u www-data php artisan"

sudo /usr/local/bin/nexplangov-backup
$ART down --retry=60

git fetch origin main
git merge --ff-only origin/main

composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build

$ART migrate --force
$ART db:seed --class='Database\Seeders\PerfisSeeder' --force
$ART optimize

sudo chmod -R ug+rwX storage bootstrap/cache
sudo systemctl reload php8.3-fpm
$ART queue:restart
$ART up
echo "Atualizado para $(git log -1 --format='%h %s')"
EOF
chmod +x /home/deploy/atualizar-nexplangov.sh
```

E a cada atualização:

```bash
~/atualizar-nexplangov.sh
```

Se algo der errado no meio, o sistema fica em manutenção. Corrija, rode o script de novo ou volte a versão (`git reset --hard <commit-anterior>` seguido do script a partir do `composer install`) e, se uma migração estragou dados, restaure o backup feito no início.

## 15. Conferência final

- [ ] `https://compras.exemplo.gov.br/up` responde 200 e `http://` redireciona para `https://`
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `NEXPLANGOV_DEMO=false`
- [ ] Cópia do `.env` guardada no cofre de senhas
- [ ] `sudo supervisorctl status` mostra a fila rodando
- [ ] `art schedule:list` mostra as duas tarefas e o cron existe em `/etc/cron.d/nexplangov`
- [ ] E-mail de "Esqueci minha senha" chega (confira SPF e DKIM do domínio)
- [ ] Verificação em duas etapas ativa para os administradores
- [ ] Backup diário rodou, a cópia foi para fora da VPS e a restauração foi testada
- [ ] `sudo ufw status` mostra só 22, 80 e 443
- [ ] PNCP em `homologacao` até rodar `art nexplangov:pncp-homologar --orgao=SIGLA` e o ciclo de teste (ver `docs/integracao-pncp.md`)
- [ ] Monitoramento externo de `/up` (UptimeRobot ou similar) avisando por e-mail

Logs para consultar quando algo falhar:

```bash
tail -f /var/www/nexplangov/storage/logs/laravel-$(date +%F).log
tail -f /var/www/nexplangov/storage/logs/fila.log
sudo tail -f /var/log/nginx/nexplangov.error.log
art queue:failed                   # envios que esgotaram as tentativas
```

## Alternativa: com Docker

O repositório já sobe com Docker (README, "Instalação com Docker"). Na Hostinger, escolha o modelo de VPS **Ubuntu 24.04 com Docker** ou instale o Docker no Ubuntu limpo. Depois:

1. Siga os passos 1, 2 e 4 deste guia (VPS, usuário e firewall).
2. Clone em `/opt/nexplangov`, preencha o `.env` (passo 7) e rode `docker compose up -d --build` e `docker compose exec app php artisan nexplangov:instalar`.
3. O sistema fica na porta 8080. Instale o Nginx no servidor como proxy para `http://127.0.0.1:8080`, gere o certificado como no passo 12 e defina `TRUSTED_PROXIES` com a rede do Docker (ex.: `172.16.0.0/12`). Use `NEXPLANGOV_PORTA=127.0.0.1:8080` no `.env`: o Docker abre as portas publicadas por fora do UFW, então só fechar no firewall não basta.
4. **O `docker-compose.yml` atual não roda o agendador** (pendência P0 nº 1). Até isso ser corrigido, ponha no cron do servidor:
   `* * * * * cd /opt/nexplangov && docker compose exec -T app php artisan schedule:run >> /dev/null 2>&1`
5. Backup com `scripts/backup.sh` no cron, como no README, mais a cópia externa com rclone (passo 13).
6. Para atualizar: backup, `git pull` e `docker compose up -d --build`.

A instalação direta deste guia gasta menos memória e é mais simples de diagnosticar numa VPS pequena. O Docker é mais fácil de repetir em vários servidores.
