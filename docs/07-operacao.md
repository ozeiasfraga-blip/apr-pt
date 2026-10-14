# 07 — Operação e troubleshooting

## Onde olhar primeiro

| Sintoma | Vá para |
|---|---|
| Tela branca/erro na SPA | console do navegador + `/var/log/apache2/apr-port-error.log` |
| Erro de API | resposta JSON (`message`) + log de erro do Apache |
| OS não aparecem | `sync_log` + `/var/log/aprpt-sync.log` |
| PDF não gera/não abre | `storage/pdfs/` e o log de erro (erro do Dompdf) |
| Drive sem cópia | `/var/log/aprpt-verifica-drive.log` + `log_auditoria` |
| Problema só no APK | diferenças de WebView (ver seção própria) |

---

## 1. Logs

| Log | O que tem |
|---|---|
| `/var/log/apache2/apr-port-access.log` | toda requisição (porta 8089) |
| `/var/log/apache2/apr-port-error.log` | erros PHP, avisos, stack traces |
| `/var/log/aprpt-sync.log` | sincronização IXC |
| `/var/log/aprpt-verifica-drive.log` | verificação/reparo da 2ª cópia |
| `log_auditoria` (banco) | quem fez o quê (assinar, encerrar, usuários, Drive) |
| `sync_log` (banco) | cada execução do sync do IXC |

```bash
tail -f /var/log/apache2/apr-port-error.log
tail -50 /var/log/aprpt-verifica-drive.log

# últimos erros PHP (sem stack trace gigante)
grep -h "PHP Fatal\|PHP Warning" /var/log/apache2/apr-port-error.log | tail -20

# auditoria recente
mysql -u apr_pt -p apr_pt -e \
  "SELECT created_at,user_id,acao,entidade,entidade_id FROM log_auditoria ORDER BY id DESC LIMIT 20;"
```

---

## 2. Diagnóstico em 10 segundos

```bash
cd /var/www/apr-pt

# App responde?
curl -s http://127.0.0.1:8089/api/health

# Consegue logar? (troque credenciais)
curl -s -X POST http://127.0.0.1:8089/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"SEU_EMAIL","password":"SUA_SENHA"}'

# Sintaxe de tudo
find src html scripts -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v 'No syntax' || echo "sintaxe OK"
node --check html/assets/js/app.js && echo "JS OK"

# O que está configurado
php -r 'require "src/bootstrap.php";
printf("DB %s@%s/%s\n", $_ENV["DB_USER"], $_ENV["DB_HOST"], $_ENV["DB_NAME"]);
printf("IXC: %s | Drive config: %s | Drive conectado: %s\n",
  (new App\Services\IxcService())->isConfigured() ? "ok" : "NAO",
  App\Services\GoogleOAuthService::configurado() ? "ok" : "NAO",
  App\Services\GoogleOAuthService::conectado() ? "ok" : "NAO");'
```

---

## 3. Problemas comuns

### SPA abre mas nada funciona (404 em tudo)

- `AllowOverride All` ausente no vhost → `.htaccess` ignorado.
- módulo `rewrite` desligado: `sudo a2enmod rewrite && sudo systemctl reload apache2`.

### Erro 401 "Não autorizado" mesmo logado

- Token expirado (`JWT_EXPIRY`, padrão 12 h) → faça login de novo.
- `JWT_SECRET` mudou → todos os tokens antigos são inválidos.
- Header `Authorization` cortado pelo Apache: o backend já cobre
  `REDIRECT_HTTP_AUTHORIZATION`; confira o `SetEnvIf`/proxy se usar reverse proxy.

### Login devolve 403 "Usuário inativo"

- Usuário foi **desativado** (ex.: exclusão com histórico). Reative em
  **Usuários → filtro Inativos → Editar → Ativo**.

### Excluir usuário "não some"

- Com histórico, a exclusão **desativa** (é intencional). O usuário sai da
  visão padrão (filtro `Ativos`). Para excluir de fato não há como — o
  histórico de assinaturas é legal e não pode ser apagado.

### PDF não gera

```bash
sudo -u www-data php -r 'require "src/bootstrap.php";
  $d = App\Models\DocumentoSst::getById(25);
  echo App\Services\PdfService::gerarDocumento($d), PHP_EOL;'
```

- Permissão de `storage/pdfs` (dono deve ser `www-data`).
- Fontes do Dompdf: vem no `vendor/`; se `composer install` foi incompleto,
  rode de novo.
- Assinaturas com caminho apontando para arquivo inexistente aparecem como
  imagem quebrada — é cosmético, o PDF gera.

### PDF local sumiu

Não é problema: o PDF é **cache**. A próxima visualização regenera a partir do
banco; o Drive guarda a cópia do momento do encerramento.

