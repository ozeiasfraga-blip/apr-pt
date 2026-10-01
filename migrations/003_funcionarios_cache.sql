-- ============================================================
-- APR/PT — 003: cache de funcionários do IXC (nome do técnico)
-- ============================================================
-- Permite mostrar o nome do técnico na lista de OS sem consultar o IXC a cada tela.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS funcionarios_cache (
    ixc_id INT PRIMARY KEY,
    nome VARCHAR(150) DEFAULT NULL,
    id_funcao INT DEFAULT NULL,
    ativo CHAR(1) DEFAULT NULL,
    atualizado_em DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
