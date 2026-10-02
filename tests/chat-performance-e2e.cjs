/* 本機Edgeの合成測定。モデル速度や本番並行処理の保証ではありません。 */
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const {chromium} = require(process.env.FMI_PLAYWRIGHT_MODULE || '../../../app/node_modules/playwright');
const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8').replace(/^\uFEFF/, ''));
const mode = process.argv[3] || 'after', output = process.argv[4] || 'docs/evidence';
(async () => {
  const browser = await chromium.launch({channel: 'msedge', headless: true});
  try {
    const context = await browser.newContext({viewport: {width: 1365, height: 1000}}); await context.addCookies(fixture.cookies);
    const page = await context.newPage(), errors = [], api = []; page.on('pageerror', (e) => errors.push(e.message)); page.on('request', (r) => { if (r.url().includes('/fourmix-intelligence/v1/')) api.push(r.url().split('/').pop()); });
    const cdp = await context.newCDPSession(page); await cdp.send('Network.enable'); await cdp.send('Network.setCacheDisabled', {cacheDisabled: true});
    await page.goto('http://localhost:48093/wp-admin/'); await page.waitForTimeout(400);
    const closed = await page.evaluate(() => { const entries = performance.getEntriesByType('resource').filter((r) => r.name.includes('/fourmix-intelligence/assets/')); return {files: entries.map((r) => r.name.split('/assets/')[1]), decodedBytes: entries.reduce((n, r) => n + r.decodedBodySize, 0), heavyweightScripts: entries.filter((r) => /vendor|answer.js|attachments.js|chat.js$/.test(r.name) && !r.name.endsWith('staff-chat.js')).length}; });
    closed.apiRequests = api.length;
    await page.locator('#fmi-dock-toggle').click(); await page.waitForFunction(() => !!window.FourmixIntelligenceChat);
    const opened = await page.evaluate(() => ({mermaidLoaded: !!window.mermaid, chatLoaded: !!window.FourmixIntelligenceChat}));
    const benchmark = await page.evaluate(async () => {
      const scope = 'synthetic-perf-' + crypto.randomUUID(), key = 'fourmix_intelligence_chat_v3_' + scope;
      const messages = Array.from({length: 80}, (_, i) => ({role: i % 2 ? 'assistant' : 'user', content: '合成履歴 ' + i + '\n\n**確認項目**\n\n- 投稿の確認\n- 権限の確認'}));
      sessionStorage.setItem(key, JSON.stringify({at: Date.now(), messages, pending: null, conversation_id: ''}));
      const host = document.createElement('div'); host.style.cssText = 'width:800px'; document.body.append(host);
      const render = window.FourmixIntelligenceAnswer.render; let calls = 0, parseMs = 0;
      window.FourmixIntelligenceAnswer.render = (...args) => { const at = performance.now(); calls++; try { return render(...args); } finally { parseMs += performance.now() - at; } };
      const started = performance.now();
      const mounted = window.FourmixIntelligenceChat.mount(host, {scope, label: '合成測定', stream: async (body, signal, receive) => {
        let answer = '| 項目 | 状態 |\n| --- | --- |\n'; receive({type: 'assistant.delta', data: {text: answer}});
        for (let i = 0; i < 200; i++) { await new Promise((r) => setTimeout(r, 4)); if (signal.aborted) throw new DOMException('停止', 'AbortError'); const part = '| 合成 ' + i + ' | 確認待ち |\n'; answer += part; receive({type: 'assistant.delta', data: {text: part}}); }
        return {result: {answer}};
      }});
      const initial = {renderMs: performance.now() - started, messageDom: host.querySelectorAll('article').length, elementDom: host.querySelectorAll('*').length};
      calls = 0; parseMs = 0; const streamAt = performance.now(); mounted.input.value = '合成測定'; host.querySelector('form').requestSubmit();
      while (host.getAttribute('aria-busy') === 'true') await new Promise((r) => setTimeout(r, 20));
      const streamed = {eventCount: 201, renderCalls: calls, parseMs, elapsedMs: performance.now() - streamAt, messageDom: host.querySelectorAll('article').length, elementDom: host.querySelectorAll('*').length, tableRows: host.querySelectorAll('tbody tr').length};
      let historyExpansion = null;
      if (host.querySelector('button') && [...host.querySelectorAll('button')].some((button) => button.textContent === '以前のメッセージを表示')) {
        for (let i = 0; i < 2; i++) [...host.querySelectorAll('button')].find((button) => button.textContent === '以前のメッセージを表示')?.click();
        historyExpansion = {messageDom: host.querySelectorAll('article').length, includesOldest: host.textContent.includes('合成履歴 0')};
      }
      mounted.dispose(); host.remove(); sessionStorage.removeItem(key); window.FourmixIntelligenceAnswer.render = render;
      const add = window.addEventListener, remove = window.removeEventListener; let added = 0, removed = 0;
      window.addEventListener = function(...args) { if (['resize','pagehide','pageshow'].includes(args[0])) added++; return add.apply(this, args); };
      window.removeEventListener = function(...args) { if (['resize','pagehide','pageshow'].includes(args[0])) removed++; return remove.apply(this, args); };
      try { for (let i = 0; i < 20; i++) { const el = document.createElement('div'); document.body.append(el); const item = window.FourmixIntelligenceChat.mount(el, {scope: scope + i}); item.dispose(); el.remove(); } } finally { window.addEventListener = add; window.removeEventListener = remove; }
      return {initial, streamed, historyExpansion, lifecycle: {mounts: 20, added, removed}, heapBytesAtEnd: performance.memory?.usedJSHeapSize || null};
    });
    assert.equal(closed.apiRequests, 0); assert.equal(opened.mermaidLoaded, false); assert.equal(benchmark.lifecycle.added, benchmark.lifecycle.removed); assert.equal(benchmark.streamed.tableRows, 200); assert.deepEqual(errors, []);
    if (mode === 'after') { assert.equal(closed.heavyweightScripts, 0); assert.ok(benchmark.initial.messageDom <= 40); assert.ok(benchmark.streamed.renderCalls < 150); assert.equal(benchmark.historyExpansion.messageDom, 82); assert.equal(benchmark.historyExpansion.includesOldest, true); }
    const result = {environment: '本機Edge 1365x1000・WordPress 7.1/PHP8.3・キャッシュ無効。80件の合成履歴、201イベントを約4ms間隔で受信するブラウザー内測定。実モデル・本番負荷・CPU固定・強制GCは未実施。', mode, closed, opened, benchmark, errors}; fs.mkdirSync(output, {recursive: true}); fs.writeFileSync(path.join(output, 'chat-performance-' + mode + '.json'), JSON.stringify(result, null, 2)); console.log(JSON.stringify(result, null, 2));
  } finally { await browser.close(); }
})().catch((e) => { console.error(e); process.exitCode = 1; });
