# 05 — API

Roteador: `html/api/index.php`. Prefixo **`/api`**. Corpo JSON; resposta JSON
(`{success, message, data}`). Autenticação por **JWT** no header
`Authorization: Bearer <token>` (aceita também o cookie `ap_token`).

O roteador casa `MÉTODO + caminho` contra padrões com `{param}`
(`/api/documentos/{id}` → `([^/]+)`). Rota inexistente devolve **404** com
`Rota não encontrada: …`.

## Respostas

| Situação | HTTP | `success` |
|---|---|---|
| OK | 200 (ou 201) | `true` |
| Dados inválidos | 422 | `false` + `errors` |
| Não autenticado | 401 | `false` |
| Sem permissão | 403 | `false` |
| Não encontrado / conflito | 404 / 409 | `false` |
| Falha de integração (IXC) | 502 | `false` |

## Permissões — resumo

| Papel | O que alcança |
|---|---|
| `tecnico` | Login, OS/APR-PT **próprias**, catálogo, próprio perfil |
| `supervisor` | Tudo do técnico + todas as OS/PT, autorizar/encerrar, usuários (criar técnico, redefinir senha de técnico) |
| `admin` | Tudo + editar/excluir usuários, modelo, sync manual, Drive |

A trava é no backend: `Middleware::requireAuth()` / `requireSupervisor()` /
`requireAdmin()`. O frontend só esconde botões.

---

## Auth — `AuthController`

| Rota | Permissão | Corpo | Retorno |
|---|---|---|---|
| `POST /api/auth/login` | pública | `email`, `password` | `access_token`, `user` |
| `GET /api/auth/me` | autenticado | — | usuário da sessão |
| `POST /api/auth/logout` | autenticado | — | encerra o token |
| `POST /api/auth/change-password` | autenticado | `current_password`, `new_password` (≥6) | troca a **própria** senha |
| `POST /api/auth/register` | admin | `name`, `email`, `password`, `role` | cria usuário |

O login devolve **403** para usuário inativo (`users.ativo = 0`).

```bash
TOKEN=$(curl -s -X POST http://127.0.0.1:8089/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"...","password":"..."}' | jq -r .data.access_token)
```

---

## Ordens de serviço — `OrdemServicoController`

| Rota | Permissão | Parâmetros |
|---|---|---|
| `GET /api/os` | autenticado | `date=YYYY-MM-DD`, `id_tecnico`, `status`, `search` (supervisor/admin) |
| `GET /api/os/{id}` | autenticado | — |

- O **técnico** vê só as OS do `ixc_funcionario_id` dele (o filtro ignora
  outros parâmetros).
- Cada OS vem com `documento` (ou `null`): `id`, `uuid`, `status`,
  `supervisor_id` da APR/PT vinculada.

---

## Documentos APR/PT — `DocumentoSstController`

| Rota | Permissão | Para que |
|---|---|---|
| `GET /api/documentos` | autenticado | lista (filtros `status`, `os_id`, `date`, `search`, `tecnico_id`) |
| `GET /api/documentos/resumo` | autenticado | contadores por status |
| `GET /api/documentos/{id}` | autenticado (dono ou supervisor) | documento completo |
| `POST /api/documentos` | autenticado | cria/abre o documento de uma OS |
| `POST /api/documentos/sync` | autenticado | **offline**: envia documento preenchido (idempotente por `uuid`) |
| `POST /api/documentos/{id}/assinar` | técnico dono | assinatura do técnico |
| `POST /api/documentos/{id}/aprovar` | supervisor/admin | autoriza ou recusa a PT |
| `POST /api/documentos/{id}/encerrar` | supervisor/admin | encerra, gera PDF e **envia a 2ª cópia** |
| `GET /api/documentos/{id}/pdf` | autenticado (dono ou supervisor) | baixa/exibe o PDF |

### `POST /api/documentos` — payload

Campos principais: `uuid`, `os_id`, `nr10`, `nr35`, `normas`, `riscos`,
`epis`, `epcs`, `ferramentas`, `checklist` (JSON), `medidas`, `restricoes`,
`atividades`, `local_atividade`, `responsavel_apr`, `equipe_envolvida`,
`etapas`, `observacoes`, `treinamento_nr10`, `treinamento_nr35`, `aso_apto`,
`validade_inicio`, `validade_fim`.
Se `validade_fim` vier vazia, é calculada com `config_textos.validade_horas`.

### `POST /api/documentos/{id}/assinar`

`{ imagem_base64, latitude?, longitude?, dispositivo? }` → grava a assinatura
(arquivo em `storage/assinaturas/`), o hash e muda o status para
`aguardando_aprovacao`.

### `POST /api/documentos/{id}/aprovar`

`{ autorizacao: "AUTORIZADA" | "NAO_AUTORIZADA", imagem_base64, nome?, registro?, latitude?, longitude?, dispositivo? }`
→ status `liberada` (ou `cancelada`).

### `POST /api/documentos/{id}/encerrar`

`{ servico_conforme: true|false, observacoes?, imagem_base64, latitude?, longitude?, dispositivo? }`
→ status `encerrada` (ou `cancelada` se `servico_conforme` falso).
Gera o PDF `OS-<ixc_id>.pdf` e dispara o envio ao Drive — **falha na nuvem não
derruba o encerramento** (vai para log).

