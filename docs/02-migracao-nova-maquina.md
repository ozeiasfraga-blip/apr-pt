# 02 — Migração para outra máquina (VPS/nova máquina)

Guia completo para levar o APR/PT de uma máquina para outra, **sem perder
banco, PDFs, assinaturas nem a autorização do Google Drive**.

> Está documentada a máquina de origem real: Debian 12, Apache 2.4.67,
> PHP 8.2.33 (mod_php), MariaDB 10.11, projeto em `/var/www/apr-pt`,
> porta **8089**.

---

## 0. Antes de copiar: inventário

Marque o que existe na máquina de origem:

| Item | Onde está | Versionado? |
|---|---|---|
| Código | `/var/www/apr-pt` | ✅ git |
| Código do APK | `/var/www/apr-pt/android` | ✅ git |
| **Banco** | MariaDB, base `apr_pt` | ❌ **só no servidor** |
| **PDFs** | `storage/pdfs/` | ❌ gitignore |
| **Assinaturas** | `storage/assinaturas/` | ❌ gitignore |
| **Anexos** | `storage/anexos/` | ❌ gitignore |
| **Credenciais Drive** | `storage/credentials/` | ❌ gitignore |
| **`.env`** | `/var/www/apr-pt/.env` | ❌ gitignore |
| **APK publicado** | `html/apk/app.apk` | ❌ gitignore |
| Crons | `/etc/cron.d/aprpt-*` | ✅ em `scripts/cron.d/` |
| Vhosts | `/etc/apache2/sites-*/` | ❌ parcialmente |
| Certificado SSL | `/etc/letsencrypt/` | ❌ |

**A regra de ouro:** o git leva o código. **Banco + `storage/` + `.env` + crons
+ vhosts precisam de backup explícito.**

---

## 1. Pré-requisitos da máquina nova

```bash
sudo apt update
sudo apt install -y apache2 libapache2-mod-php8.2 php8.2-cli \
  php8.2-mysql php8.2-curl php8.2-mbstring php8.2-xml php8.2-gd \
  php8.2-zip php8.2-intl unzip git mariadb-server curl

sudo a2enmod rewrite proxy proxy_http ssl headers
sudo systemctl restart apache2
```

### Extensões PHP obrigatórias

Confira com `php -m` — sem elas algo quebra em silêncio:

| Extensão | Para que |
|---|---|
| `pdo_mysql` | Banco (obrigatória) |
| `curl` | API do IXC + Google Drive |
| `mbstring` | UTF-8 na geração do PDF |
| `gd` | Assinaturas/imagem no Dompdf |
| `xml` | Dompdf |
| `openssl` | JWT + HTTPS do Google |
| `dom` | Dompdf |
| `fileinfo` | Tipos de upload |

```bash
php -m | grep -Ei 'pdo_mysql|curl|mbstring|gd|xml|openssl|dom|fileinfo'
```

### Composer

```bash
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
composer --version
```

### Porta do Apache

O APR/PT escuta na **8089**, declarada **dentro do vhost** (`Listen 8089` em
`sites-available/apr-porta.conf`). Se preferir centralizar, some a linha ao
`/etc/apache2/ports.conf`.

```bash
sudo a2query -m rewrite proxy proxy_http ssl headers   # devem aparecer ativos
```

---

## 2. Copiar o código

```bash
# Na máquina de ORIGEM:
cd /var/www/apr-pt
git status                       # deve estar limpo
git log --oneline -5
git remote -v
```

Na **máquina nova**:

```bash
sudo mkdir -p /var/www && cd /var/www
sudo git clone <URL_DO_REPOSITORIO> apr-pt
cd apr-pt
sudo composer install --no-dev --optimize-autoloader
```

Se não for usar git (cópia direta), **não** copie `vendor/` nem `storage/`:

```bash
# Na origem:
rsync -a --exclude vendor/ --exclude storage/ --exclude .git \
  /var/www/apr-pt/ usuario@nova-maquina:/var/www/apr-pt/
```

---

## 3. Banco de dados

### 3.1 Exportar na origem

```bash
mysqldump --single-transaction --routines --triggers \
  -u apr_pt -p apr_pt > /tmp/apr_pt_dump.sql
```

> Se não souber a senha, ela está em `DB_PASS` do `.env` da origem.

### 3.2 Importar no destino

```bash
# base + usuário
sudo mysql -e "CREATE DATABASE apr_pt CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'apr_pt'@'localhost' IDENTIFIED BY 'SENHA_FORTE';"
sudo mysql -e "GRANT ALL ON apr_pt.* TO 'apr_pt'@'localhost'; FLUSH PRIVILEGES;"

mysql -u apr_pt -p apr_pt < /tmp/apr_pt_dump.sql
```

### 3.3 Migrações

