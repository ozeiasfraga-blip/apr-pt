// APR/PT — apoio ao modo offline.
// Guarda no aparelho: sessão, catálogo, listas de OS, detalhes e os documentos
// preenchidos sem internet (com fila de envio para o servidor).
const Offline = (() => {
    'use strict';
    const P = 'aprpt_off:';

    const ler = (chave, padrao = null) => {
        try {
            const v = localStorage.getItem(P + chave);
            return v ? JSON.parse(v) : padrao;
        } catch (_) { return padrao; }
    };
    const gravar = (chave, valor) => {
        try {
            localStorage.setItem(P + chave, JSON.stringify(valor));
            return true;
        } catch (e) {
            console.warn('Offline: sem espaço para guardar dados no aparelho.', e);
            return false;
        }
    };
    const apagar = (chave) => localStorage.removeItem(P + chave);

    const dois = (n) => String(n).padStart(2, '0');
    const agora = () => {
        const d = new Date();
        return `${d.getFullYear()}-${dois(d.getMonth() + 1)}-${dois(d.getDate())} `
            + `${dois(d.getHours())}:${dois(d.getMinutes())}:${dois(d.getSeconds())}`;
    };
    const somaHoras = (dataHora, horas) => {
        const d = new Date(String(dataHora).replace(' ', 'T'));
        d.setHours(d.getHours() + (Number(horas) || 8));
        return `${d.getFullYear()}-${dois(d.getMonth() + 1)}-${dois(d.getDate())} `
            + `${dois(d.getHours())}:${dois(d.getMinutes())}:${dois(d.getSeconds())}`;
    };
    const uid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    }));

    const porOsMap = () => ler('osdoc', {});

    const api = {
        agora,
        somaHoras,
        uid,

        // Sessão (permite abrir o app sem internet)
        sessao: () => ler('user'),
        gravarSessao: (u) => gravar('user', u),

        // Catálogo (riscos, EPIs, EPCs, textos…)
        catalogo: () => ler('catalogo'),
        gravarCatalogo: (c) => gravar('catalogo', c),

        // Listas de OS por data e detalhes por OS
        osLista: (data) => ler('os:' + (data || 'todas'), []),
        gravarOsLista: (data, linhas) => gravar('os:' + (data || 'todas'), linhas),
        osDetalhe: (id) => ler('osd:' + id),
        gravarOsDetalhe: (id, os) => gravar('osd:' + id, os),

        // Documentos guardados no aparelho (pendentes de envio ou espelho local)
        doc: (uuid) => ler('doc:' + uuid),
        docDoOs: (osId) => {
            const uuid = porOsMap()[String(osId)];
            return uuid ? ler('doc:' + uuid) : null;
        },
        docsPendentes: () => Object.values(porOsMap())
            .map((u) => ler('doc:' + u))
            .filter((d) => d && d.pendente),
        contarPendentes: () => api.docsPendentes().length,

        /** Salva/atualiza o documento local a partir do formulário. */
        salvarLocal(osId, campos, extra = {}) {
            const uuid = campos.uuid || extra.uuid || uid();
            const atual = ler('doc:' + uuid) || {
                uuid,
                os_id: osId,
                criado_em: agora(),
                pendente: true,
                status: 'rascunho',
                equipe: [],
                assinaturas: [],
                pt_autorizacao: '',
                pt_supervisor_nome: '',
                pt_supervisor_registro: '',
                pt_encerramento_status: '',
                encerramento_obs: '',
            };
            const doc = Object.assign({}, atual, extra);
            doc.uuid = uuid;
            doc.os_id = osId;
            if (extra.pendente === undefined) doc.pendente = true;

            ['nr10', 'nr35', 'normas', 'riscos', 'epis', 'epcs', 'checklist', 'ferramentas',
                'local_atividade', 'responsavel_apr', 'etapas', 'treinamento_nr10', 'treinamento_nr35',
                'aso_apto', 'observacoes', 'validade_inicio', 'validade_fim', 'tecnico_id'].forEach((k) => {
                if (campos[k] !== undefined) doc[k] = campos[k];
            });
            if (campos.equipe) doc.equipe = campos.equipe;
            // Rascunho não rebaixa um documento que já foi assinado
            if (campos.status && !(doc.assinaturas || []).length) doc.status = campos.status;

            doc.atualizado_em = agora();
            gravar('doc:' + uuid, doc);
            const mapa = porOsMap();
            mapa[String(osId)] = uuid;
            gravar('osdoc', mapa);
            return doc;
        },

        /** Assinatura do técnico feita no aparelho. */
        assinarLocal(uuid, dados) {
            const doc = ler('doc:' + uuid);
            if (!doc) return null;
            doc.assinaturas = (doc.assinaturas || []).filter((a) => a.tipo !== 'tecnico');
            doc.assinaturas.push(Object.assign({ tipo: 'tecnico', criado_em: agora() }, dados));
            doc.status = 'aguardando_aprovacao';
            doc.pendente = true;
            doc.atualizado_em = agora();
            gravar('doc:' + uuid, doc);
            return doc;
        },

        /** Liberação da PT pelo supervisor feita no aparelho. */
        aprovarLocal(uuid, dados) {
            const doc = ler('doc:' + uuid);
            if (!doc) return null;
            doc.assinaturas = (doc.assinaturas || []).filter((a) => a.tipo !== 'supervisor');
            doc.assinaturas.push(Object.assign({ tipo: 'supervisor', criado_em: agora() }, dados));
            doc.pt_autorizacao = dados.pt_autorizacao || 'AUTORIZADA';
            doc.pt_supervisor_nome = dados.pt_supervisor_nome || '';
            doc.pt_supervisor_registro = dados.pt_supervisor_registro || '';
            doc.status = doc.pt_autorizacao === 'AUTORIZADA' ? 'liberada' : 'cancelada';
            doc.pendente = true;
            doc.atualizado_em = agora();
            gravar('doc:' + uuid, doc);
            return doc;
        },

        /** Encerramento da PT pelo supervisor feito no aparelho. */
        encerrarLocal(uuid, dados) {
            const doc = ler('doc:' + uuid);
            if (!doc) return null;
            doc.assinaturas = (doc.assinaturas || []).filter((a) => a.tipo !== 'supervisor_encerramento');
            doc.assinaturas.push(Object.assign({ tipo: 'supervisor_encerramento', criado_em: agora() }, dados));
            doc.pt_encerramento_status = dados.pt_encerramento_status || 'CONCLUIDO';
            doc.encerramento_obs = dados.encerramento_obs || '';
            doc.status = doc.pt_encerramento_status === 'CONCLUIDO' ? 'encerrada' : 'cancelada';
            doc.pendente = true;
            doc.atualizado_em = agora();
            gravar('doc:' + uuid, doc);
            return doc;
        },

        /** Pacote enviado ao servidor (POST /api/documentos/sync). */
        pacote(local) {
            return {
                uuid: local.uuid,
                os_id: local.os_id,
                tecnico_id: local.tecnico_id || null,
                status: local.status,
                criado_em: local.criado_em,
                campos: {
                    nr10: local.nr10,
                    nr35: local.nr35,
                    normas: local.normas,
                    riscos: local.riscos,
                    epis: local.epis,
                    epcs: local.epcs,
                    checklist: local.checklist,
                    ferramentas: local.ferramentas,
                    local_atividade: local.local_atividade,
                    responsavel_apr: local.responsavel_apr,
                    etapas: local.etapas,
                    treinamento_nr10: local.treinamento_nr10,
                    treinamento_nr35: local.treinamento_nr35,
                    aso_apto: local.aso_apto,
                    observacoes: local.observacoes,
                    validade_inicio: local.validade_inicio,
                    validade_fim: local.validade_fim,
                },
                equipe: local.equipe || [],
                assinaturas: local.assinaturas || [],
            };
        },

        /** Marca como enviado (guarda o espelho do servidor e libera espaço). */
        concluir(uuid, salvo) {
            const doc = ler('doc:' + uuid) || { uuid };
            doc.pendente = false;
            doc.id = salvo.id || doc.id || null;
            doc.status = salvo.status || doc.status;
            doc.atualizado_em = agora();
            // As assinaturas já estão no servidor: mantém só a referência (libera espaço)
            doc.assinaturas = (doc.assinaturas || []).map((a) => Object.assign({}, a, { imagem_base64: null }));
            gravar('doc:' + uuid, doc);
        },

        remover(uuid) {
            apagar('doc:' + uuid);
            const mapa = porOsMap();
            Object.keys(mapa).forEach((k) => { if (mapa[k] === uuid) delete mapa[k]; });
            gravar('osdoc', mapa);
        },

        /** Limpa caches de consulta (mantém os documentos pendentes). */
        limparCaches() {
            Object.keys(localStorage)
                .filter((k) => k.startsWith(P + 'os:') || k.startsWith(P + 'osd:') || k === P + 'catalogo')
                .forEach((k) => localStorage.removeItem(k));
        },
    };
    return api;
})();
