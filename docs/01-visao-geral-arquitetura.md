# 01 — Visão geral e arquitetura

## O que é o sistema

APR/PT é um sistema web multi-usuário de **Segurança do Trabalho (SST)** para
equipes de campo de provedor de internet. Ele produz um **documento único** que
agrupa duas exigências das Normas Regulamentadoras:

- **APR** — Análise Preliminar de Risco (NR-10 elétrica, NR-35 altura)
- **PT** — Permissão de Trabalho (autorização formal antes de executar)

Cada APR/PT está **vinculada a uma Ordem de Serviço (OS)** vinda do ERP
**IXC Soft** (integração somente leitura). O técnico preenche e assina **no
campo, offline**; o supervisor autoriza e assina; ao final a PT é **encerrada**,
gerando o PDF final — que também recebe uma **2ª cópia no Google Drive**.

## Princípios de projeto

| Princípio | Como se manifesta |
|---|---|
| **Sem framework** | MVC artesanal: Controllers → Models → PDO. Sem ORM, sem migrations automáticas. |
| **Documento único** | Uma tabela `documentos_sst` concentra APR + PT; nada de tabelas separadas por norma. |
| **Idempotência por `uuid`** | O app offline gera um `uuid` local; reenviar o mesmo documento nunca duplica (`POST /api/documentos/sync`). |
| **Offline-first** | O APK guarda cópia do casco web; dados preenchidos sem rede vão para uma fila e subem quando a conexão volta. |
| **Integração como fonte, não como dono** | O IXC alimenta `ordens_servico`; o sistema nunca escreve no IXC. |
| **Falha na nuvem não quebra o fluxo** | Se o Drive não responder, a PT encerra normalmente; o erro vai para log e para o verificador diário. |

## Arquitetura em camadas

```
┌──────────────────────────────────────────────────────────────┐
│  CLIENTES                                                    │
│  · SPA (vanilla JS)      html/assets/js/{app,api,auth}.js    │
│  · PWA (service worker)  html/service-worker.js              │
│  · APK Android (WebView nativa)  android/                    │
└───────────────┬──────────────────────────────────────────────┘
                │  HTTP + Authorization: Bearer <JWT>
┌───────────────▼──────────────────────────────────────────────┐
│  APACHE  (mod_rewrite + mod_proxy)                           │
│  · porta 8089  → DocumentRoot /var/www/apr-pt/html           │
│  · :443 /oauth/ → ProxyPass → 127.0.0.1:8089  (callback OAuth)│
└───────────────┬──────────────────────────────────────────────┘
                │
┌───────────────▼──────────────────────────────────────────────┐
│  html/api/index.php  — roteador (34 rotas, regex nomeada)    │
│  └── Middleware: CORS → JWT → papel (tecnico/supervisor/admin)│
└───────────────┬──────────────────────────────────────────────┘
                │
┌───────────────▼──────────────────────────────────────────────┐
│  CONTROLLERS   src/Controllers/     (regras, permissões)     │
│  SERVICES      src/Services/        (IXC, PDF, Drive, OAuth) │
│  MODELS        src/Models/          (SQL via PDO)            │
│  HELPERS       src/Helpers/         (JWT, Response, Validator│)
└───────────────┬──────────────────────────────────────────────┘
                │
     ┌──────────┴───────────┬──────────────────┐
     ▼                      ▼                  ▼
 MariaDB (apr_pt)    storage/            Serviços externos
 18 tabelas          · pdfs/             · API IXC Soft
                      · assinaturas/     · Google OAuth/Drive
                      · anexos/          · (rclone / conta de serviço)
                      · credentials/       — como fallback
```

## Estrutura de diretórios

```
/var/www/apr-pt/
├── src/
│   ├── Controllers/        # 9 controllers — lógica das rotas
│   ├── Models/             # User, OrdemServico, DocumentoSst, Assinatura, Log
│   ├── Services/           # IxcService, SyncService, PdfService,
│   │                        # StorageService, DriveService,
│   │                        # GoogleOAuthService, RcloneService
│   ├── Helpers/            # JWT, Response, Validator
│   ├── Database.php        # Singleton PDO
│   ├── Middleware.php      # CORS, JWT, papéis
│   └── bootstrap.php       # autoload + dotenv + requires (entry point)
├── html/
│   ├── api/index.php       # roteador — 34 rotas
│   ├── api/.htaccess       # rewrite → index.php
│   ├── index.php           # SPA (shell de login + app)
│   ├── oauth/index.php     # callback OAuth do Google Drive
│   ├── views/  assets/  apk/  manifest.webmanifest  service-worker.js
│   └── .htaccess           # rewrite da SPA + regra /oauth/
├── migrations/             # 21 arquivos .sql (001..021) — aplicados na mão
├── scripts/                # sync_os, enviar_drive, verificar_drive, renomear_pdf_os
│   └── cron.d/             # cópias versionadas dos crons
├── storage/                # runtime — NÃO versionado (exceto .gitkeep)
│   ├── pdfs/  assinaturas/  anexos/  credentials/
├── android/                # projeto do APK nativo (WebView)
├── vendor/                 # composer (não versionado)
├── docs/                   # esta documentação
├── .env                    # segredos (não versionado)
└── composer.json
```

