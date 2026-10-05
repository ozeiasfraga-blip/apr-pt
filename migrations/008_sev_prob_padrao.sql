-- ============================================================
-- APR/PT — 008: severidade/probabilidade padrão 1 e 1
-- (agiliza o preenchimento; o técnico altera somente se necessário)
-- ============================================================
SET NAMES utf8mb4;

UPDATE catalogo_riscos SET severidade = 1, probabilidade = 1;
