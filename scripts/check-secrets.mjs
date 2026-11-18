#!/usr/bin/env node
/**
 * Fails if anything that looks like a server secret is in the app's source, config or bundle.
 *
 * Everything in the app (including every EXPO_PUBLIC_* value) ends up inside the APK/AAB and can be
 * extracted by anyone. SMS gateway credentials, DB passwords and API keys belong ONLY on the server
 * (backend-php/.env). This guard runs in `npm run check` and CI.
 *
 *   node scripts/check-secrets.mjs            # source + config
 *   node scripts/check-secrets.mjs dist       # also an exported bundle (npx expo export -p android)
 */
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { extname, join, relative, resolve } from 'node:path';

const root = join(import.meta.dirname, '..');
const targets = ['src', 'app.json', 'eas.json', '.env.example', 'package.json', ...process.argv.slice(2)];
const skipDirs = new Set(['node_modules', '.git', 'backend-php', 'coverage', '.expo']);
const textExt = new Set(['.ts', '.tsx', '.js', '.jsx', '.mjs', '.cjs', '.json', '.hbc', '.map', '.example', '']);

const rules = [
  { name: 'SMS gateway URL (send SMS from the server only)', re: /smsintegra|sms(?:api|gateway)\.\w+\/|textlocal\.in|msg91\.com\/api|fast2sms\.com\/dev/i },
  { name: 'secret-looking EXPO_PUBLIC_ variable', re: /EXPO_PUBLIC_\w*(?:SMS|SECRET|PASSWORD|PASSWD|PWD|PRIVATE|TOKEN|API_?KEY|AUTH_?KEY|DB_)\w*/ },
  { name: 'credential in a URL query', re: /[?&](?:uid|pwd|passw(?:or)?d|api_?key|auth_?key|secret)=(?!\{|UID\b|PWD\b|KEY\b)[^&\s'"`]{3,}/i },
  { name: 'private key', re: /-----BEGIN [A-Z ]*PRIVATE KEY-----/ },
  { name: 'database connection string', re: /mysql:\/\/[^\s'"`]+:[^\s'"`]+@/i },
];

const findings = [];

function scanFile(path) {
  if (!textExt.has(extname(path)) && !path.endsWith('.env.example')) return;
  if (statSync(path).size > 20 * 1024 * 1024) return;
  const text = readFileSync(path, 'utf8');
  if (path.endsWith('check-secrets.mjs')) return;
  for (const rule of rules) {
    const m = text.match(rule.re);
    if (m) {
      const line = text.slice(0, m.index).split('\n').length;
      findings.push(`${relative(root, path)}:${line}  ${rule.name}  (${m[0].slice(0, 40)}…)`);
    }
  }
}

function walk(path) {
  if (!existsSync(path)) return;
  const stat = statSync(path);
  if (stat.isDirectory()) {
    for (const entry of readdirSync(path)) {
      if (!skipDirs.has(entry)) walk(join(path, entry));
    }
  } else {
    scanFile(path);
  }
}

for (const t of targets) walk(resolve(root, t));

if (findings.length) {
  process.stderr.write(`Possible secrets in the app (they would ship inside the APK):\n  ${findings.join('\n  ')}\n`);
  process.stderr.write('Move them to the server (backend-php/.env) and call the backend API instead.\n');
  process.exit(1);
}
process.stdout.write(`check-secrets: no secrets found in ${targets.join(', ')}\n`);
