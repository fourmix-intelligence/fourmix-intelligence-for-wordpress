(function () {
  'use strict';
  const __ = (message) => wp.i18n.__(message, 'fourmix-intelligence');
  const config = window.FourmixIntelligenceAdmin;
  const byId = (id) => document.getElementById(id);
  const labels = {id: __('対象ID'), post_type: __('投稿の種類'), title: __('タイトル'), content: __('本文'), status: __('状態'), query: __('検索語'), limit: __('件数'), name: __('名称'), description: __('説明'), regular_price: __('通常価格'), stock_quantity: __('在庫数'), note: __('備考'), code: __('コード'), amount: __('金額'), discount_type: __('割引方法')};
  const states = {draft: __('下書き'), publish: __('公開'), pending: __('レビュー待ち')};
  let operations = [], pending = null, chat = null, selection = 0;
  const postId = Number(new URLSearchParams(location.search).get('post_id') || 0);
  const status = (text) => { byId('fmi-result').textContent = text; };
  async function request(action, body, signal) {
    const reply = await fetch(config.endpoint + action, {method: 'POST', credentials: 'same-origin', signal, headers: {'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce}, body: JSON.stringify(body || {})});
    const data = await reply.json();
    if (!reply.ok) throw new Error(data.message || (data.state === 'unknown_effect' ? __('結果が不明です。再実行せず履歴と対象データを確認してください。') : __('処理を確認できませんでした。')));
    return data;
  }
  async function openChat(agent) {
    const version = ++selection; chat?.dispose(); chat = null;
    byId('fmi-chat').replaceChildren();
    if (!agent) { byId('fmi-chat').textContent = __('本人のAIに接続し、相談するAIを選択してください。'); return; }
    byId('fmi-chat').textContent = __('会話を読み込んでいます…');
    try {
      const session = await request('session', {agent}); if (version !== selection) return;
      const label = byId('fmi-staff-agent').selectedOptions[0]?.textContent;
      chat = window.FourmixIntelligenceChat.mount(byId('fmi-chat'), {
        ...session, label, tools: true,
        request: (action, body, signal) => request(action, {agent, ...body}, signal),
        newConversation: () => request('new_conversation', {agent}),
        context: () => ({post_id: postId, include_context: byId('fmi-include-context').checked}),
        contextLabel: (body) => body.include_context ? __('現在の投稿情報を送信（本文を含みません）') : __('投稿情報は送信していません')
      });
    } catch (error) { if (version === selection) { byId('fmi-chat').textContent = error.message; status(error.message); } }
  }
  function restoreAgents(data) {
    const select = byId('fmi-staff-agent');
    select.replaceChildren(new Option(__('StudioのAIを選択'), ''), ...data.agents.map((agent) => new Option(agent.label, agent.name)));
    select.value = data.selected_agent || ''; select.disabled = !data.agents.length; return openChat(select.value);
  }
  async function busy(button, action) {
    if (button.disabled) return; button.disabled = true;
    try { await action(); } catch (error) { status(error.message); } finally { button.disabled = false; }
  }
  byId('fmi-connect').addEventListener('submit', (event) => {
    event.preventDefault(); busy(event.submitter, async () => {
      const token = byId('fmi-personal-token').value; byId('fmi-personal-token').value = '';
      await restoreAgents(await request('connect', {token})); byId('fmi-token-panel').open = false; status(__('本人のAIに接続しました。'));
    });
  });
  byId('fmi-staff-agent').addEventListener('change', async () => {
    const select = byId('fmi-staff-agent'), agent = select.value;
    if (!agent) { openChat(''); return; }
    select.disabled = true;
    try { await restoreAgents(await request('select', {agent})); status(__('AIの選択を保存しました。')); }
    catch (error) { openChat(''); status(error.message); }
    finally { select.disabled = false; }
  });
  function fields() {
    pending = null; byId('fmi-confirm').hidden = true; byId('fmi-preview').textContent = '';
    const definition = operations.find((operation) => operation.name === byId('fmi-action').value);
    byId('fmi-fields').replaceChildren(); if (!definition) return;
    for (const [key, schema] of Object.entries(definition.input_schema.properties)) {
      if (key === 'idempotency_key') continue;
      const row = document.createElement('p'), label = document.createElement('label'); label.textContent = (labels[key] || key) + ' '; label.htmlFor = 'fmi-field-' + key;
      const input = schema.enum ? document.createElement('select') : document.createElement(['content', 'description'].includes(key) ? 'textarea' : 'input');
      input.id = 'fmi-field-' + key; input.name = key; input.dataset.type = schema.type;
      if (schema.enum) input.append(new Option(__('選択してください'), ''), ...schema.enum.map((value) => new Option(states[value] || value, value)));
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
      pending = null; byId('fmi-confirm').hidden = true; const args = {};
      byId('fmi-fields').querySelectorAll('[name]').forEach((input) => { if (input.value !== '') args[input.name] = input.dataset.type === 'integer' ? Number(input.value) : input.value; });
      const data = await request('preview', {operation: byId('fmi-action').value, arguments: args});
      byId('fmi-preview').textContent = data.description ? data.description + '\n' + Object.entries(data.arguments).map(([key, value]) => (labels[key] || key) + ': ' + (states[value] || value)).join('\n') : JSON.stringify(data.data, null, 2);
      if (data.state === 'confirmation_required') { pending = data.confirmation_id; byId('fmi-confirm').hidden = false; }
    });
  });
  byId('fmi-confirm').addEventListener('click', (event) => busy(event.target, async () => {
    if (!pending) return; const id = pending; pending = null; byId('fmi-confirm').hidden = true;
    const data = await request('confirm', {confirmation_id: id, approved: true});
    status(data.state === 'succeeded' ? __('実行しました。') + '\n' + JSON.stringify(data.data, null, 2) : __('結果が不明です。再実行せず確認してください。'));
  }));
  request('catalog', {post_id: postId}).then(async (data) => {
    await restoreAgents(data); byId('fmi-token-panel').open = !data.agents.length;
    if (data.context) { byId('fmi-context').hidden = false; byId('fmi-context-label').textContent = __('この投稿の情報を送信する: ') + data.context.title + ' (' + (states[data.context.status] || data.context.status) + ')'; }
    operations = data.operations; byId('fmi-action').replaceChildren(...operations.map((operation) => new Option(operation.description, operation.name)));
    byId('fmi-operation').querySelector('[type="submit"]').disabled = !operations.length;
    if (!operations.length) { byId('fmi-action').append(new Option(__('許可された操作がありません'), '')); byId('fmi-action').disabled = true; }
    byId('fmi-modules').textContent = (data.woocommerce ? __('WooCommerceを検出しました。') : __('WooCommerceは未導入です。')) + ' ' + (data.appointments === 'detected_not_enabled' ? __('予約プラグインを検出しました。操作の接続は別途設定してください。') : __('予約操作は有効になっていません。')); fields();
  }).catch((error) => status(error.message));
})();
