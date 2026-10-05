-- ============================================================
-- APR/PT — 006: auto-preenchimento por NR (marcação no catálogo)
-- ============================================================
SET NAMES utf8mb4;

ALTER TABLE catalogo_epc       ADD COLUMN IF NOT EXISTS nr VARCHAR(20) NULL AFTER descricao;
ALTER TABLE catalogo_checklist ADD COLUMN IF NOT EXISTS nr VARCHAR(20) NULL AFTER descricao;

-- EPC / Ferramentas por norma
UPDATE catalogo_epc SET nr = 'Via pública' WHERE codigo IN ('EPC-01','EPC-02');
UPDATE catalogo_epc SET nr = 'NR-10'       WHERE codigo IN ('EPC-03','EPC-04','EPC-05');
UPDATE catalogo_epc SET nr = 'NR-35'       WHERE codigo IN ('EPC-06','EPC-07');

-- Checklist por norma
UPDATE catalogo_checklist SET nr = 'NR-10' WHERE codigo IN ('PT-03','PT-04');
UPDATE catalogo_checklist SET nr = 'NR-35' WHERE codigo IN ('PT-05');

-- EPIs: garantir marcação (alguns já vêm do seed)
UPDATE catalogo_epis SET nr = 'NR-10' WHERE codigo IN ('EPI-03','EPI-08');
UPDATE catalogo_epis SET nr = 'NR-35' WHERE codigo IN ('EPI-05','EPI-06','EPI-07');

-- Riscos: severidade e probabilidade padrão (para auto-preenchimento)
UPDATE catalogo_riscos SET severidade = 5, probabilidade = 3 WHERE codigo = 'R35-QA';
UPDATE catalogo_riscos SET severidade = 4, probabilidade = 2 WHERE codigo = 'R35-QO';
UPDATE catalogo_riscos SET severidade = 4, probabilidade = 2 WHERE codigo = 'R35-IE';
UPDATE catalogo_riscos SET severidade = 3, probabilidade = 3 WHERE codigo = 'R35-IN';
UPDATE catalogo_riscos SET severidade = 3, probabilidade = 2 WHERE codigo = 'R35-AN';
UPDATE catalogo_riscos SET severidade = 5, probabilidade = 2 WHERE codigo = 'R10-CE';
UPDATE catalogo_riscos SET severidade = 5, probabilidade = 2 WHERE codigo = 'R10-AR';
UPDATE catalogo_riscos SET severidade = 4, probabilidade = 2 WHERE codigo = 'R10-ER';
UPDATE catalogo_riscos SET severidade = 3, probabilidade = 2 WHERE codigo = 'R10-ES';
UPDATE catalogo_riscos SET severidade = 4, probabilidade = 2 WHERE codigo = 'R10-IN';
UPDATE catalogo_riscos SET severidade = 3, probabilidade = 3 WHERE categoria = 'GERAL';
