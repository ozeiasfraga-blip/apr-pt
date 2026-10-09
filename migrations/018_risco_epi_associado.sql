-- ============================================================
-- APR/PT — 018: risco pode apontar EPIs associados
-- (ex.: Ruído e vibração → Protetor auricular), que vêm
-- marcados junto quando o risco é marcado.
-- ============================================================
SET NAMES utf8mb4;

ALTER TABLE catalogo_riscos ADD COLUMN IF NOT EXISTS epis VARCHAR(255) NULL AFTER ferramentas;
UPDATE catalogo_riscos SET epis = 'EPI-10' WHERE codigo = 'AMB-05';  -- EPI-10 = Protetor Auricular
