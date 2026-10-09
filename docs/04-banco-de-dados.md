# 04 — Banco de dados

**MySQL 8 / MariaDB 10.4+**, base `apr_pt`, charset `utf8mb4_unicode_ci`,
engine `InnoDB`. Acesso via PDO (`src/Database.php`, singleton) — **sem ORM**.

> **Não há executor de migrations.** Os `.sql` de `migrations/` são aplicados
> manualmente. Num servidor já em produção, o dump do backup já traz o schema
> atualizado.

---

## Visão geral das 18 tabelas

| Tabela | Colunas | Linhas* | Papel |
|---|---|---|---|
| `users` | 9 | 8 | Contas (técnico / supervisor / admin) |
| `ordens_servico` | 29 | 261 | Espelho das OS do IXC |
| **`documentos_sst`** | **40** | 18 | **Documento único APR+PT** |
| `assinaturas` | 12 | 51 | Assinaturas (técnico, supervisor, encerramento) |
| `documento_equipe` | 9 | 15 | Equipe envolvida (nome, função, assinatura) |
| `anexos` | 6 | 0 | Fotos/arquivos ligados ao documento |
| `auth_tokens` | 5 | 54 | Tokens de sessão |
| `log_auditoria` | 8 | 206 | Trilha de auditoria |
| `sync_log` | 8 | 387 | Histórico das sincronizações IXC |
| `config_textos` | 4 | 10 | Textos de cabeçalho/declarações (chave-valor) |
| `catalogo_riscos` | 17 | 30 | Catálogo de riscos (por assunto/NR) |
| `catalogo_epis` | 8 | 15 | Catálogo de EPIs |
| `catalogo_epc` | 7 | 9 | Catálogo de EPCs |
| `catalogo_checklist` | 9 | 11 | Catálogo de checklist |
| `catalogo_ferramentas` | 8 | 22 | Catálogo de ferramentas |
| `clientes_cache` | 6 | 311 | Cache de clientes do IXC |
| `cidades_cache` | 5 | 6 | Cache de cidades do IXC |
| `funcionarios_cache` | 6 | 138 | Cache de funcionários do IXC |

\* `TABLE_ROWS` do InnoDB é uma **estimativa**. Contagem exata:
`SELECT COUNT(*) FROM <tabela>;`

---

## Diagrama de relações

```
users ──────────────┐
 (id)               │
                    ├──< assinaturas >──┐
                    │    documento_id   │
                    ├──< auth_tokens    │
                    │                   │
ordens_servico      │                   │
 (id) ──────┐       │                   │
            │       │                   │
            │   documentos_sst ─────────┤
            └──── os_id                 │
                    tecnico_id ──> users│
                    supervisor_id ─> users
                                        │
                    documento_equipe ───┤  documento_id
                    anexos ─────────────┘  documento_id

log_auditoria.user_id ──> users (nullable)
sync_log, config_textos, catalogo_*, *_cache  → sem FK (independentes)
```

**FKs existentes** (todas `documentos_sst`/`assinaturas` como filho):

| Tabela | Coluna | Referência |
|---|---|---|
| `documentos_sst` | `os_id` | `ordens_servico.id` |
| `documentos_sst` | `tecnico_id` | `users.id` |
| `documentos_sst` | `supervisor_id` | `users.id` |
| `assinaturas` | `documento_id` | `documentos_sst.id` |
| `assinaturas` | `user_id` | `users.id` |
| `documento_equipe` | `documento_id` | `documentos_sst.id` |
| `anexos` | `documento_id` | `documentos_sst.id` |
| `auth_tokens` | `user_id` | `users.id` |

> `clientes_cache`, `cidades_cache`, `funcionarios_cache` e `catalogo_*`
> **não têm FK** — são caches/catálogos desacoplados do IXC.

---

## Tabelas principais

### `users`

