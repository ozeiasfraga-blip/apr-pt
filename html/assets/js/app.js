// APR/PT — interface (rotas por hash, views, gestão de usuários e documento APR/PT)
(function () {
    'use strict';

    const $ = (s, r = document) => r.querySelector(s);
    const $$ = (s, r = document) => [...r.querySelectorAll(s)];
    const state = { user: null, funcionarios: null, catalogo: null, apkVersao: null };

    const ROLE_LABEL = { tecnico: 'Técnico', supervisor: 'Supervisor', admin: 'Administrador' };
    const STATUS_LABEL = {
        rascunho: 'Rascunho', aguardando_aprovacao: 'Aguardando aprovação',
        liberada: 'Liberada', em_execucao: 'Em execução', encerrada: 'Encerrada', cancelada: 'Cancelada',
    };
    const OS_STATUS = { A: 'Aberta', AN: 'Análise', EN: 'Encaminhada', AS: 'Assumida', AG: 'Agendada', DS: 'Deslocamento', EX: 'Execução', F: 'Finalizada', RAG: 'Aguard. agendamento' };
    const NORMAS = ['NR-35', 'NR-10', 'Via pública', 'Outros'];
    let cfgFerramentasMin = 0; // mínimo de ferramentas exigido para assinar (config_textos)

    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));
    const isSupervisor = () => ['supervisor', 'admin'].includes(state.user?.role);
    const uid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0; return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    }));
    const localDate = () => { const d = new Date(); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10); };
    function nivel(sev, prob) {
        const n = (+sev) * (+prob);
        if (!n) return '—';
        if (n <= 4) return 'Baixo'; if (n <= 9) return 'Médio'; if (n <= 16) return 'Alto'; return 'Crítico';
    }
    const NIVEL_CLS = { '—': 'lvl-none', 'Baixo': 'lvl-baixo', 'Médio': 'lvl-medio', 'Alto': 'lvl-alto', 'Crítico': 'lvl-critico' };
    const nivelCls = (t) => NIVEL_CLS[t] || 'lvl-none';
    const nivelTexto = (sev, prob) => { const t = nivel(sev, prob); return { texto: t, cls: 'rk-nivel ' + nivelCls(t) }; };
    function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }

    // ── UI ──────────────────────────────────────────────────
    let toastTimer;
    function toast(msg, isError = false) {
        const t = $('#toast'); t.textContent = msg; t.className = 'toast' + (isError ? ' error' : '');
        clearTimeout(toastTimer); toastTimer = setTimeout(() => t.classList.add('hidden'), 3800);
    }
    function openModal(title, html) { $('#modal-title').textContent = title; $('#modal-body').innerHTML = html; $('#modal').classList.remove('hidden'); }
    function closeModal() { $('#modal').classList.add('hidden'); }

    // ── Atualização do aplicativo (APK) ────────────────────
    // As mudanças na web chegam sozinhas (sem baixar nada). O banner aparece
    // apenas quando publicamos uma nova versão do APK em /apk/versao.json.
    async function verificarAtualizacaoApp() {
        try {
            const r = await fetch('/apk/versao.json', { cache: 'no-store' });
            if (!r.ok) return;
            state.apkVersao = await r.json();
            exibirBannerSeNecessario();
        } catch (_) { /* offline ou sem versão publicada */ }
    }
    // Detecta o app Android nativo (window.AprptApp — ver android/.../MainActivity.java)
    function versaoAppNativo() {
        try {
            return (window.AprptApp && window.AprptApp.versao) ? String(window.AprptApp.versao()) : null;
        } catch (_) { return null; }
    }
    function exibirBannerSeNecessario() {
        const v = state.apkVersao;
        if (!v || !v.versao || $('#apk-banner')) return;

        // Só anuncia o APK dentro de um app instalado (nativo ou PWA em modo standalone).
        const nativa = versaoAppNativo();
        const noApp = !!nativa || window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
        if (!noApp) return;

        // Já estamos no app nativo e na versão publicada: nada para atualizar.
        if (nativa && nativa === v.versao) {
            localStorage.setItem('aprpt_apk_versao', v.versao);
            return;
        }

        const visto = localStorage.getItem('aprpt_apk_versao');
        if (v.versao === visto) return;

        const el = document.createElement('div');
        el.id = 'apk-banner';
        el.className = 'apk-banner';
        el.innerHTML = `
            <div class="apk-banner-txt">
                <strong>${noApp ? 'Nova versão disponível' : 'Versão do aplicativo disponível'}</strong>
                <span class="muted">Versão ${esc(v.versao)}${v.novidades ? ' — ' + esc(v.novidades) : ''}</span>
            </div>
            <div class="apk-banner-acoes">
                <a class="btn-link" href="${esc(v.apk || '/apk/app.apk')}">Atualizar agora</a>
                <button type="button" class="secondary" id="apk-banner-depois">Depois</button>
            </div>`;
        const host = $('#content');
        if (!host) return;
        host.prepend(el);
        $('#apk-banner-depois').addEventListener('click', () => {
            localStorage.setItem('aprpt_apk_versao', v.versao);
            el.remove();
        });
        $('.apk-banner .btn-link', el).addEventListener('click', () => {
            localStorage.setItem('aprpt_apk_versao', v.versao);
        });
    }

    async function getCatalogo() {
        if (state.catalogo) return state.catalogo;
        try {
            state.catalogo = await Api.catalogo();
            Offline.gravarCatalogo(state.catalogo);
        } catch (e) {
            const salvo = Offline.catalogo();
            if (!salvo) throw e;                 // sem cópia no aparelho: propaga o erro
            state.catalogo = salvo;
        }
        return state.catalogo;
    }

    // ── Autenticação ────────────────────────────────────────
    function showLogin() { $('#view-login').classList.remove('hidden'); $('#view-app').classList.add('hidden'); location.hash = ''; }
    function showApp(user) {
        state.user = user;
        Offline.gravarSessao(user);
        $('#view-login').classList.add('hidden'); $('#view-app').classList.remove('hidden');
        $('#user-info').textContent = `${user.name} · ${ROLE_LABEL[user.role] || user.role}`;
        const iniciais = String(user.name || user.email || '?').trim().split(/\s+/).slice(0, 2)
            .map((p) => p[0]).join('').toUpperCase();
        $('#user-avatar').textContent = iniciais || '?';
        $$('#nav a[data-roles]').forEach((a) => a.classList.toggle('hidden', !a.dataset.roles.split(',').includes(user.role)));
        render();
        verificarAtualizacaoApp();
        sincronizarPendentes();      // envia o que ficou guardado de outra sessão
    }

    // ── Roteador ────────────────────────────────────────────
    function render() {
        const hash = (location.hash || '#os').replace('#', '');
        const [view, param] = hash.split('/');
        $$('#nav a').forEach((a) => a.classList.toggle('active', a.dataset.view === view));
        setTimeout(atualizarAvisos, 0);

        if (view === 'usuarios') { if (!isSupervisor()) { location.hash = '#os'; return; } return renderUsuarios(); }
        if (view === 'modelo') { if (!isSupervisor()) { location.hash = '#os'; return; } return renderModelo(); }
        if (view === 'documentos') return renderDocumentos();
        if (view === 'doc') return renderDocForm(param);
        return renderOs();
    }

    // ── Modo offline (dados salvos no aparelho) ─────────────
    function atualizarAvisos() {
        desenharBarraOffline();
        exibirBannerSeNecessario();
    }

    // Recoloca os documentos locais sobre a lista de OS (status/ações corretos)
    function aplicarDocsLocais() {
        osCache.forEach((o) => {
            const local = Offline.docDoOs(o.id);
            if (local && (local.pendente || !o.documento)) {
                o.documento = { id: local.id || null, uuid: local.uuid, status: local.status, local: true };
            }
        });
    }

    // Guarda no aparelho o detalhe das OS do dia (para abrir sem internet)
    async function preBuscarDetalhes() {
        if (state.user?.role !== 'tecnico') return;
        const fila = osCache.filter((o) => !Offline.osDetalhe(o.id)).slice(0, 60);
        for (const o of fila) {
            if (!navigator.onLine) break;
            try { Offline.gravarOsDetalhe(o.id, await Api.getOs(o.id)); } catch (_) { break; }
        }
    }

    // Envia ao servidor os documentos preenchidos sem internet
    async function sincronizarPendentes(silencioso = true) {
        const pendentes = Offline.docsPendentes();
        if (!pendentes.length || !navigator.onLine) {
            desenharBarraOffline();
            return 0;
        }
        let enviados = 0;
        for (const local of pendentes) {
            try {
                const salvo = await Api.syncDocumento(Offline.pacote(local));
                Offline.concluir(local.uuid, salvo);
                enviados++;
            } catch (e) {
                if (e.offline) break;            // sem rede: tenta de novo depois
                console.warn('Falha ao enviar documento offline:', local.uuid, e.message);
                break;                           // erro de dados: não insiste em loop
            }
        }
        if (enviados) {
            if (!silencioso) toast(`${enviados} documento(s) enviado(s) ao servidor.`);
            render();
        } else {
            desenharBarraOffline();
        }
        return enviados;
    }

    // Barra de aviso: sem conexão / documentos aguardando envio
    function desenharBarraOffline() {
        const host = $('#content');
        if (!host) return;
        let bar = $('#offline-bar');
        const semRede = !navigator.onLine;
        const pend = Offline.contarPendentes();
        if (!semRede && !pend) { if (bar) bar.remove(); return; }
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'offline-bar';
            bar.className = 'offline-bar';
            host.prepend(bar);
        }
        bar.innerHTML = `<span>${semRede
            ? 'Sem conexão — você está usando os dados salvos no aparelho.'
            : `${pend} documento(s) aguardando envio ao servidor.`}</span>`
            + (!semRede && pend ? ' <button type="button" class="secondary small" id="off-sync">Enviar agora</button>' : '');
        const btn = $('#off-sync', bar);
        if (btn) btn.addEventListener('click', () => sincronizarPendentes(false));
    }

    // ── View: Ordens de Serviço ─────────────────────────────
    let osCache = [];
    let docEquipe = [];
    async function renderOs() {
        const c = $('#content');
        c.innerHTML = `
            <div class="page-head">
                <h1>Ordens de Serviço</h1>
                <div class="toolbar">
                    <input type="date" id="os-date" value="${localDate()}">
                    <button id="btn-hoje" class="secondary">Hoje</button>
                    ${isSupervisor() ? '<button id="btn-sync">Sincronizar IXC</button>' : ''}
                </div>
            </div>
            <div class="filters">
                <input type="search" id="os-search" placeholder="Buscar cliente, endereço, protocolo…">
                ${isSupervisor() ? '<select id="os-tec"><option value="">Todos os técnicos</option></select>' : ''}
                <select id="os-status"><option value="">Status da OS: todos</option>${Object.entries(OS_STATUS).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select>
                <select id="os-doc">
                    <option value="">APR/PT: todos</option>
                    <option value="sem">Sem documento</option>
                    ${STATUS_ORDEM.map((k) => `<option value="${k}">${STATUS_LABEL[k]}</option>`).join('')}
                </select>
            </div>
            <div id="os-body"><p class="loading">Carregando…</p></div>`;

        const load = async () => {
            const data = $('#os-date').value;
            try {
                osCache = await Api.listOs({ date: data });
                Offline.gravarOsLista(data, osCache);
                aplicarDocsLocais();
                fillTecOptions(); applyOsFilters();
                desenharBarraOffline();
                preBuscarDetalhes();
            } catch (e) {
                if (e.offline) {
                    osCache = Offline.osLista(data);
                    aplicarDocsLocais();
                    fillTecOptions(); applyOsFilters();
                    desenharBarraOffline();
                    if (!osCache.length) {
                        $('#os-body').innerHTML = '<p class="muted">Sem conexão e sem OS salvas para esta data.</p>';
                    }
                    return;
                }
                $('#os-body').innerHTML = `<p class="error">${esc(e.message)}</p>`;
            }
        };
        if (isSupervisor()) $('#btn-sync').addEventListener('click', async () => {
            toast('Sincronizando com o IXC…');
            try { const r = await Api.syncOs($('#os-date').value); toast(`Sincronizadas ${r.sincronizadas} de ${r.total} OS.`); load(); }
            catch (e) { toast(e.message, true); }
        });
        $('#os-date').addEventListener('change', load);
        $('#btn-hoje').addEventListener('click', () => { $('#os-date').value = localDate(); load(); });
        $('#os-search').addEventListener('input', applyOsFilters);
        $('#os-status').addEventListener('change', applyOsFilters);
        if ($('#os-doc')) $('#os-doc').addEventListener('change', applyOsFilters);
        if ($('#os-tec')) $('#os-tec').addEventListener('change', applyOsFilters);
        load();
    }
    function fillTecOptions() {
        const sel = $('#os-tec'); if (!sel) return; const atual = sel.value;
        const mapa = new Map();
        osCache.forEach((o) => { if (o.id_tecnico) mapa.set(String(o.id_tecnico), o.tecnico_nome || ('Técnico ' + o.id_tecnico)); });
        sel.innerHTML = '<option value="">Todos os técnicos</option>' + [...mapa.entries()].sort((a, b) => a[1].localeCompare(b[1])).map(([id, n]) => `<option value="${id}">${esc(n)}</option>`).join('');
        sel.value = atual;
    }
    function applyOsFilters() {
        const q = ($('#os-search').value || '').toLowerCase();
        const tec = $('#os-tec') ? $('#os-tec').value : '';
        const st = $('#os-status').value;
        const dd = $('#os-doc') ? $('#os-doc').value : '';
        const rows = osCache.filter((o) => {
            if (tec && String(o.id_tecnico) !== tec) return false;
            if (st && o.status_ixc !== st) return false;
            if (dd) { const s = o.documento ? o.documento.status : 'sem'; if (s !== dd) return false; }
            if (q && ![o.cliente, o.tecnico_nome, o.endereco, o.bairro, o.cidade, o.assunto, o.protocolo, o.ixc_id].join(' ').toLowerCase().includes(q)) return false;
            return true;
        });
        const body = $('#os-body');
        if (!rows.length) { body.innerHTML = '<p class="muted">Nenhuma OS encontrada com esses filtros.</p>'; return; }
        body.innerHTML = `<table class="grid">
            <thead><tr><th>Hora</th><th>Cliente</th>${isSupervisor() ? '<th>Técnico</th>' : ''}<th>Assunto</th><th>Status</th><th>APR/PT</th><th></th></tr></thead>
            <tbody>${rows.map(rowOs).join('')}</tbody></table>`;
        $$('[data-abrir]').forEach((b) => b.addEventListener('click', () => { location.hash = '#doc/' + b.dataset.abrir; }));
        $$('#os-body tr[data-linha-os]').forEach((tr) => tr.addEventListener('click', (ev) => {
            if (ev.target.closest('button')) return;
            location.hash = '#doc/' + tr.dataset.linhaOs;
        }));
    }
    function rowOs(o) {
        const hora = (o.data_agenda || '').slice(11, 16);
        const d = o.documento;
        const doc = d ? `<span class="tag tag-${d.status}">${STATUS_LABEL[d.status] || d.status}</span>` : '<span class="muted">—</span>';
        const acao = isSupervisor() ? 'Abrir' : (d ? 'Ver / continuar' : 'Preencher');
        return `<tr class="linha-os" data-linha-os="${o.id}">
            <td class="c-hora">${esc(hora)}</td>
            <td class="c-cliente">${esc(o.cliente || '(sem nome)')}<br><small class="muted">#${esc(o.ixc_id)} · ${esc(o.cidade || '')}</small></td>
            ${isSupervisor() ? `<td class="c-tec">${esc(o.tecnico_nome || '')}</td>` : ''}
            <td class="c-assunto">${esc(o.assunto || '')}</td>
            <td class="c-status"><span class="tag">${esc(OS_STATUS[o.status_ixc] || o.status_ixc || '')}</span></td>
            <td class="c-doc">${doc}</td>
            <td class="c-acao"><button class="link" data-abrir="${o.id}">${acao}</button></td>
        </tr>`;
    }

    // ── View: APR/PT (documentos) ───────────────────────────
    const STATUS_ORDEM = ['aguardando_aprovacao', 'rascunho', 'liberada', 'em_execucao', 'encerrada', 'cancelada'];
    async function renderDocumentos() {
        const c = $('#content');
        c.innerHTML = `
            <div class="page-head">
                <h1>APR / PT</h1>
                <div class="toolbar">
                    <input type="date" id="d-date" value="${localDate()}">
                    <button id="d-hoje" class="secondary">Hoje</button>
                    <button id="d-todos" class="secondary">Todo o período</button>
                </div>
            </div>
            <div id="d-resumo" class="resumo"></div>
            <div class="filters">
                <input type="search" id="d-search" placeholder="Buscar cliente, OS, técnico…">
                ${isSupervisor() ? '<select id="d-tec"><option value="">Todos os técnicos</option></select>' : ''}
                <select id="d-status"><option value="">Todos os status</option>${STATUS_ORDEM.map((k) => `<option value="${k}">${STATUS_LABEL[k]}</option>`).join('')}</select>
            </div>
            <div id="d-body"><p class="loading">Carregando…</p></div>`;

        const resumo = async () => {
            try {
                const r = await Api.resumoDocumentos();
                const chips = STATUS_ORDEM.filter((k) => r[k] > 0)
                    .map((k) => `<button class="stat tag-${k}" data-set="${k}">${STATUS_LABEL[k]}: <strong>${r[k]}</strong></button>`).join('');
                $('#d-resumo').innerHTML = `<span class="stat total">Total: <strong>${r.total}</strong></span>${chips}`;
                $$('#d-resumo [data-set]').forEach((b) => b.addEventListener('click', () => {
                    $('#d-status').value = b.dataset.set; load();
                }));
            } catch (_) { $('#d-resumo').innerHTML = ''; }
        };

        const load = async () => {
            const params = {};
            if ($('#d-date').value) params.date = $('#d-date').value;
            if ($('#d-status').value) params.status = $('#d-status').value;
            if ($('#d-search').value) params.search = $('#d-search').value;
            if ($('#d-tec') && $('#d-tec').value) params.tecnico_id = $('#d-tec').value;
            try {
                const docs = await Api.listDocumentos(params);
                fillDocTecOptions(docs);
                $('#d-body').innerHTML = docs.length ? `<table class="grid">
                    <thead><tr><th>OS</th><th>Cliente</th>${isSupervisor() ? '<th>Técnico</th>' : ''}<th>Data</th><th>Status</th><th>Supervisor</th><th></th></tr></thead>
                    <tbody>${linhasDocs(docs)}</tbody></table>`
                    : '<p class="muted">Nenhum documento encontrado com esses filtros.</p>';
                $$('[data-abrir-doc]').forEach((b) => b.addEventListener('click', () => { location.hash = '#doc/' + b.dataset.abrirDoc; }));
                $$('#d-body tr[data-linha-doc]').forEach((tr) => tr.addEventListener('click', (ev) => {
                    if (ev.target.closest('button')) return;
                    location.hash = '#doc/' + tr.dataset.linhaDoc;
                }));
            } catch (e) { $('#d-body').innerHTML = `<p class="error">${esc(e.message)}</p>`; }
        };

        const fillDocTecOptions = (docs) => {
            const sel = $('#d-tec'); if (!sel || sel.value) return;
            const mapa = new Map();
            docs.forEach((d) => { if (d.tecnico_id) mapa.set(String(d.tecnico_id), d.tecnico_nome || ('#' + d.tecnico_id)); });
            sel.innerHTML = '<option value="">Todos os técnicos</option>' + [...mapa.entries()].sort((a, b) => a[1].localeCompare(b[1])).map(([id, n]) => `<option value="${id}">${esc(n)}</option>`).join('');
        };

        $('#d-hoje').addEventListener('click', () => { $('#d-date').value = localDate(); load(); });
        $('#d-todos').addEventListener('click', () => { $('#d-date').value = ''; load(); });
        $('#d-date').addEventListener('change', load);
        $('#d-search').addEventListener('input', debounce(load, 350));
        $('#d-status').addEventListener('change', load);
        if ($('#d-tec')) $('#d-tec').addEventListener('change', load);
        resumo();
        load();
    }
    /** Lista de APR/PT agrupada por status (o que precisa de ação aparece primeiro). */
    function linhasDocs(docs) {
        const cols = isSupervisor() ? 7 : 6;
        const conhecidos = new Set(STATUS_ORDEM);
        const grupos = STATUS_ORDEM.map((st) => [st, docs.filter((d) => d.status === st)]);
        const outros = docs.filter((d) => !conhecidos.has(d.status));
        if (outros.length) grupos.push(['outros', outros]);

        return grupos.filter(([, lista]) => lista.length).map(([st, lista]) => {
            const rotulo = st === 'outros' ? 'Outros' : (STATUS_LABEL[st] || st);
            return `<tr class="linha-grupo"><td colspan="${cols}">${esc(rotulo)} · ${lista.length}</td></tr>`
                + lista.map(rowDoc).join('');
        }).join('');
    }
    function rowDoc(d) {
        const data = (d.data_agenda || '').slice(0, 10).split('-').reverse().join('/');
        return `<tr class="linha-doc" data-linha-doc="${d.os_id}">
            <td class="c-os">#${esc(d.ixc_id || d.os_id)}</td>
            <td class="c-cliente">${esc(d.cliente || '')}</td>
            ${isSupervisor() ? `<td class="c-tec">${esc(d.tecnico_nome || '')}</td>` : ''}
            <td class="c-data">${esc(data)}</td>
            <td class="c-status"><span class="tag tag-${d.status}">${STATUS_LABEL[d.status] || d.status}</span></td>
            <td class="c-sup">${esc(d.supervisor_nome || '')}</td>
            <td class="c-acao"><button class="link" data-abrir-doc="${d.os_id}">Abrir</button></td>
        </tr>`;
    }

    // ── View: Formulário APR/PT ─────────────────────────────
    async function renderDocForm(osId) {
        const c = $('#content');
        c.innerHTML = '<p class="loading">Carregando…</p>';
        let os, cat;
        try {
            os = await Api.getOs(osId);
            Offline.gravarOsDetalhe(osId, os);
        } catch (e) {
            if (!e.offline) { c.innerHTML = `<p class="error">${esc(e.message)}</p>`; return; }
            os = Offline.osDetalhe(osId) || (osCache || []).find((o) => String(o.id) === String(osId));
            if (!os) {
                c.innerHTML = '<p class="error">Esta OS não está salva no aparelho. Conecte-se à internet uma vez para abri-la.</p>';
                return;
            }
        }
        const local = Offline.docDoOs(os.id);
        if (local) os.documento = local;          // documento guardado no aparelho tem prioridade
        try { cat = await getCatalogo(); }
        catch (e) { c.innerHTML = `<p class="error">${esc(e.message)}</p>`; return; }

        const doc = os.documento;
        if (doc && doc.riscos === undefined) {
            // Documento existe no servidor, mas só temos a "capa" dele no aparelho
            c.innerHTML = `
                <div class="page-head">
                    <h1>APR / PT — OS #${esc(os.ixc_id || '')}</h1>
                    <div class="toolbar"><button class="secondary" id="doc-voltar">Voltar</button></div>
                </div>
                <p class="muted">Este documento já existe no servidor, mas os dados completos não estão salvos no aparelho. Conecte-se à internet uma vez para abri-lo (depois ele fica disponível offline).</p>`;
            $('#doc-voltar').addEventListener('click', () => { location.hash = isSupervisor() ? '#documentos' : '#os'; });
            return;
        }
        const status = doc?.status || 'rascunho';
        const ehDono = state.user.role === 'tecnico' && Number(state.user.ixc_funcionario_id) === Number(os.id_tecnico);
        const podeEditar = (!doc || status === 'rascunho') && (ehDono || state.user.role === 'admin');
        const podeLiberar = isSupervisor() && status === 'aguardando_aprovacao';
        const podeEncerrar = isSupervisor() && ['liberada', 'em_execucao'].includes(status);
        const readOnly = !podeEditar;
        const sigs = {}; toArr(doc?.assinaturas).forEach((a) => { sigs[a.tipo] = a; });
        const sigImg = (t) => {
            const s = sigs[t];
            const src = s?.imagem_base64 || (s?.imagem_path ? `/api/arquivo?p=${encodeURIComponent(s.imagem_path)}` : '');
            return src ? `<img src="${src}" style="height:58px">` : '<span class="muted">—</span>';
        };
        const autPT = doc?.pt_autorizacao || '';
        const encPT = doc?.pt_encerramento_status || '';
        const fmtData = (v) => (v ? String(v).slice(0, 10).split('-').reverse().join('/') : '—');

        const riscosSel = keyed(doc?.riscos, 'codigo');
        const episSel = new Set(toArr(doc?.epis));
        const epcsSel = new Set(toArr(doc?.epcs));
        const chkSel = {};
        toArr(doc?.checklist).forEach((i) => { chkSel[i.codigo || i.descricao] = i.resposta; });
        const normasSel = new Set(toArr(doc?.normas).length ? toArr(doc?.normas) : [doc?.nr35 ? 'NR-35' : null, doc?.nr10 ? 'NR-10' : null].filter(Boolean));
        const ferrSel = new Set(toArr(doc?.ferramentas));
        cfgFerramentasMin = Number(cat.textos.ferramentas_minimas) || 0;
        if (!doc && !readOnly) cat.ferramentas.filter((x) => Number(x.padrao)).forEach((x) => ferrSel.add(x.descricao));
        const gruposRiscos = [];
        cat.riscos.forEach((r) => {
            const nome = r.categoria || 'OUTROS';
            let g = gruposRiscos.find((x) => x.nome === nome);
            if (!g) { g = { nome, itens: [] }; gruposRiscos.push(g); }
            g.itens.push(r);
        });
        docEquipe = toArr(doc?.equipe).map((m) => ({
            nome: m.nome || '', funcao: m.funcao || '', cpf: m.cpf || '',
            assinatura_base64: null, assinatura_path: m.assinatura_path || null,
            assinado: !!m.assinatura_path,
        }));

        c.innerHTML = `
            <div class="page-head">
                <h1>APR / PT — OS #${esc(os.ixc_id)}</h1>
                <div class="toolbar">
                    <span class="tag tag-${status}">${STATUS_LABEL[status] || status}</span>
                    ${doc?.id ? `<a class="btn-link" target="_blank" href="/api/documentos/${doc.id}/pdf">PDF</a>` : ''}
                    <button class="secondary" id="doc-voltar">Voltar</button>
                </div>
            </div>

            <section class="panel">
                <h2><span class="step">1</span>Identificação</h2>
                <div class="empresa">
                    <strong>${esc(cat.textos.empresa_razao || '')}</strong>
                    <small>CNPJ: ${esc(cat.textos.empresa_cnpj || '')}</small>
                    <small>${esc(cat.textos.empresa_endereco || '')}</small>
                </div>
                <div class="grid2">
                    <div><span class="label">Cliente</span><div>${esc(os.cliente || '')}</div></div>
                    <div><span class="label">Protocolo / OS</span><div>#${esc(os.ixc_id)} ${esc(os.protocolo || '')}</div></div>
                    <div><span class="label">Atividade</span><div>${esc(os.assunto || '')}</div></div>
                    <div><span class="label">Data</span><div>${fmtData(doc?.validade_inicio || new Date().toISOString().slice(0, 10))}</div></div>
                    <label class="field"><span class="label">Local da Atividade</span>
                        <input type="text" id="doc-local" value="${esc(doc?.local_atividade || [os.endereco, os.bairro, os.cidade].filter(Boolean).join(', '))}" ${readOnly ? 'disabled' : ''}></label>
                    <label class="field"><span class="label">Responsável pela APR</span>
                        <input type="text" id="doc-responsavel" value="${esc(doc?.responsavel_apr || state.user.name || '')}" ${readOnly ? 'disabled' : ''}></label>
                </div>
            </section>

            <section class="panel">
                <h2><span class="step">2</span>Análise Preliminar de Risco (APR)</h2>

                <div class="field"><span class="label">Normas aplicáveis</span>
                    <div class="chips">${NORMAS.map((n) => `<label class="chip"><input type="checkbox" class="norma" value="${esc(n)}" ${normasSel.has(n) ? 'checked' : ''} ${readOnly ? 'disabled' : ''}> ${esc(n)}</label>`).join('')}</div>
                    <small class="muted">Ao marcar uma NR, os riscos, EPIs/EPCs, checklist e os treinamentos correspondentes são marcados automaticamente.</small>
                </div>

                <span class="label">Ferramentas Utilizadas</span>
                <div class="checks">${cat.ferramentas.map((x) => `<label class="chip"><input type="checkbox" class="ferramenta" data-codigo="${esc(x.codigo)}" value="${esc(x.descricao)}" ${ferrSel.has(x.descricao) ? 'checked' : ''} ${readOnly ? 'disabled' : ''}> ${esc(x.descricao)}</label>`).join('')}</div>
                <small class="muted" id="ferr-ajuda"></small>

                <span class="label">EPIs e EPCs Obrigatórios</span>
                <div class="checks">${cat.epis.map((x) => `<label class="chip"><input type="checkbox" class="epi" data-nr="${esc(x.nr || '')}" value="${esc(x.descricao)}" ${episSel.has(x.descricao) ? 'checked' : ''} ${readOnly ? 'disabled' : ''}> ${esc(x.descricao)}</label>`).join('')}</div>

                <div class="field"><span class="label">Riscos, impactos e medidas de controle</span>
                    <small class="muted">Marque apenas os riscos correspondentes à atividade que será executada.</small>
                    ${gruposRiscos.map((g) => `
                        <div class="grupo-risco">${esc(g.nome)}</div>
                        <div class="table-wrap"><table class="grid compact">
                            <thead><tr><th style="width:38%">Risco</th><th>Sev.</th><th>Prob.</th><th>Nível</th><th>Medidas de controle</th></tr></thead>
                            <tbody>${g.itens.map((r) => riscoRow(r, riscosSel[r.codigo], readOnly)).join('')}</tbody>
                        </table></div>`).join('')}
                </div>

                <div class="termo"><strong>Autorização formal de trabalho.</strong> O empregado está formalmente autorizado a trabalhar pela empresa em atividades envolvendo riscos com eletricidade, conforme exigido pelo item 10.8.4 da NR-10, bem como apto para trabalhos em altura nos termos da NR-35.</div>
                <div class="field"><span class="label">Validade dos treinamentos / habilitação</span>
                    <small class="muted">Preenchido automaticamente quando há NR marcada — travado, não pode ser desmarcado.</small>
                    <div class="chips">
                        <label class="chip"><input type="checkbox" id="trn-nr10" ${doc && Number(doc.treinamento_nr10) ? 'checked' : ''} ${readOnly ? 'disabled' : ''}> Treinamento NR-10 válido</label>
                        <label class="chip"><input type="checkbox" id="trn-nr35" ${doc && Number(doc.treinamento_nr35) ? 'checked' : ''} ${readOnly ? 'disabled' : ''}> Treinamento NR-35 válido</label>
                        <label class="chip"><input type="checkbox" id="trn-aso" ${doc && Number(doc.aso_apto) ? 'checked' : ''} ${readOnly ? 'disabled' : ''}> ASO apto (altura/elétrica)</label>
                    </div>
                </div>

                <label class="field"><span class="label">Outras observações / riscos adicionais não mapeados</span>
                    <textarea id="doc-observacoes" rows="2" ${readOnly ? 'disabled' : ''}>${esc(doc?.observacoes || '')}</textarea></label>

                <label class="field"><span class="label">Etapas da atividade</span>
                    <textarea id="doc-etapas" rows="3" ${readOnly ? 'disabled' : ''}>${esc(doc?.etapas || '')}</textarea></label>

                <div class="termo"><strong>Termo de Ciência e Compromisso.</strong> ${esc(cat.textos.termo_ciencia || '')}</div>

                <div class="field">
                    <span class="label">Registro e Assinatura da Equipe Envolvida</span>
                    <div class="toolbar" style="margin-bottom:6px">
                        <label class="inline">Nº de integrantes:
                            <input type="number" id="eq-qtd" min="0" max="20" value="${docEquipe.length}" ${readOnly ? 'disabled' : ''} style="width:80px"></label>
                    </div>
                    <div class="table-wrap"><table class="grid compact">
                        <thead><tr><th style="width:36px">Nº</th><th>Nome</th><th>Função / Cargo</th><th>CPF / Matrícula</th><th style="width:130px">Assinatura</th></tr></thead>
                        <tbody id="eq-body"></tbody>
                    </table></div>
                </div>

                <div class="grid2">
                    <div><span class="label">Assinatura do Responsável pela APR</span><div>${sigImg('tecnico')}</div></div>
                    <div><span class="label">Data da assinatura</span><div>${fmtData(sigs['tecnico']?.assinado_em)}</div></div>
                </div>
            </section>

            <section class="panel">
                <h2><span class="step">3</span>PT — Permissão para Trabalho</h2>
                <div class="termo">${esc(cat.textos.autorizacao_supervisor || '')}</div>
                <div class="pt-status">
                    <span class="label">Status da Permissão</span>
                    <div class="chips">
                        <label class="chip"><input type="radio" disabled ${autPT === 'AUTORIZADA' ? 'checked' : ''}> <strong>AUTORIZADA</strong></label>
                        <label class="chip"><input type="radio" disabled ${autPT === 'NAO_AUTORIZADA' ? 'checked' : ''}> <strong style="color:var(--danger)">NÃO AUTORIZADA</strong></label>
                    </div>
                </div>
                <div class="grid2">
                    <div><span class="label">Nome do Supervisor / Emissor</span><div>${esc(doc?.pt_supervisor_nome || '—')}</div></div>
                    <div><span class="label">Registro / Cargo</span><div>${esc(doc?.pt_supervisor_registro || '—')}</div></div>
                </div>
                <div class="grid2">
                    <div><span class="label">Assinatura do Supervisor</span><div>${sigImg('supervisor')}</div></div>
                    <div><span class="label">Data da assinatura</span><div>${fmtData(sigs['supervisor']?.assinado_em)}</div></div>
                </div>
            </section>

            <section class="panel">
                <h2><span class="step">4</span>Encerramento da PT</h2>
                <div class="termo">${esc(cat.textos.declaracao_encerramento || '')}</div>
                <div class="pt-status">
                    <span class="label">Status do Encerramento</span>
                    <div class="chips">
                        <label class="chip"><input type="radio" disabled ${encPT === 'CONCLUIDO' ? 'checked' : ''}> Concluído com sucesso</label>
                        <label class="chip"><input type="radio" disabled ${encPT === 'SUSPENSO' ? 'checked' : ''}> Serviço suspenso / PT cancelada</label>
                    </div>
                </div>
                <div class="field"><span class="label">Observações do encerramento</span><div>${nl2br(doc?.encerramento_obs || '—')}</div></div>
                <div class="grid2">
                    <div><span class="label">Assinatura do Supervisor no Encerramento</span><div>${sigImg('supervisor_encerramento')}</div></div>
                    <div><span class="label">Data do encerramento</span><div>${fmtData(doc?.encerrado_em)}</div></div>
                </div>
            </section>

            <div class="sticky-actions">
                ${podeEditar ? '<button class="secondary" id="btn-rascunho">Salvar rascunho</button>' : ''}
                ${podeEditar ? '<button id="btn-assinar">Assinar e enviar para aprovação</button>' : ''}
                ${podeLiberar ? '<button id="btn-liberar">Autorizar PT (assinar)</button>' : ''}
                ${podeEncerrar ? '<button id="btn-encerrar">Assinar encerramento da PT</button>' : ''}
            </div>`;

        $('#doc-voltar').addEventListener('click', () => { location.hash = isSupervisor() ? '#documentos' : '#os'; });
        $$('.rk-sev, .rk-prob').forEach((s) => s.addEventListener('change', (ev) => {
            const tr = ev.target.closest('tr');
            const nl = $('.rk-nivel', tr);
            const n = nivelTexto($('.rk-sev', tr).value, $('.rk-prob', tr).value);
            nl.className = n.cls; nl.textContent = n.texto;
        }));
        $$('.rk-check').forEach((cb) => cb.addEventListener('change', () => { preencherRisco(cb.closest('tr')); aplicarFerramentas(); }));
        $$('.ferramenta').forEach((cb) => cb.addEventListener('change', atualizaFerramentasUI));
        aplicarFerramentas();   // risco já marcado traz a ferramenta associada
        atualizaFerramentasUI(); // contador e mínimo exigido
        $$('.norma').forEach((c) => c.addEventListener('change', aplicarNormas));
        travarTreinamentos(); // treinamentos/ASO: preenche e trava ao abrir o formulário

        // Equipe envolvida — assinatura por integrante
        function eqAssinCell(m, i) {
            if (m.assinado || m.assinatura_path) {
                const src = m.assinatura_base64 || (m.assinatura_path ? '/api/arquivo?p=' + encodeURIComponent(m.assinatura_path) : '');
                return `<img src="${src}" style="height:34px"> ${readOnly ? '' : '<button type="button" class="link eq-sign">reassinar</button>'}`;
            }
            return readOnly ? '<span class="muted">—</span>' : '<button type="button" class="link eq-sign">Assinar</button>';
        }
        function renderEquipeRows() {
            const tbody = $('#eq-body'); if (!tbody) return;
            if (!docEquipe.length) { tbody.innerHTML = '<tr><td colspan="5" class="muted">Informe o nº de integrantes acima.</td></tr>'; return; }
            tbody.innerHTML = docEquipe.map((m, i) => `<tr>
                <td>${String(i + 1).padStart(2, '0')}</td>
                <td><input class="eq-nome" value="${esc(m.nome)}" ${readOnly ? 'disabled' : ''}></td>
                <td><input class="eq-funcao" value="${esc(m.funcao)}" ${readOnly ? 'disabled' : ''}></td>
                <td><input class="eq-cpf" value="${esc(m.cpf)}" ${readOnly ? 'disabled' : ''}></td>
                <td>${eqAssinCell(m, i)}</td></tr>`).join('');
            $$('#eq-body tr').forEach((tr, i) => {
                const set = (sel, k) => { const el = $(sel, tr); if (el) el.addEventListener('input', () => { if (docEquipe[i]) docEquipe[i][k] = el.value; }); };
                set('.eq-nome', 'nome'); set('.eq-funcao', 'funcao'); set('.eq-cpf', 'cpf');
                const btn = $('.eq-sign', tr);
                if (btn) btn.addEventListener('click', async () => {
                    const sig = await askSignature('Assinatura — ' + ((docEquipe[i] && docEquipe[i].nome) || ('Integrante ' + (i + 1))));
                    if (!sig) return;
                    docEquipe[i].assinatura_base64 = sig;
                    docEquipe[i].assinatura_path = null;
                    docEquipe[i].assinado = true;
                    renderEquipeRows();
                });
            });
        }
        if ($('#eq-qtd')) $('#eq-qtd').addEventListener('change', (e) => {
            const n = Math.max(0, Math.min(20, +e.target.value || 0));
            while (docEquipe.length < n) docEquipe.push({ nome: '', funcao: '', cpf: '', assinatura_path: null, assinado: false });
            docEquipe.length = n;
            renderEquipeRows();
        });
        renderEquipeRows();

        if (podeEditar) {
            $('#btn-rascunho').addEventListener('click', async () => {
                const r = await salvar(coletar(os, doc));
                if (r) render(); // recarrega o formulário para reutilizar o mesmo documento/uuid
            });
            $('#btn-assinar').addEventListener('click', async () => {
                const payload = coletar(os, doc);
                const erro = validar(payload);
                if (erro) { toast(erro, true); return; }
                const sig = await askSignature('Assinatura do técnico');
                if (!sig) return;
                const r = await salvar(payload, true);
                if (!r) return;
                if (r.offline) {
                    Offline.assinarLocal(r.uuid, { imagem_base64: sig, dispositivo: navigator.userAgent });
                    toast('Sem conexão — assinado no aparelho. Será enviado quando houver internet.');
                    location.hash = '#documentos';
                    render();
                    return;
                }
                try {
                    await Api.assinarDocumento(r.id, { imagem_base64: sig, dispositivo: navigator.userAgent });
                    toast('Assinado. Aguardando aprovação do supervisor.');
                    location.hash = '#documentos';
                    render();
                } catch (e) {
                    if (e.offline) {
                        Offline.salvarLocal(payload.os_id, payload, { uuid: r.uuid });
                        Offline.assinarLocal(r.uuid, { imagem_base64: sig, dispositivo: navigator.userAgent });
                        toast('Sem conexão — assinado no aparelho. Será enviado quando houver internet.');
                        location.hash = '#documentos';
                        render();
                        return;
                    }
                    toast(e.message, true);
                }
            });
        }
        if (podeLiberar) {
            $('#btn-liberar').addEventListener('click', () => liberarModal(doc));
        }
        if (podeEncerrar) {
            $('#btn-encerrar').addEventListener('click', () => encerrarModal(doc));
        }
    }

    function riscoRow(r, sel, readOnly) {
        const checked = sel ? 'checked' : '';
        const sev = sel ? (sel.sev ?? 1) : '';
        const prob = sel ? (sel.prob ?? 1) : '';
        const medidas = sel ? (sel.medidas || r.medidas || '') : '';
        const n = nivelTexto(sev, prob);
        const opcoes = (v) => [1, 2, 3, 4, 5].map((x) => `<option value="${x}" ${Number(v) === x ? 'selected' : ''}>${x}</option>`).join('');
        const dis = readOnly ? 'disabled' : '';
        return `<tr data-codigo="${esc(r.codigo)}" data-perigo="${esc(r.descricao)}" data-nr="${esc(r.nr || '')}" data-ferramentas="${esc(r.ferramentas || '')}" data-categoria="${esc(r.categoria || '')}" data-medida="${esc(r.medidas || '')}" data-sev="${esc(r.severidade || '')}" data-prob="${esc(r.probabilidade || '')}">
            <td><label class="inline"><input type="checkbox" class="rk-check" ${checked} ${dis}> ${esc(r.descricao)}</label></td>
            <td><select class="rk-sev" ${dis}><option value=""></option>${opcoes(sev)}</select></td>
            <td><select class="rk-prob" ${dis}><option value=""></option>${opcoes(prob)}</select></td>
            <td class="${n.cls}">${n.texto}</td>
            <td><input class="rk-medidas" value="${esc(medidas)}" ${dis}></td>
        </tr>`;
    }

    // Preenche/limpa uma linha de risco conforme marcada
    function preencherRisco(tr) {
        const cb = $('.rk-check', tr);
        const sev = $('.rk-sev', tr), prob = $('.rk-prob', tr), med = $('.rk-medidas', tr);
        const nl = $('.rk-nivel', tr);
        if (cb.checked) {
            if (!sev.value) sev.value = tr.dataset.sev || 1;
            if (!prob.value) prob.value = tr.dataset.prob || 1;
            if (med && !med.value) med.value = tr.dataset.medida || '';
            const n = nivelTexto(sev.value, prob.value);
            nl.className = n.cls; nl.textContent = n.texto;
        } else {
            sev.value = ''; prob.value = ''; if (med) med.value = '';
            nl.className = 'rk-nivel lvl-none'; nl.textContent = '—';
        }
    }

    function checkRow(i, resposta, readOnly) {
        const dis = readOnly ? 'disabled' : '';
        const r = (v) => `<label class="chip"><input type="radio" name="chk-${esc(i.codigo)}" value="${v}" ${resposta === v ? 'checked' : ''} ${dis}> ${v.toUpperCase()}</label>`;
        return `<div class="check-item" data-codigo="${esc(i.codigo)}" data-nr="${esc(i.nr || '')}"><div>${esc(i.descricao)}${Number(i.critico) ? ' <span class="req">*</span>' : ''}</div><div class="chips">${r('sim')}${r('nao')}${r('na')}</div></div>`;
    }

    // Cada item pode ter várias NRs separadas por vírgula (ex.: "NR-10,NR-35").
    // O item é marcado quando QUALQUER uma das normas dele estiver selecionada.
    const itemTemNr = (valor, selecionadas) => String(valor || '')
        .split(',').map((s) => s.trim()).filter(Boolean)
        .some((n) => selecionadas.includes(n));

    // Ferramenta associada a um risco marcado (ex.: Animais peçonhentos → Facão):
    // só acrescenta — nunca desmarca o que o técnico escolheu.
    function aplicarFerramentas() {
        const trechos = [];
        $$('tr[data-codigo]').forEach((tr) => {
            const cb = $('.rk-check', tr);
            if (cb && cb.checked && tr.dataset.ferramentas) trechos.push(tr.dataset.ferramentas);
        });
        if (trechos.length) {
            const quero = trechos.join(',').split(',').map((s) => s.trim()).filter(Boolean);
            $$('.ferramenta').forEach((cb) => {
                if (!cb.disabled && quero.includes(cb.dataset.codigo)) cb.checked = true;
            });
        }
        atualizaFerramentasUI();
    }

    // Contador de ferramentas + mínimo exigido para assinar
    function atualizaFerramentasUI() {
        const el = $('#ferr-ajuda');
        if (!el) return;
        const n = $$('.ferramenta:checked').length;
        if (!cfgFerramentasMin) { el.textContent = `Marcadas: ${n}.`; return; }
        const marca = n < cfgFerramentasMin ? '⚠ ' : '';
        el.textContent = `${marca}Marcadas: ${n} — mínimo para assinar: ${cfgFerramentasMin}.`;
    }

    // Treinamentos/ASO: vêm marcados quando há NR selecionada e ficam TRAVADOS —
    // o técnico não consegue desmarcar. Em modo leitura não mexe (já vem travado
    // pelo próprio formulário, com o valor salvo do documento).
    function travarTreinamentos(normas = $$('.norma:checked').map((x) => x.value)) {
        const temNorma = normas.length > 0;
        ['#trn-nr10', '#trn-nr35', '#trn-aso'].forEach((sel) => {
            const el = $(sel);
            if (!el) return;
            if (el.disabled && !el.dataset.travado) return; // modo leitura: só exibe
            el.checked = temNorma;
            el.disabled = true;
            el.dataset.travado = '1';
        });
    }

    // Marca automaticamente riscos/EPIs/EPCs/checklist conforme as NRs selecionadas
    function aplicarNormas() {
        const normas = $$('.norma:checked').map((x) => x.value);

        // Riscos (itens com NR definida)
        $$('tr[data-codigo]').forEach((tr) => {
            const cb = $('.rk-check', tr);
            if (!tr.dataset.nr || !cb || cb.disabled) return;
            cb.checked = itemTemNr(tr.dataset.nr, normas);
            preencherRisco(tr);
        });

        // EPIs e EPCs
        $$('.epi, .epc').forEach((cb) => {
            if (!cb.dataset.nr || cb.disabled) return;
            cb.checked = itemTemNr(cb.dataset.nr, normas);
        });

        // Checklist
        $$('.check-item').forEach((el) => {
            if (!el.dataset.nr) return;
            const sim = el.querySelector('input[type=radio][value="sim"]');
            if (!sim || sim.disabled) return;
            sim.checked = itemTemNr(el.dataset.nr, normas);
        });

        // Validade dos treinamentos / habilitação — preenchidos e travados
        travarTreinamentos(normas);

        // Risco marcado pela NR leva a ferramenta associada junto
        aplicarFerramentas();

        toast(normas.length ? `Campos de ${normas.join(', ')} marcados automaticamente.` : 'NRs desmarcadas — itens automáticos limpos.');
    }

    // ── Assinatura (canvas) ─────────────────────────────────
    function setupSignaturePad(canvas) {
        const ctx = canvas.getContext('2d');
        let desenhou = false, draw = false;
        const fill = () => { ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height); };
        fill();
        ctx.strokeStyle = '#111'; ctx.lineWidth = 2; ctx.lineJoin = 'round'; ctx.lineCap = 'round';
        const pos = (e) => { const r = canvas.getBoundingClientRect(); const t = e.touches ? e.touches[0] : e; return { x: (t.clientX - r.left) * (canvas.width / r.width), y: (t.clientY - r.top) * (canvas.height / r.height) }; };
        const start = (e) => { draw = true; desenhou = true; const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); };
        const move = (e) => { if (!draw) return; const p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); e.preventDefault(); };
        const end = () => { draw = false; };
        canvas.addEventListener('mousedown', start); canvas.addEventListener('mousemove', move); window.addEventListener('mouseup', end);
        canvas.addEventListener('touchstart', start, { passive: false }); canvas.addEventListener('touchmove', move, { passive: false }); canvas.addEventListener('touchend', end);
        return { clear: () => { fill(); desenhou = false; }, get desenhou() { return desenhou; }, dataURL: () => canvas.toDataURL('image/png') };
    }

    // Coleta os dados do formulário
    function coletar(os, doc) {
        const riscos = $$('tr[data-codigo]').filter((tr) => $('.rk-check', tr).checked).map((tr) => {
            const sev = +$('.rk-sev', tr).value || 1;
            const prob = +$('.rk-prob', tr).value || 1;
            return {
                codigo: tr.dataset.codigo, perigo: tr.dataset.perigo, nr: tr.dataset.nr, categoria: tr.dataset.categoria,
                sev, prob, nivel: nivel(sev, prob),
                medidas: $('.rk-medidas', tr).value.trim(),
            };
        });
        const epis = $$('.epi:checked').map((x) => x.value);
        const epcs = $$('.epc:checked').map((x) => x.value);
        const normas = $$('.norma:checked').map((x) => x.value);
        const checklist = $$('.check-item').map((el) => {
            const codigo = el.querySelector('input[type=radio]').name.replace('chk-', '');
            const sel = el.querySelector('input[type=radio]:checked');
            return { codigo, descricao: $('div', el).textContent.trim().replace(/\s*\*$/, ''), resposta: sel ? sel.value : '' };
        });
        return {
            uuid: doc?.uuid || uid(), os_id: os.id, status: 'rascunho',
            tecnico_id: os.tecnico_user_id || null,
            validade_inicio: doc?.validade_inicio || Offline.agora(),
            validade_fim: doc?.validade_fim || Offline.somaHoras(doc?.validade_inicio || Offline.agora(),
                state.catalogo?.textos?.validade_horas || 8),
            nr35: normas.includes('NR-35'), nr10: normas.includes('NR-10'),
            normas, riscos, epis, epcs, checklist,
            ferramentas: $$('.ferramenta:checked').map((x) => x.value),
            local_atividade: $('#doc-local')?.value.trim() || '',
            responsavel_apr: $('#doc-responsavel')?.value.trim() || '',
            etapas: $('#doc-etapas')?.value.trim() || '',
            treinamento_nr10: $('#trn-nr10')?.checked ? 1 : 0,
            treinamento_nr35: $('#trn-nr35')?.checked ? 1 : 0,
            aso_apto: $('#trn-aso')?.checked ? 1 : 0,
            observacoes: $('#doc-observacoes')?.value.trim() || '',
            equipe: docEquipe.map((m) => ({
                nome: (m.nome || '').trim(), funcao: (m.funcao || '').trim(), cpf: (m.cpf || '').trim(),
                assinatura_base64: m.assinatura_base64 || null,
                assinatura_path: m.assinatura_path || null,
            })),
        };
    }

    function validar(payload) {
        if (!payload.riscos.length) return 'Selecione ao menos um risco na APR.';
        if (payload.riscos.some((r) => !r.sev || !r.prob)) return 'Informe severidade e probabilidade de todos os riscos.';
        if (payload.normas.includes('NR-35')) {
            if (!payload.epis.some((e) => /cinto/i.test(e))) return 'NR-35: marque o Cinto paraquedista nos EPIs.';
            if (!payload.epis.some((e) => /talabarte/i.test(e))) return 'NR-35: marque o Talabarte nos EPIs.';
        }
        // Exige a LUVA isolante (a botina também é isolante, mas não substitui a luva)
        if (payload.normas.includes('NR-10') && !payload.epis.some((e) => /luva/i.test(e) && /isolante/i.test(e))) {
            return 'NR-10: marque a Luva isolante nos EPIs.';
        }
        if (cfgFerramentasMin > 0 && payload.ferramentas.length < cfgFerramentasMin) {
            return `Ferramentas: marque pelo menos ${cfgFerramentasMin} itens (você marcou ${payload.ferramentas.length}).`;
        }
        return null;
    }

    async function salvar(payload, silencioso = false) {
        if (navigator.onLine) {
            try {
                const saved = await Api.saveDocumento(payload);
                if (!silencioso) toast('Rascunho salvo.');
                return { id: saved.id, uuid: saved.uuid || payload.uuid, offline: false };
            } catch (e) {
                if (!e.offline) { toast(e.message, true); return null; }
            }
        }
        // Sem internet: guarda no aparelho e deixa na fila de envio
        const local = Offline.salvarLocal(payload.os_id, payload);
        if (!silencioso) toast('Sem conexão — rascunho salvo no aparelho.');
        desenharBarraOffline();
        return { id: local.id || null, uuid: local.uuid, offline: true };
    }

    function liberarModal(doc) {
        openModal('Autorizar PT — Supervisor / Emissor', `
            <p class="muted">${esc(state.catalogo?.textos?.autorizacao_supervisor || '')}</p>
            <span class="label">Status da Permissão</span>
            <label class="inline"><input type="radio" name="pt_aut" value="AUTORIZADA" checked> <strong>AUTORIZADA</strong></label>
            <label class="inline"><input type="radio" name="pt_aut" value="NAO_AUTORIZADA"> <strong style="color:var(--danger)">NÃO AUTORIZADA</strong></label>
            <label>Nome do Supervisor / Emissor<input type="text" id="lib-nome" value="${esc(state.user.name || '')}"></label>
            <label>Registro / Cargo<input type="text" id="lib-reg" placeholder="Ex.: Supervisor de Campo / CREA"></label>
            <span class="label">Assinatura do supervisor</span>
            <canvas id="lib-sig" class="sig-canvas" width="480" height="200"></canvas>
            <div class="form-actions">
                <button type="button" class="secondary" id="lib-cancel">Cancelar</button>
                <button type="button" id="lib-ok">Assinar e liberar</button>
            </div>`);
        const pad = setupSignaturePad($('#lib-sig'));
        $('#lib-cancel').addEventListener('click', closeModal);
        $('#lib-ok').addEventListener('click', async () => {
            const aut = document.querySelector('input[name=pt_aut]:checked')?.value || 'AUTORIZADA';
            if (!pad.desenhou) { toast('Assine antes de confirmar.', true); return; }
            const dados = {
                pt_autorizacao: aut,
                pt_supervisor_nome: $('#lib-nome').value.trim(),
                pt_supervisor_registro: $('#lib-reg').value.trim(),
                imagem_base64: pad.dataURL(),
                dispositivo: navigator.userAgent,
            };
            if (!doc.id || !navigator.onLine) {
                Offline.aprovarLocal(doc.uuid, dados);
                closeModal();
                toast('PT assinada no aparelho — será enviada quando houver internet.');
                render();
                return;
            }
            try {
                await Api.aprovarDocumento(doc.id, dados);
                closeModal();
                toast(aut === 'AUTORIZADA' ? 'PT autorizada.' : 'PT NÃO autorizada.');
                render();
            } catch (e) {
                if (e.offline) {
                    Offline.aprovarLocal(doc.uuid, dados);
                    closeModal();
                    toast('Sem conexão — PT assinada no aparelho e será enviada depois.');
                    render();
                    return;
                }
                toast(e.message, true);
            }
        });
    }

    function encerrarModal(doc) {
        openModal('Encerramento da PT — Supervisor', `
            <p class="muted">${esc(state.catalogo?.textos?.declaracao_encerramento || '')}</p>
            <span class="label">Status do Encerramento</span>
            <label class="inline"><input type="radio" name="pt_enc" value="CONCLUIDO" checked> Concluído com sucesso</label>
            <label class="inline"><input type="radio" name="pt_enc" value="SUSPENSO"> Serviço suspenso / PT cancelada</label>
            <label>Observações<textarea id="enc-obs" rows="3"></textarea></label>
            <span class="label">Assinatura do supervisor no encerramento</span>
            <canvas id="enc-sig" class="sig-canvas" width="480" height="200"></canvas>
            <div class="form-actions">
                <button type="button" class="secondary" id="enc-cancel">Cancelar</button>
                <button type="button" id="enc-ok">Assinar e encerrar</button>
            </div>`);
        const pad = setupSignaturePad($('#enc-sig'));
        $('#enc-cancel').addEventListener('click', closeModal);
        $('#enc-ok').addEventListener('click', async () => {
            const st = document.querySelector('input[name=pt_enc]:checked')?.value || 'CONCLUIDO';
            if (!pad.desenhou) { toast('Assine antes de confirmar.', true); return; }
            const dados = {
                pt_encerramento_status: st,
                encerramento_obs: $('#enc-obs').value.trim(),
                imagem_base64: pad.dataURL(),
                dispositivo: navigator.userAgent,
            };
            if (!doc.id || !navigator.onLine) {
                Offline.encerrarLocal(doc.uuid, dados);
                closeModal();
                toast('Encerramento assinado no aparelho — será enviado quando houver internet.');
                render();
                return;
            }
            try {
                await Api.encerrarDocumento(doc.id, dados);
                closeModal();
                toast(st === 'CONCLUIDO' ? 'PT encerrada com sucesso.' : 'PT encerrada como suspensa/cancelada.');
                render();
            } catch (e) {
                if (e.offline) {
                    Offline.encerrarLocal(doc.uuid, dados);
                    closeModal();
                    toast('Sem conexão — encerramento assinado no aparelho e será enviado depois.');
                    render();
                    return;
                }
                toast(e.message, true);
            }
        });
    }

    // ── Assinatura (canvas) ─────────────────────────────────
    function askSignature(title) {
        return new Promise((resolve) => {
            openModal(title, `
                <p class="muted">Assine no quadro abaixo.</p>
                <canvas id="sig-canvas" class="sig-canvas" width="480" height="200"></canvas>
                <div class="form-actions">
                    <button type="button" class="secondary" id="sig-clear">Limpar</button>
                    <button type="button" class="secondary" id="sig-cancel">Cancelar</button>
                    <button type="button" id="sig-ok">Confirmar</button>
                </div>`);
            const pad = setupSignaturePad($('#sig-canvas'));
            $('#sig-clear').addEventListener('click', pad.clear);
            $('#sig-cancel').addEventListener('click', () => { closeModal(); resolve(null); });
            $('#sig-ok').addEventListener('click', () => {
                if (!pad.desenhou) { toast('Assine antes de confirmar.', true); return; }
                const data = pad.dataURL();
                closeModal();
                resolve(data);
            });
        });
    }

    // ── View: Usuários ──────────────────────────────────────
    let cachedUsers = [];
    async function renderUsuarios() {
        const c = $('#content');
        c.innerHTML = `
            <div class="page-head">
                <h1>Usuários</h1>
                <div class="toolbar">
                    <input type="search" id="u-search" placeholder="Buscar nome ou e-mail">
                    <select id="u-role">
                        <option value="">Todos os perfis</option>
                        <option value="tecnico">Técnico</option>
                        <option value="supervisor">Supervisor</option>
                        <option value="admin">Administrador</option>
                    </select>
                    <button id="btn-new-user">+ Novo usuário</button>
                </div>
            </div>
            <div id="u-body"><p class="loading">Carregando…</p></div>`;
        const load = async () => {
            try {
                cachedUsers = await Api.listUsers({ search: $('#u-search').value, role: $('#u-role').value });
                $('#u-body').innerHTML = cachedUsers.length ? `<table class="grid">
                    <thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Func. IXC</th><th>Ativo</th><th></th></tr></thead>
                    <tbody>${cachedUsers.map(rowUser).join('')}</tbody></table>` : '<p class="muted">Nenhum usuário encontrado.</p>';
                bindUserActions();
            } catch (e) { $('#u-body').innerHTML = `<p class="error">${esc(e.message)}</p>`; }
        };
        $('#btn-new-user').addEventListener('click', () => userForm(null));
        $('#u-search').addEventListener('input', debounce(load, 300));
        $('#u-role').addEventListener('change', load);
        load();
    }
    function rowUser(u) {
        return `<tr>
            <td>${esc(u.name)}</td><td>${esc(u.email)}</td>
            <td><span class="tag role-${u.role}">${ROLE_LABEL[u.role] || u.role}</span></td>
            <td>${u.ixc_funcionario_id ?? '<span class="muted">—</span>'}</td>
            <td>${Number(u.ativo) ? 'Sim' : 'Não'}</td>
            <td class="acoes"><button class="link" data-edit="${u.id}">Editar</button> <button class="link" data-pass="${u.id}">Senha</button> <button class="link danger" data-del="${u.id}">Excluir</button></td>
        </tr>`;
    }
    function bindUserActions() {
        $$('[data-edit]').forEach((b) => b.addEventListener('click', () => userForm(cachedUsers.find((x) => x.id === +b.dataset.edit))));
        $$('[data-pass]').forEach((b) => b.addEventListener('click', () => passwordForm(+b.dataset.pass)));
        $$('[data-del]').forEach((b) => b.addEventListener('click', () => deleteUser(+b.dataset.del)));
    }
    async function funcionariosOptions(selected) {
        if (!state.funcionarios) { try { state.funcionarios = await Api.ixcFuncionarios(); } catch { state.funcionarios = []; } }
        return '<option value="">— não vincular —</option>' + state.funcionarios.map((f) =>
            `<option value="${f.id}" ${String(selected) === String(f.id) ? 'selected' : ''}>${esc(f.nome)}${f.email ? ' — ' + esc(f.email) : ''}</option>`).join('');
    }
    async function userForm(u) {
        const isEdit = !!u;
        const funcOpts = await funcionariosOptions(u?.ixc_funcionario_id);
        const podeAdmin = state.user.role === 'admin';
        openModal(isEdit ? 'Editar usuário' : 'Novo usuário', `
            <form id="user-form">
                <label>Nome<input name="name" required value="${esc(u?.name || '')}"></label>
                <label>E-mail<input type="email" name="email" required value="${esc(u?.email || '')}"></label>
                <label>Perfil<select name="role" required>
                    <option value="tecnico" ${u?.role === 'tecnico' ? 'selected' : ''}>Técnico</option>
                    <option value="supervisor" ${u?.role === 'supervisor' ? 'selected' : ''}>Supervisor</option>
                    ${podeAdmin ? `<option value="admin" ${u?.role === 'admin' ? 'selected' : ''}>Administrador</option>` : ''}
                </select></label>
                <label>Funcionário no IXC<select name="ixc_funcionario_id">${funcOpts}</select></label>
                ${isEdit ? `<label class="inline"><input type="checkbox" name="ativo" ${Number(u.ativo) ? 'checked' : ''}> Ativo</label>`
                         : `<label>Senha inicial<input type="text" name="password" required minlength="6" value="${randPass()}"></label>`}
                <div class="form-actions"><button type="button" class="secondary" id="uf-cancel">Cancelar</button><button type="submit">Salvar</button></div>
            </form>`);
        $('#uf-cancel').addEventListener('click', closeModal);
        $('#user-form').addEventListener('submit', async (ev) => {
            ev.preventDefault();
            const f = new FormData(ev.target);
            const payload = { name: f.get('name').trim(), email: f.get('email').trim(), role: f.get('role'), ixc_funcionario_id: f.get('ixc_funcionario_id') || null };
            if (isEdit) payload.ativo = f.get('ativo') ? 1 : 0; else payload.password = f.get('password');
            try { isEdit ? await Api.updateUser(u.id, payload) : await Api.createUser(payload); closeModal(); toast('Usuário salvo.'); renderUsuarios(); }
            catch (e) { toast(e.message, true); }
        });
    }
    function passwordForm(id) {
        const nova = randPass();
        openModal('Redefinir senha', `<form id="pass-form"><p class="muted">A senha atual deixará de funcionar.</p>
            <label>Nova senha<input type="text" name="password" required minlength="6" value="${nova}"></label>
            <div class="form-actions"><button type="button" class="secondary" id="pf-cancel">Cancelar</button><button type="submit">Redefinir</button></div></form>`);
        $('#pf-cancel').addEventListener('click', closeModal);
        $('#pass-form').addEventListener('submit', async (ev) => {
            ev.preventDefault();
            try { await Api.resetUserPassword(id, new FormData(ev.target).get('password')); closeModal(); toast('Senha: ' + nova); } catch (e) { toast(e.message, true); }
        });
    }
    async function deleteUser(id) {
        if (!confirm('Excluir este usuário?')) return;
        try { await Api.deleteUser(id); toast('Usuário excluído.'); renderUsuarios(); } catch (e) { toast(e.message, true); }
    }

    // ── Helpers ─────────────────────────────────────────────
    function randPass() { return 'Nwt@' + [...crypto.getRandomValues(new Uint8Array(3))].map((b) => b.toString(16).padStart(2, '0')).join(''); }
    function toArr(v) { if (Array.isArray(v)) return v; if (typeof v === 'string' && v) { try { return JSON.parse(v) || []; } catch { return []; } } return []; }
    function keyed(v, k) { const o = {}; toArr(v).forEach((i) => { o[i[k]] = i; }); return o; }
    function toLocalInput(v) { if (!v) return ''; return String(v).replace(' ', 'T').slice(0, 16); }
    function nl2br(v) { return esc(v || '').replace(/\n/g, '<br>'); }

    // ── View: Modelo APR/PT (edição do catálogo) ────────────
    const MODELO_DEFS = {
        riscos: {
            label: 'Riscos',
            colunas: ['descricao', 'categoria', 'nr', 'ordem', 'ativo'],
            campos: [
                { k: 'codigo', l: 'Código', t: 'text' },
                { k: 'categoria', l: 'Categoria (NR-35 / NR-10 / GERAL)', t: 'text' },
                { k: 'nr', l: 'Normas (marcação automática)', t: 'nr' },
                { k: 'descricao', l: 'Perigo', t: 'text', req: true },
                { k: 'fonte', l: 'Fonte geradora', t: 'text' },
                { k: 'consequencia', l: 'Consequência', t: 'text' },
                { k: 'medidas', l: 'Medidas de controle (sugestão)', t: 'textarea' },
                { k: 'ferramentas', l: 'Ferramentas associadas (marca automática)', t: 'ferramentas' },
                { k: 'ordem', l: 'Ordem', t: 'number' },
                { k: 'ativo', l: 'Ativo', t: 'bool' },
            ],
        },
        epis: {
            label: 'EPIs',
            colunas: ['descricao', 'nr', 'ordem', 'ativo'],
            campos: [
                { k: 'codigo', l: 'Código', t: 'text' },
                { k: 'descricao', l: 'Descrição', t: 'text', req: true },
                { k: 'nr', l: 'Normas (marcação automática)', t: 'nr' },
                { k: 'ordem', l: 'Ordem', t: 'number' },
                { k: 'ativo', l: 'Ativo', t: 'bool' },
            ],
        },
        epcs: {
            label: 'EPCs',
            colunas: ['descricao', 'nr', 'ordem', 'ativo'],
            campos: [
                { k: 'codigo', l: 'Código', t: 'text' },
                { k: 'descricao', l: 'Descrição', t: 'text', req: true },
                { k: 'nr', l: 'Normas (marcação automática)', t: 'nr' },
                { k: 'ordem', l: 'Ordem', t: 'number' },
                { k: 'ativo', l: 'Ativo', t: 'bool' },
            ],
        },
        ferramentas: {
            label: 'Ferramentas',
            colunas: ['codigo', 'descricao', 'padrao', 'ordem', 'ativo'],
            campos: [
                { k: 'codigo', l: 'Código', t: 'text' },
                { k: 'descricao', l: 'Descrição', t: 'text', req: true },
                { k: 'padrao', l: 'Pré-marcar (sempre usada em documento novo)', t: 'bool' },
                { k: 'ordem', l: 'Ordem', t: 'number' },
                { k: 'ativo', l: 'Ativo', t: 'bool' },
            ],
        },
        checklist: {
            label: 'Checklist PT',
            colunas: ['descricao', 'grupo', 'nr', 'critico', 'ordem', 'ativo'],
            campos: [
                { k: 'codigo', l: 'Código', t: 'text' },
                { k: 'descricao', l: 'Item', t: 'text', req: true },
                { k: 'grupo', l: 'Grupo', t: 'text' },
                { k: 'nr', l: 'Normas (marcação automática)', t: 'nr' },
                { k: 'critico', l: 'Item crítico', t: 'bool' },
                { k: 'ordem', l: 'Ordem', t: 'number' },
                { k: 'ativo', l: 'Ativo', t: 'bool' },
            ],
        },
    };

    async function renderModelo() {
        const c = $('#content');
        c.innerHTML = '<p class="loading">Carregando…</p>';
        try { state.modelo = await Api.getModelo(); }
        catch (e) { c.innerHTML = `<p class="error">${esc(e.message)}</p>`; return; }

        const abas = ['riscos', 'epis', 'epcs', 'ferramentas', 'checklist', 'textos'];
        const labels = { ...Object.fromEntries(Object.entries(MODELO_DEFS).map(([k, v]) => [k, v.label])), textos: 'Textos' };
        c.innerHTML = `
            <div class="page-head"><h1>Modelo APR/PT</h1><span class="muted">Catálogo editável usado na tela do técnico</span></div>
            <div class="tabs">${abas.map((a, i) => `<button class="tab ${i === 0 ? 'active' : ''}" data-tab="${a}">${labels[a]}</button>`).join('')}</div>
            <div id="modelo-body"></div>`;
        $$('.tab').forEach((b) => b.addEventListener('click', () => {
            $$('.tab').forEach((x) => x.classList.toggle('active', x === b));
            mostrarAba(b.dataset.tab);
        }));
        mostrarAba('riscos');
    }

    function mostrarAba(tipo) {
        return tipo === 'textos' ? renderTextos() : renderListaModelo(tipo);
    }

    function renderListaModelo(tipo) {
        const def = MODELO_DEFS[tipo];
        const itens = state.modelo.listas[tipo] || [];
        const th = def.colunas.map((c) => `<th>${esc(campoLabel(def, c))}</th>`).join('');
        const rows = itens.map((it) => `<tr>${def.colunas.map((c) => `<td>${celula(it[c], c)}</td>`).join('')}
            <td class="acoes"><button class="link" data-m-ed="${it.id}">Editar</button> <button class="link danger" data-m-del="${it.id}">Excluir</button></td></tr>`).join('');
        $('#modelo-body').innerHTML = `
            <div class="toolbar" style="margin-bottom:10px"><button id="m-novo">+ Novo</button></div>
            <div class="table-wrap"><table class="grid"><thead><tr>${th}<th></th></tr></thead><tbody>${rows || `<tr><td colspan="${def.colunas.length + 1}" class="muted">Nenhum item.</td></tr>`}</tbody></table></div>`;
        $('#m-novo').addEventListener('click', () => itemForm(tipo, null));
        $$('[data-m-ed]').forEach((b) => b.addEventListener('click', () => itemForm(tipo, itens.find((x) => x.id === +b.dataset.mEd))));
        $$('[data-m-del]').forEach((b) => b.addEventListener('click', () => delItem(tipo, +b.dataset.mDel)));
    }

    function campoLabel(def, k) { const c = def.campos.find((x) => x.k === k); return c ? c.l : k; }
    function celula(v, c) {
        if (['ativo', 'critico', 'padrao'].includes(c)) return Number(v) ? 'Sim' : 'Não';
        if (c === 'nr' && v) return esc(String(v).split(',').map((s) => s.trim()).join(' · '));
        return esc(v ?? '');
    }

    function itemForm(tipo, item) {
        const def = MODELO_DEFS[tipo];
        const isEdit = !!item;
        const campos = def.campos.map((c) => {
            const v = item ? (item[c.k] ?? '') : (c.t === 'bool' ? 1 : c.k === 'ordem' ? 0 : '');
            if (c.t === 'nr') {
                const atuais = String(v || '').split(',').map((s) => s.trim()).filter(Boolean);
                const opcoes = [...NORMAS, ...atuais.filter((a) => !NORMAS.includes(a))];
                return `<span class="label">${esc(c.l)}</span>
                    <div class="checks">${opcoes.map((n) => `<label class="chip"><input type="checkbox" name="${c.k}" value="${esc(n)}" ${atuais.includes(n) ? 'checked' : ''}> ${esc(n)}</label>`).join('')}</div>`;
            }
            if (c.t === 'ferramentas') {
                const atuais = String(v || '').split(',').map((s) => s.trim()).filter(Boolean);
                const ops = state.modelo.listas.ferramentas || [];
                return `<span class="label">${esc(c.l)}</span>
                    <div class="checks">${ops.map((f) => `<label class="chip"><input type="checkbox" name="${c.k}" value="${esc(f.codigo)}" ${atuais.includes(f.codigo) ? 'checked' : ''}> ${esc(f.descricao)}</label>`).join('')}</div>`;
            }
            if (c.t === 'bool') return `<label class="inline"><input type="checkbox" name="${c.k}" ${Number(v) ? 'checked' : ''}> ${esc(c.l)}</label>`;
            if (c.t === 'textarea') return `<label>${esc(c.l)}<textarea name="${c.k}" rows="3">${esc(v)}</textarea></label>`;
            if (c.t === 'number') return `<label>${esc(c.l)}<input type="number" name="${c.k}" value="${esc(v)}"></label>`;
            return `<label>${esc(c.l)}<input type="text" name="${c.k}" value="${esc(v)}" ${c.req ? 'required' : ''}></label>`;
        }).join('');
        openModal(isEdit ? 'Editar item' : 'Novo item', `
            <form id="m-form">${campos}
                <div class="form-actions"><button type="button" class="secondary" id="m-cancel">Cancelar</button><button type="submit">Salvar</button></div>
            </form>`);
        $('#m-cancel').addEventListener('click', closeModal);
        $('#m-form').addEventListener('submit', async (ev) => {
            ev.preventDefault();
            const f = new FormData(ev.target);
            const payload = {};
            def.campos.forEach((c) => {
                if (c.t === 'nr' || c.t === 'ferramentas') { payload[c.k] = f.getAll(c.k).join(','); return; }
                payload[c.k] = c.t === 'bool' ? (f.get(c.k) ? 1 : 0) : (c.t === 'number' ? (+f.get(c.k) || 0) : (f.get(c.k) || '').trim());
            });
            try {
                if (isEdit) await Api.updateModelo(tipo, item.id, payload);
                else await Api.createModelo(tipo, payload);
                closeModal(); toast('Salvo.'); state.modelo = await Api.getModelo(); renderListaModelo(tipo);
            } catch (e) { toast(e.message, true); }
        });
    }

    async function delItem(tipo, id) {
        if (!confirm('Remover este item?')) return;
        try { await Api.deleteModelo(tipo, id); toast('Removido.'); state.modelo = await Api.getModelo(); renderListaModelo(tipo); }
        catch (e) { toast(e.message, true); }
    }

    function renderTextos() {
        const t = state.modelo.textos || [];
        $('#modelo-body').innerHTML = `
            <p class="muted">Textos das declarações exibidos e assinados no documento.</p>
            <form id="txt-form">
                ${t.map((x) => `<label>${esc(x.descricao || x.chave)}<textarea name="${esc(x.chave)}" rows="4">${esc(x.valor || '')}</textarea></label>`).join('')}
                <div class="form-actions"><button type="submit">Salvar textos</button></div>
            </form>`;
        $('#txt-form').addEventListener('submit', async (ev) => {
            ev.preventDefault();
            const f = new FormData(ev.target);
            const payload = {};
            t.forEach((x) => { payload[x.chave] = f.get(x.chave); });
            try { await Api.saveTextos(payload); toast('Textos salvos.'); state.modelo = await Api.getModelo(); }
            catch (e) { toast(e.message, true); }
        });
    }

    // ── Eventos globais ─────────────────────────────────────
    $('#form-login').addEventListener('submit', async (ev) => {
        ev.preventDefault(); $('#login-error').textContent = '';
        const f = new FormData(ev.target);
        try { showApp(await Api.login(f.get('email'), f.get('password'))); } catch (e) { $('#login-error').textContent = e.message; }
    });
    $('#btn-logout').addEventListener('click', async () => { await Api.logout(); Offline.gravarSessao(null); showLogin(); });
    $('#modal-close').addEventListener('click', closeModal);
    $('#modal').addEventListener('click', (e) => { if (e.target.id === 'modal') closeModal(); });
    window.addEventListener('hashchange', render);

    // Reconexão: envia o que ficou pendente e recarrega os dados
    window.addEventListener('online', () => { sincronizarPendentes(); render(); });
    window.addEventListener('offline', () => atualizarAvisos());
    setInterval(() => { if (navigator.onLine && Offline.contarPendentes()) sincronizarPendentes(); }, 60000);

    (async () => {
        if (!Api.isAuthenticated()) return showLogin();
        try { showApp(await Api.me()); }
        catch (e) {
            if (e.offline) {
                const salvo = Offline.sessao();
                if (salvo && salvo.id) return showApp(salvo);   // abre com os dados do aparelho
            }
            Api.clear(); showLogin();
        }
    })();
})();
