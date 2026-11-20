// Claude Code hook (PostToolUse on Edit/Write, see .claude/settings.json): checks a file right after it is edited.
//   .php            -> php -n -l (syntax)
//   public/**/*.js  -> ESLint
// Problems are printed to stderr with exit code 2, which Claude Code shows to the model so it fixes them at once.
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';

let input = {};
try {
  input = JSON.parse(readFileSync(0, 'utf8') || '{}');
} catch {
  process.exit(0);
}
const file = input.tool_response?.filePath || input.tool_input?.file_path;
if (!file) process.exit(0);

const root = path.resolve(import.meta.dirname, '..', '..');
const rel = path.relative(root, path.resolve(file)).split(path.sep).join('/');
if (rel.startsWith('..') || rel.startsWith('vendor/') || rel.startsWith('node_modules/') || rel.startsWith('public/vendor/')) process.exit(0);

const run = (cmd, args) => spawnSync(cmd, args, { cwd: root, encoding: 'utf8', shell: process.platform === 'win32' });
let res = null;
if (rel.endsWith('.php')) {
  res = run('php', ['-n', '-l', JSON.stringify(path.resolve(file))]);
} else if (/^public\/.+\.js$/.test(rel)) {
  res = run('npx', ['--no-install', 'eslint', '--no-warn-ignored', rel]);
}
if (!res) process.exit(0);
if (res.error) {
  // The checker itself is not available (PHP or node_modules missing): say so, but do not block.
  process.stderr.write(`check-edit: could not run the checker for ${rel}: ${res.error.message}\n`);
  process.exit(0);
}
if (res.status !== 0) {
  process.stderr.write(`Check failed for ${rel}:\n${(res.stdout || '') + (res.stderr || '')}`.trim() + '\n');
  process.exit(2);
}
process.exit(0);
