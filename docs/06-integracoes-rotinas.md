# 06 — Integrações e rotinas

## 1. IXC Soft (ERP) — somente leitura

### Como funciona

`src/Services/IxcService.php` → cliente HTTP;
`src/Services/SyncService.php` → regras de sincronização.
Base: `IXC_BASE_URL` (ex.: `https://provedor/webservice/v1`).
Auth: `Authorization: Basic base64(IXC_USER:IXC_TOKEN)`.

Listagem: header **`ixcsoft: listar`**, corpo com `qtype`, `query`, `oper`,
`page`, `rp`. Recursos usados: **`su_oss_chamado`** (OS) e **`funcionarios`**.

### O que o sync faz

- `su_oss_chamado` → tabela `ordens_servico` (upsert por `ixc_id` UNIQUE;
  guarda também o `raw_json` original e `sincronizado_em`).
- `funcionarios` → `funcionarios_cache` (para vincular usuário ao técnico).
- Clientes/cidades → caches desnormalizados (a lista funciona **offline**).
- Cada execução grava uma linha em `sync_log` (recurso, total, status,
  mensagem, tempos) — é onde se olha quando "as OS não aparecem".

### Agendamento

`/etc/cron.d/aprpt-sync` (cópia versionada em `scripts/cron.d/aprpt-sync`):

```cron
0,15,30,45 * * * * root /usr/bin/php /var/www/apr-pt/scripts/sync_os.php >> /var/log/aprpt-sync.log 2>&1
```

Manual:

```bash
php scripts/sync_os.php            # hoje
php scripts/sync_os.php 2026-10-01 # data específica
php scripts/sync_os.php 2026-10-01 45  # data + id do técnico
```

### Restrições conhecidas

- A API pública **não expõe anexo de arquivo na OS**. A alternativa é gravar
  observação com link (campo `mensagem`).
- `IXC_VERIFY_SSL=false` é comum (certificado auto-assinado do provedor).

### Diagnóstico

```bash
tail -20 /var/log/aprpt-sync.log
mysql -u apr_pt -p apr_pt -e \
  "SELECT recurso,status,total,mensagem FROM sync_log ORDER BY id DESC LIMIT 5;"
curl -s http://127.0.0.1:8089/api/health | grep ixc
```

---

## 2. Google Drive — segunda cópia do PDF

### Regra de negócio

Ao **encerrar** uma PT (ou recusar, quando gera `cancelada`), o PDF final é
enviado para o Drive e o ID fica em `documentos_sst.pdf_drive_id`.
**Reencerrar atualiza o mesmo arquivo** (conteúdo e nome) — nunca duplica,
porque a busca é pelo `uuid` gravado nos `appProperties` do arquivo.

### Backends (tenta nesta ordem)

| Ordem | Serviço | Estado |
|---|---|---|
| 1º | **`GoogleOAuthService`** | **ativo** — usa a cota da conta Gmail real |
| 2º | `DriveService` (conta de serviço) | inativo — cota zero pós-15/04/2025 |
| 3º | `RcloneService` | desativado por escolha (mantido como fallback) |

Se nenhum estiver configurado, o envio é **ignorado e nada quebra**.

### Por que OAuth e não conta de serviço

O Google zerou a cota de armazenamento de contas de serviço criadas após
**15/04/2025**. Elas só gravam em **unidade compartilhada = Google Workspace
pago**. Como a conta usada é **pessoal**, a solução é OAuth: o app atua em nome
do dono da conta (15 GB dele).

### Fluxo OAuth (detalhado)

```
Admin logado → GET /api/drive/oauth-url → URL de consentimento
   → usuário autoriza no Google (escopo drive.file)
   → Google redireciona https://<host>/oauth/drive/callback?code=…&state=…
   → html/oauth/index.php valida o state e troca o code por tokens
   → grava storage/credentials/google-oauth.json (access + refresh)
```

- O redirect URI é o que estiver em `GOOGLE_OAUTH_REDIRECT_URI` e **precisa
  estar cadastrado no Console Google**, caractere a caractere.
- `state` é **de uso único** (cada URL nova invalida a anterior).
- O `access_token` renova sozinho pelo `refresh_token` (que não expira com o
  app publicado).

### Consent screen — configuração única (no Console Google)

1. **APIs e serviços → Tela de permissão OAuth**: tipo **Externo**; adicionar
   a conta em **Usuários de teste**; depois **Publicar app** (sem isso o
   refresh token expira em 7 dias).
2. **Credenciais → Criar → ID do cliente OAuth → Aplicativo Web**:
   adicionar o redirect URI.
3. **Biblioteca → Google Drive API**: ativar.

### Nome do arquivo no Drive

`OS-<ixc_id>.pdf` — o mesmo do disco e do download (ver
[01-visao-geral-arquitetura.md](01-visao-geral-arquitetura.md)).

### Conferência e reparo (cron diário)

`scripts/verificar_drive.php`, cron `/etc/cron.d/aprpt-verifica-drive`
(03:30, user **`www-data`**), log `/var/log/aprpt-verifica-drive.log`.

Para cada PT **encerrada** ele confere:
1. tem `pdf_drive_id`?
2. o arquivo ainda existe no Drive? (alguém pode apagar a pasta)
3. o nome no Drive é o mesmo do servidor?

O que estiver errado é **reenviado na hora** e registrado em `log_auditoria`
(`drive_backup_reparado` / `drive_backup_reparo_falhou`).

