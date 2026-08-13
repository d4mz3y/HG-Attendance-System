const SESSION_TOKEN_KEY = 'hg_token';
const REMEMBERED_TOKEN_KEY = 'hg_remember_token';

export function getAuthToken() {
    return sessionStorage.getItem(SESSION_TOKEN_KEY) || localStorage.getItem(REMEMBERED_TOKEN_KEY);
}

export function hasAuthToken() {
    return Boolean(getAuthToken());
}

export function storeAuthToken(token, remember = false) {
    if (remember) {
        localStorage.setItem(REMEMBERED_TOKEN_KEY, token);
        sessionStorage.removeItem(SESSION_TOKEN_KEY);
        return;
    }

    sessionStorage.setItem(SESSION_TOKEN_KEY, token);
    localStorage.removeItem(REMEMBERED_TOKEN_KEY);
}

export function clearAuthToken() {
    sessionStorage.removeItem(SESSION_TOKEN_KEY);
    localStorage.removeItem(REMEMBERED_TOKEN_KEY);
}

export function isRememberedSession() {
    return Boolean(localStorage.getItem(REMEMBERED_TOKEN_KEY));
}