| Coluna | Tipo | Nota |
|---|---|---|
| `id` | int PK | |
| `name` | varchar(150) | |
| `email` | varchar(180) | **UNIQUE** |
| `password_hash` | varchar(255) | `password_hash()` PHP |
| `role` | enum(`tecnico`,`supervisor`,`admin`) | default `tecnico` |
| `ixc_funcionario_id` | int NULL | FK lógico p/ `funcionarios_cache.ixc_id` |
| `ativo` | tinyint(1) | default 1 |
| `created_at` / `updated_at` | timestamp | |

### `ordens_servico` — espelho do IXC

Chave de negócio: **`ixc_id` UNIQUE** (o ID da OS no IXC; é o `#` da tela).
`raw_json` guarda a resposta bruta original.

Principais campos: `protocolo`, `tipo`, `assunto`, `cliente`,
`id_tecnico`, `endereco`/`bairro`/`cidade`, `latitude`/`longitude`,
`status_ixc`, `data_agenda`, `data_abertura`, `data_final`,
`mensagem` (**usada como "anexo"**: a API pública do IXC não sobe arquivo na OS),
`sincronizado_em`.

### `documentos_sst` — o documento único

**40 colunas.** Estrutura em blocos:

**Identidade e vínculo**
`id` (bigint PK) · `uuid` (char 36, **UNIQUE** — chave de idempotência do app
offline) · `os_id` → `ordens_servico` · `tecnico_id` → `users` ·
`supervisor_id` → `users`

**Status**
```sql
status ENUM('rascunho','aguardando_aprovacao','liberada',
            'em_execucao','encerrada','cancelada')
```

**Conteúdo da APR (checkboxes e campos livres)**
`nr10`, `nr35` (tinyint) · `normas`, `riscos`, `epis`, `epcs`,
`ferramentas`, `checklist` (**longtext — JSON**) · `medidas`,
`restricoes`, `atividades`, `local_atividade`, `responsavel_apr`,
`equipe_envolvida`, `etapas`, `observacoes` (text)

**Bloco PT (autorização)**
`pt_autorizacao` · `pt_supervisor_nome` · `pt_supervisor_registro` ·
`treinamento_nr10` · `treinamento_nr35` · `aso_apto` ·
`validade_inicio` / `validade_fim`

**Bloco encerramento**
`iniciado_em` · `encerrado_em` · `servico_conforme` · `encerramento_obs` ·
`pt_encerramento_status`

**Integridade e arquivos**
| Coluna | Conteúdo |
|---|---|
| `hash_documento` | char(64) — hash do conteúdo (`DocumentoSst::calcularHash()`) |
| `pdf_path` | ex.: `pdfs/OS-170742.pdf` (relativo a `storage/`) |
| `pdf_drive_id` | ID do arquivo no Google Drive (2ª cópia) |

> **`pdf_drive_id`** veio da migração **`021_pdf_drive_id.sql`** — essa é a
> coluna que a 2ª cópia depende. Confira se existe:
> `SHOW COLUMNS FROM documentos_sst LIKE 'pdf_drive_id';`

### `assinaturas`

| Coluna | Tipo |
|---|---|
| `documento_id` | FK → `documentos_sst` |
| `tipo` | enum(`tecnico`,`supervisor`,`supervisor_encerramento`) |
| `user_id` | FK → `users` |
| `imagem_path` | ex.: `assinaturas/tecnico_20261009_..._a1b2c3d4.png` |
| `ip` | varchar(45) (aceita IPv6) |
| `latitude` / `longitude` / `dispositivo` | geolocalização e aparelho |
| `hash` | char(64) |
| `assinado_em` | datetime |

O arquivo **não** é `UNIQUE` por documento+tipo — o histórico de reassinaturas
acumula (51 linhas para 18 documentos).

### `config_textos` — textos editáveis no painel

Chave-valor (`chave` PK, `valor` text, `descricao`). Seed conhecido:

