-- ============================================================
-- APR/PT — 012: adiciona Protetor Solar aos EPIs
-- Serviço é ao ar livre em praticamente todas as normas,
-- então ele vem pré-marcado para NR-35, NR-10 e Via pública
-- (o técnico desmarca quando não se aplicar).
-- ============================================================
SET NAMES utf8mb4;

INSERT IGNORE INTO catalogo_epis (codigo, descricao, nr, ordem, ativo) VALUES
('EPI-15', 'Protetor Solar', 'NR-35,NR-10,Via pública', 15, 1);
