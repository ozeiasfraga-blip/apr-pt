-- ============================================================
-- APR/PT — 011: remove NR-33 (espaço confinado) e NR-18 (construção)
-- do catálogo. As normas do sistema passam a ser NR-35, NR-10,
-- Via pública e Outros.
--
-- Observação: documentos já assinados/encerrados NÃO são alterados
-- (ficam como registro histórico do que foi preenchido na época).
-- ============================================================
SET NAMES utf8mb4;

-- catalogo_riscos
UPDATE catalogo_riscos SET nr = NULLIF(TRIM(BOTH ',' FROM REPLACE(CONCAT(',', nr, ','), ',NR-33,', ',')), '') WHERE nr LIKE '%NR-33%';
UPDATE catalogo_riscos SET nr = NULLIF(TRIM(BOTH ',' FROM REPLACE(CONCAT(',', nr, ','), ',NR-18,', ',')), '') WHERE nr LIKE '%NR-18%';

-- catalogo_epis
UPDATE catalogo_epis SET nr = NULLIF(TRIM(BOTH ',' FROM REPLACE(CONCAT(',', nr, ','), ',NR-33,', ',')), '') WHERE nr LIKE '%NR-33%';
UPDATE catalogo_epis SET nr = NULLIF(TRIM(BOTH ',' FROM REPLACE(CONCAT(',', nr, ','), ',NR-18,', ',')), '') WHERE nr LIKE '%NR-18%';

-- catalogo_epc
UPDATE catalogo_epc SET nr = NULLIF(TRIM(BOTH ',' FROM REPLACE(CONCAT(',', nr, ','), ',NR-33,', ',')), '') WHERE nr LIKE '%NR-33%';
UPDATE catalogo_epc SET nr = NULLIF(TRIM(BOTH ',' FROM REPLACE(CONCAT(',', nr, ','), ',NR-18,', ',')), '') WHERE nr LIKE '%NR-18%';

-- catalogo_checklist
UPDATE catalogo_checklist SET nr = NULLIF(TRIM(BOTH ',' FROM REPLACE(CONCAT(',', nr, ','), ',NR-33,', ',')), '') WHERE nr LIKE '%NR-33%';
UPDATE catalogo_checklist SET nr = NULLIF(TRIM(BOTH ',' FROM REPLACE(CONCAT(',', nr, ','), ',NR-18,', ',')), '') WHERE nr LIKE '%NR-18%';
