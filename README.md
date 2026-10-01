# APR/PT

Sistema de **APR** (Análise Preliminar de Risco) e **PT** (Permissão de Trabalho) para equipes de
campo de provedores de internet, integrado ao ERP **IXC Soft**.

## Visão geral

- Um **documento único** de APR+PT por **Ordem de Serviço (OS)** do técnico.
- O técnico preenche e assina no aplicativo (**funciona offline**).
- O supervisor assina a PT (no local ou remotamente).
- Os dados da OS vêm do IXC via API (somente leitura).

## Requisitos

- PHP 8.1+ com extensões `pdo_mysql`, `mbstring`, `openssl`, `curl`
- MySQL 8+ / MariaDB 10.4+
- Composer

## Instalação

```bash
cp .env.example .env       # e edite as credenciais
composer install
mysql -u root < migrations/001_init.sql
```

## Sincronização das OS

```bash
php scripts/sync_os.php            # OS de hoje
php scripts/sync_os.php 2026-10-01 # data específica
```

Idealmente agendar em cron (ex.: a cada 15 minutos).

## API

Prefixo: `/api`. Principais rotas:

- `POST /api/auth/login`
- `GET  /api/auth/me`
- `GET  /api/os` — OS do técnico autenticado (ou filtros p/ supervisor)
- `POST /api/sync/os` — dispara sincronização com o IXC
- `GET  /api/documentos`
- `POST /api/documentos` — cria/sincroniza um documento (idempotente por `uuid`)
- `POST /api/documentos/{id}/assinar`
- `POST /api/documentos/{id}/aprovar`
- `POST /api/documentos/{id}/encerrar`
- `GET  /api/documentos/{id}/pdf`

## Estrutura

Veja `AGENTS.md`.