Códigos de saída: `0` ok · `1` sem nuvem configurada · `2` não conseguiu ler
o Drive (aborta sem reparar por cima) · `3` ainda tem problema.

```bash
php scripts/verificar_drive.php --dry-run   # só mostra
php scripts/verificar_drive.php            # repara
```

### Backfill

```bash
php scripts/enviar_drive.php          # só as que faltam
php scripts/enviar_drive.php --all    # reenvia/atualiza todas as encerradas
```

### Renomeação (migração histórica)

`scripts/renomear_pdf_os.php` renomeia os arquivos antigos
(`apr_pt_<uuid>.pdf` → `OS-<ixc_id>.pdf`) e corrige o `pdf_path`.
Depois, `enviar_drive.php --all` atualiza os nomes no Drive.

### Armadilhas já encontradas

| Sintoma | Causa | Conserto |
|---|---|---|
| "estado inválido" no callback | token gravado por `root`; Apache não lê | `chown www-data:www-data storage/credentials/google-oauth.json` |
| `redirect_uri_mismatch` | URI fora do console, ou com espaço/barra extra | conferir no Console |
| `access_denied` | app em modo teste e conta não é test user | adicionar conta em Usuários de teste |
| `storageQuotaExceeded` | conta de serviço (cota zero) | usar OAuth (é o fluxo atual) |
| autorização expira em 7 dias | app não publicado | **Publicar app** no console |
| duplicou ao reenviar | `appProperties.uuid` perdido | conferir `enviarPdf()` — faz update, não create |

---

## 3. Android (APK) e PWA

### O APK

Projeto em `android/` — **WebView nativa** carregando
`http://177.125.60.123:8089/`. As telas vêm do servidor: mudanças na web
chegam sem reinstalar. O APK leva uma cópia do casco (`assets/site`) para
abrir **offline**; com rede, o servidor é sempre a fonte.

Recursos nativos tratados no `MainActivity.java`:

- PDF e APK de atualização: baixados pelo `DownloadManager` (com o cookie de
  sessão) e abertos no app correspondente;
- links externos (e-mail, telefone, outros sites) abrem fora;
- página "sem conexão" com botão de tentar de novo;
- botão Voltar navega no histórico;
- **upload de foto** (`<input type=file>`) com câmera/galeria via
  `onShowFileChooser` + `FileProvider`.

### Gerar e publicar

```bash
cd android
export ANDROID_HOME=/opt/android-sdk
# 1. suba versionCode/versionName em app/build.gradle
./gradlew assembleRelease
cp app/build/outputs/apk/release/app-release.apk ../html/apk/app.apk
# 2. atualize html/apk/versao.json (versao, versionCode, tamanho, novidades)
```

O app compara `versao.json` com `BuildConfig.VERSION_NAME` e mostra o banner
de atualização (`window.AprptApp.versao()` identifica o app nativo).

- **Keystore:** `android/android.keystore` + senha em
  `android/keystore.properties` — ambos **fora do git**. Sem a chave o Android
  recusa atualizar o app instalado.
- O APK publicado em `html/apk/app.apk` também está fora do git.

### Offshore (offline)

Escrito em `html/assets/js/offline.js`: OS do dia, catálogo e a APR/PT
preenchida ficam em `localStorage`; sem internet o técnico trabalha normal e a
fila é enviada por `POST /api/documentos/sync` quando a conexão volta
(idempotente por `uuid`).

### PWA

`html/service-worker.js` (network-first, cache `aprpt-shell-vN`) +
`html/manifest.webmanifest`. O service worker **nunca** vem do cache HTTP
(`.htaccess` força `no-store`), e o `index.php` é sempre revalidado.

---

## 4. Resumo das rotinas automáticas

| Rotina | Quando | O quê | Log |
|---|---|---|---|
| `sync_os.php` | a cada 15 min | OS do dia do IXC | `/var/log/aprpt-sync.log` |
| `verificar_drive.php` | diário 03:30 | confere/repara a 2ª cópia | `/var/log/aprpt-verifica-drive.log` |
| `enviar_drive.php` | manual | backfill da 2ª cópia | saída no terminal |
| `renomear_pdf_os.php` | manual (migração) | padroniza nome dos PDFs | saída no terminal |

Restaurar os crons (cópias versionadas):

```bash
cp /var/www/apr-pt/scripts/cron.d/aprpt-* /etc/cron.d/
chmod 644 /etc/cron.d/aprpt-*
```

---

## 5. Variáveis de integração

Todas no `.env` — detalhes em
[03-configuracao-variaveis.md](03-configuracao-variaveis.md):

- **IXC**: `IXC_BASE_URL`, `IXC_USER`, `IXC_TOKEN`, `IXC_VERIFY_SSL`, `IXC_PAGE_SIZE`
- **Drive OAuth**: `GOOGLE_OAUTH_CLIENT_ID`, `GOOGLE_OAUTH_CLIENT_SECRET`,
  `GOOGLE_OAUTH_REDIRECT_URI`, `GOOGLE_DRIVE_FOLDER_ID`
- **Conta de serviço (fallback)**: `GOOGLE_DRIVE_CREDENTIALS`, `GOOGLE_DRIVE_FOLDER_ID`
- **rclone (reserva)**: `RCLONE_BIN`, `RCLONE_CONFIG`, `RCLONE_REMOTE`, `RCLONE_DIR`