| chave | valor (inicial) |
|---|---|
| `empresa_razao` | NETWORKS WIRELESS TELECOM - NWT LTDA |
| `empresa_cnpj` | 12.403.032/0001-17 |
| `empresa_endereco` | Av. Mário Gurgel, 5353, Sala 917, Bloco … |
| `declaracao_tecnico` | Declaro, na qualidade de executante… |
| `declaracao_encerramento` | Declaro que os trabalhos foram concluído… |
| `autorizacao_supervisor` | Eu, na condição de Supervisor / Emissor… |
| `termo_ciencia` | Declaro que fui devidamente orientado(a)… |
| `validade_horas` | **8** — validade da PT a partir da liberação |
| `ferramentas_minimas` | **4** — mínimo p/ assinar |
| `cargos_supervisor` | Supervisor de Campo,Fiscal de Campo |

Editáveis em *Modelo APR/PT → Textos* (admin).

### `catalogo_*` — itens configuráveis

Todos seguem o padrão `id, codigo, descricao, nr, ordem, ativo`.

| Tabela | Colunas extras |
|---|---|
| `catalogo_riscos` | `id_assunto`, `categoria`, `fonte`, `consequencia`, `medidas`, `ferramentas`, `epis`, `severidade`, `probabilidade` |
| `catalogo_epis` | — |
| `catalogo_epc` | — |
| `catalogo_checklist` | `grupo`, `critico` |
| `catalogo_ferramentas` | **`padrao`** (pré-marcada se =1) |

> A coluna **`nr` aceita várias normas separadas por vírgula**
> (`NR-10,NR-35`) — migração `010_nr_multipla.sql`. Um item é marcado
> quando **qualquer uma** das normas dele está selecionada.
>
> `catalogo_riscos.epis` e `.ferramentas` guardam **códigos** (ex.: `EPI-10`,
> `FR-20`) referenciando as outras tabelas — marcados junto com o risco
> (só acrescentam, nunca desmarcam).

### Caches do IXC

`clientes_cache`, `cidades_cache`, `funcionarios_cache` — todos com `ixc_id`
como chave + `atualizado_em`. Desnormalizados de propósito para a lista
renderizar **offline**.

### `log_auditoria`

`id, user_id (nullable), acao, entidade, entidade_id, detalhes (JSON), ip, created_at`

Ações relevantes da nuvem: `drive_backup_reparado`,
`drive_backup_reparo_falhou`, `drive_verificacao_falha`.

```sql
SELECT id, user_id, acao, entidade_id, detalhes, created_at
  FROM log_auditoria WHERE acao LIKE 'drive_%' ORDER BY id DESC;
```

### `sync_log`

`id, recurso, total, status, mensagem, iniciado_em, finalizado_em, created_at`
— uma linha por execução do sync do IXC (diagnóstico de falhas de integração).

---

## Migrações (`migrations/`)

| # | Arquivo | Assunto |
|---|---|---|
| 001 | `001_init.sql` | Schema inicial (users, ordens_servico, documentos_sst, assinaturas, anexos, catálogos, log, sync_log) |
| 002 | `002_lookups.sql` | Tabelas de lookup |
| 003 | `003_funcionarios_cache.sql` | Cache de funcionários |
| 004 | `004_modelo_aprpt.sql` | Modelo APR/PT |
| 005 | `005_pt_novo_modelo.sql` | Novo modelo de PT |
| 006 | `006_auto_nr.sql` | NR automática |
| 007 | `007_apr_modelo_novo.sql` | Modelo de APR revisado |
| 008 | `008_sev_prob_padrao.sql` | Severidade/probabilidade padrão |
| 009 | `009_equipe.sql` | Tabela `documento_equipe` |
| 010 | `010_nr_multipla.sql` | **`nr` vira CSV de normas** |
| 011 | `011_remove_nr33_nr18.sql` | Remove NR-33 e NR-18 |
| 012 | `012_add_protetor_solar.sql` | EPI: protetor solar |
| 013 | `013_risco_radiacao_solar.sql` | Risco: radiação solar |
| 014 | `014_botina_isolante.sql` | EPI: botina isolante |
| 015 | `015_grupo_ambiental.sql` | Grupo ambiental no checklist |
| 016 | `016_ferramentas.sql` | Tabela `catalogo_ferramentas` |
| 017 | `017_ambiental_intemperies_ruido.sql` | Itens ambientais |
| 018 | `018_risco_epi_associado.sql` | **`catalogo_riscos.epis`** |
| 019 | `019_risco_area_ao_redor.sql` | Risco: área ao redor |
| 020 | `020_cargos_supervisor.sql` | `config_textos.cargos_supervisor` |
| 021 | `021_pdf_drive_id.sql` | **`documentos_sst.pdf_drive_id`** (2ª cópia Drive) |

