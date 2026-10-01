-- ============================================================
-- APR/PT — 004: modelo completo (catálogos editáveis + campos)
-- ============================================================
SET NAMES utf8mb4;

-- ── Extensões do catálogo de riscos ─────────────────────────
ALTER TABLE catalogo_riscos
    ADD COLUMN IF NOT EXISTS categoria VARCHAR(20) NULL AFTER id_assunto,
    ADD COLUMN IF NOT EXISTS fonte VARCHAR(200) NULL AFTER descricao,
    ADD COLUMN IF NOT EXISTS consequencia VARCHAR(200) NULL AFTER fonte,
    ADD COLUMN IF NOT EXISTS medidas TEXT NULL AFTER consequencia,
    ADD COLUMN IF NOT EXISTS ordem INT NOT NULL DEFAULT 0 AFTER probabilidade;
ALTER TABLE catalogo_riscos ADD UNIQUE KEY uq_risco_codigo (codigo);

ALTER TABLE catalogo_epis
    ADD COLUMN IF NOT EXISTS ordem INT NOT NULL DEFAULT 0 AFTER descricao,
    ADD COLUMN IF NOT EXISTS nr VARCHAR(20) NULL AFTER ordem;
ALTER TABLE catalogo_epis ADD UNIQUE KEY uq_epi_codigo (codigo);

