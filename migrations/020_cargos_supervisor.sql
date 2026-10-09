-- ============================================================
-- APR/PT — 020: cargos do supervisor/emissor da PT
-- Lista separada por vírgula, exibida na caixa de seleção da
-- autorização da PT. Novos cargos (ex.: Fiscal de Campo) entram
-- aqui ou por Modelo APR/PT → Textos (chave cargos_supervisor).
-- ============================================================
SET NAMES utf8mb4;

INSERT IGNORE INTO config_textos (chave, valor, descricao) VALUES
('cargos_supervisor', 'Supervisor de Campo,Fiscal de Campo', 'Cargos do supervisor/emissor da PT (lista separada por vírgula)');
