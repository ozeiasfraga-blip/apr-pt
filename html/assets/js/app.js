// APR/PT — interface (rotas por hash, views, gestão de usuários e documento APR/PT)
(function () {
    'use strict';

    const $ = (s, r = document) => r.querySelector(s);
    const $$ = (s, r = document) => [...r.querySelectorAll(s)];
    const state = { user: null, funcionarios: null, catalogo: null };

    const ROLE_LABEL = { tecnico: 'Técnico', supervisor: 'Supervisor', admin: 'Administrador' };
    const STATUS_LABEL = {
        rascunho: 'Rascunho', aguardando_aprovacao: 'Aguardando aprovação',
        liberada: 'Liberada', em_execucao: 'Em execução', encerrada: 'Encerrada', cancelada: 'Cancelada',
    };
    const OS_STATUS = { A: 'Aberta', AN: 'Análise', EN: 'Encaminhada', AS: 'Assumida', AG: 'Agendada', DS: 'Deslocamento', EX: 'Execução', F: 'Finalizada', RAG: 'Aguard. agendamento' };
    const NORMAS = ['NR-35', 'NR-10', 'NR-33', 'NR-18', 'Via pública', 'Outros'];

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
    function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }

    // ── UI ──────────────────────────────────────────────────
    let toastTimer;
    function toast(msg, isError = false) {
        const t = $('#toast'); t.textContent = msg; t.className = 'toast' + (isError ? ' error' : '');
        clearTimeout(toastTimer); toastTimer = setTimeout(() => t.classList.add('hidden'), 3800);
    }
    function openModal(title, html) { $('#modal-title').textContent = title; $('#modal-body').innerHTML = html; $('#modal').classList.remove('hidden'); }
    function closeModal() { $('#modal').classList.add('hidden'); }

    async function getCatalogo() { if (!state.catalogo) state.catalogo = await Api.catalogo(); return state.catalogo; }

    // ── Autenticação ────────────────────────────────────────
    function showLogin() { $('#view-login').classList.remove('hidden'); $('#view-app').classList.add('hidden'); location.hash = ''; }
    function showApp(user) {
        state.user = user;
        $('#view-login').classList.add('hidden'); $('#view-app').classList.remove('hidden');
        $('#user-info').textContent = `${user.name} · ${ROLE_LABEL[user.role] || user.role}`;
        $$('#nav a[data-roles]').forEach((a) => a.classList.toggle('hidden', !a.dataset.roles.split(',').includes(user.role)));
        render();
    }

    // ── Roteador ────────────────────────────────────────────
    function render() {
        const hash = (location.hash || '#os').replace('#', '');
        const [view, param] = hash.split('/');
        $$('#nav a').forEach((a) => a.classList.toggle('active', a.dataset.view === view));

        if (view === 'usuarios') { if (!isSupervisor()) { location.hash = '#os'; return; } return renderUsuarios(); }
        if (view === 'documentos') return renderDocumentos();
        if (view === 'doc') return renderDocForm(param);
        return renderOs();
    }

    // ── View: Ordens de Serviço ─────────────────────────────
    let osCache = [];
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
                <select id="os-status"><option value="">Todos os status</option>${Object.entries(OS_STATUS).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select>
            </div>
            <div id="os-body"><p class="muted">Carregando…</p></div>`;

        const load = async () => {
            try { osCache = await Api.listOs({ date: $('#os-date').value }); fillTecOptions(); applyOsFilters(); }
            catch (e) { $('#os-body').innerHTML = `<p class="error">${esc(e.message)}</p>`; }
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
        const rows = osCache.filter((o) => {
            if (tec && String(o.id_tecnico) !== tec) return false;
            if (st && o.status_ixc !== st) return false;
            if (q && ![o.cliente, o.tecnico_nome, o.endereco, o.bairro, o.cidade, o.assunto, o.protocolo, o.ixc_id].join(' ').toLowerCase().includes(q)) return false;
            return true;
        });
        const body = $('#os-body');
        if (!rows.length) { body.innerHTML = '<p class="muted">Nenhuma OS encontrada com esses filtros.</p>'; return; }
        body.innerHTML = `<table class="grid">
            <thead><tr><th>Hora</th><th>Cliente</th>${isSupervisor() ? '<th>Técnico</th>' : ''}<th>Assunto</th><th>Status</th><th>APR/PT</th><th></th></tr></thead>
            <tbody>${rows.map(rowOs).join('')}</tbody></table>`;
        $$('[data-abrir]').forEach((b) => b.addEventListener('click', () => { location.hash = '#doc/' + b.dataset.abrir; }));
    }
    function rowOs(o) {
        const hora = (o.data_agenda || '').slice(11, 16);
        const d = o.documento;
        const doc = d ? `<span class="tag tag-${d.status}">${STATUS_LABEL[d.status] || d.status}</span>` : '<span class="muted">—</span>';
        const acao = isSupervisor() ? 'Abrir' : (d ? 'Ver / continuar' : 'Preencher');
        return `<tr>
            <td>${esc(hora)}</td>
            <td>${esc(o.cliente || '(sem nome)')}<br><small class="muted">#${esc(o.ixc_id)} · ${esc(o.cidade || '')}</small></td>
            ${isSupervisor() ? `<td>${esc(o.tecnico_nome || '')}</td>` : ''}
            <td>${esc(o.assunto || '')}</td>
            <td><span class="tag">${esc(OS_STATUS[o.status_ixc] || o.status_ixc || '')}</span></td>
            <td>${doc}</td>
            <td><button class="link" data-abrir="${o.id}">${acao}</button></td>
        </tr>`;
    }

    // ── View: Documentos ────────────────────────────────────
    async function renderDocumentos() {
        const c = $('#content');
        c.innerHTML = `
            <div class="page-head">
                <h1>Documentos APR/PT</h1>
                <div class="toolbar">
                    <select id="d-status">
                        <option value="">Todos os status</option>
                        ${Object.entries(STATUS_LABEL).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}
                    </select>
                </div>
            </div>
            <div id="d-body"><p class="muted">Carregando…</p></div>`;

        const load = async () => {
            try {
                const docs = await Api.listDocumentos({ status: $('#d-status').value });
                $('#d-body').innerHTML = docs.length ? `<table class="grid">
                    <thead><tr><th>OS</th><th>Cliente</th><th>Técnico</th><th>Status</th><th>Supervisor</th><th></th></tr></thead>
                    <tbody>${docs.map(rowDoc).join('')}</tbody></table>`
                    : '<p class="muted">Nenhum documento encontrado.</p>';
                $$('[data-abrir-doc]').forEach((b) => b.addEventListener('click', () => { location.hash = '#doc/' + b.dataset.abrirDoc; }));
            } catch (e) { $('#d-body').innerHTML = `<p class="error">${esc(e.message)}</p>`; }
        };
        $('#d-status').addEventListener('change', load);
        load();
    }
    function rowDoc(d) {
        return `<tr>
            <td>#${esc(d.ixc_id || d.os_id)}</td>
            <td>${esc(d.cliente || '')}</td>
            <td>${esc(d.tecnico_nome || '')}</td>
            <td><span class="tag tag-${d.status}">${STATUS_LABEL[d.status] || d.status}</span></td>
            <td>${esc(d.supervisor_nome || '—')}</td>
            <td><button class="link" data-abrir-doc="${d.os_id}">Abrir</button></td>
        </tr>`;
    }

    // ── View: Formulário APR/PT ─────────────────────────────
    async function renderDocForm(osId) {
        const c = $('#content');
        c.innerHTML = '<p class="muted">Carregando…</p>';
        let os, cat;
        try { [os, cat] = await Promise.all([Api.getOs(osId), getCatalogo()]); }
        catch (e) { c.innerHTML = `<p class="error">${esc(e.message)}</p>`; return; }

        const doc = os.documento;
        const status = doc?.status || 'rascunho';
        const ehDono = state.user.role === 'tecnico' && Number(state.user.ixc_funcionario_id) === Number(os.id_tecnico);
        const podeEditar = (!doc || status === 'rascunho') && (ehDono || state.user.role === 'admin');
        const podeLiberar = isSupervisor() && status === 'aguardando_aprovacao';
        const podeEncerrar = (ehDono || state.user.role === 'admin') && ['liberada', 'em_execucao'].includes(status);
        const readOnly = !podeEditar;

        const riscosSel = keyed(doc?.riscos, 'codigo');
        const episSel = new Set(toArr(doc?.epis));
        const epcsSel = new Set(toArr(doc?.epcs));
        const chkSel = {};
        toArr(doc?.checklist).forEach((i) => { chkSel[i.codigo || i.descricao] = i.resposta; });
        const normasSel = new Set(toArr(doc?.normas).length ? toArr(doc?.normas) : [doc?.nr35 ? 'NR-35' : null, doc?.nr10 ? 'NR-10' : null].filter(Boolean));

        c.innerHTML = `
            <div class="page-head">
                <h1>APR / PT — OS #${esc(os.ixc_id)}</h1>
                <div class="toolbar">
                    <span class="tag tag-${status}">${STATUS_LABEL[status] || status}</span>
                    ${doc ? `<a class="btn-link" target="_blank" href="/api/documentos/${doc.id}/pdf">PDF</a>` : ''}
                    <button class="secondary" id="doc-voltar">Voltar</button>
                </div>
            </div>

            <section class="panel">
                <h2>1. Identificação</h2>
                <div class="grid2">
                    <div><span class="label">Cliente</span><div>${esc(os.cliente || '')}</div></div>
                    <div><span class="label">Protocolo</span><div>${esc(os.protocolo || '')}</div></div>
                    <div><span class="label">Endereço</span><div>${esc([os.endereco, os.bairro, os.cidade].filter(Boolean).join(' '))}</div></div>
                    <div><span class="label">Atividade</span><div>${esc(os.assunto || '')}</div></div>
                    <div><span class="label">Técnico</span><div>${esc(os.tecnico_nome || '')}</div></div>
                    <div><span class="label">Data agenda</span><div>${esc(os.data_agenda || '')}</div></div>
                </div>
            </section>

            <section class="panel">
                <h2>2. APR — Análise Preliminar de Risco</h2>

                <div class="field"><span class="label">Normas aplicáveis</span>
                    <div class="chips">${NORMAS.map((n) => `<label class="chip"><input type="checkbox" class="norma" value="${esc(n)}" ${normasSel.has(n) ? 'checked' : ''} ${readOnly ? 'disabled' : ''}> ${esc(n)}</label>`).join('')}</div>
                </div>

                <label class="field"><span class="label">Descrição da atividade</span>
                    <textarea id="doc-atividades" rows="2" ${readOnly ? 'disabled' : ''}>${esc(doc?.atividades || '')}</textarea></label>

                <span class="label">Riscos identificados</span>
                <div class="table-wrap"><table class="grid compact">
                    <thead><tr><th style="width:44%">Perigo</th><th>NR</th><th>Sev.</th><th>Prob.</th><th>Nível</th><th>Medidas de controle</th></tr></thead>
                    <tbody>${cat.riscos.map((r) => riscoRow(r, riscosSel[r.codigo], readOnly)).join('')}</tbody>
                </table></div>

                <span class="label">EPIs</span>
                <div class="checks">${cat.epis.map((x) => `<label class="chip"><input type="checkbox" class="epi" value="${esc(x.descricao)}" ${episSel.has(x.descricao) ? 'checked' : ''} ${readOnly ? 'disabled' : ''}> ${esc(x.descricao)}</label>`).join('')}</div>

                <span class="label">EPC / Ferramentas</span>
                <div class="checks">${cat.epcs.map((x) => `<label class="chip"><input type="checkbox" class="epc" value="${esc(x.descricao)}" ${epcsSel.has(x.descricao) ? 'checked' : ''} ${readOnly ? 'disabled' : ''}> ${esc(x.descricao)}</label>`).join('')}</div>

                <label class="field"><span class="label">Observações da APR</span>
                    <textarea id="doc-observacoes" rows="2" ${readOnly ? 'disabled' : ''}>${esc(doc?.observacoes || '')}</textarea></label>
            </section>

            <section class="panel">
                <h2>3. PT — Permissão de Trabalho</h2>
                <span class="label">Checklist de liberação</span>
                <div class="checklist">
                    ${cat.checklist.map((i) => checkRow(i, chkSel[i.codigo], readOnly)).join('')}
                </div>
                <label class="field"><span class="label">Restrições / observações</span>
                    <textarea id="doc-restricoes" rows="2" ${readOnly ? 'disabled' : ''}>${esc(doc?.restricoes || '')}</textarea></label>
                <div class="grid2">
                    <label class="field"><span class="label">Validade — início</span><input type="datetime-local" id="doc-val-ini" value="${toLocalInput(doc?.validade_inicio)}" ${readOnly ? 'disabled' : ''}></label>
                    <label class="field"><span class="label">Validade — fim</span><input type="datetime-local" id="doc-val-fim" value="${toLocalInput(doc?.validade_fim)}" ${readOnly ? 'disabled' : ''}></label>
                </div>
                <div class="termo"><strong>Declaração do executante.</strong> ${esc(cat.textos.declaracao_tecnico || '')}</div>
                <div class="termo"><strong>Autorização do supervisor.</strong> ${esc(cat.textos.autorizacao_supervisor || '')}</div>
            </section>

            ${assinaturasBlock(doc)}

            ${doc && doc.status === 'encerrada' ? `
            <section class="panel"><h2>5. Encerramento</h2>
                <div class="grid2">
                    <div><span class="label">Concluído em</span><div>${esc(doc.encerrado_em || '')}</div></div>
                    <div><span class="label">Serviço conforme</span><div>${doc.servico_conforme === null ? '—' : (Number(doc.servico_conforme) ? 'Sim' : 'Não')}</div></div>
                </div>
                <div>${nl2br(doc.encerramento_obs)}</div>
            </section>` : ''}

            <div class="sticky-actions">
                ${podeEditar ? '<button class="secondary" id="btn-rascunho">Salvar rascunho</button>' : ''}
                ${podeEditar ? '<button id="btn-assinar">Assinar e enviar para aprovação</button>' : ''}
                ${podeLiberar ? '<button id="btn-liberar">Autorizar PT (assinar)</button>' : ''}
                ${podeEncerrar ? '<button id="btn-encerrar">Encerrar documento</button>' : ''}
            </div>`;

        $('#doc-voltar').addEventListener('click', () => { location.hash = isSupervisor() ? '#documentos' : '#os'; });
        $$('.rk-sev, .rk-prob').forEach((s) => s.addEventListener('change', (ev) => {
            const tr = ev.target.closest('tr');
            $('.rk-nivel', tr).textContent = nivel($('.rk-sev', tr).value, $('.rk-prob', tr).value);
        }));

        if (podeEditar) {
            $('#btn-rascunho').addEventListener('click', async () => {
                const id = await salvar(coletar(os, doc));
                if (id) render(); // recarrega o formulário para reutilizar o mesmo documento/uuid
            });
            $('#btn-assinar').addEventListener('click', async () => {
                const payload = coletar(os, doc);
                const erro = validar(payload);
                if (erro) { toast(erro, true); return; }
                const sig = await askSignature('Assinatura do técnico');
                if (!sig) return;
                const id = await salvar(payload, true);
                if (!id) return;
                try {
                    await Api.assinarDocumento(id, { imagem_base64: sig, dispositivo: navigator.userAgent });
                    toast('Assinado. Aguardando aprovação do supervisor.');
                    location.hash = '#documentos';
                    render();
                } catch (e) { toast(e.message, true); }
            });
        }
        if (podeLiberar) {
            $('#btn-liberar').addEventListener('click', async () => {
                const sig = await askSignature('Assinatura do supervisor');
                if (!sig) return;
                try { await Api.aprovarDocumento(doc.id, { imagem_base64: sig, dispositivo: navigator.userAgent }); toast('PT liberada.'); render(); }
                catch (e) { toast(e.message, true); }
            });
        }
        if (podeEncerrar) {
            $('#btn-encerrar').addEventListener('click', () => encerrarModal(doc));
        }
    }

    function riscoRow(r, sel, readOnly) {
        const checked = sel ? 'checked' : '';
        const sev = sel?.sev ?? ''; const prob = sel?.prob ?? '';
        const opcoes = (v) => [1, 2, 3, 4, 5].map((n) => `<option value="${n}" ${Number(v) === n ? 'selected' : ''}>${n}</option>`).join('');
        const dis = readOnly ? 'disabled' : '';
        return `<tr data-codigo="${esc(r.codigo)}" data-perigo="${esc(r.descricao)}" data-nr="${esc(r.nr || '')}">
            <td><label class="inline"><input type="checkbox" class="rk-check" ${checked} ${dis}> ${esc(r.descricao)}</label>${r.fonte ? `<br><small class="muted">${esc(r.fonte)}</small>` : ''}</td>
            <td>${esc(r.nr || '')}</td>
            <td><select class="rk-sev" ${dis}><option value=""></option>${opcoes(sev)}</select></td>
            <td><select class="rk-prob" ${dis}><option value=""></option>${opcoes(prob)}</select></td>
            <td class="rk-nivel">${nivel(sev, prob)}</td>
            <td><input class="rk-medidas" value="${esc(sel?.medidas || '')}" ${dis}></td>
        </tr>`;
    }

    function checkRow(i, resposta, readOnly) {
        const dis = readOnly ? 'disabled' : '';
        const r = (v) => `<label class="chip"><input type="radio" name="chk-${esc(i.codigo)}" value="${v}" ${resposta === v ? 'checked' : ''} ${dis}> ${v.toUpperCase()}</label>`;
        return `<div class="check-item"><div>${esc(i.descricao)}${Number(i.critico) ? ' <span class="req">*</span>' : ''}</div><div class="chips">${r('sim')}${r('nao')}${r('na')}</div></div>`;
    }

    function assinaturasBlock(doc) {
        const ass = toArr(doc?.assinaturas);
        if (!ass.length) return '';
        return `<section class="panel"><h2>4. Assinaturas</h2>${ass.map((a) => `
            <div class="assinatura">
                ${a.imagem_path ? `<img src="/api/arquivo?p=${encodeURIComponent(a.imagem_path)}" alt="assinatura">` : ''}
                <div><strong>${esc(a.user_nome)}</strong> — ${a.tipo === 'tecnico' ? 'Técnico executante' : 'Supervisor'}<br><small class="muted">Assinado em ${esc(a.assinado_em)}</small></div>
            </div>`).join('')}</section>`;
    }

    // Coleta os dados do formulário
    function coletar(os, doc) {
        const riscos = $$('tr[data-codigo]').filter((tr) => $('.rk-check', tr).checked).map((tr) => ({
            codigo: tr.dataset.codigo, perigo: tr.dataset.perigo, nr: tr.dataset.nr,
            sev: +$('.rk-sev', tr).value || null, prob: +$('.rk-prob', tr).value || null,
            nivel: nivel($('.rk-sev', tr).value, $('.rk-prob', tr).value),
            medidas: $('.rk-medidas', tr).value.trim(),
        }));
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
            nr35: normas.includes('NR-35'), nr10: normas.includes('NR-10'),
            normas, riscos, epis, epcs, checklist,
            atividades: $('#doc-atividades')?.value.trim() || '',
            observacoes: $('#doc-observacoes')?.value.trim() || '',
            restricoes: $('#doc-restricoes')?.value.trim() || '',
            validade_inicio: $('#doc-val-ini')?.value || null,
            validade_fim: $('#doc-val-fim')?.value || null,
        };
    }

    function validar(payload) {
        if (!payload.riscos.length) return 'Selecione ao menos um risco na APR.';
        if (payload.riscos.some((r) => !r.sev || !r.prob)) return 'Informe severidade e probabilidade de todos os riscos.';
        if (payload.normas.includes('NR-35')) {
            if (!payload.epis.some((e) => /cinto/i.test(e))) return 'NR-35: marque o Cinto paraquedista nos EPIs.';
            if (!payload.epis.some((e) => /talabarte/i.test(e))) return 'NR-35: marque o Talabarte nos EPIs.';
        }
        if (payload.normas.includes('NR-10') && !payload.epis.some((e) => /isolante/i.test(e))) {
            return 'NR-10: marque a Luva isolante nos EPIs.';
        }
        return null;
    }

    async function salvar(payload, silencioso = false) {
        try {
            const saved = await Api.saveDocumento(payload);
            if (!silencioso) toast('Rascunho salvo.');
            return saved.id;
        } catch (e) { toast(e.message, true); return null; }
    }

    function encerrarModal(doc) {
        openModal('Encerrar documento', `
            <form id="enc-form">
                <label class="inline"><input type="checkbox" name="servico_conforme" checked> Serviço executado conforme previsto</label>
                <label>Ocorrências / observações<textarea name="encerramento_obs" rows="3"></textarea></label>
                <div class="form-actions">
                    <button type="button" class="secondary" id="enc-cancel">Cancelar</button>
                    <button type="submit">Encerrar</button>
                </div>
            </form>`);
        $('#enc-cancel').addEventListener('click', closeModal);
        $('#enc-form').addEventListener('submit', async (ev) => {
            ev.preventDefault();
            const f = new FormData(ev.target);
            try {
                await Api.encerrarDocumento(doc.id, { servico_conforme: f.get('servico_conforme') ? 1 : 0, encerramento_obs: f.get('encerramento_obs') });
                closeModal(); toast('Documento encerrado.'); render();
            } catch (e) { toast(e.message, true); }
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
            const cv = $('#sig-canvas');
            const ctx = cv.getContext('2d');
            ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, cv.width, cv.height);
            ctx.strokeStyle = '#111'; ctx.lineWidth = 2; ctx.lineJoin = 'round'; ctx.lineCap = 'round';
            let desenhou = false, draw = false;
            const pos = (e) => {
                const r = cv.getBoundingClientRect();
                const t = e.touches ? e.touches[0] : e;
                return { x: (t.clientX - r.left) * (cv.width / r.width), y: (t.clientY - r.top) * (cv.height / r.height) };
            };
            const start = (e) => { draw = true; desenhou = true; const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); };
            const move = (e) => { if (!draw) return; const p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); e.preventDefault(); };
            const end = () => { draw = false; };
            cv.addEventListener('mousedown', start); cv.addEventListener('mousemove', move); window.addEventListener('mouseup', end);
            cv.addEventListener('touchstart', start, { passive: false }); cv.addEventListener('touchmove', move, { passive: false }); cv.addEventListener('touchend', end);
            $('#sig-clear').addEventListener('click', () => { ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, cv.width, cv.height); desenhou = false; });
            $('#sig-cancel').addEventListener('click', () => { closeModal(); resolve(null); });
            $('#sig-ok').addEventListener('click', () => {
                if (!desenhou) { toast('Assine antes de confirmar.', true); return; }
                const data = cv.toDataURL('image/png');
                closeModal(); resolve(data);
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
            <div id="u-body"><p class="muted">Carregando…</p></div>`;
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

    // ── Eventos globais ─────────────────────────────────────
    $('#form-login').addEventListener('submit', async (ev) => {
        ev.preventDefault(); $('#login-error').textContent = '';
        const f = new FormData(ev.target);
        try { showApp(await Api.login(f.get('email'), f.get('password'))); } catch (e) { $('#login-error').textContent = e.message; }
    });
    $('#btn-logout').addEventListener('click', async () => { await Api.logout(); showLogin(); });
    $('#modal-close').addEventListener('click', closeModal);
    $('#modal').addEventListener('click', (e) => { if (e.target.id === 'modal') closeModal(); });
    window.addEventListener('hashchange', render);

    (async () => {
        if (!Api.isAuthenticated()) return showLogin();
        try { showApp(await Api.me()); } catch { Api.clear(); showLogin(); }
    })();
})();
