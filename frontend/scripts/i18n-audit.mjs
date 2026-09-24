import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../src/', import.meta.url));
const files = [];
function walk(directory) {
  for (const name of readdirSync(directory)) {
    const path = join(directory, name);
    if (statSync(path).isDirectory()) walk(path);
    else if (/\.(?:tsx|ts)$/.test(name) && !path.includes(`${join('i18n', '')}`)) files.push(path);
  }
}
walk(root);

const candidates = [];
const patterns = [
  { kind: 'JSX text', regex: />\s*([A-Z][A-Za-z0-9][^<>{}\n]{1,160})\s*</g },
  { kind: 'visible attribute', regex: /\b(?:placeholder|title|aria-label|aria-description|alt|label|description|emptyText|help|hint)=(['"])([^'"{}]*[A-Za-z][^'"{}]*)\1/g, group: 2 },
  { kind: 'dialog/message', regex: /\b(?:confirm|alert|prompt|toast\.(?:success|error|info|warning))\(\s*(['"`])([^'"`$]*[A-Za-z][^'"`$]*)\1/g, group: 2 },
];
const exclusions = /^(?:GET|POST|PUT|PATCH|DELETE|ILS|Rx|PharmaSaaS|English|[A-Z_][A-Z0-9_]*|[a-z]+(?:\.[a-z_]+)+)$/;
for (const file of files) {
  const source = readFileSync(file, 'utf8');
  for (const pattern of patterns) {
    for (const match of source.matchAll(pattern.regex)) {
      const text = (match[pattern.group ?? 1] || '').trim().replace(/\s+/g, ' ');
      if (!text || exclusions.test(text) || /^(?:https?:|\/|\.|#)/.test(text)) continue;
      const line = source.slice(0, match.index).split('\n').length;
      candidates.push({ file: relative(root, file).replaceAll('\\', '/'), line, kind: pattern.kind, text });
    }
  }
}

const unique = [...new Map(candidates.map(item => [`${item.file}:${item.line}:${item.text}`, item])).values()];
for (const item of unique) console.log(`${item.file}:${item.line} [${item.kind}] ${JSON.stringify(item.text)}`);
console.log(`\n${unique.length} possible untranslated user-facing strings.`);
process.exitCode = unique.length ? 1 : 0;
