# APR/PT — Guia para Agentes

## Projeto

Sistema de **Segurança do Trabalho (SST)** para telecom: **APR** (Análise Preliminar de Risco)
e **PT** (Permissão de Trabalho) em um **documento único**, vinculado às **Ordens de Serviço (OS)**
dos técnicos. Integra-se de forma **somente leitura** ao ERP **IXC Soft** (API WebService v1).

- **Técnico** preenche a APR/PT no app (funciona **offline**) e assina.
- **Supervisor** assina a PT (no local, no aparelho do técnico, ou remotamente).
- Normas contempladas inicialmente: **NR-10** (elétrica) e **NR-35** (trabalho em altura).

## Stack

- **PHP** 8.2 (sem framework, MVC próprio)
- **MySQL/MariaDB** com PDO (sem ORM)
- **Vanilla JS** (sem framework frontend)
- **App Android nativo** (WebView em tela cheia, projeto em `android/`) distribuído como APK
- **PWA** (service worker + manifest) para acesso pelo navegador
- **JWT** (`firebase/php-jwt`), **dotenv** (`vlucas/phpdotenv`), **Dompdf** (PDF)

## Estrutura

```
/var/www/apr-pt/
├── src/
│   ├── Controllers/      # Lógica das rotas da API
│   ├── Models/           # Queries SQL (PDO)
│   ├── Services/         # Integrações (IxcService)
│   ├── Helpers/          # JWT, Response, Validator
│   ├── Database.php      # Conexão Singleton PDO
│   ├── Middleware.php    # CORS, JWT auth, permissões
│   └── bootstrap.php     # Entry point
├── html/
│   ├── api/index.php     # Roteador da API
│   ├── index.php         # SPA entry
│   ├── views/            # Páginas
│   ├── assets/js|css/    # JS e CSS
│   ├── apk/              # APK publicado, página de download e versao.json
│   ├── manifest.webmanifest
│   └── service-worker.js
├── android/              # Projeto do app Android nativo (WebView) + keystore
├── migrations/           # Schema SQL
├── scripts/              # Cron (ex: sync_os.php)
├── storage/              # PDFs, assinaturas e anexos (runtime)
├── vendor/               # Composer
├── composer.json
└── .env
```

## Arquivos-chave

| Arquivo | Propósito |
|---|---|
| `html/api/index.php` | Rotas da API |
| `src/Services/IxcService.php` | Cliente da API do IXC (leitura) |
| `src/Models/OrdemServico.php` | Espelho das OS do IXC |
| `src/Models/DocumentoSst.php` | Documento único APR/PT |
| `scripts/sync_os.php` | Sincroniza as OS do dia (cron) |
| `migrations/001_init.sql` | Schema inicial |
| `android/app/src/main/java/br/net/nwt/aprpt/MainActivity.java` | App Android nativo (WebView) |
| `android/README.md` | Como gerar e publicar o APK |
| `html/apk/versao.json` | Versão publicada do APK (usada pelo aviso de atualização) |

## Níveis de acesso

| Função | Técnico | Supervisor | Admin |
|---|---|---|---|
| Ver OS e APR/PT, preencher e assinar | próprias | todas | todas |
| Autorizar (liberar) e encerrar PT | — | ✅ | ✅ |
| Criar usuário **técnico** | — | ✅ | ✅ |
| Editar, redefinir senha ou excluir usuários | — | — | ✅ |
| Criar supervisor / mexer em admin | — | — | ✅ |
| Modelo APR/PT (catálogo e textos) | — | — | ✅ |
| Sincronização manual do IXC | — | — | ✅ |

Supervisor enxerga somente técnicos na lista de usuários (`UserController`
força o filtro) e usa a lista de funcionários do IXC só para vincular o
técnico novo. A trava é no backend (`requireAdmin`); o frontend só esconde.

## App Android (APK)

