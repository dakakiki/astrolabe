// Known vulnerabilities in the production npm dependencies, for CI (Phase 8a security review):
// `npm audit --omit=dev`, failing on any high or critical advisory — except the ones accepted
// below, each with its reason and a last day, after which it fails again.
//
//   node scripts/npm-audit.mjs
import { spawnSync } from 'node:child_process';

const ACCEPTED = {
    // Accepted 18 Nov 2026 by the user (no fixed picomatch version existed yet).
    'GHSA-fc5q-39xc-vjpf': {
        until: '2026-12-31',
        why:
            'picomatch ReDoS through repeated extglobs; only build tools use it (Vite, the file-routing ' +
            "plugin in vue-router's package, which the app does not use) with the project's own patterns — " +
            'none of it reaches the browser bundle or runs on the server',
    },
};

const FAILING = new Set(['high', 'critical']);

const run = spawnSync('npm audit --omit=dev --json', { encoding: 'utf8', shell: true, maxBuffer: 64 * 1024 * 1024 });

let report;
try {
    report = JSON.parse(run.stdout);
} catch {
    console.error('npm audit gave no report.\n' + run.stdout + run.stderr);
    process.exit(1);
}
if (report.error) {
    console.error('npm audit failed:', report.error.summary ?? report.error);
    process.exit(1);
}

// Each package lists the advisories against it, or the packages it is vulnerable through.
const advisories = new Map();
for (const vulnerability of Object.values(report.vulnerabilities ?? {})) {
    for (const via of vulnerability.via) {
        if (typeof via === 'object' && FAILING.has(via.severity)) {
            advisories.set(via.url.split('/').pop(), via);
        }
    }
}

const today = new Date().toISOString().slice(0, 10);
let failed = false;

for (const [id, advisory] of advisories) {
    const accepted = ACCEPTED[id];
    if (accepted && today <= accepted.until) {
        console.log(`Accepted until ${accepted.until}: ${id} (${advisory.name}) — ${accepted.why}`);
        continue;
    }
    failed = true;
    const expired = accepted ? ` (accepted only until ${accepted.until})` : '';
    console.error(`${advisory.severity}: ${id} ${advisory.name} ${advisory.range} — ${advisory.title}${expired}`);
    console.error(`  ${advisory.url}`);
}

for (const id of Object.keys(ACCEPTED)) {
    if (!advisories.has(id)) console.log(`No longer reported, remove it from ACCEPTED: ${id}`);
}

if (failed) process.exit(1);
console.log('npm audit: no high or critical advisories outside the accepted list.');
