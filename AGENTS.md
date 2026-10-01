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
- **PWA** (offline-first, service worker + IndexedDB) empacotado como APK via TWA/Bubblewrap
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
│   ├── manifest.webmanifest
│   └── service-worker.js
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
```

## Observações

- Projeto **independente** do GeoManager (que fica na raiz de `/var/www`), com git próprio.
- UI e API em português (pt-BR).
- Servido via Apache com mod_rewrite (`.htaccess`).
