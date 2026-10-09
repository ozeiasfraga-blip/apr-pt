# 03 — Configuração e variáveis

Toda a configuração vem de **`.env`** na raiz do projeto, carregado por
`vlucas/phpdotenv` em `src/bootstrap.php` e lido via `$_ENV`.

- `.env` **não é versionado** (`.gitignore`).
- O modelo é **`.env.example`** — sempre commitado, sem segredos.
- Existe um script de sanitização em `.env.example` (`sed` que zera segredos)
  para gerar um exemplo novo.

**Depois de mexer em `.env`, reinicie o Apache** (`mod_php` carrega o arquivo
no processo):

```bash
sudo systemctl reload apache2
```

---

## Aplicação

| Variável | Obrigatória | Padrão | O que faz |
|---|---|---|---|
| `APP_NAME` | não | `APR/PT` | Marca exibida no `<title>`, na tela de login e no `/api/health` |
| `APP_URL` | **não usada** | `http://localhost` | Existe só no `.env.example`; **nenhum código lê**. Não confie nela para gerar links |
| `APP_ENV` | **não usada** | `development` | Idem — não lida pelo código |
| `APP_TIMEZONE` | não | `America/Sao_Paulo` | `date_default_timezone_set()` em `bootstrap.php` |
| `STORAGE_PATH` | sim | `storage` | Diretório de arquivos, **relativo à raiz do projeto**. `StorageService::basePath()` faz `dirname(__DIR__,2) . '/' . $path` |

> `STORAGE_PATH` aceita caminho relativo (`storage`) ou absoluto? O código
> monta `raiz + '/' + valor`, então **use relativo**. Mudar para um caminho
> absoluto quebra o `dirname()`.

---

## Banco de dados

| Variável | Padrão | Notas |
|---|---|---|
| `DB_HOST` | `localhost` | |
| `DB_PORT` | `3306` | |
| `DB_NAME` | `apr_pt` | |
| `DB_USER` | `apr_pt` | |
| `DB_PASS` | — | **obrigatória** |

Conexão: `src/Database.php` (Singleton PDO). Erros de conexão aparecem como
`SQLSTATE[HY000]` na resposta da API.

---

## Autenticação (JWT)

| Variável | Padrão | Notas |
|---|---|---|
| `JWT_SECRET` | — | **obrigatória e forte.** Trocar invalida todos os tokens ativos |
| `JWT_EXPIRY` | `43200` | Segundos (12 h). É o mesmo valor do `max-age` do cookie `ap_token` |

Decodificação em `src/Helpers/JWT.php` (`firebase/php-jwt`).
O cliente guarda o token no `localStorage` **e** no cookie `ap_token`.

> Ao migrar mantendo o mesmo `JWT_SECRET`, os tokens continuam válidos até
> expirarem. Trocando o secret, todo mundo é deslogado (comportamento esperado).

---

## Integração IXC Soft

| Variável | Obrigatória | Padrão | Notas |
|---|---|---|---|
| `IXC_BASE_URL` | sim | — | `https://SEU_DOMINIO/webservice/v1` (**sem** barra no fim) |
| `IXC_USER` | sim | — | Usuário da API no IXC |
| `IXC_TOKEN` | sim | — | Token gerado no cadastro do usuário da API |
| `IXC_VERIFY_SSL` | não | `false` | `false` para certificado auto-assinado |
| `IXC_PAGE_SIZE` | não | `100` | Registros por página do listador |

Todos lidos em `src/Services/IxcService.php` (linhas 25–29).
Auth: `Authorization: Basic base64(IXC_USER:IXC_TOKEN)`.

Diagnóstico:

```bash
curl -s http://127.0.0.1:8089/api/health | grep ixc_configurado
# "ixc_configurado": true/false
```

---

## Google Drive — 2ª cópia (OAuth, ativo)

| Variável | Obrigatória | Exemplo/nota |
|---|---|---|
| `GOOGLE_OAUTH_CLIENT_ID` | sim | `173471321889-....apps.googleusercontent.com` |
| `GOOGLE_OAUTH_CLIENT_SECRET` | sim | `GOCSPX-...` |
| `GOOGLE_OAUTH_REDIRECT_URI` | sim | `https://rede.nwt.net.br/oauth/drive/callback` |
| `GOOGLE_DRIVE_FOLDER_ID` | não | ID da pasta de destino; vazio = "Meu Drive" raiz |

- Lidas em `src/Services/GoogleOAuthService.php` (`clientId()`, `clientSecret()`,
  `redirectUri()`). `configurado()` exige **as três**.
- O **redirect URI deve bater caractere a caractere** com o registrado no
  Console Google; senão: `redirect_uri_mismatch`.
- Escopo: `https://www.googleapis.com/auth/drive.file` — o app só enxerga
  arquivos que ele mesmo criou.
- `access_type=offline&prompt=consent` → `refresh_token` permanente.

### Onde ficam os tokens

```
storage/credentials/google-oauth.json   ← NÃO versionado
{ "access_token", "refresh_token", "expires_at", "state", "token_type" }
```