### `GET /api/documentos/{id}/pdf`

`Content-Type: application/pdf`, `Content-Disposition: inline; filename="OS-<n>.pdf"`.
Regerado a cada chamada a partir do banco (o arquivo local é cache).

---

## Catálogo e modelo — `CatalogoController` / `CatalogoAdminController`

| Rota | Permissão | Para que |
|---|---|---|
| `GET /api/catalogo` | autenticado | riscos, EPIs, EPCs, checklist, ferramentas + `config_textos` |
| `GET /api/modelo` | admin | catálogo completo (inclui inativos) + textos |
| `PUT /api/modelo/textos` | admin | grava `config_textos` |
| `POST /api/modelo/{tipo}` | admin | cria item (`tipo`: `riscos`, `epis`, `epc`, `checklist`, `ferramentas`) |
| `PUT /api/modelo/{tipo}/{id}` | admin | edita item |
| `DELETE /api/modelo/{tipo}/{id}` | admin | remove item |

`catalogo_*.nr` aceita **várias normas** separadas por vírgula (`NR-10,NR-35`).
`catalogo_riscos.ferramentas` e `.epis` guardam **códigos** (ex.: `FR-20`,
`EPI-10`) marcados junto quando o risco é selecionado.

---

## Usuários — `UserController`

| Rota | Permissão | Corpo/notas |
|---|---|---|
| `GET /api/users` | supervisor/admin | filtros `role` (admin), `search`; supervisor vê só técnicos |
| `POST /api/users` | supervisor/admin | `name`, `email`, `password`, `role` (supervisor só cria `tecnico`), `ixc_funcionario_id?`, `ativo?` |
| `PUT /api/users/{id}` | admin | `name`, `email`, `role`, `ativo`, `ixc_funcionario_id` |
| `POST /api/users/{id}/senha` | admin (qualquer) / supervisor (**só técnico**) | `{ password }` ≥6 |
| `DELETE /api/users/{id}` | admin | exclui se sem histórico; **senão desativa** |

Regras da exclusão:
- Não exclui a si mesmo (409).
- Usuário com documentos ou assinaturas → `ativo = 0` + tokens derrubados +
  log `desativar_usuario`. Sem histórico → exclusão física, log `excluir_usuario`.
- O login bloqueia inativo, então o usuário desativado não entra mais.

---

## Sincronização IXC — `SyncController`

| Rota | Permissão | Corpo |
|---|---|---|
| `POST /api/sync/os` | admin | `{ date?: "YYYY-MM-DD", id_tecnico?: int }` |
| `GET /api/ixc/funcionarios` | supervisor/admin | — (lista para vincular usuário) |

O cron (`scripts/sync_os.php`, a cada 15 min) faz a mesma sincronização sem
API. Falha do IXC devolve **502** com a mensagem original.

---

## Arquivos — `ArquivoController`

| Rota | Permissão | Uso |
|---|---|---|
| `GET /api/arquivo?p=<caminho>` | autenticado (Bearer **ou cookie**) | serve PNG/JPG/PDF de dentro de `storage/` |

Aceita cookie porque é usado em `<img>` e `<a>` (foto de perfil, assinaturas,
PDF). Bloqueia `..` e extensões fora de `pdf/png/jpg/jpeg`. Caminho é validado
contra o `realpath` do storage.

---

## Google Drive — `DriveController` (admin)

| Rota | Para que |
|---|---|
| `GET /api/drive/status` | o que está configurado/conectado |
| `GET /api/drive/oauth-url` | URL de consentimento para conectar |
| `DELETE /api/drive/oauth` | desconecta (apaga o token local) |

---

## Perfil — `PerfilController`

| Rota | Permissão | Corpo |
|---|---|---|
| `POST /api/perfil/foto` | autenticado | `{ imagem_base64 }` (data URI aceita) — máx. 2 MB, JPG/PNG/GIF/WebP |
| `DELETE /api/perfil/foto` | autenticado | remove a foto (volta às iniciais) |

A troca de senha do próprio usuário é `POST /api/auth/change-password`.

---

## Utilitários

| Rota | Permissão | Retorno |
|---|---|---|
| `GET /api/health` | pública | `{ app, time, ixc_configurado }` |

---

## Exemplos

```bash
# Listar OS do dia (técnico)
curl -H "Authorization: Bearer $TOKEN" \
  "http://127.0.0.1:8089/api/os?date=$(date +%F)"

# Encerrar PT e disparar a 2ª cópia no Drive
curl -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"servico_conforme":true,"imagem_base64":"data:image/png;base64,..."}' \
  "http://127.0.0.1:8089/api/documentos/25/encerrar"

# Baixar o PDF
curl -H "Authorization: Bearer $TOKEN" -OJ \
  "http://127.0.0.1:8089/api/documentos/25/pdf"
```

## Alterar rotas

1. Registre em `html/api/index.php` (`route(...)`).
2. Implemente o método no controller; valide payload com `Validator`.
3. Chame `Middleware::requireX()` **antes** de qualquer regra.
4. Registre `Log::create(...)` nas ações que alteram dados.
5. Atualize este documento.
