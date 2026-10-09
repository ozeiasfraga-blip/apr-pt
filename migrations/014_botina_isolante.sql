-- ============================================================
-- APR/PT — 014: botina passa a ser isolante
-- A botina passa a atender também à NR-10 (calçado isolante),
-- mantendo as demais normas que já cobre.
--
-- O formulário casa o EPI pelo nome, então os documentos em
-- andamento recebem também o novo nome — senão a marcação da
-- botina sumiria ao reabrir o rascunho. Documentos encerrados
-- ou cancelados não são tocados (registro histórico).
-- ============================================================
SET NAMES utf8mb4;

-- 1) Novo nome no catálogo
UPDATE catalogo_epis
SET descricao = 'Botina de Segurança Isolante (bico rígido)'
WHERE codigo = 'EPI-03'
  AND descricao = 'Botina de Segurança (bico rígido)';

-- 2) Documentos em andamento passam a citar o novo nome
UPDATE documentos_sst
SET epis = REPLACE(epis,
                   'Botina de Segurança (bico rígido)',
                   'Botina de Segurança Isolante (bico rígido)')
WHERE status NOT IN ('encerrada', 'cancelada')
  AND epis LIKE '%Botina de Segurança (bico rígido)%';
