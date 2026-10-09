-- ============================================================
-- APR/PT — 015: grupo vira só AMBIENTAL e sai a Radiação (RF)
-- 1) Categoria "AMBIENTAL E RF" → "AMBIENTAL"
-- 2) Remove a opção "Radiação (RF)" (estava só desativada)
--    Documentos que já citam esse risco mantêm o valor salvo
--    no próprio JSON (nada é apagado deles).
--
-- Para restaurar a linha removida:
-- INSERT INTO catalogo_riscos
--   (id, categoria, codigo, descricao, medidas, nr, severidade, probabilidade, ordem, ativo)
-- VALUES
--   (24, 'AMBIENTAL', 'AMB-01', 'Radiação (RF)',
--    'Respeitar distância de segurança das antenas.', NULL, 1, 1, 7, 0);
-- ============================================================
SET NAMES utf8mb4;

-- 1) Renomeia a categoria
UPDATE catalogo_riscos
SET categoria = 'AMBIENTAL'
WHERE categoria = 'AMBIENTAL E RF';

-- 2) Remove a opção Radiação (RF)
DELETE FROM catalogo_riscos
WHERE codigo = 'AMB-01' AND descricao = 'Radiação (RF)';
