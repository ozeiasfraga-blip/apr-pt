-- ============================================================
-- APR/PT — 009: registro e assinatura da equipe envolvida
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS documento_equipe (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    documento_id BIGINT NOT NULL,
    ordem INT NOT NULL DEFAULT 0,
    nome VARCHAR(150) DEFAULT NULL,
    funcao VARCHAR(120) DEFAULT NULL,
    cpf VARCHAR(30) DEFAULT NULL,
    assinatura_path VARCHAR(255) DEFAULT NULL,
    assinado_em DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_eq_doc (documento_id),
    CONSTRAINT fk_eq_doc FOREIGN KEY (documento_id) REFERENCES documentos_sst(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
