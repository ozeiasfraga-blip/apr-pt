-- ============================================================
-- APR/PT — 016: ferramentas — mínimo exigido, pré-marcação e
--                associação com risco
-- 1) Risco "Animais / Insetos" → "Animais peçonhentos"
-- 2) Risco passa a poder apontar ferramentas (ex.: peçonhentos → Facão)
--    que vêm marcadas junto quando o risco é marcado.
-- 3) Ferramenta com padrao = 1 vem pré-marcada em documento novo.
-- 4) config_textos.ferramentas_minimas = mínimo exigido para assinar
--    (editável em Modelo APR/PT → Textos).
-- ============================================================
SET NAMES utf8mb4;

-- 1) Nome novo do risco (documentos salvos guardam o próprio texto;
--    a seleção no formulário é pelo código, então nada quebra)
UPDATE catalogo_riscos
SET descricao = 'Animais peçonhentos'
WHERE codigo = 'AMB-02' AND descricao = 'Animais / Insetos';

-- 2) Ferramentas associadas ao risco (códigos separados por vírgula)
ALTER TABLE catalogo_riscos ADD COLUMN IF NOT EXISTS ferramentas VARCHAR(255) NULL AFTER medidas;
UPDATE catalogo_riscos SET ferramentas = 'FR-20' WHERE codigo = 'AMB-02';  -- FR-20 = Facão

-- 3) Ferramentas sempre usadas (vêm pré-marcadas)
ALTER TABLE catalogo_ferramentas ADD COLUMN IF NOT EXISTS padrao TINYINT(1) NOT NULL DEFAULT 0 AFTER ativo;
UPDATE catalogo_ferramentas SET padrao = 1
WHERE codigo IN (
    'FR-01',  -- Alicate de Corte
    'FR-03',  -- Caneta Testadora de Tensão
    'FR-11',  -- Clivador
    'FR-12',  -- Power Meter
    'FR-14'   -- Alicates Decapadores
);

-- 4) Mínimo exigido para assinar
INSERT IGNORE INTO config_textos (chave, valor, descricao) VALUES
('ferramentas_minimas', '4', 'Mínimo de ferramentas exigidas para assinar a APR/PT');
