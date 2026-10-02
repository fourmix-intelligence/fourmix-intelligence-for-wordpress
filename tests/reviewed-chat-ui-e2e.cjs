/* 実WordPressと本機Edgeで、本文増分がない審査経路の状態遷移を検証します。 */
const fs = require('node:fs'), path = require('node:path'), assert = require('node:assert/strict');
const {chromium} = require(process.env.FMI_PLAYWRIGHT_MODULE || '../../../app/node_modules/playwright');
const fixture = JSON.parse(fs.readFileSync(process.argv[2], 'utf8').replace(/^\uFEFF/, ''));
const output = process.argv[3] || path.resolve(__dirname, '../docs/evidence');
fs.mkdirSync(output, {recursive: true});

(async () => {
  const browser = await chromium.launch({channel: 'msedge', headless: true});
  const deadline = setTimeout(() => browser.close().catch(() => {}), 90000);
  deadline.unref();
  const checks = [], screenshots = [], errors = [];
  const checked = (name) => { checks.push(name); console.log(name); };
  try {
    const staff = await browser.newContext({viewport: {width: 1365, height: 1000}});
    await staff.addCookies(fixture.cookies);
    const page = await staff.newPage();
    page.setDefaultTimeout(15000); page.setDefaultNavigationTimeout(15000);
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('http://localhost:48093/wp-admin/admin.php?page=fourmix-intelligence-personal-settings');
    await page.locator('#fmi-personal-token').fill('synthetic-ui-personal-token');
    await page.getByRole('button', {name: 'AI一覧を取得'}).click();
    await page.locator('#fmi-personal-status').getByText('本人のAIに接続しました。').waitFor();
    await page.locator('#fmi-staff-agent').selectOption('synthetic-internal');
    await page.locator('#fmi-personal-status').getByText('AIの選択を保存しました。').waitFor();
    await page.goto('http://localhost:48093/wp-admin/admin.php?page=fourmix-intelligence-operations');

    const verify = async (target, selector, prefix) => {
      await target.evaluate(() => {
        const stream = window.FourmixIntelligenceChat.stream;
        window.reviewEvents = [];
        window.FourmixIntelligenceChat.stream = (url, init, signal, receive) => stream(url, init, signal, (event) => {
          window.reviewEvents.push(event); receive(event);
        });
      });
      const chat = target.locator(selector);
      await chat.locator('textarea').fill('合成審査後の一括回答');
      await chat.getByRole('button', {name: '送信', exact: true}).click();
      await chat.locator('.fmi-chat__status').getByText('回答内容を確認しています', {exact: true}).waitFor();
      assert.equal(await chat.getAttribute('aria-busy'), 'true');
      assert.equal(await chat.getByText('審査後の合成確定回答です。', {exact: false}).count(), 0);
      assert.equal(await target.evaluate(() => window.reviewEvents.some((e) => e.type === 'assistant.delta' || e.type === 'assistant.message')), false);
      checked(prefix + '：審査待ちを表示し本文を公開しない');
      await target.screenshot({path: path.join(output, prefix + '-review-waiting.png')});
      screenshots.push(prefix + '-review-waiting.png');
      await target.waitForFunction((s) => document.querySelector(s)?.getAttribute('aria-busy') === 'false', selector);
      const events = await target.evaluate(() => window.reviewEvents);
      assert.equal(events.filter((e) => e.type === 'assistant.delta').length, 0);
      assert.equal(events.filter((e) => e.type === 'assistant.message').length, 1);
      assert.equal(events.filter((e) => e.type === 'run.completed').length, 1);
      assert.equal(await chat.getByText('審査後の合成確定回答です。実際のモデルは呼び出していません。', {exact: true}).count(), 1);
      checked(prefix + '：増分なしでも確定本文を重複せず表示する');
      await target.screenshot({path: path.join(output, prefix + '-reviewed-final.png')});
      screenshots.push(prefix + '-reviewed-final.png');
    };

    await verify(page, '.fmi-staff-surface .fmi-chat', 'staff');
    const guest = await browser.newContext({viewport: {width: 390, height: 844}});
    const publicPage = await guest.newPage();
    publicPage.setDefaultTimeout(15000); publicPage.setDefaultNavigationTimeout(15000);
    publicPage.on('pageerror', (error) => errors.push(error.message));
    await publicPage.goto(fixture.url);
    await publicPage.locator('.fmi-chat textarea').waitFor();
    await verify(publicPage, '.fmi-chat', 'public');
    assert.deepEqual(errors, []); checked('管理・公開画面にJavaScript例外なし');
    fs.writeFileSync(path.join(output, 'reviewed-chat-ui-results.json'), JSON.stringify({
      environment: '本機Edge・実WordPress HTTP/REST・合成HTTP上流。実Studio/モデルは未使用。',
      checks, screenshots, errors,
    }, null, 2));
    console.log(JSON.stringify({checks: checks.length, screenshots: screenshots.length, errors}));
    await guest.close(); await staff.close();
  } finally { clearTimeout(deadline); await browser.close(); }
})().catch((error) => { console.error(error.stack); process.exitCode = 1; });
