-- ============================================================
-- APR/PT — 007: APR no modelo atualizado do técnico
-- ============================================================
SET NAMES utf8mb4;

-- ── Cabeçalho da empresa + termo (editáveis) ────────────────
INSERT IGNORE INTO config_textos (chave, valor, descricao) VALUES
('empresa_razao', 'NETWORKS WIRELESS TELECOM - NWT LTDA', 'Razão social (cabeçalho da APR)'),
('empresa_cnpj', '12.403.032/0001-17', 'CNPJ (cabeçalho da APR)'),
('empresa_endereco', 'Av. Mário Gurgel, 5353, Sala 917, Bloco 4A, Bairro São Francisco, Cariacica/ES', 'Endereço (cabeçalho da APR)'),
('termo_ciencia', 'Declaro que fui devidamente orientado(a) quanto aos riscos da atividade, às medidas de controle descritas nesta APR, ao uso obrigatório dos EPIs/EPCs aplicáveis e que estou física e mentalmente apto(a) para a realização dos serviços. Comprometo-me a exercer o direito de recusa e paralisar as atividades caso sejam identificadas condições imprevistas de risco grave ou iminente.', 'Termo de Ciência e Compromisso');

-- ── Novos campos do documento ───────────────────────────────
ALTER TABLE documentos_sst
    ADD COLUMN IF NOT EXISTS local_atividade VARCHAR(200) NULL AFTER atividades,
    ADD COLUMN IF NOT EXISTS responsavel_apr VARCHAR(150) NULL AFTER local_atividade,
    ADD COLUMN IF NOT EXISTS equipe_envolvida TEXT NULL AFTER responsavel_apr,
    ADD COLUMN IF NOT EXISTS etapas TEXT NULL AFTER equipe_envolvida,
    ADD COLUMN IF NOT EXISTS ferramentas JSON NULL AFTER epcs,
    ADD COLUMN IF NOT EXISTS treinamento_nr10 TINYINT(1) NOT NULL DEFAULT 0 AFTER restricoes,
    ADD COLUMN IF NOT EXISTS treinamento_nr35 TINYINT(1) NOT NULL DEFAULT 0 AFTER treinamento_nr10,
    ADD COLUMN IF NOT EXISTS aso_apto TINYINT(1) NOT NULL DEFAULT 0 AFTER treinamento_nr35;

-- ── Catálogo de Ferramentas Utilizadas ──────────────────────
CREATE TABLE IF NOT EXISTS catalogo_ferramentas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(30) UNIQUE,
    descricao VARCHAR(255) NOT NULL,
    nr VARCHAR(20) NULL,
    ordem INT NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO catalogo_ferramentas (codigo, descricao, ordem) VALUES
('FR-01','Alicate de Corte',1),('FR-02','Alicate Universal',2),
('FR-03','Caneta Testadora de Tensão',3),('FR-04','Martelo',4),
('FR-05','Chave de Fenda',5),('FR-06','Chave Phillips',6),
('FR-07','Alicate de Crimpar',7),('FR-08','Sonda de Passar Fios',8),
('FR-09','Furadeira',9),('FR-10','Parafusadeira',10),
('FR-11','Clivador',11),('FR-12','Power Meter',12),
('FR-13','Caneta Laser',13),('FR-14','Alicates Decapadores',14),
('FR-15','Testador de Fibra Ativa',15),('FR-16','Máquina de Fusão',16),
('FR-17','Chave BAP',17),('FR-18','OTDR',18),
('FR-19','Máquina de Cintar Poste',19),('FR-20','Facão',20),
('FR-21','Estilete',21),('FR-22','Maçanico',22);

-- ── EPIs e EPCs Obrigatórios (lista única do modelo) ────────
UPDATE catalogo_epis SET ativo = 0;
INSERT INTO catalogo_epis (codigo, descricao, nr, ordem, ativo) VALUES
('EPI-01','Capacete c/ Jugular',NULL,1,1),
('EPI-02','Óculos de Segurança',NULL,2,1),
('EPI-03','Botina de Segurança (bico rígido)',NULL,3,1),
('EPI-04','Bota de PVC',NULL,4,1),
('EPI-05','Perneira de Couro / Raspa',NULL,5,1),
('EPI-06','Luvas (Proteção mecânica/isolante)','NR-10',6,1),
('EPI-07','Cinto Paraquedista (Altura)','NR-35',7,1),
('EPI-08','Talabarte de Posicionamento','NR-35',8,1),
('EPI-09','Vestimenta Alta Visibilidade',NULL,9,1),
('EPI-10','Protetor Auricular',NULL,10,1),
('EPI-11','Máscara de Proteção (Pó/Química)',NULL,11,1),
('EPI-12','Cones de Sinalização','Via pública',12,1),
('EPI-13','Fita Zebrada',NULL,13,1)
ON DUPLICATE KEY UPDATE descricao = VALUES(descricao), nr = VALUES(nr), ordem = VALUES(ordem), ativo = 1;

-- ── Riscos nos grupos do modelo (com medidas de controle) ───
ALTER TABLE catalogo_riscos MODIFY COLUMN nr VARCHAR(20) NULL;
UPDATE catalogo_riscos SET ativo = 0
 WHERE codigo LIKE 'R35-%' OR codigo LIKE 'R10-%' OR codigo LIKE 'GE-%';
INSERT INTO catalogo_riscos (codigo, categoria, nr, descricao, medidas, ordem, ativo) VALUES
('ALT-01','TRABALHO EM ALTURA','NR-35','Queda de Pessoas','Usar cinto tipo paraquedista + talabarte duplo + amarração obrigatória da escada (topo/base).',1,1),
('ALT-02','TRABALHO EM ALTURA','NR-35','Queda de Objetos','Isolar área embaixo e usar bolsa de ferramentas.',2,1),
('ALT-03','TRABALHO EM ALTURA','NR-35','Escadas','Verificar estabilidade, calçamento e amarrar base/topo.',3,1),
('ELE-01','RISCO ELÉTRICO','NR-10','Choque Elétrico','Teste OBRIGATÓRIO de tensão e fuga de energia antes de iniciar qualquer procedimento, e uso de ferramentas isoladas.',4,1),
('ELE-02','RISCO ELÉTRICO','NR-10','Energia Estática / Rede','Manter distância da rede elétrica pública.',5,1),
('ELE-03','RISCO ELÉTRICO','NR-10','Direito/Dever de Recusa','Paralisar a atividade imediatamente em caso de detecção de fuga de energia ou risco grave e iminente.',6,1),
('AMB-01','AMBIENTAL E RF',NULL,'Radiação (RF)','Respeitar distância de segurança das antenas.',7,1),
('AMB-02','AMBIENTAL E RF',NULL,'Animais / Insetos','Inspecionar caixas e postes antes de tocar.',8,1),
('AMB-03','AMBIENTAL E RF',NULL,'Clima','Parar em caso de chuva ou raios.',9,1),
('TRA-01','TRÂNSITO E ERGONOMIA','Via pública','Atropelamento','Sinalizar a via com cones e fita.',10,1),
('TRA-02','TRÂNSITO E ERGONOMIA',NULL,'Esforço Físico','Levantar cargas flexionando os joelhos.',11,1),
('TRA-03','TRÂNSITO E ERGONOMIA',NULL,'Ferramentas Cortantes','Manusear estiletes/facas com cuidado.',12,1)
ON DUPLICATE KEY UPDATE categoria = VALUES(categoria), nr = VALUES(nr), descricao = VALUES(descricao), medidas = VALUES(medidas), ordem = VALUES(ordem), ativo = 1;
