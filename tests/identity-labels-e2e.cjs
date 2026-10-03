/* 独立 WordPress の実画面で、共有接続とログインの案内を確認します。 */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require(process.env.FOURMIX_PLAYWRIGHT_PATH || path.resolve(__dirname, '../../app/node_modules/playwright'));
const base = process.env.FOURMIX_WP_IDENTITY_URL || 'http://localhost:18193';
const output = process.env.FOURMIX_WP_IDENTITY_EVIDENCE;
if (!output) throw new Error('FOURMIX_WP_IDENTITY_EVIDENCE に保存先を指定してください。');
const checks = [];
function check(value, label) { assert.ok(value, label); checks.push(label); }
(async () => {
  const browser = await chromium.launch({channel: 'msedge', headless: true});
  try {
    const page = await browser.newPage({viewport: {width: 1365, height: 950}});
    page.setDefaultTimeout(30000);
    let chatRequests = 0;
    page.on('request', r => { if (/\/staff\/chat(?:_stream)?$/.test(r.url())) chatRequests++; });
    await page.goto(base + '/wp-login.php');
    await page.locator('#user_login').fill(process.env.FOURMIX_WP_IDENTITY_LOGIN || 'identity_fixture');
    await page.locator('#user_pass').fill(process.env.FOURMIX_WP_IDENTITY_PASSWORD || 'synthetic-local-ui-only');
    await page.locator('#wp-submit').click();
    await page.waitForURL('**/wp-admin/**');
    await page.goto(base + '/wp-admin/admin.php?page=fourmix-intelligence-personal-settings');
    check(await page.locator('h1').innerText() === 'Fourmix Intelligence 社内向けAIの設定', '既存 URL で社内向け AI 設定を表示する');
    check(await page.locator('#fmi-connect').innerText() === 'Fourmix Intelligence にログイン', 'ログイン入口を個人 AI 接続と表記しない');
    check(await page.locator('#fmi-personal-token').count() === 0, '個人 token 入力欄を戻さない');
    const body = await page.locator('body').innerText();
    check(body.includes('管理者が登録した業務接続') && body.includes('選択はログイン中のアカウントごとに保存'), '共有の業務接続とアカウント別の選択を説明する');
    check(!/本人のAI設定|本人のAIに接続/.test(body), '設定ページとメニューに旧案内がない');
    await page.screenshot({path: path.join(output, 'wordpress-internal-ai-settings.png'), fullPage: true});
    await page.goto(base + '/wp-admin/admin.php?page=fourmix-intelligence-operations');
    await page.getByRole('link', {name: 'Fourmix Intelligence にログイン', exact: true}).last().waitFor();
    check(!/本人のAI設定|本人のAIに接続/.test(await page.locator('body').innerText()), '相談ページも同じ案内を使う');
    await page.goto(base + '/wp-admin/index.php');
    await page.locator('#fmi-dock-toggle').click();
    await page.locator('#fmi-dock-panel').getByRole('link', {name: 'Fourmix Intelligence にログイン', exact: true}).waitFor();
    check(await page.locator('.fmi-dock-footer').getByRole('link', {name: '社内向けAIの設定', exact: true}).count() === 1, '全画面の浮窓リンクを修正する');
    check(!/本人のAI設定|本人のAIに接続/.test(await page.locator('#fmi-dock-panel').innerText()), '浮窓にも旧案内がない');
    check(await page.locator('#fmi-dock-panel textarea').count() === 0, '本人未ログインでは入力を許可しない');
    check(chatRequests === 0, '検証中にモデルや相談を送信しない');
    await page.screenshot({path: path.join(output, 'wordpress-internal-ai-floating.png'), fullPage: true});
    fs.writeFileSync(path.join(output, 'wordpress-labels-result.json'), JSON.stringify({checks, chatRequests}, null, 2));
    console.log(`WordPress actual UI labels: ${checks.length} checks passed`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error.message); process.exit(1); });
