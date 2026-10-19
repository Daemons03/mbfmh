/**
 * API client. Keeps the CSRF token; every failure becomes an ApiError.
 */

let csrfToken = null;

export class ApiError extends Error {
    constructor(status, body) {
        super(body?.message ?? messageFor(status, body?.error));
        this.status = status;
        this.code = body?.error ?? 'network';
        this.body = body ?? {};
    }
}

function messageFor(status, code) {
    if (status === 0) return 'Connexion au serveur impossible.';
    if (code === 'invalid_credentials') return 'Identifiant ou mot de passe incorrect.';
    if (status === 401) return 'Session expirée, reconnectez-vous.';
    if (status === 403) return 'Session expirée, rechargez la page.';
    return 'Une erreur est survenue.';
}

async function request(method, route, body) {
    const headers = { Accept: 'application/json' };
    if (method === 'POST') {
        headers['Content-Type'] = 'application/json';
        if (csrfToken) headers['X-CSRF-Token'] = csrfToken;
    }

    let response;
    try {
        response = await fetch(`api/${route}`, {
            method,
            headers,
            credentials: 'same-origin',
            body: body === undefined ? undefined : JSON.stringify(body),
        });
    } catch {
        throw new ApiError(0, null);
    }

    let json = null;
    try {
        json = await response.json();
    } catch {
        // non-JSON error page
    }
    if (json?.csrf_token) csrfToken = json.csrf_token;
    if (!response.ok || !json?.ok) throw new ApiError(response.status, json);

    return json;
}

export const api = {
    session: () => request('GET', 'session'),
    login: (login, password) => request('POST', 'login', { login, password }),
    logout: () => request('POST', 'logout', {}),
    data: () => request('GET', 'data'),
    state: () => request('GET', 'state'),
    action: (type, payload, version) => request('POST', 'action', { type, payload, version }),
    admin: (op, payload, version) => request('POST', 'admin', { op, payload, version }),
    bots: () => request('GET', 'bots'),
    bot: (id) => request('GET', `bot?id=${encodeURIComponent(id)}`),
};
