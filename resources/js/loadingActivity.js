let nextRequestId = 0;
const activeRequestIds = new Set();

function publishActivity() {
    if (typeof window === 'undefined') {
        return;
    }

    window.dispatchEvent(new CustomEvent('hg:api-activity', {
        detail: { pending: activeRequestIds.size },
    }));
}

/**
 * Keep network activity in one small, framework-independent place so the
 * application shell can show feedback for any API-backed screen.
 */
export function beginApiActivity(config) {
    if (!config || config.hgSkipGlobalLoader || config.__hgActivityRequestId) {
        return config;
    }

    const requestId = ++nextRequestId;
    config.__hgActivityRequestId = requestId;
    activeRequestIds.add(requestId);
    publishActivity();

    return config;
}

export function endApiActivity(config) {
    const requestId = config?.__hgActivityRequestId;
    if (!requestId || !activeRequestIds.delete(requestId)) {
        return;
    }

    publishActivity();
}

export function getPendingApiActivity() {
    return activeRequestIds.size;
}
