// Cliente HTTP da API — guarda o token no localStorage e injeta o Bearer.
const Api = (() => {
    const TOKEN_KEY = 'ap_token';
    const USER_KEY = 'ap_user';
    const BASE = '/api';

    function getToken() {
        return localStorage.getItem(TOKEN_KEY);
    }
    function setSession(token, user) {
        localStorage.setItem(TOKEN_KEY, token);
        localStorage.setItem(USER_KEY, JSON.stringify(user || {}));
    }
    function getUser() {
        try { return JSON.parse(localStorage.getItem(USER_KEY) || '{}'); } catch { return {}; }
    }
    function clear() {
        localStorage.removeItem(TOKEN_KEY);
        localStorage.removeItem(USER_KEY);
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
        getToken,
        getUser,
        clear,
        isAuthenticated: () => !!getToken(),
    };
})();
