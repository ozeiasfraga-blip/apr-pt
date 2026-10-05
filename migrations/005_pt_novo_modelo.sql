-- ============================================================
-- APR/PT — 005: novo modelo de PT (emissor autorizado + encerramento)
-- ============================================================
SET NAMES utf8mb4;

-- Campos da PT: liberação e encerramento assinados pelo supervisor
ALTER TABLE documentos_sst
    ADD COLUMN IF NOT EXISTS pt_autorizacao VARCHAR(20) NULL AFTER checklist,
    ADD COLUMN IF NOT EXISTS pt_supervisor_nome VARCHAR(150) NULL AFTER pt_autorizacao,
    ADD COLUMN IF NOT EXISTS pt_supervisor_registro VARCHAR(100) NULL AFTER pt_supervisor_nome,
    ADD COLUMN IF NOT EXISTS pt_encerramento_status VARCHAR(20) NULL AFTER encerramento_obs;

-- Tipo de assinatura de encerramento (supervisor)
ALTER TABLE assinaturas
    MODIFY COLUMN tipo ENUM('tecnico','supervisor','supervisor_encerramento') NOT NULL;

-- Texto de liberação conforme o novo modelo
UPDATE config_textos
SET valor = 'Eu, na condição de Supervisor / Emissor Autorizado da PT, declaro que analisei a APR anexada, conferi as condições de segurança elétrica do circuito e autorizo o início dos trabalhos.'
WHERE chave = 'autorizacao_supervisor';

-- Texto de encerramento
INSERT IGNORE INTO config_textos (chave, valor, descricao) VALUES
('declaracao_encerramento',
 'Declaro que os trabalhos foram concluídos ou paralisados e a equipe e ferramentas foram retiradas da zona de risco.',
 'Declaração de encerramento (supervisor)');
