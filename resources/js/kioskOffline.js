import { hmacSha256 } from './kioskCrypto';
import { beginApiActivity, endApiActivity } from './loadingActivity';

const DB_NAME = 'hg-attendance-kiosk';
const DB_VERSION = 2;
const STATE_STORE = 'state';
const EVENT_STORE = 'events';
const QUEUE_LOCK = 'queue';
const RECOVERY_REQUESTS_STATE = 'recovery_requests';
const PENDING_RECEPTION_SECRET_STATE = 'pending_reception_secret';
const memoryLocks = new Map();

// The scanner deliberately uses native fetch because it works without a
// portal session. Still report that work to the common application loader so
// a slow secure pairing or queue sync gets the same small progress feedback
// as every Axios-backed portal request.
async function kioskFetch(input, init = {}) {
    const activity = beginApiActivity({});
    try {
        return await fetch(input, init);
    } finally {
        endApiActivity(activity);
    }
}

function requestPromise(request) {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

function transactionPromise(transaction) {
    return new Promise((resolve, reject) => {
        transaction.oncomplete = resolve;
        transaction.onerror = () => reject(transaction.error);
        transaction.onabort = () => reject(transaction.error ?? new Error('IndexedDB transaction aborted.'));
    });
}

function openDatabase() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);
        request.onupgradeneeded = (event) => {
            const db = request.result;
            if (!db.objectStoreNames.contains(STATE_STORE)) db.createObjectStore(STATE_STORE, { keyPath: 'key' });
            if (!db.objectStoreNames.contains(EVENT_STORE)) {
                const events = db.createObjectStore(EVENT_STORE, { keyPath: 'event_id' });
                events.createIndex('sequence', 'sequence');
                events.createIndex('status', 'status');
            } else if (event.oldVersion < 2) {
                const events = request.transaction.objectStore(EVENT_STORE);
                if (events.indexNames.contains('sequence')) events.deleteIndex('sequence');
                events.createIndex('sequence', 'sequence');
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function state(key) {
    const db = await openDatabase();
    const value = await requestPromise(db.transaction(STATE_STORE).objectStore(STATE_STORE).get(key));
    db.close();
    return value?.value;
}

async function setState(key, value) {
    const db = await openDatabase();
    const transaction = db.transaction(STATE_STORE, 'readwrite');
    transaction.objectStore(STATE_STORE).put({ key, value });
    await transactionPromise(transaction);
    db.close();
}

async function allEvents() {
    const db = await openDatabase();
    const events = await requestPromise(db.transaction(EVENT_STORE).objectStore(EVENT_STORE).getAll());
    db.close();
    return events.sort((left, right) => left.sequence - right.sequence);
}

function tokenParts(token) {
    const dot = token.indexOf('.');
    if (dot < 1) throw new Error('Invalid device token.');
    const identifier = token.slice(0, dot);
    const secret = token.slice(dot + 1);
    if (!/^[0-9a-f-]{36}$/i.test(identifier) || secret.length < 32) throw new Error('Invalid device token.');
    return { identifier, secret };
}

function canonical(event) {
    return `${event.event_id}\n${event.code}\n${event.occurred_at}\n${event.sequence}`;
}

function uuid() {
    if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
    const bytes = new Uint8Array(16);
    if (globalThis.crypto?.getRandomValues) globalThis.crypto.getRandomValues(bytes);
    else for (let index = 0; index < bytes.length; index += 1) bytes[index] = Math.floor(Math.random() * 256);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

function randomSecret() {
    if (!globalThis.crypto?.getRandomValues) {
        throw new Error('This browser cannot create the reception scanner credential securely. Use an up-to-date browser on the reception computer.');
    }

    const bytes = new Uint8Array(32);
    globalThis.crypto.getRandomValues(bytes);

    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
}

async function exclusive(name, callback) {
    if (navigator.locks?.request) return navigator.locks.request(`hg-kiosk-${name}`, callback);

    const storageKey = `hg-kiosk-lock:${name}`;
    const owner = uuid();
    const deadline = Date.now() + 12000;
    const readLock = () => {
        try { return JSON.parse(localStorage.getItem(storageKey) || 'null'); } catch { return null; }
    };
    try {
        while (Date.now() < deadline) {
            const current = readLock();
            if (!current || current.expires_at < Date.now()) {
                localStorage.setItem(storageKey, JSON.stringify({ owner, expires_at: Date.now() + 30000 }));
                if (readLock()?.owner === owner) {
                    const heartbeat = window.setInterval(() => {
                        if (readLock()?.owner === owner) {
                            localStorage.setItem(storageKey, JSON.stringify({ owner, expires_at: Date.now() + 30000 }));
                        }
                    }, 5000);
                    try {
                        return await callback();
                    } finally {
                        window.clearInterval(heartbeat);
                        if (readLock()?.owner === owner) localStorage.removeItem(storageKey);
                    }
                }
            }
            await new Promise((resolve) => window.setTimeout(resolve, 40 + Math.floor(Math.random() * 60)));
        }
        throw new Error('Another kiosk tab is busy. Close duplicate tabs and try again.');
    } catch (error) {
        if (!(error instanceof DOMException)) throw error;
    }

    // Storage may be disabled in a restrictive browser. Keep an in-tab lock
    // as a final fallback; the UI warns operators to use one kiosk tab.
    const previous = memoryLocks.get(name) ?? Promise.resolve();
    let release;
    const current = new Promise((resolve) => { release = resolve; });
    memoryLocks.set(name, current);
    await previous;
    try { return await callback(); } finally {
        release();
        if (memoryLocks.get(name) === current) memoryLocks.delete(name);
    }
}

export async function loadDeviceToken() {
    return state('device_token');
}

/**
 * Pair the approved reception browser without showing a token or requiring a
 * staff member to enter one. IT must already have configured the one
 * reception scanner's exact IP address in Scan devices. The browser-only secret
 * remains in IndexedDB; the server receives and retains only its hash.
 *
 * @return Promise<{token: string, config: Record<string, unknown>}>
 */
export async function pairReceptionScanner({ replaceExisting = false } = {}) {
    if (!window.isSecureContext) {
        throw new Error('A trusted HTTPS connection is required before the reception scanner can pair securely.');
    }

    return exclusive('reception-pairing', async () => {
        const existing = await loadDeviceToken();
        if (existing && !replaceExisting) {
            // The same page may initialize twice in React development mode.
            // Treat a credential saved by the first initializer as success,
            // never as a reason to overwrite or show a setup failure.
            return { token: existing, config: await receptionConfig(existing) };
        }

        // Persist this before the request. React development mode can start
        // an effect twice, and a lost response should be retried with the
        // same proof rather than accidentally claiming a second browser.
        let secret = await state(PENDING_RECEPTION_SECRET_STATE);
        if (typeof secret !== 'string' || !/^[a-f0-9]{64}$/i.test(secret)) {
            secret = randomSecret();
            await setState(PENDING_RECEPTION_SECRET_STATE, secret);
        }
        const pairResponse = await kioskFetch('/api/scan/reception/pair', {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ secret }),
        });
        const pairBody = await pairResponse.json().catch(() => ({}));
        if (!pairResponse.ok) {
            const error = new Error(pairBody.message ?? 'The reception scanner could not be paired.');
            error.status = pairResponse.status;
            throw error;
        }

        const token = `${pairBody.device?.id ?? ''}.${secret}`;
        // Validate the response before persisting a credential. This also
        // gives us the full configuration used by the signed queue.
        tokenParts(token);
        const config = await receptionConfig(token, 'The reception scanner paired but its configuration could not be verified.');

        await saveDeviceToken(token, config);
        await setState(PENDING_RECEPTION_SECRET_STATE, null);

        return { token, config };
    });
}

async function receptionConfig(token, fallback = 'The reception scanner configuration could not be verified.') {
        const configResponse = await kioskFetch('/api/scan/config', {
        headers: { Accept: 'application/json', 'X-Device-Token': token },
    });
    const config = await configResponse.json().catch(() => ({}));
    if (!configResponse.ok || !config.device?.id) {
        const error = new Error(config.message ?? fallback);
        error.status = configResponse.status;
        throw error;
    }

    return config;
}

export async function saveDeviceToken(token, config) {
    return exclusive(QUEUE_LOCK, async () => {
        const { identifier } = tokenParts(token.trim());
        const previousIdentifier = await state('device_identifier');
        const unresolved = (await allEvents()).filter((event) => ['pending', 'blocked'].includes(event.status));
        if (previousIdentifier && previousIdentifier !== identifier && unresolved.length > 0) {
            throw new Error('Sync or resolve the current device queue before replacing its token.');
        }
        await setState('device_token', token.trim());
        await setState('device_identifier', identifier);
        await setState('kiosk_config', config);
        await setState('clock_offset_ms', Date.parse(config.server_time) - Date.now());
        if (unresolved.length === 0) await setState('next_sequence', config.device.next_sequence);
    });
}

export async function clearDeviceToken() {
    return exclusive(QUEUE_LOCK, async () => {
        const unresolved = (await allEvents()).filter((event) => ['pending', 'blocked'].includes(event.status));
        if (unresolved.length > 0) throw new Error('This device has unsynced or blocked events. Resolve them before removing its token.');
        await setState('device_token', null);
        await setState('device_identifier', null);
        await setState('kiosk_config', null);
        await setState(RECOVERY_REQUESTS_STATE, []);
    });
}

export async function discardKioskData() {
    return exclusive(QUEUE_LOCK, async () => {
        const unresolved = (await allEvents()).filter((event) => ['pending', 'blocked'].includes(event.status));
        if (unresolved.length > 0) {
            throw new Error('This kiosk has unresolved attendance events. IT must review or synchronize them before the browser can be reset.');
        }
        const db = await openDatabase();
        db.close();
        await new Promise((resolve, reject) => {
            const request = indexedDB.deleteDatabase(DB_NAME);
            request.onsuccess = resolve;
            request.onerror = () => reject(request.error);
            request.onblocked = () => reject(new Error('Close other kiosk tabs before resetting this device.'));
        });
    });
}

export async function cachedKioskConfig() {
    return state('kiosk_config');
}

export async function updateKioskConfig(config) {
    return exclusive(QUEUE_LOCK, async () => {
        await setState('kiosk_config', config);
        await setState('clock_offset_ms', Date.parse(config.server_time) - Date.now());
        const unresolved = (await allEvents()).filter((event) => ['pending', 'blocked'].includes(event.status));
        if (unresolved.length === 0 && config.device) await setState('next_sequence', config.device.next_sequence);
    });
}

export async function captureScan(code) {
    return exclusive(QUEUE_LOCK, async () => {
        const token = await loadDeviceToken();
        if (!token) throw new Error('This browser is not registered as a kiosk.');
        const { secret } = tokenParts(token);
        const offset = Number(await state('clock_offset_ms') ?? 0);
        const eventId = uuid();
        const occurredAt = new Date(Date.now() + offset).toISOString();
        let sequence = Number(await state('next_sequence') ?? 1);

        // navigator.locks handles normal browsers, but this optimistic
        // compare-and-set also protects the IndexedDB counter if a legacy
        // fallback lock races between duplicate kiosk tabs.
        for (let attempt = 0; attempt < 3; attempt += 1) {
            const event = {
                event_id: eventId,
                code: code.trim(),
                occurred_at: occurredAt,
                sequence,
                status: 'pending',
                attempts: 0,
                last_error: null,
                captured_at: new Date().toISOString(),
            };
            event.signature = await hmacSha256(secret, canonical(event));

            if (await storeCapturedEvent(event, sequence)) return event;
            sequence = Number(await state('next_sequence') ?? 1);
        }

        throw new Error('The kiosk queue changed while this scan was being saved. Close duplicate kiosk tabs and scan again.');
    });
}

async function storeCapturedEvent(event, expectedSequence) {
    const db = await openDatabase();

    try {
        return await new Promise((resolve, reject) => {
            const transaction = db.transaction([STATE_STORE, EVENT_STORE], 'readwrite');
            const stateStore = transaction.objectStore(STATE_STORE);
            const eventStore = transaction.objectStore(EVENT_STORE);
            let sequenceChanged = false;

            transaction.oncomplete = () => resolve(true);
            transaction.onabort = () => {
                if (sequenceChanged) resolve(false);
                else reject(transaction.error ?? new Error('The kiosk event could not be saved.'));
            };
            transaction.onerror = () => {};

            const currentRequest = stateStore.get('next_sequence');
            currentRequest.onerror = () => transaction.abort();
            currentRequest.onsuccess = () => {
                const currentSequence = Number(currentRequest.result?.value ?? 1);
                if (currentSequence !== expectedSequence) {
                    sequenceChanged = true;
                    transaction.abort();
                    return;
                }

                try {
                    eventStore.add(event);
                    stateStore.put({ key: 'next_sequence', value: expectedSequence + 1 });
                } catch {
                    transaction.abort();
                }
            };
        });
    } finally {
        db.close();
    }
}

async function updateEvents(updates) {
    const db = await openDatabase();
    const transaction = db.transaction(EVENT_STORE, 'readwrite');
    const store = transaction.objectStore(EVENT_STORE);
    updates.forEach((event) => store.put(event));
    await transactionPromise(transaction);
    db.close();
}

async function resequencePending(expected, secret) {
    const pending = (await allEvents()).filter((event) => event.status === 'pending');
    let sequence = expected;
    for (const event of pending) {
        event.sequence = sequence;
        event.signature = await hmacSha256(secret, canonical(event));
        event.last_error = null;
        sequence += 1;
    }

    // Re-signed events and their next counter must commit together. A crash
    // between two separate writes could otherwise reuse a sequence number.
    const db = await openDatabase();
    const transaction = db.transaction([STATE_STORE, EVENT_STORE], 'readwrite');
    const eventStore = transaction.objectStore(EVENT_STORE);
    pending.forEach((event) => eventStore.put(event));
    transaction.objectStore(STATE_STORE).put({ key: 'next_sequence', value: sequence });
    await transactionPromise(transaction);
    db.close();
}

async function pruneResolved() {
    const resolved = (await allEvents()).filter((event) => ['confirmed', 'rejected'].includes(event.status));
    const remove = resolved.slice(0, Math.max(0, resolved.length - 100));
    if (!remove.length) return;
    const db = await openDatabase();
    const transaction = db.transaction(EVENT_STORE, 'readwrite');
    remove.forEach((event) => transaction.objectStore(EVENT_STORE).delete(event.event_id));
    await transactionPromise(transaction);
    db.close();
}

export async function queueSummary() {
    const events = await allEvents();
    return {
        pending: events.filter((event) => event.status === 'pending').length,
        blocked: events.filter((event) => event.status === 'blocked').length,
        rejected: events.filter((event) => event.status === 'rejected').length,
    };
}

export async function recentQueueEvents() {
    return (await allEvents()).slice(-10).reverse();
}

function trackedRecoveries(value) {
    if (!Array.isArray(value)) return [];
    return value.filter((request) => typeof request?.request_id === 'string'
        && Array.isArray(request.event_ids)
        && request.event_ids.length > 0
        && request.event_ids.every((eventId) => typeof eventId === 'string'));
}

function recoveryPayload(events) {
    return events.map((event) => ({
        event_id: event.event_id,
        sequence: event.sequence,
        code: event.code,
        occurred_at: event.occurred_at,
        signature: event.signature,
        error: event.server_result?.error ?? null,
        message: event.last_error ?? null,
    }));
}

async function requestRecovery(token, events) {
    const response = await kioskFetch('/api/scan/recover', {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Device-Token': token },
        body: JSON.stringify({ blocked_events: recoveryPayload(events) }),
    });
    const body = await response.json().catch(() => ({}));
    if (response.status === 401 || response.status === 403) {
        const error = new Error(body.message ?? 'This kiosk is no longer authorized.');
        error.authorization = true;
        throw error;
    }
    if (!response.ok) throw new Error(body.message ?? 'The recovery request could not be checked.');

    return body;
}

export async function recoverBlockedEvents() {
    if (!window.isSecureContext) {
        throw new Error('A trusted HTTPS connection is required before this kiosk can transmit its device credential.');
    }

    return exclusive(QUEUE_LOCK, async () => {
        const token = await loadDeviceToken();
        if (!token) throw new Error('This browser is not registered as a kiosk.');
        const { secret } = tokenParts(token);
        const events = await allEvents();
        const blocked = events.filter((event) => event.status === 'blocked');
        if (!blocked.length) return { status: 'clear', count: 0 };

        const blockedById = new Map(blocked.map((event) => [event.event_id, event]));
        const known = trackedRecoveries(await state(RECOVERY_REQUESTS_STATE));
        // Prefer a previously requested subset. IT can approve it while a
        // later scan becomes blocked; that later scan must not invalidate the
        // already-reviewed evidence.
        const tracked = known.find((request) => request.event_ids.every((eventId) => blockedById.has(eventId)));
        const target = tracked
            ? tracked.event_ids.map((eventId) => blockedById.get(eventId))
            : blocked;
        const body = await requestRecovery(token, target);
        if (body.status !== 'approved') {
            if (body.request_id) {
                const targetIds = target.map((event) => event.event_id);
                const remaining = known.filter((request) => !request.event_ids.every((eventId) => targetIds.includes(eventId)));
                remaining.push({ request_id: body.request_id, event_ids: targetIds });
                await setState(RECOVERY_REQUESTS_STATE, remaining);
            }
            return { status: body.status ?? 'pending', requestId: body.request_id, message: body.message };
        }

        const acknowledged = new Set(body.acknowledged_event_ids ?? []);
        if (target.some((event) => !acknowledged.has(event.event_id))) {
            throw new Error('The IT approval does not match this browser’s reviewed blocked events. No local events were changed.');
        }

        // Re-read before committing even though the shared queue lock normally
        // serializes this work. This prevents a stale browser tab from
        // overwriting a state transition made outside the current page.
        const currentEvents = await allEvents();
        const currentById = new Map(currentEvents.map((event) => [event.event_id, event]));
        const targetIds = new Set(target.map((event) => event.event_id));
        const currentTarget = target.map((event) => currentById.get(event.event_id));
        if (currentTarget.some((event) => !event || event.status !== 'blocked')) {
            throw new Error('The local queue changed while IT recovery was being checked. No local events were changed.');
        }

        const pending = currentEvents.filter((event) => event.status === 'pending');
        let sequence = Number(body.next_sequence);
        if (!Number.isSafeInteger(sequence) || sequence < 1) throw new Error('The server returned an invalid recovery sequence.');
        for (const event of pending) {
            event.sequence = sequence;
            event.signature = await hmacSha256(secret, canonical(event));
            event.last_error = null;
            sequence += 1;
        }

        const db = await openDatabase();
        const transaction = db.transaction([STATE_STORE, EVENT_STORE], 'readwrite');
        const eventStore = transaction.objectStore(EVENT_STORE);
        targetIds.forEach((eventId) => eventStore.delete(eventId));
        pending.forEach((event) => eventStore.put(event));
        transaction.objectStore(STATE_STORE).put({ key: 'next_sequence', value: sequence });
        transaction.objectStore(STATE_STORE).put({
            key: RECOVERY_REQUESTS_STATE,
            value: known.filter((request) => !request.event_ids.some((eventId) => targetIds.has(eventId))),
        });
        await transactionPromise(transaction);
        db.close();

        return {
            status: 'recovered',
            count: target.length,
            remainingBlocked: currentEvents.filter((event) => event.status === 'blocked' && !targetIds.has(event.event_id)).length,
        };
    });
}

export async function syncQueuedScans() {
    return exclusive(QUEUE_LOCK, async () => {
        if (!window.isSecureContext) {
            throw new Error('A trusted HTTPS connection is required before this kiosk can transmit its device credential.');
        }

        const token = await loadDeviceToken();
        if (!token) return [];
        const { secret } = tokenParts(token);
        const events = await allEvents();
        if (events.some((event) => event.status === 'blocked')) return [];
        const pending = events.filter((event) => event.status === 'pending').slice(0, 100);
        if (!pending.length) return [];

        const abort = new AbortController();
        const timeout = window.setTimeout(() => abort.abort(), 10000);
        let response;
        try {
            response = await kioskFetch('/api/scan/sync', {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Device-Token': token },
                body: JSON.stringify({ events: pending.map(({ event_id, code, occurred_at, sequence, signature }) => ({ event_id, code, occurred_at, sequence, signature })) }),
                signal: abort.signal,
            });
        } finally {
            window.clearTimeout(timeout);
        }
        const body = await response.json().catch(() => ({}));
        if (response.status === 401 || response.status === 403) {
            const error = new Error(body.message ?? 'This kiosk is no longer authorized.');
            error.authorization = true;
            throw error;
        }
        if (!response.ok) throw new Error(body.message ?? 'The scan queue could not be synchronized.');

        const byId = new Map(pending.map((event) => [event.event_id, event]));
        const updated = [];
        for (const result of body.results ?? []) {
            const event = byId.get(result.event_id);
            if (!event) continue;
            event.attempts += 1;
            event.last_attempt_at = new Date().toISOString();
            event.server_result = result;
            if (result.accepted) {
                event.status = result.status === 'synced' ? 'confirmed' : 'rejected';
                event.last_error = result.message ?? null;
            } else if (result.retryable) {
                event.last_error = result.message;
            } else {
                event.status = 'blocked';
                event.last_error = result.message;
            }
            updated.push(event);
        }
        await updateEvents(updated);

        const gap = (body.results ?? []).find((result) => result.error === 'sequence_gap' && result.expected_sequence);
        if (gap && !updated.some((event) => event.status === 'blocked')) {
            await resequencePending(gap.expected_sequence, secret);
        }
        await pruneResolved();
        return body.results ?? [];
    });
}
