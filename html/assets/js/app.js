// APR/PT — interface (rotas por hash, views e gestão de usuários)
(function () {
    'use strict';

    const $ = (s, r = document) => r.querySelector(s);
    const state = { user: null, funcionarios: null };

    const ROLE_LABEL = { tecnico: 'Técnico', supervisor: 'Supervisor', admin: 'Administrador' };
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));

    // ── Utilidades de UI ────────────────────────────────────
    let toastTimer;
    function toast(msg, isError = false) {
        const t = $('#toast');
        t.textContent = msg;
        t.className = 'toast' + (isError ? ' error' : '');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => t.classList.add('hidden'), 3500);
    }
    function openModal(title, bodyHtml) {
        $('#modal-title').textContent = title;
        $('#modal-body').innerHTML = bodyHtml;
        $('#modal').classList.remove('hidden');
    }
    function closeModal() { $('#modal').classList.add('hidden'); }

    // ── Autenticação ────────────────────────────────────────
    function showLogin() {
        $('#view-login').classList.remove('hidden');
        $('#view-app').classList.add('hidden');
        location.hash = '';
    }
    function showApp(user) {
        state.user = user;
        $('#view-login').classList.add('hidden');
        $('#view-app').classList.remove('hidden');
        $('#user-info').textContent = `${user.name} · ${ROLE_LABEL[user.role] || user.role}`;

        // Esconde itens de menu sem permissão
        document.querySelectorAll('#nav a[data-roles]').forEach((a) => {
            const roles = a.dataset.roles.split(',');
            a.classList.toggle('hidden', !roles.includes(user.role));
        });

        render();
    }

    // ── Roteador ────────────────────────────────────────────
    function currentView() {
        const v = (location.hash || '#os').replace('#', '');
        return ['os', 'documentos', 'usuarios'].includes(v) ? v : 'os';
    }
    function render() {
        const view = currentView();
        document.querySelectorAll('#nav a').forEach((a) =>
            a.classList.toggle('active', a.dataset.view === view));

        if (view === 'usuarios') {
            if (!['supervisor', 'admin'].includes(state.user.role)) { location.hash = '#os'; return; }
            return renderUsuarios();
        }
        if (view === 'documentos') return renderDocumentos();
        return renderOs();
    }

    // ── View: Ordens de Serviço ─────────────────────────────
    const OS_STATUS = { A: 'Aberta', AN: 'Análise', EN: 'Encaminhada', AS: 'Assumida', AG: 'Agendada', DS: 'Deslocamento', EX: 'Execução', F: 'Finalizada', RAG: 'Aguard. agendamento' };
    let osCache = [];

    async function renderOs() {
        const c = $('#content');
        const podeSync = ['supervisor', 'admin'].includes(state.user.role);
        c.innerHTML = `
            <div class="page-head">
                <h1>Ordens de Serviço</h1>
                <div class="toolbar">
                    <input type="date" id="os-date" value="${localDate()}">
                    <button id="btn-hoje" class="secondary">Hoje</button>
                    ${podeSync ? '<button id="btn-sync">Sincronizar IXC</button>' : ''}
                </div>
            </div>
            <div class="filters">
                <input type="search" id="os-search" placeholder="Buscar cliente, técnico, endereço…">
                <select id="os-tec"><option value="">Todos os técnicos</option></select>
                <select id="os-status"><option value="">Todos os status</option>${Object.entries(OS_STATUS).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select>
            </div>
            <div id="os-body"><p class="muted">Carregando…</p></div>`;

        const load = async () => {
            try {
                osCache = await Api.listOs({ date: $('#os-date').value });
                fillTecOptions();
                applyOsFilters();
            } catch (e) {
                $('#os-body').innerHTML = `<p class="error">${esc(e.message)}</p>`;
            }
        };

        if (podeSync) {
            $('#btn-sync').addEventListener('click', async () => {
                toast('Sincronizando com o IXC…');
                try {
                    const r = await Api.syncOs($('#os-date').value);
                    toast(`Sincronizadas ${r.sincronizadas} de ${r.total} OS.`);
                    load();
                } catch (e) { toast(e.message, true); }
            });
        }
        $('#os-date').addEventListener('change', load);
        $('#btn-hoje').addEventListener('click', () => { $('#os-date').value = localDate(); load(); });
        $('#os-search').addEventListener('input', applyOsFilters);
        $('#os-tec').addEventListener('change', applyOsFilters);
        $('#os-status').addEventListener('change', applyOsFilters);
        load();
    }

    function fillTecOptions() {
        const sel = $('#os-tec');
        const atual = sel.value;
        const mapa = new Map();
        osCache.forEach((o) => {
            if (o.id_tecnico) mapa.set(String(o.id_tecnico), o.tecnico_nome || ('Técnico ' + o.id_tecnico));
        });
        const opts = [...mapa.entries()].sort((a, b) => a[1].localeCompare(b[1]))
            .map(([id, nome]) => `<option value="${id}">${esc(nome)}</option>`).join('');
        sel.innerHTML = '<option value="">Todos os técnicos</option>' + opts;
        sel.value = atual;
    }

    function applyOsFilters() {
        const q = ($('#os-search').value || '').toLowerCase();
        const tec = $('#os-tec').value;
        const st = $('#os-status').value;

        const rows = osCache.filter((o) => {
            if (tec && String(o.id_tecnico) !== tec) return false;
            if (st && o.status_ixc !== st) return false;
            if (q) {
                const alvo = [o.cliente, o.tecnico_nome, o.endereco, o.bairro, o.cidade, o.assunto, o.protocolo, o.ixc_id]
                    .join(' ').toLowerCase();
                if (!alvo.includes(q)) return false;
            }
            return true;
        });

        const body = $('#os-body');
        if (!rows.length) {
            body.innerHTML = '<p class="muted">Nenhuma OS encontrada com esses filtros.</p>';
            return;
        }
        body.innerHTML = `<table class="grid">
            <thead><tr><th>Hora</th><th>Cliente</th><th>Técnico</th><th>Assunto</th><th>Status</th><th>APR/PT</th></tr></thead>
            <tbody>${rows.map(rowOs).join('')}</tbody></table>`;
    }

    function rowOs(o) {
        const hora = (o.data_agenda || '').slice(11, 16);
        const doc = o.documento ? `<span class="tag tag-${o.documento.status}">${o.documento.status}</span>` : '<span class="muted">—</span>';
        return `<tr>
            <td>${esc(hora)}</td>
            <td>${esc(o.cliente || '(sem nome)')}<br><small class="muted">#${esc(o.ixc_id)} · ${esc(o.cidade || '')}</small></td>
            <td>${esc(o.tecnico_nome || (o.id_tecnico ? 'Técnico ' + o.id_tecnico : ''))}</td>
            <td>${esc(o.assunto || '')}</td>
            <td><span class="tag">${esc(OS_STATUS[o.status_ixc] || o.status_ixc || '')}</span></td>
            <td>${doc}</td>
        </tr>`;
    }

    function localDate() {
        const d = new Date();
        return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    }

    // ── View: Documentos ────────────────────────────────────
    function renderDocumentos() {
        $('#content').innerHTML = `
            <div class="page-head"><h1>Documentos APR/PT</h1></div>
            <div class="card empty-state">
                <p>As telas de preenchimento (técnico) e aprovação (supervisor) entram na próxima fase.</p>
            </div>`;
    }

    // ── View: Usuários ──────────────────────────────────────
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
                const users = await Api.listUsers({
                    search: $('#u-search').value,
                    role: $('#u-role').value,
                });
                $('#u-body').innerHTML = users.length
                    ? `<table class="grid">
                        <thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Func. IXC</th><th>Ativo</th><th></th></tr></thead>
                        <tbody>${users.map(rowUser).join('')}</tbody></table>`
                    : '<p class="muted">Nenhum usuário encontrado.</p>';
                bindUserActions();
            } catch (e) {
                $('#u-body').innerHTML = `<p class="error">${esc(e.message)}</p>`;
            }
        };

        $('#btn-new-user').addEventListener('click', () => userForm(null));
        $('#u-search').addEventListener('input', debounce(load, 300));
        $('#u-role').addEventListener('change', load);
        load();
    }

    function rowUser(u) {
        return `<tr>
            <td>${esc(u.name)}</td>
            <td>${esc(u.email)}</td>
            <td><span class="tag role-${u.role}">${ROLE_LABEL[u.role] || u.role}</span></td>
            <td>${u.ixc_funcionario_id ?? '<span class="muted">—</span>'}</td>
            <td>${Number(u.ativo) ? 'Sim' : 'Não'}</td>
            <td class="acoes">
                <button class="link" data-edit="${u.id}">Editar</button>
                <button class="link" data-pass="${u.id}">Senha</button>
                <button class="link danger" data-del="${u.id}">Excluir</button>
            </td>
        </tr>`;
    }

    function bindUserActions() {
        document.querySelectorAll('[data-edit]').forEach((b) =>
            b.addEventListener('click', () => editUserById(+b.dataset.edit)));
        document.querySelectorAll('[data-pass]').forEach((b) =>
            b.addEventListener('click', () => passwordForm(+b.dataset.pass)));
        document.querySelectorAll('[data-del]').forEach((b) =>
            b.addEventListener('click', () => deleteUser(+b.dataset.del)));
    }

    let cachedUsers = [];
    async function editUserById(id) {
        if (!cachedUsers.length) cachedUsers = await Api.listUsers();
        const u = cachedUsers.find((x) => x.id === id) || (await Api.listUsers()).find((x) => x.id === id);
        userForm(u);
    }

    async function funcionariosOptions(selected) {
        if (!state.funcionarios) {
            try { state.funcionarios = await Api.ixcFuncionarios(); }
            catch (_) { state.funcionarios = []; }
        }
        const opts = state.funcionarios.map((f) =>
            `<option value="${f.id}" ${String(selected) === String(f.id) ? 'selected' : ''}>${esc(f.nome)}${f.email ? ' — ' + esc(f.email) : ''}</option>`).join('');
        return `<option value="">— não vincular —</option>${opts}`;
    }

    async function userForm(u) {
        const isEdit = !!u;
        const funcOpts = await funcionariosOptions(u?.ixc_funcionario_id);
        const podeAdmin = state.user.role === 'admin';
        openModal(isEdit ? 'Editar usuário' : 'Novo usuário', `
            <form id="user-form">
                <label>Nome<input name="name" required value="${esc(u?.name || '')}"></label>
                <label>E-mail<input type="email" name="email" required value="${esc(u?.email || '')}"></label>
                <label>Perfil
                    <select name="role" required>
                        <option value="tecnico" ${u?.role === 'tecnico' ? 'selected' : ''}>Técnico</option>
                        <option value="supervisor" ${u?.role === 'supervisor' ? 'selected' : ''}>Supervisor</option>
                        ${podeAdmin ? `<option value="admin" ${u?.role === 'admin' ? 'selected' : ''}>Administrador</option>` : ''}
                    </select>
                </label>
                <label>Funcionário no IXC
                    <select name="ixc_funcionario_id">${funcOpts}</select>
                </label>
                ${isEdit ? `<label class="inline"><input type="checkbox" name="ativo" ${Number(u.ativo) ? 'checked' : ''}> Ativo</label>`
                         : `<label>Senha inicial<input type="text" name="password" required minlength="6" value="${randPass()}"></label>`}
                <div class="form-actions">
                    <button type="button" class="secondary" id="uf-cancel">Cancelar</button>
                    <button type="submit">Salvar</button>
                </div>
            </form>`);

        $('#uf-cancel').addEventListener('click', closeModal);
        $('#user-form').addEventListener('submit', async (ev) => {
            ev.preventDefault();
            const f = new FormData(ev.target);
            const payload = {
                name: f.get('name').trim(),
                email: f.get('email').trim(),
                role: f.get('role'),
                ixc_funcionario_id: f.get('ixc_funcionario_id') || null,
            };
            if (isEdit) payload.ativo = f.get('ativo') ? 1 : 0;
            else payload.password = f.get('password');
            try {
                if (isEdit) await Api.updateUser(u.id, payload);
                else await Api.createUser(payload);
                closeModal();
                toast(isEdit ? 'Usuário atualizado.' : 'Usuário criado.');
                cachedUsers = [];
                renderUsuarios();
            } catch (e) { toast(e.message, true); }
        });
    }

    function passwordForm(id) {
        const nova = randPass();
        openModal('Redefinir senha', `
            <form id="pass-form">
                <p class="muted">A senha atual do usuário deixará de funcionar.</p>
                <label>Nova senha<input type="text" name="password" required minlength="6" value="${nova}"></label>
                <div class="form-actions">
                    <button type="button" class="secondary" id="pf-cancel">Cancelar</button>
                    <button type="submit">Redefinir</button>
                </div>
            </form>`);
        $('#pf-cancel').addEventListener('click', closeModal);
        $('#pass-form').addEventListener('submit', async (ev) => {
            ev.preventDefault();
            const pw = new FormData(ev.target).get('password');
            try {
                await Api.resetUserPassword(id, pw);
                closeModal();
                toast('Senha redefinida: ' + pw);
            } catch (e) { toast(e.message, true); }
        });
    }

    async function deleteUser(id) {
        if (!confirm('Excluir este usuário?')) return;
        try { await Api.deleteUser(id); toast('Usuário excluído.'); cachedUsers = []; renderUsuarios(); }
        catch (e) { toast(e.message, true); }
    }

    // ── Helpers ─────────────────────────────────────────────
    function randPass() {
        const hex = [...crypto.getRandomValues(new Uint8Array(3))].map((b) => b.toString(16).padStart(2, '0')).join('');
        return 'Nwt@' + hex;
    }
    function debounce(fn, ms) {
        let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
    }

    // ── Eventos globais ─────────────────────────────────────
    $('#form-login').addEventListener('submit', async (ev) => {
        ev.preventDefault();
        $('#login-error').textContent = '';
        const f = new FormData(ev.target);
        try {
            const user = await Api.login(f.get('email'), f.get('password'));
            showApp(user);
        } catch (e) { $('#login-error').textContent = e.message; }
    });
    $('#btn-logout').addEventListener('click', async () => { await Api.logout(); showLogin(); });
    $('#modal-close').addEventListener('click', closeModal);
    $('#modal').addEventListener('click', (e) => { if (e.target.id === 'modal') closeModal(); });
    window.addEventListener('hashchange', render);

    // ── Inicialização ───────────────────────────────────────
    (async () => {
        if (!Api.isAuthenticated()) return showLogin();
        try {
            const user = await Api.me();
            showApp(user);
        } catch (_) { Api.clear(); showLogin(); }
    })();
})();
