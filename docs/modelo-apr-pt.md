# Modelo Padrão APR/PT

Documento único de **Análise Preliminar de Risco (APR)** e **Permissão de Trabalho (PT)**,
vinculado a uma **Ordem de Serviço (OS)** do técnico. Modelo de referência para as telas,
com base nas **NR-10** (eletricidade) e **NR-35** (trabalho em altura).

> **Editabilidade:** a estrutura abaixo (seções e ordem) é fixa; o **conteúdo** — riscos, EPIs,
> EPCs, itens de checklist e os textos das declarações — é **configurável em catálogo**, para o
> engenheiro de segurança ajustar termos e opções sem alterar código.

---

## Sumário de status (fluxo)

```
rascunho → aguardando aprovação → liberada → em execução → encerrada
```

- **rascunho**: técnico começou a preencher.
- **aguardando aprovação**: técnico assinou; aguarda o supervisor.
- **liberada**: supervisor autorizou (assinou).
- **em execução**: trabalho iniciado.
- **encerrada**: trabalho concluído e assinado.

---

## Matriz de risco

**Probabilidade (P)** e **Severidade (S)**, de 1 a 5. **Nível = P × S.**

| Probabilidade | | Severidade | |
|---|---|---|---|
| 1 | Raro | 1 | Insignificante |
| 2 | Improvável | 2 | Leve |
| 3 | Possível | 3 | Moderada |
| 4 | Provável | 4 | Grave |
| 5 | Quase certo | 5 | Catastrófica |

| Nível (P×S) | Classificação |
|---|---|
| 1–4 | 🟢 Baixo |
| 5–9 | 🟡 Médio |
| 10–16 | 🟠 Alto |
| 17–25 | 🔴 Crítico |

---

## 1. Identificação

Preenchido automaticamente a partir da OS do IXC (somente leitura), exceto o que for indicado.

| Campo | Origem |
|---|---|
| Nº do documento (ex.: `APR-PT-2026-000123`) | automático |
| OS (IXC) / protocolo | OS |
| Cliente | OS |
| Endereço / cidade | OS |
| Atividade (assunto da OS) | OS |
| Técnico executante / equipe | OS / usuário |
| Data/hora de emissão | automático |

---

## 2. APR — Análise Preliminar de Risco

### 2.1 Descrição da atividade
Campo de texto livre (o que será executado).

### 2.2 Enquadramento / normas aplicáveis
Seleção múltipla:
- ☐ **NR-35** — Trabalho em altura
- ☐ **NR-10** — Serviços em eletricidade
- ☐ **NR-33** — Espaço confinado
- ☐ **NR-18** — Construção (quando aplicável)
- ☐ Sinalização de via pública
- ☐ Outros

### 2.3 Riscos identificados
Tabela: **Perigo · Fonte geradora · Consequência · Probabilidade (1–5) · Severidade (1–5) ·
Nível (automático) · Medidas de controle**.

### 2.4 EPIs (checklist)
Ver catálogo padrão abaixo.

### 2.5 EPC / Ferramentas (checklist)
Ver catálogo padrão abaixo.

### 2.6 Observações da APR
Texto livre.

---

## 3. PT — PERMISSÃO PARA TRABALHO

> Emitida e assinada pelo **Supervisor / Emissor Autorizado da PT**. Refere-se à APR acima (anexa).

### 3.1 Declaração de liberação
> Eu, na condição de Supervisor / Emissor Autorizado da PT, declaro que analisei a APR anexada,
> conferi as condições de segurança elétrica do circuito e autorizo o início dos trabalhos.

### 3.2 Status da Permissão
☐ **AUTORIZADA** &nbsp;&nbsp; ☐ **NÃO AUTORIZADA**

### 3.3 Identificação do emissor
- **Nome do Supervisor / Emissor**
- **Registro / Cargo**

### 3.4 Assinatura
- **Assinatura do supervisor** + **Data e Hora da Assinatura**

---

## 4. ENCERRAMENTO DA PT

> Assinado pelo **supervisor**.

### 4.1 Declaração de encerramento
> Declaro que os trabalhos foram concluídos ou paralisados e a equipe e ferramentas foram
> retiradas da zona de risco.

