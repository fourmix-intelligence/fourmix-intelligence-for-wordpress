(function () {
  'use strict';
  const settings = window.FourmixIntelligenceSettings || {};
  const persistentKey = 'fourmix_intelligence_conversation_v1';
  const temporaryKey = 'fourmix_intelligence_session_v1';
  const storage = () => settings.conversationMode === 'history' ? window.localStorage : window.sessionStorage;
  const storageKey = () => settings.conversationMode === 'history' ? persistentKey : temporaryKey;
  function state() { try { return JSON.parse(storage().getItem(storageKey()) || '{}'); } catch (_) { return {}; } }
  function save(payload) { if (payload.conversation_id && payload.customer_token) storage().setItem(storageKey(), JSON.stringify({conversation_id: payload.conversation_id, customer_token: payload.customer_token})); }
  function context(kind) {
    const products = Array.from(document.querySelectorAll('[data-product_id], button[name="add-to-cart"]')).map((el) => Number(el.dataset.product_id || el.value || 0)).filter(Boolean).slice(0, 20);
    return {url: window.location.href.split('#')[0], title: document.title, kind, product_ids: products};
  }
  async function ask(kind, message) {
    const response = await fetch(settings.endpoint, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(Object.assign({message, context: context(kind)}, state()))});
    const payload = await response.json();
    if (!response.ok) throw new Error(payload.message || 'ご案内を準備できませんでした。');
    save(payload); return payload;
  }
  function cacheKey(kind) { return 'fmi_auto_' + window.btoa(unescape(encodeURIComponent(kind + '|' + window.location.pathname + '|' + context(kind).product_ids.join(',')))).replace(/=/g, ''); }
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
      if (item.price_html) { const price = document.createElement('div'); price.className = 'fmi-price'; price.innerHTML = item.price_html; card.appendChild(price); }
      if (item.reason) { const reason = document.createElement('p'); reason.textContent = item.reason; card.appendChild(reason); }
      const actions = document.createElement('div'); actions.className = 'fmi-actions';
      const link = document.createElement('a'); link.className = 'fmi-button fmi-button--secondary'; link.href = item.product_url || '#'; link.textContent = '商品を確認'; actions.appendChild(link);
      if (item.purchasable && item.product_id && settings.addToCartEndpoint) {
        const add = document.createElement('button'); add.type = 'button'; add.className = 'fmi-button'; add.textContent = 'カートに追加';
        add.addEventListener('click', async () => { add.disabled = true; try { const response = await fetch(settings.addToCartEndpoint, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: new URLSearchParams({product_id: String(item.product_id), quantity: '1'})}); if (!response.ok) throw new Error(); add.textContent = '追加しました'; document.body.dispatchEvent(new CustomEvent('wc_fragment_refresh')); } catch (_) { window.location.href = item.product_url; } finally { add.disabled = false; } });
        actions.appendChild(add);
      }
      card.appendChild(actions); grid.appendChild(card);
    });
    node.appendChild(grid);
  }
  function renderResult(body, payload) { body.innerHTML = ''; text(body, payload.result && payload.result.answer || ''); cards(body, payload.result && payload.result.data); }
  function form(block, body, kind) {
    const form = document.createElement('form'); form.className = 'fmi-form';
    const input = document.createElement('input'); input.type = 'text'; input.required = true; input.maxLength = 5000; input.placeholder = kind === 'site-search' ? '知りたいことを入力' : 'ご相談内容を入力';
    const button = document.createElement('button'); button.type = 'submit'; button.textContent = 'AIに相談'; form.append(input, button);
    form.addEventListener('submit', async (event) => { event.preventDefault(); button.disabled = true; body.innerHTML = '<p class="fmi-loading">ご案内を準備しています…</p>'; try { renderResult(body, await ask(kind, input.value)); } catch (error) { body.innerHTML = ''; text(body, error.message); } finally { button.disabled = false; } });
    block.appendChild(form);
  }
  document.querySelectorAll('[data-fmi-kind]').forEach((block) => {
    const kind = block.dataset.fmiKind; const body = block.querySelector('.fmi-block__body');
    if (!settings.enabled) { text(body, '現在、このAI案内は準備中です。'); return; }
    if (['ai-concierge', 'site-search', 'product-recommendation'].includes(kind)) form(block, body, kind);
    if (block.dataset.fmiAuto !== '1') return;
    const prior = cached(kind); if (prior) { renderResult(body, prior); return; }
    body.innerHTML = '<p class="fmi-loading">おすすめを選んでいます…</p>';
    const prompt = kind === 'related-content' ? 'このページを見ている人に役立つサイト内の情報を案内してください。' : kind === 'cart-assistant' ? '現在のカートを確認し、買い忘れや相性のよい商品を提案してください。' : '現在の商品と一緒に使うと便利な商品を提案してください。';
    ask(kind, prompt).then((payload) => { cache(kind, payload); renderResult(body, payload); }).catch(() => { body.innerHTML = ''; });
  });
})();