**Não há runner de migrations.** Elas são aplicadas manualmente e o dump já
traz o schema final. Se estiver instalando **do zero** (sem dump), execute em
ordem:

```bash
cd /var/www/apr-pt
for f in migrations/*.sql; do echo "== $f"; mysql -u apr_pt -p apr_pt < "$f"; done
```

São 22 arquivos (`001_init.sql` … `022_user_foto.sql`), todos com
`CREATE TABLE IF NOT EXISTS` / `ALTER` defensivo.

**Confira a versão do schema:**

```bash
mysql -u apr_pt -p apr_pt -e "SHOW COLUMNS FROM documentos_sst LIKE 'pdf_drive_id';"
```

Se não existir a coluna, falta a `021_pdf_drive_id.sql`.

---

## 4. `storage/` — a parte mais fácil de esquecer

```bash
# Na origem (permissões corretas são importantes):
rsync -a /var/www/apr-pt/storage/ usuario@nova:/var/www/apr-pt/storage/
```

**Necessário:** `pdfs/`, `assinaturas/`, `anexos/`, `credentials/`.

### Permissões — crítico

Tudo deve ser do usuário do Apache (`www-data`), senão o sistema "não acha"
os arquivos e o `state` do OAuth falha:

```bash
cd /var/www/apr-pt
sudo chown -R www-data:www-data storage
sudo chmod 775 storage
sudo find storage -type d -exec chmod 775 {} +
sudo find storage -type f -exec chmod 664 {} +

# credenciais ficam restritas (0600) — só o Apache lê
sudo chmod 750 storage/credentials
sudo chmod 600 storage/credentials/*.json storage/credentials/*.conf 2>/dev/null
sudo chown -R www-data:www-data storage/credentials
```

> ⚠️ **Arquivo de token criado por `root` = erro "estado inválido" no OAuth do
> Google.** O Apache (`www-data`) não consegue ler. Se acontecer:
> `sudo chown www-data:www-data storage/credentials/google-oauth.json`.

### Conteúdo de `storage/credentials/` (NUNCA versionado)

| Arquivo | Origem |
|---|---|
| `google-oauth.json` | Gerado ao conectar o Drive (refresh token) — **copiar da origem** ou reconectar |
| `google-drive.json` | Conta de serviço (fallback, hoje sem cota) |
| `rclone.conf` | Fallback rclone (hoje não usado) |

---

## 5. `.env`

```bash
cd /var/www/apr-pt
cp .env.example .env
sudo nano .env
```

**Copie o `.env` da origem inteiro** e ajuste o que mudar. Variáveis obrigatórias
estão detalhadas em [03-configuracao-variaveis.md](03-configuracao-variaveis.md).

Checklist mínimo:

- [ ] `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` ← novo banco
- [ ] `JWT_SECRET` ← **troque** se o servidor mudar de propósito público
- [ ] `IXC_BASE_URL` / `IXC_USER` / `IXC_TOKEN`
- [ ] `GOOGLE_OAUTH_CLIENT_ID` / `GOOGLE_OAUTH_CLIENT_SECRET` / `GOOGLE_OAUTH_REDIRECT_URI`
- [ ] `GOOGLE_DRIVE_FOLDER_ID`
- [ ] `STORAGE_PATH=storage`

```bash
sudo chown www-data:www-data .env && sudo chmod 640 .env
```

---

## 6. Apache (vhosts)

### 6.1 Vhost principal (porta 8089)

Copie `apr-porta.conf` da origem para `sites-available/` na nova máquina:

```bash
# Na origem:
scp /etc/apache2/sites-available/apr-porta.conf usuario@nova:/tmp/
# Na nova:
sudo cp /tmp/apr-porta.conf /etc/apache2/sites-available/
sudo a2ensite apr-porta.conf
```

Conteúdo mínimo (ajuste o caminho se não for `/var/www/apr-pt`):

```apache
Listen 8089

<VirtualHost *:8089>
    DocumentRoot /var/www/apr-pt/html

    <Directory /var/www/apr-pt/html>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/apr-port-error.log
    CustomLog ${APACHE_LOG_DIR}/apr-port-access.log combined
</VirtualHost>
```

`AllowOverride All` **é obrigatório** — sem ele os `.htaccess` (rewrite da
SPA, regra do `/oauth/`, limites de upload) são ignorados e tudo dá 404.

### 6.2 HTTPS + callback do Google Drive (se for manter o OAuth)

O Google **exige HTTPS** no redirect URI. Na origem há duas peças:

**(a) Certificado Let's Encrypt** para o hostname usado no redirect:

```bash
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d SEU_HOST.nwt.net.br --redirect --agree-tos -m seu@email
# renovação automática (confira): sudo systemctl status certbot.timer
```

