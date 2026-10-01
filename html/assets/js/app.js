// Bootstrap da interface (esqueleto). As telas de APR/PT entram nas próximas fases.
(function () {
    const $ = (sel) => document.querySelector(sel);
    const viewLogin = $('#view-login');
    const viewApp = $('#view-app');
    const userInfo = $('#user-info');
    const appMsg = $('#app-msg');
    const osList = $('#os-list');

    function showLogin() {
        viewLogin.classList.remove('hidden');
        viewApp.classList.add('hidden');
        userInfo.textContent = '';
    }
    function showApp(user) {
        viewLogin.classList.add('hidden');
        viewApp.classList.remove('hidden');
        userInfo.textContent = user.name ? `${user.name} (${user.role})` : '';
    }

    async function loadOs() {
        osList.innerHTML = '<p>Carregando…</p>';
        try {
            const os = await Api.get('/os?date=' + new Date().toISOString().slice(0, 10));
            if (!os.length) {
                osList.innerHTML = '<p>Nenhuma OS para hoje. Use "Sincronizar OS do dia".</p>';
                return;
            }
            osList.innerHTML = os.map((o) => `
                <article class="os-item">
                    <header><strong>#${o.ixc_id}</strong> <span class="badge">${o.status_ixc || ''}</span></header>
                    <div>${o.cliente || '(sem cliente)'}</div>
                    <small>${o.endereco || ''} ${o.bairro || ''}</small>
                    <div class="doc">APR/PT: <strong>${o.documento ? o.documento.status : 'não iniciado'}</strong></div>
                </article>
            `).join('');
        } catch (e) {
            osList.innerHTML = `<p class="error">${e.message}</p>`;
        }
    }

    $('#form-login').addEventListener('submit', async (ev) => {
        ev.preventDefault();
        $('#login-error').textContent = '';
        const form = new FormData(ev.target);
        try {
            const user = await Api.login(form.get('email'), form.get('password'));
            showApp(user);
            loadOs();
        } catch (e) {
            $('#login-error').textContent = e.message;
        }
    });

    $('#btn-logout').addEventListener('click', async () => {
        await Api.logout();
        showLogin();
    });

    $('#btn-sync').addEventListener('click', async () => {
        appMsg.textContent = 'Sincronizando…';
        try {
            const r = await Api.post('/sync/os', { date: new Date().toISOString().slice(0, 10) });
            appMsg.textContent = `Sincronizadas ${r.sincronizadas} de ${r.total} OS.`;
            loadOs();
        } catch (e) {
            appMsg.textContent = e.message;
        }
    });

    // Inicialização
    (async () => {
        if (!Api.isAuthenticated()) return showLogin();
        try {
            const user = await Api.me();
            showApp(user);
            loadOs();
        } catch (_) {
            Api.clear();
            showLogin();
        }
    })();
})();
