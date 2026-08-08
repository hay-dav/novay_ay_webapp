const TOKEN_STORAGE_KEY = 'novaya_ya_token';
let memoryToken = null;

function safeGet(storage) {
    try {
        return storage?.getItem(TOKEN_STORAGE_KEY) ?? null;
    }
    catch {
        return null;
    }
}

export function readAuthToken() {
    const token = safeGet(window.localStorage) ?? safeGet(window.sessionStorage) ?? memoryToken;
    if (token) {
        memoryToken = token;
        try {
            window.localStorage.setItem(TOKEN_STORAGE_KEY, token);
        }
        catch {
            // Some privacy modes prohibit persistent storage. The in-memory
            // fallback keeps the current session usable instead of failing.
        }
    }
    return token;
}

export function storeAuthToken(token) {
    memoryToken = token;
    try {
        window.localStorage.setItem(TOKEN_STORAGE_KEY, token);
        window.sessionStorage.removeItem(TOKEN_STORAGE_KEY);
        return 'local';
    }
    catch {
        try {
            window.sessionStorage.setItem(TOKEN_STORAGE_KEY, token);
            return 'session';
        }
        catch {
            return 'memory';
        }
    }
}

export function clearAuthToken() {
    memoryToken = null;
    try { window.localStorage.removeItem(TOKEN_STORAGE_KEY); } catch { /* ignored */ }
    try { window.sessionStorage.removeItem(TOKEN_STORAGE_KEY); } catch { /* ignored */ }
}
