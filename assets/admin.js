(function () {
  'use strict';
  const __ = (message) => wp.i18n.__(message, 'fourmix-intelligence');
  const config = window.FourmixIntelligenceAdmin;
  const byId = (id) => document.getElementById(id);
  const labels = {id: __('対象ID'), post_type: __('投稿の種類'), title: __('タイトル'), content: __('本文'), status: __('状態'), query: __('検索語'), limit: __('件数'), name: __('名称'), description: __('説明'), regular_price: __('通常価格'), stock_quantity: __('在庫数'), note: __('備考'), code: __('コード'), amount: __('金額'), discount_type: __('割引方式')};
  let operations = [], pending = null;
  byId('fmi-chat').querySelector('[type="submit"]').disabled = true;
  byId('fmi-operation').querySelector('[type="submit"]').disabled = true;
  async function request(action, body) {
    const reply = await fetch(config.endpoint + action, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce}, body: JSON.stringify(body || {})});
    const data = await reply.json();
    if (!reply.ok) throw new Error(data.message || (data.state === 'unknown_effect' ? __('結果を確認できません。対象データと履歴を確認してください。') : __('処理を完了できませんでした。')));
    return data;
  }
  async function busy(button, action) {
    button.disabled = true;
    try { await action(); } catch (error) { byId('fmi-result').textContent = error.message; } finally { button.disabled = false; }
  }
  byId('fmi-connect').addEventListener('submit', (event) => {
    event.preventDefault();
    busy(event.submitter, async () => {
      const token = byId('fmi-personal-token').value;
      byId('fmi-personal-token').value = '';
      const data = await request('connect', {token});
      byId('fmi-staff-agent').replaceChildren(...data.agents.map((agent) => new Option(agent.label, agent.name)));
      byId('fmi-chat').querySelector('[type="submit"]').disabled = !data.agents.length;
      byId('fmi-result').textContent = __('本人のAIに接続しました。');
    });
  });
  byId('fmi-chat').addEventListener('submit', (event) => {
    event.preventDefault();
    busy(event.submitter, async () => {
      const data = await request('chat', {agent: byId('fmi-staff-agent').value, message: byId('fmi-message').value, post_id: Number(new URLSearchParams(location.search).get('post_id') || 0)});
      byId('fmi-result').textContent = data.result?.answer || __('回答を確認できませんでした。');
    });
  });
  function fields() {
    pending = null; byId('fmi-confirm').hidden = true; byId('fmi-preview').textContent = '';
    const definition = operations.find((operation) => operation.name === byId('fmi-action').value);
    byId('fmi-fields').replaceChildren();
    if (!definition) return;
    for (const [key, schema] of Object.entries(definition.input_schema.properties)) {
      if (key === 'idempotency_key') continue;
      const row = document.createElement('p'), label = document.createElement('label');
      label.textContent = (labels[key] || key) + ' '; label.htmlFor = 'fmi-field-' + key;
      const input = schema.enum ? document.createElement('select') : document.createElement(key === 'content' || key === 'description' ? 'textarea' : 'input');
      input.id = 'fmi-field-' + key; input.name = key; input.dataset.type = schema.type;
      if (schema.enum) { input.append(new Option(__('選択してください'), ''), ...schema.enum.map((value) => new Option(({draft: __('下書き'), publish: __('公開'), pending: __('レビュー待ち')})[value] || value, value))); }
      else { if (input.tagName === 'INPUT') input.type = schema.type === 'integer' ? 'number' : 'text'; input.className = 'large-text'; }
      input.required = definition.input_schema.required.includes(key);
      if (schema.minimum !== undefined) input.min = schema.minimum;
      if (schema.maximum !== undefined) input.max = schema.maximum;
      if (schema.maxLength) input.maxLength = schema.maxLength;
      row.append(label, input); byId('fmi-fields').append(row);
    }
  }
  byId('fmi-action').addEventListener('change', fields);
  byId('fmi-fields').addEventListener('input', () => { pending = null; byId('fmi-confirm').hidden = true; });
  byId('fmi-operation').addEventListener('submit', (event) => {
    event.preventDefault();
    busy(event.submitter, async () => {
      pending = null; byId('fmi-confirm').hidden = true;
      const args = {};
      byId('fmi-fields').querySelectorAll('[name]').forEach((input) => { if (input.value !== '') args[input.name] = input.dataset.type === 'integer' ? Number(input.value) : input.value; });
      const data = await request('preview', {operation: byId('fmi-action').value, arguments: args});
      byId('fmi-preview').textContent = data.description ? data.description + '\n' + Object.entries(data.arguments).map(([key, value]) => (labels[key] || key) + ': ' + (({draft: __('下書き'), publish: __('公開'), pending: __('レビュー待ち')})[value] || value)).join('\n') : JSON.stringify(data.data, null, 2);
      if (data.state === 'confirmation_required') { pending = data.confirmation_id; byId('fmi-confirm').hidden = false; }
    });
  });
  byId('fmi-confirm').addEventListener('click', (event) => busy(event.target, async () => {
    if (!pending) return;
    const data = await request('confirm', {confirmation_id: pending, approved: true});
    byId('fmi-result').textContent = data.state === 'succeeded' ? __('実行しました。') + '\n' + JSON.stringify(data.data, null, 2) : __('結果を確認してください。');
    byId('fmi-confirm').hidden = true; pending = null;
  }));
  request('catalog').then((data) => {
    operations = data.operations;
    byId('fmi-action').replaceChildren(...operations.map((operation) => new Option(operation.description, operation.name)));
    byId('fmi-operation').querySelector('[type="submit"]').disabled = !operations.length;
    if (!operations.length) {
      byId('fmi-action').append(new Option(__('許可された操作がありません'), ''));
      byId('fmi-action').disabled = true;
      byId('fmi-result').textContent = __('管理者が連携設定で業務を許可すると、操作を選択できます。');
    }
    byId('fmi-modules').textContent = (data.woocommerce ? __('WooCommerceを検出しました。') : __('WooCommerceは未導入です。')) + ' ' + (data.appointments === 'detected_not_enabled' ? __('予約プラグインを検出しました。予約操作の接続は別途設定してください。') : __('予約操作は有効になっていません。'));
    fields();
  }).catch((error) => { byId('fmi-result').textContent = error.message; });
})();
