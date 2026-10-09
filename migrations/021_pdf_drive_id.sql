-- ============================================================
-- APR/PT — 021: guarda o ID do PDF no Google Drive
-- Quando configurado (GOOGLE_DRIVE_* no .env), o PDF final é
-- enviado à pasta do Drive ao encerrar a PT e o ID do arquivo
-- fica em pdf_drive_id (reencerrar atualiza o mesmo arquivo).
-- ============================================================
SET NAMES utf8mb4;

ALTER TABLE documentos_sst ADD COLUMN IF NOT EXISTS pdf_drive_id VARCHAR(100) NULL AFTER pdf_path;
