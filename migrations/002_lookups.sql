-- ============================================================
-- APR/PT — 002: caches de enriquecimento (nomes de cliente/cidade)
-- ============================================================
-- A API do IXC retorna apenas os IDs na OS; os nomes são resolvidos
-- sob demanda e ficam cacheados localmente para evitar chamadas repetidas.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS clientes_cache (
    ixc_id BIGINT PRIMARY KEY,
    nome VARCHAR(200) DEFAULT NULL,
    fantasia VARCHAR(200) DEFAULT NULL,
    cnpj_cpf VARCHAR(25) DEFAULT NULL,
    atualizado_em DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cidades_cache (
    ixc_id INT PRIMARY KEY,
    nome VARCHAR(120) DEFAULT NULL,
    uf CHAR(2) DEFAULT NULL,
    atualizado_em DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
