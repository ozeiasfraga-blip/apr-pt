-- ============================================================
-- APR/PT — 017: ajusta grupo AMBIENTAL
-- 1) Risco "Clima" → "Intempéries"
-- 2) Adiciona o risco "Ruído e vibração" (sem NR, como os demais
--    do grupo — o técnico marca quando se aplicar).
-- ============================================================
SET NAMES utf8mb4;

-- 1) Renomeia (a seleção no formulário é pelo código, nada quebra)
UPDATE catalogo_riscos
SET descricao = 'Intempéries'
WHERE codigo = 'AMB-03' AND descricao = 'Clima';

-- 2) Risco novo
INSERT IGNORE INTO catalogo_riscos
    (categoria, codigo, descricao, fonte, consequencia, medidas, nr, severidade, probabilidade, ordem, ativo)
VALUES
    ('AMBIENTAL', 'AMB-05',
     'Ruído e vibração',
     'Equipamentos, máquinas, furadeira',
     'Perda auditiva e lesões por vibração',
     'Protetor auricular, limitar tempo de exposição, pausas e revezamento.',
     NULL, 1, 1, 14, 1);
