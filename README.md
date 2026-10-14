# APR/PT

Sistema de **APR** (Análise Preliminar de Risco) e **PT** (Permissão de Trabalho) para equipes de
campo de provedores de internet, integrado ao ERP **IXC Soft**.

## Visão geral

- Um **documento único** de APR+PT por **Ordem de Serviço (OS)** do técnico.
- O técnico preenche e assina no aplicativo (**funciona offline**).
- O supervisor assina a PT (no local ou remotamente).
- Os dados da OS vêm do IXC via API (somente leitura).
- Ao encerrar, o PDF final recebe uma **2ª cópia no Google Drive**.

## Documentação

| Documento | Para que serve |
|---|---|
| [`docs/`](docs/README.md) | Índice geral da documentação técnica |
| [`docs/01-visao-geral-arquitetura.md`](docs/01-visao-geral-arquitetura.md) | Arquitetura, camadas, fluxo da PT |
| [`docs/02-migracao-nova-maquina.md`](docs/02-migracao-nova-maquina.md) | **Migrar para outra VPS/servidor** |
| [`docs/03-configuracao-variaveis.md`](docs/03-configuracao-variaveis.md) | Todas as variáveis do `.env` |
| [`docs/04-banco-de-dados.md`](docs/04-banco-de-dados.md) | Tabelas, relações e migrações |
| [`docs/05-api.md`](docs/05-api.md) | Rotas da API e permissões |
| [`docs/06-integracoes-rotinas.md`](docs/06-integracoes-rotinas.md) | IXC, Google Drive, APK/PWA, crons |
| [`docs/07-operacao.md`](docs/07-operacao.md) | Logs, backup, troubleshooting |
| [`docs/modelo-apr-pt.md`](docs/modelo-apr-pt.md) | Modelo de referência da APR/PT (NR-10/NR-35) |

Convenções de código e infraestrutura: [`AGENTS.md`](AGENTS.md).

## Requisitos

- PHP 8.1+ com extensões `pdo_mysql`, `mbstring`, `openssl`, `curl`, `gd`, `xml`
- MySQL 8+ / MariaDB 10.4+
- Apache com `mod_rewrite` (e `mod_proxy` se usar o callback OAuth por HTTPS)
- Composer

## Instalação rápida

```bash
cp .env.example .env       # e edite as credenciais
composer install
# banco (as 22 migrations, em ordem):
for f in migrations/*.sql; do mysql -u apr_pt -p apr_pt < "$f"; done
```

Guia completo (produção/migração): [`docs/02-migracao-nova-maquina.md`](docs/02-migracao-nova-maquina.md).

## Sincronização das OS

```bash
php scripts/sync_os.php            # OS de hoje
php scripts/sync_os.php 2026-10-01 # data específica
```

Agendada em cron (a cada 15 minutos — veja `docs/06-integracoes-rotinas.md`).

## 2ª cópia do PDF (Google Drive)

```bash
# conectar (admin, pela tela Modelo APR/PT) ou gerar a URL:
php -r 'require "src/bootstrap.php";
  echo App\Services\GoogleOAuthService::authorizeUrl(), PHP_EOL;'

# backfill das PTs encerradas
php scripts/enviar_drive.php          # só as que faltam
php scripts/enviar_drive.php --all    # reenvia/atualiza todas

# conferência diária (cron) — confere e repara
php scripts/verificar_drive.php
```

## Estrutura

```
src/          Backend PHP (Controllers, Models, Services, Helpers)
html/         SPA, API (/api), assets, APK publicado, callback OAuth
migrations/   Schema SQL (aplicar manualmente)
scripts/      Cron (sync, Drive) + cópias dos crons em scripts/cron.d
storage/      PDFs, assinaturas, fotos e credenciais (runtime — fora do git)
android/      Projeto do app Android (WebView nativa)
docs/         Documentação
```

Detalhes em [`docs/01-visao-geral-arquitetura.md`](docs/01-visao-geral-arquitetura.md).