**(b) Proxy `/oauth/` no vhost HTTPS** — encaminha o callback do Google para
a porta 8089. **Copie este bloco do vhost que serve o hostname do redirect:**

```apache
# Callback OAuth do APR/PT (Google exige HTTPS)
ProxyPass /oauth/ http://127.0.0.1:8089/oauth/ retry=0
ProxyPassReverse /oauth/ http://127.0.0.1:8089/oauth/
```

Sem este bloco, o callback cai na SPA (ou em outro site) e o usuário vê
"rota não encontrada".

**Teste depois do reload:**

```bash
sudo apache2ctl configtest && sudo systemctl reload apache2
curl -sI https://SEU_HOST/oauth/drive/callback | head -1   # deve ser 400/404 do APR/PT
```

### 6.3 Módulos

```bash
sudo a2enmod rewrite proxy proxy_http ssl headers
```

---

## 7. Google Drive — o que fazer na nova máquina

Duas opções:

### Opção A — copiar o token (mais rápido)

```bash
scp /var/www/apr-pt/storage/credentials/google-oauth.json usuario@nova:/var/www/apr-pt/storage/credentials/
sudo chown www-data:www-data /var/www/apr-pt/storage/credentials/google-oauth.json
sudo chmod 600 /var/www/apr-pt/storage/credentials/google-oauth.json
```

> **Só funciona se o redirect URI não mudar.** Se o hostname/URL for diferente,
> use a Opção B.

### Opção B — reconectar (necessário se mudar o redirect URI)

1. No Console Google → **APIs e serviços → Credenciais** → client `APR-PT`
2. Em **URIs de redirecionamento autorizados**, adicione o novo:
   `https://NOVO_HOST/oauth/drive/callback`
3. Atualize `GOOGLE_OAUTH_REDIRECT_URI` no `.env`
4. Gere a URL de autorização e abra:

   ```bash
   php -r 'require "/var/www/apr-pt/src/bootstrap.php";
     echo App\Services\GoogleOAuthService::authorizeUrl(), PHP_EOL;'
   ```

5. Autorize → tela verde **"Google Drive conectado"**
6. Confira:

   ```bash
   sudo -u www-data php /var/www/apr-pt/scripts/verificar_drive.php --dry-run
   ```

> Escopo `drive.file`: o app só enxerga arquivos que **ele mesmo** criou.
> O `refresh_token` não expira (app publicado); o `access_token` renova sozinho.

---

## 8. Crons

Há dois. **Os arquivos versionados estão em `scripts/cron.d/`** — restaure
sempre de lá:

```bash
cd /var/www/apr-pt
sudo cp scripts/cron.d/aprpt-sync /etc/cron.d/
sudo cp scripts/cron.d/aprpt-verifica-drive /etc/cron.d/
sudo chmod 644 /etc/cron.d/aprpt-*
```

| Arquivo | Agenda | Usa | Log |
|---|---|---|---|
| `aprpt-sync` | `0,15,30,45 * * * *` | `root` | `/var/log/aprpt-sync.log` |
| `aprpt-verifica-drive` | `30 3 * * *` | **`www-data`** | `/var/log/aprpt-verifica-drive.log` |

O `verificar_drive` roda como `www-data` **de propósito**: ele pode gerar PDFs
em `storage/`, e arquivo criado por `root` travaria o Apache.

Crie os logs com dono certo:

```bash
sudo touch /var/log/aprpt-sync.log /var/log/aprpt-verifica-drive.log
sudo chown www-data:www-data /var/log/aprpt-verifica-drive.log
sudo chmod 644 /var/log/aprpt-sync.log /var/log/aprpt-verifica-drive.log
```

Verifique:

```bash
grep -H . /etc/cron.d/aprpt-*        # vê as linhas
systemctl status cron --no-pager     # ou cron / crond
```

---

## 9. Aplicação e permissões do código

```bash
cd /var/www/apr-pt
sudo chown -R root:root .            # código não deve ser gravável pelo Apache
sudo chmod -R o+rX .
# apenas o que o Apache precisa escrever:
sudo chown -R www-data:www-data storage
sudo chmod 640 .env
```

O Apache (`www-data`) precisa escrever **só** em `storage/` (e ler tudo).

---

## 10. Verificação final (checklist)

Rode na máquina nova, nesta ordem:

