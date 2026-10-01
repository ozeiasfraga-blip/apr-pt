-- ============================================================
-- APR/PT — Schema inicial
-- ============================================================

SET NAMES utf8mb4;

-- ── Usuários (técnicos, supervisores, admin) ────────────────
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(180) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('tecnico','supervisor','admin') NOT NULL DEFAULT 'tecnico',
    -- Vínculo com o cadastro de funcionários do IXC (funcionarios.id)
    ixc_funcionario_id INT DEFAULT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_role (role),
    INDEX idx_users_ixc (ixc_funcionario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Tokens de autenticação ──────────────────────────────────
CREATE TABLE IF NOT EXISTS auth_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(512) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tokens_user (user_id),
    INDEX idx_tokens_token (token(191)),
    CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Ordens de serviço (espelho do IXC) ──────────────────────
CREATE TABLE IF NOT EXISTS ordens_servico (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    ixc_id BIGINT NOT NULL UNIQUE,
    protocolo VARCHAR(30) DEFAULT NULL,
    tipo CHAR(1) DEFAULT 'C',
    id_assunto INT DEFAULT NULL,
    assunto VARCHAR(200) DEFAULT NULL,
    id_cliente INT DEFAULT NULL,
    cliente VARCHAR(200) DEFAULT NULL,
    id_estrutura INT DEFAULT NULL,
    id_login INT DEFAULT NULL,
    id_tecnico INT DEFAULT NULL,
    id_filial INT DEFAULT NULL,
    endereco VARCHAR(200) DEFAULT NULL,
    complemento VARCHAR(120) DEFAULT NULL,
    referencia VARCHAR(120) DEFAULT NULL,
    bairro VARCHAR(120) DEFAULT NULL,
    cidade VARCHAR(120) DEFAULT NULL,
    latitude VARCHAR(50) DEFAULT NULL,
    longitude VARCHAR(50) DEFAULT NULL,
    prioridade CHAR(1) DEFAULT 'N',
    status_ixc VARCHAR(5) DEFAULT NULL,
    data_agenda DATETIME DEFAULT NULL,
    data_abertura DATETIME DEFAULT NULL,
    data_final DATETIME DEFAULT NULL,
    mensagem TEXT DEFAULT NULL,
    raw_json JSON DEFAULT NULL,
    sincronizado_em DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_os_tecnico_data (id_tecnico, data_agenda),
    INDEX idx_os_status (status_ixc),
    INDEX idx_os_data (data_agenda)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Documento único APR/PT ──────────────────────────────────
CREATE TABLE IF NOT EXISTS documentos_sst (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    -- UUID gerado no app (permite criar offline e sincronizar sem duplicar)
    uuid CHAR(36) NOT NULL UNIQUE,
    os_id BIGINT NOT NULL,
    tecnico_id INT NOT NULL,
    supervisor_id INT DEFAULT NULL,
    status ENUM('rascunho','aguardando_aprovacao','liberada','em_execucao','encerrada','cancelada')
        NOT NULL DEFAULT 'rascunho',
    nr10 TINYINT(1) NOT NULL DEFAULT 0,
    nr35 TINYINT(1) NOT NULL DEFAULT 0,
    riscos JSON DEFAULT NULL,
    epis JSON DEFAULT NULL,
    medidas JSON DEFAULT NULL,
    atividades TEXT DEFAULT NULL,
    observacoes TEXT DEFAULT NULL,
    -- Hash encadeado para trilha de auditoria (integridade do documento)
    hash_documento CHAR(64) DEFAULT NULL,
    pdf_path VARCHAR(255) DEFAULT NULL,
    iniciado_em DATETIME DEFAULT NULL,
    encerrado_em DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_doc_os (os_id),
    INDEX idx_doc_tecnico (tecnico_id),
    INDEX idx_doc_status (status),
    CONSTRAINT fk_doc_os FOREIGN KEY (os_id) REFERENCES ordens_servico(id) ON DELETE CASCADE,
    CONSTRAINT fk_doc_tecnico FOREIGN KEY (tecnico_id) REFERENCES users(id),
    CONSTRAINT fk_doc_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Assinaturas (técnico e supervisor) ──────────────────────
CREATE TABLE IF NOT EXISTS assinaturas (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    documento_id BIGINT NOT NULL,
    tipo ENUM('tecnico','supervisor') NOT NULL,
    user_id INT NOT NULL,
    imagem_path VARCHAR(255) DEFAULT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    latitude VARCHAR(50) DEFAULT NULL,
    longitude VARCHAR(50) DEFAULT NULL,
    dispositivo VARCHAR(200) DEFAULT NULL,
    hash CHAR(64) DEFAULT NULL,
    assinado_em DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ass_doc (documento_id),
    CONSTRAINT fk_ass_doc FOREIGN KEY (documento_id) REFERENCES documentos_sst(id) ON DELETE CASCADE,
    CONSTRAINT fk_ass_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Anexos (fotos/evidências) ───────────────────────────────
CREATE TABLE IF NOT EXISTS anexos (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    documento_id BIGINT NOT NULL,
    tipo VARCHAR(50) DEFAULT 'foto',
    arquivo_path VARCHAR(255) NOT NULL,
    descricao VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_anexo_doc (documento_id),
    CONSTRAINT fk_anexo_doc FOREIGN KEY (documento_id) REFERENCES documentos_sst(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Catálogos configuráveis (engenharia de segurança) ───────
CREATE TABLE IF NOT EXISTS catalogo_riscos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_assunto INT DEFAULT NULL,
    codigo VARCHAR(30) DEFAULT NULL,
    descricao VARCHAR(255) NOT NULL,
    nr ENUM('NR-10','NR-35','OUTRA') DEFAULT NULL,
    severidade TINYINT DEFAULT NULL,
    probabilidade TINYINT DEFAULT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_risco_assunto (id_assunto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalogo_epis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(30) DEFAULT NULL,
    descricao VARCHAR(255) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Log de auditoria ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS log_auditoria (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    acao VARCHAR(80) NOT NULL,
    entidade VARCHAR(80) DEFAULT NULL,
    entidade_id BIGINT DEFAULT NULL,
    detalhes JSON DEFAULT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_log_user (user_id),
    INDEX idx_log_entidade (entidade, entidade_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Log de sincronização com o IXC ──────────────────────────
CREATE TABLE IF NOT EXISTS sync_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    recurso VARCHAR(60) NOT NULL,
    total INT DEFAULT 0,
    status ENUM('ok','erro') NOT NULL DEFAULT 'ok',
    mensagem TEXT DEFAULT NULL,
    iniciado_em DATETIME DEFAULT NULL,
    finalizado_em DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Usuário admin inicial ───────────────────────────────────
-- Senha padrão: admin123  (TROQUE após o primeiro acesso)
INSERT INTO users (name, email, password_hash, role)
SELECT 'Administrador', 'admin@aprpt.local',
       '$2y$10$1D63Um4dc6priFmM6Qcow.uwhevNoLwp7TIrrUJOwcM9bZbnY0DXC',
       'admin'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'admin@aprpt.local');