- Projeto em `android/`: app nativo com WebView própria que carrega
  `http://177.125.60.123:8089/` em tela cheia (sem barra de navegador e sem depender
  do Chrome). Como as telas vêm do servidor, mudanças na web chegam automaticamente.
- **Gerar:** `cd android && ANDROID_HOME=/opt/android-sdk ./gradlew assembleRelease`
- **Publicar:** copie `app/build/outputs/apk/release/app-release.apk` para
  `html/apk/app.apk`, incremente `versionCode`/`versionName` em `android/app/build.gradle`
  e atualize `html/apk/versao.json` (o app avisa os usuários da nova versão).
- **Keystore:** `android/android.keystore` (alias `android`, senha `AprPT@2026`) —
  **não versionada**. Sem ela o Android recusa atualizar o app instalado.
- **Offline:** o APK leva uma cópia do casco (HTML/CSS/JS) gerada por
  `android/tools/sync-web.sh`; OS do dia e catálogo ficam em cache no aparelho e
  a APR/PT preenchida sem internet entra numa fila, enviada por
  `POST /api/documentos/sync` quando a conexão volta.
- O download fica em `http://177.125.60.123:8089/apk/`.

## Integração IXC (WebService v1)

- Base: `https://<host>/webservice/v1/<recurso>`
- Auth: `Authorization: Basic base64(<IXC_USER>:<IXC_TOKEN>)`
- Listagem: header `ixcsoft: listar`, corpo com `qtype`/`query`/`oper`/`page`/`rp`
- Recurso principal: `su_oss_chamado` (ordens de serviço); também `funcionarios`, `su_equipe`
- **Restrições conhecidas:** a API pública **não expõe anexo de arquivo na OS**;
  a alternativa é gravar uma observação com link (via `mensagem`) para o PDF hospedado aqui.

## Commits

**Para toda seção de trabalho concluída com sucesso, faça um novo commit.** Mensagens claras e
concisas, no imperativo (pt-BR ou inglês). Commits atômicos.

```bash
php -l <file>              # Verificar sintaxe PHP
node --check <file>.js     # Verificar sintaxe JS
composer install           # Instalar dependências
php scripts/sync_os.php    # Sincronizar OS do dia (manual)
cd android && ANDROID_HOME=/opt/android-sdk ./gradlew assembleRelease  # Gerar o APK
```

## Observações

- Projeto **independente** do GeoManager (que fica na raiz de `/var/www`), com git próprio.
- UI e API em português (pt-BR).
- Servido via Apache com mod_rewrite (`.htaccess`).
- A sincronização das OS do IXC roda automaticamente por cron em
  `/etc/cron.d/aprpt-sync` (a cada 15 minutos; cópia versionada em
  `scripts/cron.d/aprpt-sync`). Se o cron for perdido, restaure com:
  `cp scripts/cron.d/aprpt-sync /etc/cron.d/ && chmod 644 /etc/cron.d/aprpt-sync`.
- Os itens do catálogo (`catalogo_riscos`, `catalogo_epis`, `catalogo_epc`,
  `catalogo_checklist`) aceitam **várias NRs** na coluna `nr`, separadas por
  vírgula (ex.: `NR-10,NR-35`). O formulário marca o item quando **qualquer uma**
  das normas dele estiver selecionada.
- Ferramentas: `catalogo_ferramentas.padrao = 1` vem **pré-marcada** em documento
  novo; cada risco pode apontar `ferramentas` (códigos, ex.: Animais peçonhentos →
  `FR-20`) e a ferramenta é marcada junto quando o risco é marcado (só acrescenta,
  nunca desmarca); o **mínimo para assinar** fica em
  `config_textos.ferramentas_minimas` (editável em Modelo APR/PT → Textos).
- Risco também pode apontar **EPIs associados** (`catalogo_riscos.epis`, códigos
  separados por vírgula — ex.: Ruído e vibração → `EPI-10`), marcados junto quando
  o risco é marcado (mesma regra: só acrescenta). Ambos editáveis no próprio campo
  do risco em Modelo APR/PT → Riscos.
