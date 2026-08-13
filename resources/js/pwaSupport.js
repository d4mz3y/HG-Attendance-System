/**
 * Some embedded browsers expose `navigator.serviceWorker` without providing
 * the registration API. Treat that as unsupported instead of letting the
 * kiosk page throw while it is loading.
 */
export function supportsServiceWorkerRegistration(browser = globalThis) {
    return Boolean(
        browser?.isSecureContext
        && typeof browser?.navigator?.serviceWorker?.register === 'function',
    );
}
