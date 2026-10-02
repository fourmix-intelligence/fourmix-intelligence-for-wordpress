/* 実製品の表示部品を本機Edgeで検証。HTTPは合成、認証・モデル・業務更新なし。 */
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict'), crypto = require('node:crypto');
const {chromium} = require(process.env.FMI_PLAYWRIGHT_MODULE || '../../../app/node_modules/playwright');
const output = process.argv[2]; if (!output) throw new Error('証拠の出力先を指定してください。'); fs.mkdirSync(output, {recursive: true});
const assets = path.resolve(__dirname, '../assets'), base = 'http://127.0.0.1:48093';
const checks = [], errors = [];
const check = (value, label) => {assert.ok(value, label); checks.push(label); console.log('PASS ' + label);};
(async () => {
  const browser = await chromium.launch({channel: 'msedge', headless: true});
  try {
    const context = await browser.newContext({viewport: {width: 1100, height: 850}}), page = await context.newPage(); page.setDefaultTimeout(20000);
    page.on('pageerror', error => errors.push(error.message)); page.on('dialog', dialog => dialog.accept());
    const files = new Map();
    await page.route('**/*', async route => {
      const request = route.request(), url = new URL(request.url()); assert.equal(url.origin, base);
      if (url.pathname.startsWith('/assets/')) {const file = path.join(assets, url.pathname.slice(8)); return route.fulfill({body: fs.readFileSync(file), contentType: file.endsWith('.css') ? 'text/css' : 'text/javascript'});}
      const reply = value => route.fulfill({contentType: 'application/json', body: JSON.stringify(value)});
      if (url.pathname.endsWith('/attachment_prepare')) return reply({conversation_id: 'synthetic-history-conversation'});
      if (url.pathname.endsWith('/attachment_upload')) {
        const bytes = request.postDataBuffer(), boundary = request.headers()['content-type'].split('boundary=')[1];
        const part = bytes.toString('latin1').split('--' + boundary).find(row => row.includes('filename="'));
        const offset = part.indexOf('\r\n\r\n'), header = part.slice(0, offset), body = Buffer.from(part.slice(offset + 4, -2), 'latin1');
        const name = Buffer.from(header.match(/filename="([^"]+)"/)[1], 'latin1').toString('utf8'), mime = header.match(/Content-Type: ([^\r]+)/i)[1];
        const metadata = {id: crypto.randomUUID(), name, mime, size: body.length}; files.set(metadata.id, {metadata, body}); return reply({conversation_id: 'synthetic-history-conversation', attachment: metadata});
      }
      if (url.pathname.endsWith('/attachment_content')) {const row = files.get(request.postDataJSON().id); assert.ok(row); return route.fulfill({body: row.body, contentType: row.metadata.mime});}
      return route.fulfill({contentType: 'text/html', body: '<html lang="ja"><meta charset="utf-8"><title>会話履歴の無料合成検証</title><link rel="stylesheet" href="/assets/chat.css"><link rel="stylesheet" href="/assets/attachments.css"><script>window.wp={i18n:{__:x=>x}};</script><script src="/assets/attachments.js"></script><script src="/assets/chat.js"></script><div id="chat"></div></html>'});
    });
    await page.goto(base + '/synthetic-history');
    await page.evaluate(() => {
      window.remoteHistory = []; window.historyPending = false;
      window.widget = FourmixIntelligenceChat.mount(document.querySelector('#chat'), {
        scope: 'synthetic-history-ui', label: '合成AI',
        attachments: {endpoint: '/synthetic/', policy: {enabled: true, extensions: ['.png', '.txt'], max_files: 8, max_bytes: 10485760, context_bytes: 31457280}, identity: () => ({agent: 'synthetic'})},
        request: async (action, body) => {window.lastSent = body; return {conversation_id: 'synthetic-history-conversation', result: {answer: '合成回答', follow_up_questions: ['次の確認事項']}};},
        history: async () => {if (window.historyPending) await new Promise(resolve => {window.releaseHistory = resolve;}); return {messages: window.remoteHistory};}
      });
    });
    const png = Buffer.from(await page.evaluate(() => {const canvas = document.createElement('canvas'); canvas.width = 32; canvas.height = 16; canvas.getContext('2d').fillRect(0, 0, 32, 16); return canvas.toDataURL('image/png').split(',')[1];}), 'base64');
    const text = Buffer.from('合成の通常ファイル・履歴確認');
    await page.locator('input[type=file]').setInputFiles([{name: '合成画像.png', mimeType: 'image/png', buffer: png}, {name: '合成資料.txt', mimeType: 'text/plain', buffer: text}]);
    await page.getByText('添付の保存を確認しました', {exact: true}).nth(1).waitFor();
    await page.locator('textarea').fill('添付の履歴を確認'); await page.getByRole('button', {name: '送信', exact: true}).click(); await page.getByText('応答を受け取りました。', {exact: true}).waitFor();
    await page.evaluate(() => {window.remoteHistory = [{role: 'user', content: '添付の履歴を確認'}, {role: 'assistant', content: '合成回答', follow_up_questions: ['履歴の次の確認']}];});
    await page.getByRole('button', {name: '履歴を読み込む', exact: true}).click(); await page.getByText('会話履歴を読み込みました。', {exact: true}).waitFor();
    check(await page.locator('.fmi-message-attachments button').count() === 2, '添付メタデータを返さない履歴でも同じ会話の画像・通常ファイルを保持');
    await page.waitForFunction(() => document.querySelector('.fmi-message-image')?.naturalWidth === 32);
    check(await page.locator('.fmi-message-image').evaluate(image => image.complete && image.naturalWidth === 32), '履歴の画像プレビューが実データを表示');
    check(await page.getByRole('button', {name: '履歴の次の確認', exact: true}).count() === 1, '本体履歴の次の依頼候補を復元');
    const downloading = page.waitForEvent('download'); await page.getByRole('button', {name: '合成資料.txt', exact: true}).click(); const download = await downloading;
    check(fs.readFileSync(await download.path()).equals(text), '復元した通常ファイルの取得は元の内容と一致');
    await page.screenshot({path: path.join(output, 'history-attachments.png'), fullPage: true});
    // 履歴の一部だけが返り、同文の別メッセージと区別できない場合は添付を推測しない。
    await page.evaluate(() => {window.remoteHistory = [{role: 'user', content: '添付の履歴を確認'}, {role: 'user', content: '添付の履歴を確認'}, {role: 'assistant', content: '合成回答'}];});
    await page.getByRole('button', {name: '履歴を読み込む', exact: true}).click(); await page.getByText('会話履歴を読み込みました。', {exact: true}).waitFor();
    check(await page.locator('.fmi-message-attachments').count() === 0, '対応位置が不明な重複文へ別の添付を誤って付けない');
    await page.evaluate(() => {window.remoteHistory = [{role: 'user', content: '別の過去の会話'}, {role: 'assistant', content: '過去の合成回答'}]; window.historyPending = true;});
    await page.getByRole('button', {name: '履歴を読み込む', exact: true}).click(); await page.waitForFunction(() => typeof window.releaseHistory === 'function');
    await page.getByRole('button', {name: '新しい相談', exact: true}).click(); await page.getByText('新しい相談を始められます。', {exact: true}).waitFor();
    await page.evaluate(() => window.releaseHistory()); await page.waitForFunction(() => !document.querySelectorAll('.fmi-chat__toolbar button')[1].disabled);
    check(await page.locator('.fmi-chat__message').count() === 0, '遅れて返る旧履歴で新しい会話を上書きしない');
    check(errors.length === 0, 'JavaScript例外なし');
    fs.writeFileSync(path.join(output, 'history-result.json'), JSON.stringify({environment: '実製品chat.js・attachments.js、Edge、合成HTTP。資格情報・実モデル・業務更新なし。', checks, errors}, null, 2));
  } finally {await browser.close();}
})().catch(error => {console.error(error.stack); process.exitCode = 1;});
