-- ============================================================
-- APR/PT — 010: várias NRs por item do catálogo
-- Antes: cada item aceitava só uma norma (ex.: NR-35).
-- Agora: pode ter mais de uma, separadas por vírgula
--         (ex.: "NR-10,NR-35" para luvas e óculos).
-- A marcação automática no formulário passa a marcar o item quando
-- QUALQUER uma das normas dele estiver selecionada.
-- ============================================================
SET NAMES utf8mb4;

ALTER TABLE catalogo_riscos    MODIFY nr VARCHAR(60) NULL;
ALTER TABLE catalogo_epis      MODIFY nr VARCHAR(60) NULL;
ALTER TABLE catalogo_epc       MODIFY nr VARCHAR(60) NULL;
ALTER TABLE catalogo_checklist MODIFY nr VARCHAR(60) NULL;

-- Normaliza valores antigos separados por barra: "NR-35/NR-10" → "NR-10,NR-35"
UPDATE catalogo_riscos    SET nr = REPLACE(REPLACE(nr, ' / ', ','), '/', ',') WHERE nr LIKE '%/%';
UPDATE catalogo_epis      SET nr = REPLACE(REPLACE(nr, ' / ', ','), '/', ',') WHERE nr LIKE '%/%';
UPDATE catalogo_epc       SET nr = REPLACE(REPLACE(nr, ' / ', ','), '/', ',') WHERE nr LIKE '%/%';
UPDATE catalogo_checklist SET nr = REPLACE(REPLACE(nr, ' / ', ','), '/', ',') WHERE nr LIKE '%/%';