Aplicar uma nova:

```bash
mysql -u apr_pt -p apr_pt < migrations/0NN_descricao.sql
```

**Não há controle de versão de schema no banco.** Para descobrir o que está
aplicado, confira a coluna/objetivo:

```bash
mysql -u apr_pt -p apr_pt -e "SHOW COLUMNS FROM documentos_sst LIKE 'pdf_drive_id';"
```

---

## Backup e restauração

```bash
# Dump consistente
mysqldump --single-transaction --routines --triggers -u apr_pt -p apr_pt > dump.sql

# Restaurar
mysql -u apr_pt -p apr_pt < dump.sql

# Contagem para conferência
mysql -u apr_pt -p apr_pt -N -e "
  SELECT 'os', COUNT(*) FROM ordens_servico
  UNION SELECT 'documentos', COUNT(*) FROM documentos_sst
  UNION SELECT 'assinaturas', COUNT(*) FROM assinaturas;
```

> **O dump não leva arquivos.** `storage/pdfs`, `storage/assinaturas`,
> `storage/anexos` e `storage/credentials` precisam de backup separado —
> veja [02-migracao-nova-maquina.md](02-migracao-nova-maquina.md).

---

## Consultas úteis de diagnóstico

```sql
-- PTs por status
SELECT status, COUNT(*) FROM documentos_sst GROUP BY status;

-- Encerradas SEM cópia no Drive
SELECT id, uuid, pdf_path, pdf_drive_id FROM documentos_sst
 WHERE status = 'encerrada'
   AND (pdf_drive_id IS NULL OR pdf_drive_id = '');

-- PDF local ausente ou apontando para nada
SELECT id, pdf_path FROM documentos_sst
 WHERE pdf_path IS NOT NULL AND pdf_path <> '';

-- Colisão de nome (duas PTs na mesma OS)
SELECT os_id, COUNT(*) qtd FROM documentos_sst GROUP BY os_id HAVING qtd > 1;

-- Documentos sem OS correspondente (FK quebrada)
SELECT d.id FROM documentos_sst d
  LEFT JOIN ordens_servico o ON o.id = d.os_id WHERE o.id IS NULL;

-- Últimas sincronizações do IXC
SELECT recurso, status, total, mensagem, finalizado_em
  FROM sync_log ORDER BY id DESC LIMIT 10;

-- Auditoria de backup da nuvem
SELECT acao, entidade_id, detalhes, created_at FROM log_auditoria
 WHERE acao LIKE 'drive_%' ORDER BY id DESC;
```

---

## Invariantes a preservar

1. **`documentos_sst.uuid` UNIQUE** — base da idempotência offline. Não remover.
2. **`ordens_servico.ixc_id` UNIQUE** — impede OS duplicada no sync.
3. **`pdf_path` relativo** a `storage/` (nunca absoluto).
4. **`pdf_drive_id`** é o único vínculo com o Drive; apagar a coluna derruba
   a 2ª cópia e o verificador diário.
5. `status` segue a máquina de estados descrita em
   [01-visao-geral-arquitetura.md](01-visao-geral-arquitetura.md).
6. `config_textos.chave` é PK — um `INSERT` repetido falha; use `UPDATE`.
