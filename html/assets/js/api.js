// Cliente HTTP da API — guarda o token no localStorage e injeta o Bearer.
const Api = (() => {
    const TOKEN_KEY = 'ap_token';
    const USER_KEY = 'ap_user';
    const BASE = '/api';

    function getToken() { return localStorage.getItem(TOKEN_KEY); }
    function setSession(token, user) {
        localStorage.setItem(TOKEN_KEY, token);
        localStorage.setItem(USER_KEY, JSON.stringify(user || {}));
        // Cookie permite abrir PDF/imagens em nova aba ou <img> (que não enviam header Authorization)
        document.cookie = `${TOKEN_KEY}=${token}; path=/; SameSite=Lax; max-age=43200`;
    }
    function getUser() {
        try { return JSON.parse(localStorage.getItem(USER_KEY) || '{}'); } catch { return {}; }
    }
    function clear() {
        localStorage.removeItem(TOKEN_KEY);
        localStorage.removeItem(USER_KEY);
        document.cookie = `${TOKEN_KEY}=; path=/; max-age=0`;
    }

    async function request(method, path, body) {
        const headers = { 'Content-Type': 'application/json' };
        const token = getToken();
        if (token) headers['Authorization'] = 'Bearer ' + token;

        const res = await fetch(BASE + path, {
            method,
            headers,
            body: body ? JSON.stringify(body) : undefined,
        });

        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.success === false) {
            const err = new Error(data.message || 'Erro na requisição');
            err.status = res.status;
            err.payload = data;
            throw err;
        }
        return data.data;
    }

    return {
        get: (p) => request('GET', p),
        post: (p, b) => request('POST', p, b),
        put: (p, b) => request('PUT', p, b),
        del: (p) => request('DELETE', p),

        login: async (email, password) => {
            const data = await request('POST', '/auth/login', { email, password });
            setSession(data.access_token, data.user);
            return data.user;
        },
        logout: async () => {
            try { await request('POST', '/auth/logout'); } catch (_) {}
            clear();
        },
        me: () => request('GET', '/auth/me'),
        changePassword: (current_password, new_password) =>
            request('POST', '/auth/change-password', { current_password, new_password }),

        // Ordens de serviço
        listOs: (params = {}) => {
            const qs = new URLSearchParams(params).toString();
            return request('GET', '/os' + (qs ? '?' + qs : ''));
        },
        syncOs: (date) => request('POST', '/sync/os', { date }),

        // Usuários
        listUsers: (params = {}) => {
            const qs = new URLSearchParams(params).toString();
            return request('GET', '/users' + (qs ? '?' + qs : ''));
        },
        createUser: (u) => request('POST', '/users', u),
        updateUser: (id, u) => request('PUT', '/users/' + id, u),
        resetUserPassword: (id, password) => request('POST', `/users/${id}/senha`, { password }),
        deleteUser: (id) => request('DELETE', '/users/' + id),

        // Documentos / catálogo
        catalogo: () => request('GET', '/catalogo'),
        listDocumentos: (params = {}) => {
            const qs = new URLSearchParams(params).toString();
            return request('GET', '/documentos' + (qs ? '?' + qs : ''));
        },
        getDocumento: (id) => request('GET', '/documentos/' + id),
        resumoDocumentos: () => request('GET', '/documentos/resumo'),
        saveDocumento: (d) => request('POST', '/documentos', d),
        assinarDocumento: (id, dados) => request('POST', `/documentos/${id}/assinar`, dados),
        aprovarDocumento: (id, dados) => request('POST', `/documentos/${id}/aprovar`, dados),
        encerrarDocumento: (id, dados) => request('POST', `/documentos/${id}/encerrar`, dados),
        getOs: (id) => request('GET', '/os/' + id),

        // IXC
        ixcFuncionarios: () => request('GET', '/ixc/funcionarios'),

        // Modelo APR/PT (edição)
        getModelo: () => request('GET', '/modelo'),
        createModelo: (tipo, d) => request('POST', '/modelo/' + tipo, d),
        updateModelo: (tipo, id, d) => request('PUT', `/modelo/${tipo}/${id}`, d),
        deleteModelo: (tipo, id) => request('DELETE', `/modelo/${tipo}/${id}`),
        saveTextos: (d) => request('PUT', '/modelo/textos', d),

        getToken, getUser, clear,
        isAuthenticated: () => !!getToken(),
    };
})();