- Dono **`www-data`**, modo **0600**, diretório **0750**.
- ⚠️ **Criado por `root` ⇒ erro "estado inválido"** no callback (o Apache não
  lê). Conserto: `sudo chown www-data:www-data storage/credentials/google-oauth.json`
- `state` é de **uso único**: cada `authorizeUrl()` gera um novo, invalidando o
  anterior.

### Reconectar / desconectar

```bash
# URL de consentimento (admin logado, via API)
curl -H "Authorization: Bearer TOKEN" http://127.0.0.1:8089/api/drive/oauth-url

# Status
curl -H "Authorization: Bearer TOKEN" http://127.0.0.1:8089/api/drive/status

# Desconectar (apaga o token local)
curl -X DELETE -H "Authorization: Bearer TOKEN" http://127.0.0.1:8089/api/drive/oauth
```

Ou na mão:

```bash
php -r 'require "src/bootstrap.php"; echo App\Services\GoogleOAuthService::authorizeUrl(), PHP_EOL;'
```

---

## Google Drive — conta de serviço (fallback, sem cota)

| Variável | Nota |
|---|---|
| `GOOGLE_DRIVE_CREDENTIALS` | Caminho do JSON da conta de serviço. Padrão: `storage/credentials/google-drive.json` |
| `GOOGLE_DRIVE_FOLDER_ID` | Pasta compartilhada com a conta (função Editor) |

⚠️ **Hoje inutilizável para "Meu Drive"**: o Google cortou a cota de
armazenamento de contas de serviço criadas **após 15/04/2025**
(`storageQuotaExceeded — Service Accounts do not have storage quota`). Só
funciona em **unidade compartilhada = Google Workspace pago**. Por isso o
backend ativo é o OAuth.

---

## rclone (fallback, não utilizado)

| Variável | Padrão | Nota |
|---|---|---|
| `RCLONE_BIN` | `rclone` | Binário |
| `RCLONE_CONFIG` | `storage/credentials/rclone.conf` | **não versionar** |
| `RCLONE_REMOTE` | `nwt` | Nome do remote |
| `RCLONE_DIR` | `APR-PT` | Pasta no remote |

O rclone está instalado na máquina (`/usr/bin/rclone`), mas **a integração foi
descartada** — o OAuth com conta pessoal o tornou desnecessário. Mantido apenas
como fallback.

---

## Ordem de prioridade no envio

`DocumentoSstController::encerrar()` e `scripts/enviar_drive.php` tentam, nesta
ordem, e **parem no primeiro que responder**:

1. **`GoogleOAuthService`** ← ativo
2. `DriveService` (conta de serviço)
3. `RcloneService`

Se nada estiver configurado, **o envio é ignorado e nada quebra** (o encerramento
segue normalmente; só não há 2ª cópia).

---

## Segredos — o que NUNCA versionar

Já coberto pelo `.gitignore`, confira com:

```bash
git check-ignore -v .env storage/credentials/google-oauth.json
```

| Arquivo | Conteúdo |
|---|---|
| `.env` | Senhas, JWT secret, token do IXC, client secret do Google |
| `storage/credentials/google-oauth.json` | Refresh token da conta |
| `storage/credentials/google-drive.json` | Chave da conta de serviço |
| `storage/credentials/rclone.conf` | Token rclone |
| `android/*.keystore` | Chave privada do APK |

---

## Gerar um `.env.example` limpo

```bash
sed -E 's/^(JWT_SECRET|DB_PASS|IXC_TOKEN|GOOGLE_OAUTH_CLIENT_SECRET)=.*/\1=/' \
  .env > .env.example
```

Revise o diff antes de commitar — `sed` cega para valores em linhas com
comentário podem apagar mais do que o esperado.

---

## Variáveis de ambiente do sistema (não são do `.env`)

Só o build do Android usa:

| Variável | Uso |
|---|---|
| `ANDROID_HOME` | `ANDROID_HOME=/opt/android-sdk ./gradlew assembleRelease` |

Os crons não leem `.env` diretamente — o script carrega sozinho via `bootstrap.php`.

---

## Diagnóstico rápido de configuração

```bash
cd /var/www/apr-pt

# O que está configurado (sem imprimir segredos)
php -r 'require "src/bootstrap.php";
printf("DB %s@%s:%s/%s\n", $_ENV["DB_USER"], $_ENV["DB_HOST"], $_ENV["DB_PORT"], $_ENV["DB_NAME"]);
printf("IXC configurado: %s\n", (new App\Services\IxcService())->isConfigured() ? "sim" : "NAO");
printf("Drive OAuth configurado: %s\n", App\Services\GoogleOAuthService::configurado() ? "sim" : "NAO");
printf("Drive OAuth conectado: %s\n", App\Services\GoogleOAuthService::conectado() ? "sim" : "NAO");
printf("Conta servico: %s | rclone: %s\n",
  App\Services\DriveService::configurado() ? "sim" : "nao",
  App\Services\RcloneService::configurado() ? "sim" : "nao");
printf("STORAGE: %s\n", App\Services\StorageService::basePath());'
```