```bash
# 1. PHP sem erros de sintaxe
cd /var/www/apr-pt && find src html scripts -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v 'No syntax' || echo "OK"

# 2. Banco acessível com o .env
php -r 'require "src/bootstrap.php"; \
  echo App\Database::getInstance()->query("SELECT COUNT(*) c FROM documentos_sst")->fetch()["c"], " documentos\n";'

# 3. Health da API
curl -s http://127.0.0.1:8089/api/health
# {"success":true,...,"ixc_configurado":true/false}

# 4. Login real (troque a senha)
curl -s -X POST http://127.0.0.1:8089/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"SEU_EMAIL","password":"SUA_SENHA"}'

# 5. SPA abre
curl -sI http://127.0.0.1:8089/ | head -1        # HTTP/1.1 200

# 6. Sincronização com o IXC
php scripts/sync_os.php

# 7. Backup da 2ª cópia
sudo -u www-data php scripts/verificar_drive.php

# 8. PDFs contados (local x banco)
ls storage/pdfs | wc -l
mysql -u apr_pt -p apr_pt -N -e \
  "SELECT COUNT(*) FROM documentos_sst WHERE pdf_path IS NOT NULL AND pdf_path<>''"
```

**Do lado do usuário (não dá para automatizar):**

- [ ] Login pela SPA/apk funciona
- [ ] Lista de OS do dia aparece (sync OK)
- [ ] Abrir uma PT e **baixar o PDF** (`OS-<n>.pdf`)
- [ ] Drive: `GET /api/drive/status` → `oauth_conectado: true`
- [ ] Encerrar uma PT de teste → conferir o PDF na pasta do Drive
- [ ] APK (se usável) aponta para a URL nova — veja abaixo

---

## 11. O APK Android

O APK **aponta para uma URL fixa** gravada no build
(`http://177.125.60.123:8089/`). Se o endereço mudar, **gere um APK novo**:

```bash
cd android
# ajuste a URL em app/src/main/java/.../MainActivity.java
ANDROID_HOME=/opt/android-sdk ./gradlew assembleRelease
cp app/build/outputs/apk/release/app-release.apk ../html/apk/app.apk
# incremente versionCode/versionName em app/build.gradle
# atualize html/apk/versao.json  (o app avisa a atualização)
```

Detalhes em [`android/README.md`](../android/README.md).
**Keystore obrigatório** (`android/android.keystore`, fora do git) — sem ele o
Android **recusa atualizar** o app instalado.

---

## 12. Troubleshooting de migração

| Sintoma | Causa provável | Conserto |
|---|---|---|
| 404 em tudo | `AllowOverride All` ausente | Conferir o `<Directory>` do vhost |
| 404 na SPA mas `/api/health` ok | Falta `a2enmod rewrite` | `sudo a2enmod rewrite && reload` |
| `SQLSTATE[HY000] [1045]` | `.env` com credencial antiga | Conferir `DB_*` |
| Erro "estado inválido" no OAuth | `google-oauth.json` com dono `root` | `chown www-data:www-data` |
| `redirect_uri_mismatch` | URI não bate com o console | Conferir `GOOGLE_OAUTH_REDIRECT_URI` + console |
| `access_denied` | App em modo teste sem usuário | Console → Público-alvo → Publicar app |
| PDF não aparece | `storage/` sem dono `www-data` | `chown -R www-data:www-data storage` |
| Upload falha | Limite baixo | `.htaccess`: 12M/14M |
| Drive sempre vazio | Cron sem log ou sem permissão | Conferir `/etc/cron.d/` e o dono do log |
| IXC devolve 403 | Token errado/renovado | `IXC_USER`/`IXC_TOKEN` |
| Header `Authorization` perdido | Apache não repassa | Já contornado: `Middleware` lê `REDIRECT_HTTP_AUTHORIZATION` |

Logs:

```bash
tail -50 /var/log/apache2/apr-port-error.log   # PHP/Apache
tail -20 /var/log/aprpt-sync.log               # sync IXC
tail -20 /var/log/aprpt-verifica-drive.log     # verificação do Drive
sudo journalctl -u cron --since today          # cron
```

---

## 13. Rollback / ida e volta

Para voltar para a máquina antiga (ou tentar de novo):

1. Não apague a máquina de origem antes do checklist da seção 10 passar.
2. O dump do banco é **compatível** entre as duas (schema idêntico).
3. `storage/` e `.env` podem ir e voltar livremente — são autocontidos.
4. O único item com estado externo é o **token do Google**: se você reconectar
   numa máquina, a conexão continua válida (o refresh token não é "de uma
   máquina", é do app + conta). Basta copiar `google-oauth.json`.

## 14. Backup contínuo (depois da migração)

Rotina sugerida (ajuste caminhos):

```bash
# Diário 03:00 — banco + storage
0 3 * * * root mysqldump --single-transaction -u apr_pt -pSENHA apr_pt | gzip > /backup/apr_pt_$(date +\%F).sql.gz
15 3 * * * root rsync -a /var/www/apr-pt/storage/ /backup/apr-pt-storage/
```

Combinado com o `aprpt-verifica-drive` (03:30), você tem: banco salvo,
arquivos salvos e conferência da cópia no Drive.