### 4.2 Status do Encerramento
☐ **Concluído com sucesso** &nbsp;&nbsp; ☐ **Serviço suspenso / PT cancelada**

### 4.3 Assinatura
- **Assinatura do Supervisor no Encerramento** + **Data e Hora do Encerramento**

---

## 5. Verificações de segurança (APR — executante)

Cada item: **Sim / Não / N/A**. Ver catálogo padrão abaixo.

## Catálogos padrão (editáveis)

### Riscos — NR-35 (altura)
| Perigo | Fonte geradora | Consequência |
|---|---|---|
| Queda de altura | Poste, torre, escada, telhado, CTO | Lesão grave / óbito |
| Queda de objetos sobre terceiros | Ferramentas/materiais no alto | Lesão a pessoas abaixo |
| Instabilidade da estrutura | Escada/andaime/poste comprometido | Queda |
| Exposição a intempéries | Vento, chuva, descargas | Queda / choque |
| Animais peçonhentos / insetos | Caixas, postes, vegetação | Acidente |

### Riscos — NR-10 (eletricidade)
| Perigo | Fonte geradora | Consequência |
|---|---|---|
| Choque elétrico | Contato direto/indireto com rede energizada | Óbito |
| Arco elétrico / curto-circuito | Manuseio em painéis/quadros | Queimaduras |
| Energia residual / backup | Gerador, nobreak, banco de baterias | Choque |
| Eletricidade estática | Cabos/manuseio | Descarga |
| Indução de terceiros | Linhas vizinhas | Choque |

### Riscos — gerais / operacionais
| Perigo | Fonte geradora | Consequência |
|---|---|---|
| Atropelamento / colisão | Via pública | Lesão grave |
| Corte / perfuração | Ferramentas manuais | Lesão |
| Lesão por esforço/postura | Movimentos repetitivos, peso | Lesão ergonômica |
| Exposição química | Álcool, limpeza de fibra | Irritação / queimadura |
| Exposição ao calor | Sol, intempéries | Insolação / desidratação |
| Ruído | Equipamentos, máquinas | Perda auditiva |

### EPIs
- Capacete de segurança com jugular
- Óculos de proteção
- Luva isolante de borracha (classe conforme tensão) — NR-10
- Luva de vaqueta / couro
- Cinto de segurança tipo paraquedista — NR-35
- Talabarte em Y com absorvedor de energia — NR-35
- Trava-queda / linha de vida — NR-35
- Vestimenta de segurança (anti-chama, quando elétrica)
- Botina de segurança
- Protetor auricular (quando aplicável)
- Respirador / máscara (poeira ou produto químico)

### EPC / Ferramentas
- Cones e fita de isolamento / cavalete
- Sinalização viária
- Bloqueio e etiquetagem (LOTO)
- Detector de tensão
- Aterramento temporário
- Linha de vida / ponto de ancoragem
- Escada em bom estado / vara
- Kit de primeiros socorros
- Comunicação (celular / rádio)

### Checklist de liberação (PT)
1. Área isolada e sinalizada
2. Serviço autorizado pelo cliente/responsável do local
3. Rede/equipamento desenergizado e bloqueado (NR-10)
4. Ausência de tensão confirmada (NR-10)
5. Ponto de ancoragem/linha de vida definido e validado (NR-35)
6. Condições climáticas favoráveis (sem chuva, vento forte ou descargas)
7. EPIs verificados e em bom estado
8. Ferramentas e EPCs conferidos
9. Equipe orientada sobre os riscos (DDS/APR)
10. Responsável/supervisor ciente e disponível
11. Meios de comunicação disponíveis

---

## Regras e validações

1. **Liberação** só após assinatura do **supervisor**.
2. **Encerramento** só permitido se o documento estiver **liberada** ou **em execução**.
3. Se marcar **NR-35**, exige no mínimo **cinto paraquedista** + **talabarte** e **ponto de
   ancoragem** no checklist.
4. Se marcar **NR-10**, exige **luva isolante** + itens de **bloqueio/ausência de tensão**.
5. **Validade** da PT expira ao fim do período (padrão: mesma data).
6. Toda ação (salvar, assinar, liberar, encerrar) gera **registro de auditoria** com usuário,
   data/hora e IP.
7. **PDF** consolidado gerado na liberação e no encerramento, guardado no histórico da OS.
