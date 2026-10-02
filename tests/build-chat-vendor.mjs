// 主体Appに既にある依存だけを利用します。インストールやネットワーク通信は行いません。
import fs from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import {fileURLToPath, pathToFileURL} from 'node:url';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../..');
const app = path.join(root, 'services/app');
const vendor = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../assets/vendor');
const {rolldown} = await import(pathToFileURL(path.join(app, 'node_modules/rolldown/dist/index.mjs')));
const module = path.join(app, 'packages/platform-app/node_modules/highlight.js');
const pkg = JSON.parse(await fs.readFile(path.join(module, 'package.json'), 'utf8'));
if (pkg.version !== '11.12.0') throw new Error('主体Appのhighlight.js版を確認してください。');
const temporary = await fs.mkdtemp(path.join(os.tmpdir(), 'fmi-wp-highlight-'));
try {
  const input = path.join(temporary, 'entry.js');
  await fs.writeFile(input, `import hljs from ${JSON.stringify(path.join(module, 'lib/common.js').replaceAll('\\', '/'))}; export default hljs;\n`);
  const bundle = await rolldown({input, platform: 'browser'});
  try { await bundle.write({file: path.join(vendor, 'highlight.min.js'), format: 'iife', name: 'FourmixIntelligenceHighlight', exports: 'default', minify: true}); } finally { await bundle.close(); }
  await fs.copyFile(path.join(module, 'LICENSE'), path.join(vendor, 'highlight.LICENSE.txt'));
  console.log('既存highlight.js 11.12.0からブラウザー用表示部品を作成しました。');
} finally { const target = path.resolve(temporary), tempRoot = path.resolve(os.tmpdir()) + path.sep; if (!target.toLowerCase().startsWith(tempRoot.toLowerCase()) || !path.basename(target).startsWith('fmi-wp-highlight-')) throw new Error('一時削除先を確認できません。'); await fs.rm(target, {recursive: true, force: true}); }
