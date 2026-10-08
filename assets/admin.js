(function () {
  'use strict';
  const __ = (message) => wp.i18n.__(message, 'fourmix-intelligence');
  const config = window.FourmixIntelligenceAdmin;
  const byId = (id) => document.getElementById(id);
  const labels = {id: __('対象ID'), post_type: __('投稿の種類'), title: __('タイトル'), content: __('本文'), status: __('状態'), query: __('検索語'), limit: __('件数'), name: __('名称'), description: __('説明'), regular_price: __('通常価格'), stock_quantity: __('在庫数'), note: __('備考'), code: __('コード'), amount: __('金額'), discount_type: __('割引方法')};
  const states = {draft: __('下書き'), publish: __('公開'), pending: __('レビュー待ち')};
  const orderStates = {pending: __('支払い待ち'), processing: __('処理中'), 'on-hold': __('保留中'), completed: __('完了'), cancelled: __('キャンセル'), refunded: __('返金済み'), failed: __('失敗'), 'checkout-draft': __('下書き')};
  function displayValue(key, value) {
    if (key !== 'status') return value;
    const choices = byId('fmi-action').value.startsWith('orders.') ? orderStates : states;
    return choices[value] || value;
  }
  let operations = [], pending = null, agent = '';
  const bodyIdentity = (body) => ({agent, ...body});
  const postId = Number(new URLSearchParams(location.search).get('post_id') || 0);
  const status = (text) => { byId('fmi-result').textContent = text; };
  function failure(error) {
    const authentication = error.status === 401;
    byId('fmi-auth-recovery').hidden = !authentication;
    if (authentication || error.status === 403) {
      operations = []; pending = null; byId('fmi-confirm').hidden = true;
      byId('fmi-action').disabled = true;
      byId('fmi-operation').querySelector('[type="submit"]').disabled = true;
    }
    status(authentication ? __('Fourmix Intelligence にログインし直してから、このページを再読み込みしてください。操作は自動で再実行されません。')
      : error.status === 403 ? __('この業務操作を利用する権限を確認できません。現在のワークスペースと許可範囲を管理者に確認してください。')
      : error.status >= 500 || error.network ? __('接続先の応答を確認できません。時間をおいて再読み込みしてください。変更を送信した場合は再実行せず、対象データと履歴を確認してください。') : error.message);
  }
  async function request(action, body, signal) {
    let reply; try { reply = await fetch(config.endpoint + action, {method: 'POST', credentials: 'same-origin', signal, headers: {'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce}, body: JSON.stringify(bodyIdentity(body || {}))}); } catch (cause) { const error = new Error(__('接続を確認できませんでした。')); error.network = true; throw error; }
    let data; try { data = await reply.json(); } catch (cause) { const error = new Error(__('接続先の応答を確認できませんでした。')); error.status = reply.ok ? 502 : reply.status; throw error; }
    if (!reply.ok) { const error = new Error(data.message || (data.state === 'unknown_effect' ? __('結果が不明です。再実行せず履歴と対象データを確認してください。') : __('処理を確認できませんでした。'))); error.status = reply.status; throw error; }
    return data;
  }
  async function busy(button, action) {
    if (button.disabled) return; button.disabled = true;
    try { await action(); } catch (error) { failure(error); } finally { button.disabled = !operations.length; }
  }
  function fields() {
    pending = null; byId('fmi-confirm').hidden = true; byId('fmi-preview').textContent = '';
    const definition = operations.find((operation) => operation.name === byId('fmi-action').value);
    byId('fmi-fields').replaceChildren(); if (!definition) return;
    for (const [key, schema] of Object.entries(definition.input_schema.properties)) {
      if (key === 'idempotency_key') continue;
      const row = document.createElement('p'), label = document.createElement('label'); label.textContent = (labels[key] || key) + ' '; label.htmlFor = 'fmi-field-' + key;
      const input = schema.enum ? document.createElement('select') : document.createElement(['content', 'description'].includes(key) ? 'textarea' : 'input');
      input.id = 'fmi-field-' + key; input.name = key; input.dataset.type = schema.type;
      if (schema.enum) input.append(new Option(__('選択してください'), ''), ...schema.enum.map((value) => new Option(displayValue(key, value), value)));
      else { if (input.tagName === 'INPUT') input.type = schema.type === 'integer' ? 'number' : 'text'; input.className = 'large-text'; }
      input.required = definition.input_schema.required.includes(key);
      if (schema.minimum !== undefined) input.min = schema.minimum; if (schema.maximum !== undefined) input.max = schema.maximum; if (schema.maxLength) input.maxLength = schema.maxLength;
      row.append(label, input); byId('fmi-fields').append(row);
    }
  }
  byId('fmi-action').addEventListener('change', fields);
  byId('fmi-fields').addEventListener('input', () => { pending = null; byId('fmi-confirm').hidden = true; });
  byId('fmi-operation').addEventListener('submit', (event) => {
    event.preventDefault(); busy(event.submitter, async () => {
      pending = null; byId('fmi-confirm').hidden = true; byId('fmi-preview').textContent = ''; const args = {};
      byId('fmi-fields').querySelectorAll('[name]').forEach((input) => { if (input.value !== '') args[input.name] = input.dataset.type === 'integer' ? Number(input.value) : input.value; });
      const data = await request('preview', {operation: byId('fmi-action').value, arguments: args});
      status('');
      byId('fmi-preview').textContent = data.description ? data.description + '\n' + Object.entries(data.arguments).map(([key, value]) => (labels[key] || key) + ': ' + displayValue(key, value)).join('\n') : JSON.stringify(data.data, null, 2);
      if (data.state === 'confirmation_required') { pending = data.confirmation_id; byId('fmi-confirm').hidden = false; }
    });
  });
  byId('fmi-confirm').addEventListener('click', (event) => busy(event.target, async () => {
    if (!pending) return; const id = pending; pending = null; byId('fmi-confirm').hidden = true;
    const data = await request('confirm', {confirmation_id: id, approved: true});
    status(data.state === 'succeeded' ? __('実行しました。') + '\n' + JSON.stringify(data.data, null, 2) : __('結果が不明です。再実行せず確認してください。'));
  }));
  request('catalog', {post_id: postId}).then(async (data) => {
    agent = data.selected_agent || ''; operations = data.operations; byId('fmi-action').replaceChildren(...operations.map((operation) => new Option(operation.description, operation.name)));
    byId('fmi-operation').querySelector('[type="submit"]').disabled = !operations.length;
    if (!operations.length) { byId('fmi-action').append(new Option(__('直接利用できる操作はありません。Studio の会話から依頼してください。'), '')); byId('fmi-action').disabled = true; }
    byId('fmi-modules').textContent = (data.woocommerce ? __('WooCommerceを検出しました。') : __('WooCommerceは未導入です。')) + ' ' + (data.appointments === 'detected_not_enabled' ? __('予約プラグインを検出しました。操作の接続は別途設定してください。') : __('予約操作は有効になっていません。')); fields();
  }).catch((error) => { byId('fmi-action').disabled = true; byId('fmi-operation').querySelector('[type="submit"]').disabled = true; failure(error); });
})();