-- ── Novos catálogos ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS catalogo_epc (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(30) UNIQUE,
    descricao VARCHAR(255) NOT NULL,
    ordem INT NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalogo_checklist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(30) UNIQUE,
    descricao VARCHAR(255) NOT NULL,
    grupo VARCHAR(30) NOT NULL DEFAULT 'PT',
    critico TINYINT(1) NOT NULL DEFAULT 0,
    ordem INT NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS config_textos (
    chave VARCHAR(60) PRIMARY KEY,
    valor TEXT,
    descricao VARCHAR(200) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Novos campos no documento APR/PT ────────────────────────
ALTER TABLE documentos_sst
    ADD COLUMN IF NOT EXISTS normas JSON NULL AFTER nr35,
    ADD COLUMN IF NOT EXISTS epcs JSON NULL AFTER epis,
    ADD COLUMN IF NOT EXISTS checklist JSON NULL AFTER epcs,
    ADD COLUMN IF NOT EXISTS restricoes TEXT NULL AFTER medidas,
    ADD COLUMN IF NOT EXISTS validade_inicio DATETIME NULL AFTER observacoes,
    ADD COLUMN IF NOT EXISTS validade_fim DATETIME NULL AFTER validade_inicio,
    ADD COLUMN IF NOT EXISTS servico_conforme TINYINT(1) NULL AFTER encerrado_em,
    ADD COLUMN IF NOT EXISTS encerramento_obs TEXT NULL AFTER servico_conforme;

-- ── Seeds: riscos ───────────────────────────────────────────
INSERT IGNORE INTO catalogo_riscos (codigo, categoria, nr, descricao, fonte, consequencia) VALUES
('R35-QA','NR-35','NR-35','Queda de altura','Poste, torre, escada, telhado, CTO','Lesão grave / óbito'),
('R35-QO','NR-35','NR-35','Queda de objetos sobre terceiros','Ferramentas/materiais em altura','Lesão a pessoas abaixo'),
('R35-IE','NR-35','NR-35','Instabilidade da estrutura','Escada/andaime/poste comprometido','Queda'),
('R35-IN','NR-35','NR-35','Exposição a intempéries','Vento, chuva, descargas','Queda / choque'),
('R35-AN','NR-35','NR-35','Animais peçonhentos / insetos','Caixas, postes, vegetação','Acidente'),
('R10-CE','NR-10','NR-10','Choque elétrico','Contato direto/indireto com rede energizada','Óbito'),
('R10-AR','NR-10','NR-10','Arco elétrico / curto-circuito','Manuseio em painéis/quadros','Queimaduras'),
('R10-ER','NR-10','NR-10','Energia residual / backup','Gerador, nobreak, banco de baterias','Choque'),
('R10-ES','NR-10','NR-10','Eletricidade estática','Cabos / manuseio','Descarga'),
('R10-IN','NR-10','NR-10','Indução de terceiros','Linhas vizinhas','Choque'),
('GE-AT','GERAL',NULL,'Atropelamento / colisão','Via pública','Lesão grave'),
('GE-CO','GERAL',NULL,'Corte / perfuração','Ferramentas manuais','Lesão'),
('GE-ER','GERAL',NULL,'Lesão por esforço / postura','Movimentos repetitivos, peso','Lesão ergonômica'),
('GE-QU','GERAL',NULL,'Exposição química','Álcool, limpeza de fibra','Irritação / queimadura'),
('GE-CA','GERAL',NULL,'Exposição ao calor','Sol, intempéries','Insolação / desidratação'),
('GE-RU','GERAL',NULL,'Ruído','Equipamentos, máquinas','Perda auditiva');

-- ── Seeds: EPIs ─────────────────────────────────────────────
INSERT IGNORE INTO catalogo_epis (codigo, descricao, nr, ordem) VALUES
('EPI-01','Capacete de segurança com jugular',NULL,1),
('EPI-02','Óculos de proteção',NULL,2),
('EPI-03','Luva isolante de borracha (classe conforme tensão)','NR-10',3),
('EPI-04','Luva de vaqueta / couro',NULL,4),
('EPI-05','Cinto de segurança tipo paraquedista','NR-35',5),
('EPI-06','Talabarte em Y com absorvedor de energia','NR-35',6),
('EPI-07','Trava-queda / linha de vida','NR-35',7),
('EPI-08','Vestimenta de segurança (anti-chama, quando elétrica)','NR-10',8),
('EPI-09','Botina de segurança',NULL,9),
('EPI-10','Protetor auricular',NULL,10),
('EPI-11','Respirador / máscara (poeira ou produto químico)',NULL,11);

-- ── Seeds: EPC / Ferramentas ────────────────────────────────
INSERT IGNORE INTO catalogo_epc (codigo, descricao, ordem) VALUES
('EPC-01','Cones e fita de isolamento / cavalete',1),
('EPC-02','Sinalização viária',2),
('EPC-03','Bloqueio e etiquetagem (LOTO)',3),
('EPC-04','Detector de tensão',4),
('EPC-05','Aterramento temporário',5),
('EPC-06','Linha de vida / ponto de ancoragem',6),
('EPC-07','Escada em bom estado / vara',7),
('EPC-08','Kit de primeiros socorros',8),
('EPC-09','Comunicação (celular / rádio)',9);

-- ── Seeds: checklist de liberação (PT) ──────────────────────
INSERT IGNORE INTO catalogo_checklist (codigo, descricao, grupo, critico, ordem) VALUES
('PT-01','Área isolada e sinalizada','PT',1,1),
('PT-02','Serviço autorizado pelo cliente/responsável do local','PT',1,2),
('PT-03','Rede/equipamento desenergizado e bloqueado (NR-10)','PT',0,3),
('PT-04','Ausência de tensão confirmada (NR-10)','PT',0,4),
('PT-05','Ponto de ancoragem / linha de vida definido e validado (NR-35)','PT',0,5),
('PT-06','Condições climáticas favoráveis (sem chuva, vento forte ou descargas)','PT',1,6),
('PT-07','EPIs verificados e em bom estado','PT',1,7),
('PT-08','Ferramentas e EPCs conferidos','PT',0,8),
('PT-09','Equipe orientada sobre os riscos (DDS/APR)','PT',0,9),
('PT-10','Responsável/supervisor ciente e disponível','PT',0,10),
('PT-11','Meios de comunicação disponíveis','PT',0,11);

-- ── Seeds: textos editáveis ─────────────────────────────────
INSERT IGNORE INTO config_textos (chave, valor, descricao) VALUES
('declaracao_tecnico',
 'Declaro, na qualidade de executante, que li e compreendi a Análise Preliminar de Risco (APR), que participei da avaliação dos riscos e que executarei o trabalho observando todas as medidas de controle e utilizando os EPIs indicados. Assumo a responsabilidade pelo cumprimento das normas NR-10 e NR-35 aplicáveis.',
 'Declaração assinada pelo técnico'),
('autorizacao_supervisor',
 'Autorizo a execução do trabalho nas condições descritas nesta Permissão de Trabalho, desde que mantidas as medidas de controle e a validade indicada. Esta autorização é válida somente para a OS e o período registrados.',
 'Autorização assinada pelo supervisor'),
('validade_horas',
 '8',
 'Validade padrão da PT, em horas a partir da liberação');