### Drive: "estado inválido" no callback

Token criado por outro usuário do SO (ex.: `root`) — o Apache não lê:

```bash
sudo chown www-data:www-data /var/www/apr-pt/storage/credentials/google-oauth.json
sudo chmod 600 /var/www/apr-pt/storage/credentials/google-oauth.json
```

### Drive: `redirect_uri_mismatch`

O valor de `GOOGLE_OAUTH_REDIRECT_URI` tem que ser **idêntico** ao cadastrado
no Console (sem barra extra, sem espaço). Confira também o bloco
`ProxyPass /oauth/` no vhost HTTPS.

### Drive: `access_denied`

App em modo teste e a conta não está em **Usuários de teste** — adicione em
**Público-alvo** ou **publique o app**.

### Drive: autorização cai depois de 7 dias

App não publicado (modo teste). No console: **Público-alvo → Publicar app**.

### Verificador do Drive reclama mas o arquivo existe

Pode ser o **nome** diferente (o verificador compara com `pdf_path`). Rode:

```bash
php scripts/enviar_drive.php --all   # renomeia/atualiza no Drive
```

### IXC devolve 403/erro

- `IXC_USER`/`IXC_TOKEN` errados ou revogados no IXC.
- `IXC_BASE_URL` sem `/webservice/v1` ou com barra no fim.
- Certificado auto-assinado: mantenha `IXC_VERIFY_SSL=false`.

### No APK a foto de perfil não abre câmera/galeria

- Precisa do APK **1.3.2+** (tem `onShowFileChooser` + FileProvider).
- Se instalou por cima de uma versão antiga assinada com outra chave, o
  Android recusa — confira o keystore.

### Upload falha ("arquivo grande")

`.htaccess` (html/): `upload_max_filesize 12M`, `post_max_size 14M`.
A foto de perfil é reduzida a 512px antes de subir (limite de 2 MB no servidor).

---

## 4. Backup

| O que | Como |
|---|---|
| Banco | `mysqldump --single-transaction -u apr_pt -p apr_pt > dump.sql` |
| Arquivos | `rsync -a storage/ /backup/apr-pt-storage/` |
| Código | git (o repo é a fonte) |
| Segredos | `.env`, `storage/credentials/*`, `android/keystore.properties` (+ keystore) |

Sugestão de cron:

```cron
0 3 * * * root mysqldump --single-transaction -u apr_pt -pSENHA apr_pt | gzip > /backup/apr_pt_$(date +\%F).sql.gz
15 3 * * * root rsync -a /var/www/apr-pt/storage/ /backup/apr-pt-storage/
```

**Restauração:** `mysql ... < dump.sql`, devolver `storage/`, restaurar `.env`
e crons. Passo a passo completo em
[02-migracao-nova-maquina.md](02-migracao-nova-maquina.md).

---

## 5. Atualizações seguras

```bash
cd /var/www/apr-pt
git pull                     # código (não mexe em .env/storage)
composer install --no-dev    # se composer.json mudou
# migrations novas (aplicar manualmente, veja docs/04):
# mysql -u apr_pt -p apr_pt < migrations/0NN_....sql
sudo chown -R www-data:www-data storage
sudo systemctl reload apache2
```

- O git **não** toca `.env` nem `storage/` (ignorados).
- Depois de deploy, teste: `/api/health` e um login.

---

## 6. Comandos de banco úteis

```sql
-- PTs por status
SELECT status, COUNT(*) FROM documentos_sst GROUP BY status;

-- Encerradas sem cópia no Drive
SELECT id, uuid, pdf_path FROM documentos_sst
 WHERE status='encerrada' AND (pdf_drive_id IS NULL OR pdf_drive_id='');

-- Últimas sincronizações
SELECT recurso,status,total,mensagem,finalizado_em FROM sync_log ORDER BY id DESC LIMIT 10;

-- Auditoria de nuvem
SELECT acao,entidade_id,detalhes,created_at FROM log_auditoria
 WHERE acao LIKE 'drive_%' ORDER BY id DESC;

-- Sessões ativas (tokens)
SELECT COUNT(*) FROM auth_tokens WHERE expires_at > NOW();
```

---

## 7. Contatos e referências internas

- Arquitetura: [01-visao-geral-arquitetura.md](01-visao-geral-arquitetura.md)
- Migração: [02-migracao-nova-maquina.md](02-migracao-nova-maquina.md)
- Configuração: [03-configuracao-variaveis.md](03-configuracao-variaveis.md)
- Banco: [04-banco-de-dados.md](04-banco-de-dados.md)
- API: [05-api.md](05-api.md)
- Integrações e rotinas: [06-integracoes-rotinas.md](06-integracoes-rotinas.md)
