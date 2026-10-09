-- ============================================================
-- APR/PT — 019: adiciona o risco "Área ao redor" ao grupo
-- TRABALHO EM ALTURA (com NR-35, então marca automático).
-- ============================================================
SET NAMES utf8mb4;

INSERT IGNORE INTO catalogo_riscos
    (categoria, codigo, descricao, fonte, consequencia, medidas, ferramentas, epis, nr, severidade, probabilidade, ordem, ativo)
VALUES
    ('TRABALHO EM ALTURA', 'ALT-04',
     'Área ao redor',
     'Pessoas, veículos e objetos na área abaixo do serviço',
     'Acidentes com terceiros por queda de objetos',
     'Isolar e sinalizar a área ao redor/abaixo do serviço (cones e fita).',
     NULL, NULL,
     'NR-35', 1, 1, 4, 1);
