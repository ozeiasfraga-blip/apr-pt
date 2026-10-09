-- ============================================================
-- APR/PT — 013: adiciona o risco "Radiação solar" ao catálogo
-- Ligado a NR-35, NR-10 e Via pública (todo serviço externo),
-- igual ao EPI Protetor Solar — vem pré-marcado e o técnico
-- desmarca quando não se aplicar.
-- ============================================================
SET NAMES utf8mb4;

INSERT IGNORE INTO catalogo_riscos
    (categoria, codigo, descricao, fonte, consequencia, medidas, nr, severidade, probabilidade, ordem, ativo)
VALUES
    ('AMBIENTAL E RF', 'AMB-04',
     'Radiação solar (insolação / queimadura)',
     'Sol / exposição prolongada ao ar livre',
     'Queimaduras, insolação e golpe de calor',
     'Protetor solar FPS 30+, vestimenta de manga longa e chapéu, hidratação e sombra nos intervalos.',
     'NR-35,NR-10,Via pública', 1, 1, 13, 1);
