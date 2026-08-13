import { access, readFile, stat } from 'node:fs/promises';
import path from 'node:path';
import { supportsServiceWorkerRegistration } from '../resources/js/pwaSupport.js';

const root = process.cwd();
const manifestPath = path.join(root, 'public/build/manifest.json');
const workerPath = path.join(root, 'public/sw.js');
await access(manifestPath);
const manifest = JSON.parse(await readFile(manifestPath, 'utf8'));
const worker = await readFile(workerPath, 'utf8');

if (!supportsServiceWorkerRegistration({
    isSecureContext: true,
    navigator: { serviceWorker: { register() {} } },
})) {
    throw new Error('The PWA capability guard rejected a valid service-worker registration API.');
}

if (supportsServiceWorkerRegistration({ isSecureContext: true, navigator: { serviceWorker: {} } })) {
    throw new Error('The PWA capability guard accepted a browser without serviceWorker.register().');
}

if (supportsServiceWorkerRegistration({
    isSecureContext: false,
    navigator: { serviceWorker: { register() {} } },
})) {
    throw new Error('The PWA capability guard accepted an insecure context.');
}

if (!worker.includes("fetch('/build/manifest.json'")) {
    throw new Error('The kiosk service worker does not load the Vite manifest during installation.');
}

const assets = new Set();
for (const entry of Object.values(manifest)) {
    if (entry.file) assets.add(entry.file);
    for (const file of entry.css ?? []) assets.add(file);
    for (const file of entry.assets ?? []) assets.add(file);
}

if (assets.size === 0) throw new Error('The Vite manifest contains no production assets.');
for (const asset of assets) {
    const details = await stat(path.join(root, 'public/build', asset));
    if (!details.isFile() || details.size === 0) throw new Error(`Missing or empty PWA asset: ${asset}`);
}

console.log(`PWA precache check passed for ${assets.size} fingerprinted asset(s).`);
