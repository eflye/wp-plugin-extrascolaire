// Construit dist/ : bundle ESM (react externe), déclarations .d.ts et
// feuille de style unique concaténée depuis les CSS réelles du plugin
// (assets/css), dans l'ordre d'enqueue de WordPress. Aucune règle n'est
// réécrite : seules les URL de police sont rebasées sur dist/fonts/.
import { build } from 'esbuild';
import { execSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync, copyFileSync, rmSync } from 'node:fs';
import { resolve } from 'node:path';

const assets = resolve('../../assets');
rmSync('dist', { recursive: true, force: true });
mkdirSync('dist/fonts', { recursive: true });

await build({
  entryPoints: ['src/index.ts'],
  bundle: true,
  format: 'esm',
  outfile: 'dist/index.js',
  jsx: 'automatic',
  external: ['react', 'react/jsx-runtime', 'react-dom'],
  target: 'es2019',
});
execSync('npx tsc -p tsconfig.json', { stdio: 'inherit' });

const sheets = ['frontend.css', 'portal.css', 'psc-messages.css', 'sidscm.css'];
const css = sheets
  .map((f) => `/* ==== assets/css/${f} ==== */\n` + readFileSync(`${assets}/css/${f}`, 'utf8'))
  .join('\n')
  .replaceAll("url('../fonts/", "url('./fonts/");
writeFileSync('dist/sidiscm.css', css);
for (const f of ['publicsans-variable.woff2', 'publicsans-italic-variable.woff2']) {
  copyFileSync(`${assets}/fonts/${f}`, `dist/fonts/${f}`);
}
console.log('dist/ construit');
