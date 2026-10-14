-- Foto de perfil do usuário (022)
ALTER TABLE users ADD COLUMN IF NOT EXISTS foto_path varchar(255) NULL AFTER ativo;