## O request, passo a passo

1. **Apache** resolve o caminho. `html/.htaccess` manda `api/` para
   `html/api/`, `oauth/` para `html/oauth/` e o resto para a SPA (`index.php`).
2. **`html/api/index.php`** carrega `src/bootstrap.php` (autoload + `.env`),
   chama `Middleware::cors()` e registra as rotas.
3. O roteador compara `METHOD + URI` contra padrões com `{param}`
   (`preg_replace` vira `([^/]+)`) e invoca o handler.
4. O **controller** chama `Middleware::requireAuth()` / `requireAdmin()`,
   valida com `Validator` e repassa para o **Model**.
5. O **Model** executa SQL via `Database::getInstance()` (PDO singleton) e
   devolve arrays.
6. A resposta sai por `Response::success()` / `Response::error()` em JSON.

### Autenticação

- Login (`POST /api/auth/login`) devolve um **JWT** (`firebase/php-jwt`),
  validade `JWT_EXPIRY` (padrão 43200 s = 12 h).
- O cliente guarda no `localStorage` **e** no cookie `ap_token`
  (`SameSite=Lax`, 12 h) — o cookie serve para rotas abertas em navegação
  normal; o `localStorage` serve para a SPA.
- `Middleware::getAuthUser()` aceita o token pelo header `Authorization`,
  por `REDIRECT_HTTP_AUTHORIZATION` (caso o Apache não repasse o header) ou
  pelo cookie.

### Papéis

| Papel | Alcance |
|---|---|
| `tecnico` | Vê e preenche **as suas** OS/APR-PT |
| `supervisor` | Vê todas, autoriza/encerra PT, cria técnicos |
| `admin` | Tudo + usuários, catálogo/modelo, sync manual, Drive |

A trava é sempre no **backend** (`Middleware::requireAdmin()`); o frontend só
esconde os botões. **Não há isolamento por tenant** — é um único CNPJ/usuário.

## Fluxo de vida de uma PT

```
 rascunho ──assinatura do técnico──▶ aguardando_aprovacao
    │                                        │
    │                                        ▼
    │                        ┌────── liberada (supervisor autorizou)
    │                        │             │
    │                        │             ▼
    │                        │       em_execucao ────┐
    │                        │                       │
    │◀── PT não autorizada ──┘                       ▼
    │                                          encerrada ──▶ PDF + 2ª cópia Drive
    ▼
 cancelada
```

Pontos críticos do fluxo:

1. **Assinatura do técnico** (`POST /documentos/{id}/assinar`) → muda para
   `aguardando_aprovacao` e gera o PDF da prévia.
2. **Autorização do supervisor** (`POST /documentos/{id}/aprovar`) →
   `liberada` (ou `cancelada` se recusada); regenera o PDF com a assinatura.
3. **Encerramento** (`POST /documentos/{id}/encerrar`) → `encerrada`;
   gera o PDF final **e dispara a 2ª cópia no Google Drive**.
4. Reencerrar **não duplica** no Drive: a busca é pelo `uuid` gravado em
   `appProperties`, então atualiza o mesmo arquivo (conteúdo e nome).

### Integridade do documento

- `documentos_sst.hash_documento` guarda o hash do conteúdo
  (`DocumentoSst::calcularHash()`) — detecta adulteração.
- O PDF é regerado **sob demanda** a cada visualização/encerramento, sempre
  a partir do banco. O arquivo em `storage/pdfs/` é cache; se sumir, é
  recriado. Por isso **apagar o PDF local não perde nada** (o Drive guarda a
  cópia do momento do encerramento).

## Nomenclatura dos arquivos

O PDF é gravado como **`storage/pdfs/OS-<ixc_id>.pdf`** — o mesmo número que
aparece na tela (`#170742`). Vale para disco, download e nome no Drive.

Regra em `PdfService::nomeArquivo()`:

- Sem `ixc_id` → cai para `apr_pt_<uuid>.pdf`
- Outra PT já ocupando o nome → `OS-<ixc_id>_<8 primeiros do uuid>.pdf`
  (o banco **não** garante 1 PT por OS)

## Pontos de extensão

| Para adicionar… | Onde mexer |
|---|---|
| Rota nova | `html/api/index.php` + controller em `src/Controllers/` |
| Coluna/tabela | `migrations/0NN_descricao.sql` (aplicar **na mão**, não há runner) |
| Item de catálogo (risco, EPI, EPC, checklist, ferramenta) | Painel *Modelo APR/PT* (admin) — sem mexer em código |
| Backend de nuvem | Implementar `enviarPdf(caminho, nome, uuid)` e enfileirar em `DocumentoSstController::encerrar()` e `scripts/enviar_drive.php` |
| Norma (NR) nova | `migrations/010_nr_multipla.sql` define a coluna `nr` (CSV de normas) |

## Restrições conhecidas

- **IXC**: a API pública **não expõe anexo de arquivo na OS** — a alternativa
  registrada é gravar observação com link (campo `mensagem`).
- **Sem testes automatizados** e sem CI: toda validação é manual.
- **Sem multi-empresa**: um único banco atende um único provedor.
- **Uploads**: limitados a 12 M (`upload_max_filesize`) via `.htaccess`.
