# Documentação — APR/PT

Documentação técnica do sistema **APR/PT** (Análise Preliminar de Risco +
Permissão de Trabalho) integrado ao **IXC Soft**.

> **Este diretório é para quem vai manter, migrar ou integrar o sistema.**
> Para uso do dia a dia (telas, perfis), comece pelo
> [manual de visão geral](01-visao-geral-arquitetura.md).

## Sumário

| # | Documento | Para que serve |
|---|---|---|
| 01 | [Visão geral e arquitetura](01-visao-geral-arquitetura.md) | O que é o sistema, camadas, componentes, fluxo de uma PT |
| 02 | [Migração para outra máquina](02-migracao-nova-maquina.md) | **Passo a passo para levar tudo para outra VPS/servidor** |
| 03 | [Configuração e variáveis](03-configuracao-variaveis.md) | Todo o `.env` explicado, arquivo por arquivo |
| 04 | [Banco de dados](04-banco-de-dados.md) | As 18 tabelas, relações e migrações |
| 05 | [API](05-api.md) | As 34 rotas, autenticação e permissões |
| 06 | [Integrações e rotinas](06-integracoes-rotinas.md) | IXC, Google Drive (2ª cópia), Android/PWA, crons |
| 07 | [Operação e troubleshooting](07-operacao.md) | Logs, backup, diagnóstico de problemas |

## Leitura rápida

- **Vai migrar o sistema de servidor?** → [02-migracao-nova-maquina.md](02-migracao-nova-maquina.md)
- **Vai mexer no código?** → [01-visao-geral-arquitetura.md](01-visao-geral-arquitetura.md)
- **Precisa de uma rota da API?** → [05-api.md](05-api.md)
- **Algo parou de funcionar?** → [07-operacao.md](07-operacao.md)

## Stack em produção

| Componente | Versão na máquina atual |
|---|---|
| PHP | 8.2.33 (mod_php, não-FPM) |
| Servidor web | Apache 2.4.67 (Debian) |
| Banco | MariaDB 10.11.14 (protocolo MySQL) |
| Composer | 2.10 |
| Sistema | Debian 12 (bookworm) |

Dependências PHP (`composer.json`): `vlucas/phpdotenv ^5.6`,
`firebase/php-jwt ^6.0`, `dompdf/dompdf ^3.1`, `google/apiclient ^2.20`.

## Convencionais deste repositório

- Commits atômicos, mensagens no imperativo em pt-BR.
- Não existem testes automatizados — a validação é manual
  (`php -l`, `node --check`).
- UI e API em português (pt-BR).
- Segredos **nunca** entram no git: `.env` e `storage/credentials/*` estão
  no `.gitignore`.
