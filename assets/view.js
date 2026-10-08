(function () {
  'use strict';
  const settings = window.FourmixIntelligenceSettings || {};
  const __ = (message) => wp.i18n.__(message, 'fourmix-intelligence');
  let visitor = null;
  // 旧版のブラウザー内会話トークンは引き継ぎません。
  try { localStorage.removeItem('fourmix_intelligence_conversation_v2_' + settings.agent); sessionStorage.removeItem('fourmix_intelligence_session_v2_' + settings.agent); } catch (_) {}
  async function request(endpoint, body, signal) {
    const response = await fetch(endpoint, {method: 'POST', credentials: 'same-origin', signal, headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body || {})});
    const payload = await response.json();
    if (!response.ok) throw new Error(payload.message || __('ご案内を準備できませんでした。'));
    return payload;
  }
  const session = () => visitor ||= request(settings.sessionEndpoint, {});
  function context(kind) {
    const products = Array.from(document.querySelectorAll('[data-product_id], button[name="add-to-cart"]')).map((el) => Number(el.dataset.product_id || el.value || 0)).filter(Boolean).slice(0, 20);
    return {url: window.location.href.split('#')[0], title: document.title, kind, product_ids: products};
  }
  async function ask(kind, message) {
    await session();
    return request(settings.endpoint, {message, context: context(kind), request_id: Date.now() + ':' + crypto.randomUUID()});
  }
  function cacheKey(kind) { return 'fmi_auto_' + window.btoa(unescape(encodeURIComponent(settings.agent + '|' + kind + '|' + window.location.pathname + '|' + context(kind).product_ids.join(',')))).replace(/=/g, ''); }
  function cached(kind) { try { const value = JSON.parse(sessionStorage.getItem(cacheKey(kind)) || 'null'); return value && Date.now() - value.at < 300000 ? value.payload : null; } catch (_) { return null; } }
  function cache(kind, payload) { try { sessionStorage.setItem(cacheKey(kind), JSON.stringify({at: Date.now(), payload})); } catch (_) {} }
  function text(node, value) { const p = document.createElement('p'); p.className = 'fmi-answer'; p.textContent = value; node.appendChild(p); }
  function cards(node, data) {
    const items = data && Array.isArray(data.items) ? data.items : [];
    if (!items.length) return;
    const grid = document.createElement('div'); grid.className = 'fmi-products';
    items.slice(0, 4).forEach((item) => {
      const card = document.createElement('article'); card.className = 'fmi-product';
      const title = document.createElement('strong'); title.textContent = item.name || item.title || '商品を見る'; card.appendChild(title);
      if (item.price_html) { const price = document.createElement('div'); price.className = 'fmi-price'; price.textContent = new DOMParser().parseFromString(item.price_html, 'text/html').body.textContent; card.appendChild(price); }
      if (item.reason) { const reason = document.createElement('p'); reason.textContent = item.reason; card.appendChild(reason); }
      const actions = document.createElement('div'); actions.className = 'fmi-actions';
      const link = document.createElement('a'); link.className = 'fmi-button fmi-button--secondary';
      let target; try { target = new URL(item.product_url || '', location.origin); } catch (_) { return; }
      if (!['http:', 'https:'].includes(target.protocol) || target.origin !== location.origin) return;
      link.href = target.href; link.textContent = item.post_id ? __('内容を確認') : __('商品を確認'); actions.appendChild(link);
      if (item.purchasable && item.product_id && settings.addToCartEndpoint) {
        const add = document.createElement('button'); add.type = 'button'; add.className = 'fmi-button'; add.textContent = 'カートに追加';
        const error = document.createElement('p'); error.setAttribute('role', 'alert'); error.hidden = true;
        add.addEventListener('click', async () => {
          add.disabled = true; error.hidden = true; error.textContent = ''; add.textContent = __('カートに追加');
          try {
            const response = await fetch(settings.addToCartEndpoint, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: new URLSearchParams({product_id: String(item.product_id), quantity: '1'})});
            if (!response.ok) throw new Error();
            const result = await response.json();
            if (!result || result.error || !result.fragments || typeof result.fragments !== 'object' || typeof result.cart_hash !== 'string' || !result.cart_hash) throw new Error();
            add.textContent = __('追加しました'); document.body.dispatchEvent(new CustomEvent('wc_fragment_refresh'));
          } catch (_) {
            error.textContent = __('カートへの追加を確認できませんでした。商品ページで価格と在庫を確認してください。'); error.hidden = false;
          } finally { add.disabled = false; }
        });
        card.appendChild(error);
        actions.appendChild(add);
      }
      card.appendChild(actions); grid.appendChild(card);
    });
    node.appendChild(grid);
  }
  function renderResult(body, payload) { body.replaceChildren(); text(body, payload.result && payload.result.answer || ''); cards(body, payload.result && payload.result.data); }
  async function form(block, body, kind) {
    text(body, __('相談を準備しています…'));
    try {
      const data = await session();
      window.FourmixIntelligenceChat.mount(body, {
        scope: data.scope + ':' + kind, label: __('AI案内'), ttl: 86400000,
        attachments: {policy: data.attachments, endpoint: settings.attachmentEndpoint},
          newConversation: () => request(settings.attachmentEndpoint + 'new_conversation', {}),
          cancel: (body) => request(settings.attachmentEndpoint + 'cancel_run', body),
        stream: (body, signal, receive) => window.FourmixIntelligenceChat.stream(settings.streamEndpoint, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)}, signal, receive),
        welcome: __('このサイトの内容についてご相談ください。サイトの管理者が設定した公開AIがご案内します。'),
        request: (action, payload, signal) => request(action === 'chat' ? settings.endpoint : settings.statusEndpoint, payload, signal),
        context: () => ({context: context(kind)}),
        contextLabel: () => __('公開ページのURL・タイトルを参考情報として送信します'),
        history: settings.conversationMode === 'history' ? (id) => request(settings.historyEndpoint, {conversation_id: id}) : null,
        decorate: cards
      });
    } catch (error) { body.replaceChildren(); text(body, error.message); }
  }
  document.querySelectorAll('[data-fmi-kind]').forEach((block) => {
    const kind = block.dataset.fmiKind; const body = block.querySelector('.fmi-block__body');
    if (!settings.enabled) { text(body, '現在、このAI案内は準備中です。'); return; }
    if (['ai-concierge', 'site-search', 'product-recommendation'].includes(kind)) form(block, body, kind);
    if (block.dataset.fmiAuto !== '1') return;
    const prior = cached(kind); if (prior) { renderResult(body, prior); return; }
    body.innerHTML = '<p class="fmi-loading">おすすめを選んでいます…</p>';
    const prompt = kind === 'related-content' ? 'このページを見ている人に役立つサイト内の情報を案内してください。' : kind === 'cart-assistant' ? '現在のカートを確認し、買い忘れや相性のよい商品を提案してください。' : '現在の商品と一緒に使うと便利な商品を提案してください。';
    ask(kind, prompt).then((payload) => { cache(kind, payload); renderResult(body, payload); }).catch(() => { body.replaceChildren(); const notice = document.createElement('p'); notice.className = 'fmi-answer'; notice.setAttribute('role', 'alert'); notice.textContent = __('おすすめを表示できませんでした。時間をおいてページを再読み込みしてください。'); body.appendChild(notice); });
  });
})();
