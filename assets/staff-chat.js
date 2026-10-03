(function () {
  'use strict';
  const __ = (text) => wp.i18n.__(text, 'fourmix-intelligence');
  const config = window.FourmixIntelligenceStaff;
  const panel = document.getElementById('fmi-dock-panel'), toggle = document.getElementById('fmi-dock-toggle');
  const dockBody = document.getElementById('fmi-dock-body'), page = document.getElementById('fmi-chat-page');
  if (!config || !panel || !toggle) return;
  const node = (tag, cls, text) => { const el = document.createElement(tag); el.className = cls; if (text) el.textContent = text; return el; };
  const surface = node('div', 'fmi-staff-surface'), host = page ? document.getElementById('fmi-chat') : node('div', 'fmi-staff-chat-host');
  const context = node('div', 'fmi-staff-context'), label = node('label', ''), include = document.createElement('input');
  include.type = 'checkbox'; include.id = 'fmi-include-context';
  label.append(include, document.createTextNode(' ' + __('現在の画面情報を送信する')));
  context.append(label, node('p', 'fmi-staff-context-title', config.context.title || config.context.screen), node('p', 'description', __('画面名と、権限のある投稿のID・種類・タイトル・状態だけを送信します。本文や一覧のデータは含めません。')));
  surface.append(context, host); (page || dockBody).append(surface);
  const moved = node('p', 'fmi-chat-placeholder', __('右下のウィンドウで相談しています。'));
  const returnButton = node('button', 'button', __('ページに戻す')); returnButton.type = 'button'; moved.append(document.createTextNode(' '), returnButton); if (page) { page.append(moved); moved.hidden = true; }
  const storageKey = 'fourmix_intelligence_dock_' + config.uiScope;
  let chat = null, initialization = null, revision = 0, opened = false;
  const scriptPromises = new Map();
  async function loadChat() {
    if (window.FourmixIntelligenceChat) return;
    window.FourmixIntelligenceAnswer = {...(window.FourmixIntelligenceAnswer || {}), mermaidUrl: config.mermaidUrl};
    const scripts = new Map(config.scripts.map((item) => [item.handle, item]));
    function load(handle) {
      if (scriptPromises.has(handle)) return scriptPromises.get(handle);
      const item = scripts.get(handle);
      const promise = Promise.all(item.dependencies.map(load)).then(() => new Promise((resolve, reject) => { const script = document.createElement('script'); script.src = item.url; script.onload = resolve; script.onerror = () => { script.remove(); scriptPromises.delete(handle); reject(new Error(__('相談の表示部品を読み込めませんでした。ページを再読込してください。'))); }; document.head.append(script); }));
      scriptPromises.set(handle, promise); return promise;
    }
    await load('fourmix-intelligence-chat');
  }
  function unavailable(message, loginUrl = null, denied = false) {
    chat?.dispose(); chat = null; context.hidden = true; host.classList.remove('fmi-chat');
    const prompt = node('div', 'fmi-staff-setup'); prompt.append(node('p', '', message || __('相談を始めるには、社内向けAIの設定を確認してください。')));
    const link = node('a', 'button button-primary', __('社内向けAIの設定を開く')); link.href = loginUrl || config.settingsUrl; if (loginUrl) link.textContent = __('Fourmix Intelligence にログイン'); if (!denied) prompt.append(link); host.replaceChildren(prompt);
  }
  async function request(action, body, signal) {
    const reply = await fetch(config.endpoint + action, {method: 'POST', credentials: 'same-origin', signal, headers: {'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce}, body: JSON.stringify(body || {})});
    const data = await reply.json();
    if (!reply.ok) {
      const error = new Error(data.message || __('Fourmix Intelligence のログインとワークスペースの権限を確認してください。'));
      error.status = reply.status; error.loginUrl = data.login_url;
      if (reply.status === 401 || reply.status === 403) {
        ++revision; unavailable(error.message, data.login_url, reply.status === 403);
      } else if (['rest_forbidden', 'rest_cookie_invalid_nonce'].includes(data.code) || (reply.status === 403 && ['session', 'run_status', 'new_conversation'].includes(action))) {
        ++revision; unavailable(error.message);
      } else if (action === 'chat') {
        // 実行中の結果は保留にし、再送せず結果照会で本人接続を確認します。
        context.hidden = true;
      }
      throw error;
    }
    return data;
  }
  function initialize(force = false) {
    if (initialization && !force) return initialization;
    const version = ++revision; chat?.dispose(); chat = null;
    host.classList.remove('fmi-chat'); host.replaceChildren(node('p', 'fmi-chat-placeholder', __('相談を読み込んでいます…'))); context.hidden = true;
    initialization = (async () => {
      try {
        await loadChat(); if (version !== revision) return;
        const catalog = await request('catalog', {post_id: config.context.post_id}); if (version !== revision) return;
        const agent = catalog.agents.find((item) => item.name === catalog.selected_agent);
        if (!agent) { unavailable(); return; }
        await loadChat(); if (version !== revision) return;
        const session = await request('session', {agent: agent.name}); if (version !== revision) return;
        context.hidden = false;
        if (catalog.context) context.querySelector('.fmi-staff-context-title').textContent = catalog.context.title;
        chat = window.FourmixIntelligenceChat.mount(host, {
          ...session, label: agent.label, tools: true, preserveDraft: true,
          attachments: {policy: agent.attachments, endpoint: config.endpoint, nonce: config.nonce, identity: () => ({agent: agent.name})},
          stream: async (body, signal, receive) => {
            try { return await window.FourmixIntelligenceChat.stream(config.endpoint + 'chat_stream', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce}, body: JSON.stringify({agent: agent.name, ...body})}, signal, receive); }
            catch (error) { if (error.status === 401 || error.status === 403 || ['rest_forbidden', 'rest_cookie_invalid_nonce'].includes(error.code)) { ++revision; unavailable(error.message, error.loginUrl, error.status === 403); } throw error; }
          },
          request: (action, body, signal) => request(action, {agent: agent.name, ...body}, signal),
          newConversation: () => request('new_conversation', {agent: agent.name}),
          context: () => ({post_id: catalog.context ? config.context.post_id : 0, include_context: include.checked, screen: config.context.screen, screen_title: config.context.title}),
          contextLabel: (body) => body.include_context ? __('現在の画面情報を送信（本文は含みません）') : __('画面情報は送信していません')
        });
      } catch (error) { if (version === revision) unavailable(error.message, error.loginUrl, error.status === 403); }
    })();
    return initialization;
  }
  function open(focus = false) {
    opened = true; panel.hidden = false; toggle.setAttribute('aria-expanded', 'true'); toggle.setAttribute('aria-label', __('Fourmix Intelligenceの相談を閉じる'));
    dockBody.append(surface); if (page) moved.hidden = false;
    try { sessionStorage.setItem(storageKey, 'open'); } catch (_) {}
    initialize().then(() => { if (opened && focus) (chat?.input || panel.querySelector('a'))?.focus(); });
  }
  function close() {
    const restoreFocus = panel.contains(document.activeElement); opened = false; panel.hidden = true; toggle.setAttribute('aria-expanded', 'false'); toggle.setAttribute('aria-label', __('Fourmix Intelligenceの相談を開く'));
    if (page) { page.prepend(surface); moved.hidden = true; }
    try { sessionStorage.setItem(storageKey, 'closed'); } catch (_) {}
    if (restoreFocus) toggle.focus();
  }
  toggle.addEventListener('click', () => opened ? close() : open(true)); document.getElementById('fmi-dock-close').addEventListener('click', close); returnButton.addEventListener('click', close);
  panel.addEventListener('keydown', (event) => { if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(); } });
  window.addEventListener('fourmix-intelligence-settings-updated', () => { if (initialization) initialize(true); });
  function viewport() {
    const view = window.visualViewport;
    const height = view ? view.height : innerHeight, inset = view ? Math.max(0, innerHeight - view.height - view.offsetTop) : 0;
    panel.style.setProperty('--fmi-viewport-height', height + 'px'); document.getElementById('fmi-dock').style.setProperty('--fmi-keyboard-inset', inset + 'px');
    document.getElementById('fmi-dock').classList.toggle('fmi-dock-compact', height < 550);
  }
  window.visualViewport?.addEventListener('resize', viewport); window.visualViewport?.addEventListener('scroll', viewport); window.addEventListener('resize', viewport); viewport();
  let restore = false; try { restore = sessionStorage.getItem(storageKey) === 'open'; } catch (_) {}
  if (restore) open(); else if (page) initialize();
})();
