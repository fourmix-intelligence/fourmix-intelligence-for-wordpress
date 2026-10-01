(function () {
  'use strict';
  const __ = (text) => wp.i18n.__(text, 'fourmix-intelligence');
  const node = (tag, cls, text) => { const el = document.createElement(tag); if (cls) el.className = cls; if (text) el.textContent = text; return el; };
  const button = (label) => { const el = node('button', 'fmi-chat__button', label); el.type = 'button'; return el; };
  const actionIds = (value, depth = 0) => {
    if (!value || typeof value !== 'object' || depth > 12) return [];
    const own = value.status === 'confirmation_required' && /^[a-f0-9-]{36}$/i.test(value.id || '') ? [value.id] : [];
    return [...new Set(own.concat(...Object.values(value).map((item) => actionIds(item, depth + 1))))];
  };
  function content(target, text) {
    // HTMLは解釈せず、段落・見出し・コードだけをDOMとして組み立てます。
    let code = null;
    String(text || '').split('\n').forEach((line) => {
      if (line.startsWith('```')) { if (code) code = null; else { code = node('pre', 'fmi-chat__code'); target.append(code); } return; }
      if (code) { code.textContent += line + '\n'; return; }
      const heading = line.match(/^#{1,3}\s+(.+)/);
      const el = node(heading ? 'h3' : 'p', '', heading ? heading[1] : line || '\u00a0');
      target.append(el);
    });
  }
  function mount(host, options) {
    let state = {messages: [], pending: null, conversation_id: '', thread_id: options.thread_id || ''};
    const key = 'fourmix_intelligence_chat_v3_' + options.scope;
    try { const prior = JSON.parse(sessionStorage.getItem(key) || 'null'); if (prior && Date.now() - prior.at < (options.ttl || 900000) && (!options.thread_id || prior.thread_id === options.thread_id)) state = prior; } catch (_) {}
    const save = () => { try { sessionStorage.setItem(key, JSON.stringify({...state, at: Date.now()})); } catch (_) {} };
    let controller = null, generation = 0, busy = false, disposed = false;
    host.replaceChildren(); host.classList.add('fmi-chat');
    const toolbar = node('div', 'fmi-chat__toolbar');
    toolbar.append(node('span', 'fmi-chat__label', options.label || __('AIとの相談')));
    const fresh = button(__('新しい相談')), history = button(__('履歴を読み込む'));
    toolbar.append(fresh); if (options.history) toolbar.append(history);
    const log = node('div', 'fmi-chat__messages'); log.setAttribute('role', 'log'); log.setAttribute('aria-label', __('相談のメッセージ')); log.setAttribute('aria-live', 'polite'); log.tabIndex = 0;
    const status = node('div', 'fmi-chat__status'); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
    const recover = node('div', 'fmi-chat__recovery');
    const check = button(__('送信結果を確認')), retry = button(__('同じ送信を再開')); recover.append(check, retry); retry.hidden = true; recover.hidden = true;
    const form = node('form', 'fmi-chat__composer');
    const label = node('label', 'fmi-chat__input-label', __('相談内容'));
    const input = node('textarea', 'fmi-chat__input'); input.rows = 3; input.maxLength = 5000; input.required = true; input.placeholder = options.placeholder || __('相談したいことを入力してください'); label.append(input);
    const controls = node('div', 'fmi-chat__controls');
    const hint = node('span', 'fmi-chat__hint', __('Enterで改行・Ctrl/⌘ + Enterで送信'));
    const stop = button(__('受信を停止')); stop.hidden = true;
    const send = button(__('送信')); send.type = 'submit'; send.classList.add('fmi-chat__button--primary'); controls.append(hint, stop, send); form.append(label, controls);
    host.append(toolbar, log, status, recover, form);
    function say(text) { status.textContent = text; }
    function activity(value) { busy = value; send.disabled = value || !!state.pending; input.disabled = value; fresh.disabled = value; history.disabled = value; stop.hidden = !value; host.setAttribute('aria-busy', String(value)); }
    function scroll() { log.scrollTop = log.scrollHeight; }
    function tool(target, id) {
      const card = node('section', 'fmi-chat__tool'); card.dataset.actionId = id;
      card.append(node('h3', '', __('操作内容の確認')));
      const info = node('div', 'fmi-chat__tool-info', __('正式な操作内容を確認しています…'));
      const result = node('p', 'fmi-chat__tool-status'); result.setAttribute('role', 'status');
      const actions = node('div', 'fmi-chat__tool-actions');
      const approve = button(__('内容を確認して実行')), reject = button(__('今回は実行しない')), refresh = button(__('実行結果を確認'));
      approve.classList.add('fmi-chat__button--primary'); approve.disabled = true; refresh.hidden = true; actions.append(approve, reject, refresh); card.append(info, result, actions); target.append(card);
      let processing = false, declined = (state.dismissed || []).includes(id), uncertain = false, lastStatus = '', permitted = false, formal = {};
      approve.hidden = declined; reject.hidden = declined;
      const labels = {confirmation_required: __('確認待ち'), completed: __('実行済み'), failed: __('実行に失敗しました'), unknown_effect: __('結果不明。再実行せず対象データと監査履歴を確認してください。'), running: __('実行中。再実行せず結果を確認してください。'), expired: __('確認期限が切れています')};
      function render(data) {
        const atEnd = log.scrollHeight - log.scrollTop - log.clientHeight < 80;
        formal = {...formal, ...data};
        lastStatus = data.status;
        permitted = data.can_confirm !== false;
        info.replaceChildren(node('strong', '', formal.operation_name || __('業務操作')));
        const details = node('dl', 'fmi-chat__arguments');
        const fields = {id: __('対象ID'), post_type: __('投稿の種類'), title: __('変更後のタイトル'), content: __('本文'), status: __('状態'), name: __('名称'), regular_price: __('通常価格'), stock_quantity: __('在庫数')};
        Object.entries(formal.arguments || {}).forEach(([name, value]) => { if (name !== 'idempotency_key') details.append(node('dt', '', fields[name] || name), node('dd', '', typeof value === 'object' ? JSON.stringify(value, null, 2) : String(value))); }); info.append(details);
        info.append(node('small', 'fmi-chat__hint', __('監査ID: ') + id));
        result.textContent = declined && data.status === 'confirmation_required' ? __('この画面からは実行しません。確認要求は期限切れまで保留されます。') : (!permitted && data.message ? data.message : labels[data.status] || __('実行状態を確認できません。'));
        approve.hidden = declined || data.status !== 'confirmation_required'; approve.disabled = processing || uncertain || data.status !== 'confirmation_required';
        reject.hidden = declined || data.status !== 'confirmation_required'; refresh.hidden = !['unknown_effect', 'running'].includes(data.status) && !uncertain;
        if (data.status === 'completed' && data.result) { const detail = node('pre', 'fmi-chat__arguments'); detail.textContent = JSON.stringify(data.result, null, 2); info.append(detail); }
        if (atEnd) scroll();
      }
      const load = async () => { if (processing || disposed) return; processing = true; refresh.disabled = true; approve.disabled = true; try { render(await options.request('action', {id, thread_id: state.thread_id})); } catch (error) { lastStatus = ''; result.textContent = error.message; refresh.hidden = false; } finally { processing = false; refresh.disabled = false; approve.disabled = uncertain || !permitted || lastStatus !== 'confirmation_required'; } };
      reject.addEventListener('click', () => { declined = true; state.dismissed = [...new Set([...(state.dismissed || []), id])]; save(); approve.hidden = true; reject.hidden = true; result.textContent = __('この画面からは実行しません。確認要求は期限切れまで保留されます。'); });
      refresh.addEventListener('click', load);
      approve.addEventListener('click', async () => {
        if (processing || declined || uncertain || !permitted) return;
        processing = true; approve.disabled = true; reject.disabled = true; result.textContent = __('実行結果を確認しています…');
        try { const data = await options.request('confirm_action', {id, thread_id: state.thread_id, approved: true}); uncertain = ['unknown_effect', 'running'].includes(data.status); render(data); }
        catch (error) { uncertain = true; approve.hidden = true; reject.hidden = true; refresh.hidden = false; result.textContent = error.message + ' ' + labels.unknown_effect; }
        finally { processing = false; reject.disabled = false; }
      });
      load();
    }
    function message(item) {
      const article = node('article', 'fmi-chat__message fmi-chat__message--' + (item.role === 'user' ? 'user' : 'assistant'));
      article.append(node('span', 'fmi-chat__speaker', item.role === 'user' ? __('あなた') : options.label || __('AI')));
      const body = node('div', 'fmi-chat__content'); content(body, item.content); article.append(body);
      if (item.context) article.append(node('small', 'fmi-chat__hint', item.context));
      if (options.decorate && item.data) options.decorate(body, item.data);
      if (options.tools) (item.actions || []).forEach((id) => tool(body, id));
      log.append(article);
    }
    function redraw() { log.replaceChildren(); if (!state.messages.length) log.append(node('p', 'fmi-chat__empty', options.welcome || __('ここからAIに相談できます。操作が必要な場合は、内容を確認してから実行します。'))); else state.messages.forEach(message); scroll(); }
    function finish(payload) {
      if (payload.state === 'unknown_effect') { unknown(); return; }
      state.conversation_id = payload.conversation_id || state.conversation_id;
      const answer = payload.result?.answer || payload.answer || __('応答を受け取りました。内容をご確認ください。');
      state.messages.push({role: 'assistant', content: answer, data: payload.result?.data, actions: options.tools ? actionIds(payload) : []});
      state.pending = null; save(); recover.hidden = true; retry.hidden = true; redraw(); say(__('応答を受け取りました。')); activity(false); input.focus();
    }
    function unknown() { recover.hidden = false; retry.hidden = true; say(__('受信を完了できませんでした。処理が続いている可能性があります。再送せず、送信結果を確認してください。')); activity(false); }
    async function transmit() {
      if (!state.pending || busy || disposed) return;
      const turn = ++generation; controller = new AbortController(); activity(true); recover.hidden = true; say(__('応答を待っています…'));
      const timeout = setTimeout(() => controller?.abort(), 185000);
      try { const data = await options.request('chat', state.pending, controller.signal); if (turn === generation && !disposed) finish(data); }
      catch (error) { if (turn === generation && !disposed) { unknown(); if (error.name !== 'AbortError') say(error.message + ' ' + __('送信結果を確認してから再開してください。')); } }
      finally { clearTimeout(timeout); if (turn === generation && !disposed) { controller = null; activity(false); } }
    }
    form.addEventListener('submit', (event) => {
      event.preventDefault(); if (busy || state.pending || !input.value.trim()) return;
      const extra = options.context ? options.context() : {};
      state.pending = {...extra, message: input.value.trim(), request_id: Date.now() + ':' + crypto.randomUUID(), thread_id: state.thread_id, conversation_id: state.conversation_id};
      state.messages.push({role: 'user', content: input.value.trim(), context: options.contextLabel ? options.contextLabel(extra) : ''}); input.value = ''; save(); redraw(); transmit();
    });
    input.addEventListener('keydown', (event) => { if (event.key === 'Enter' && (event.ctrlKey || event.metaKey) && !event.isComposing) { event.preventDefault(); form.requestSubmit(); } });
    stop.addEventListener('click', () => { ++generation; controller?.abort(); controller = null; unknown(); say(__('受信を停止しました。サーバー側の処理は取り消されていません。送信結果を確認してください。')); });
    check.addEventListener('click', async () => {
      if (!state.pending || busy) return; check.disabled = true;
      try { const data = await options.request('run_status', {request_id: state.pending.request_id}); if (data.state === 'succeeded') finish(data.response); else if (data.state === 'not_started') { retry.hidden = false; say(__('この送信の実行記録はまだありません。同じ確認情報で送信を再開できます。')); } else say(__('結果がまだ確定していません。再実行せず、時間をおいて結果を確認してください。')); }
      catch (error) { say(error.message); } finally { check.disabled = false; }
    });
    retry.addEventListener('click', transmit);
    fresh.addEventListener('click', async () => {
      if (busy) return;
      if ((state.messages.length || state.pending) && !window.confirm(__('新しい相談を始めますか？ 前の処理や確認待ちの操作は取り消されません。'))) return;
      fresh.disabled = true;
      try { const data = options.newConversation ? await options.newConversation() : {}; state = {messages: [], pending: null, conversation_id: '', thread_id: data.thread_id || ''}; save(); recover.hidden = true; redraw(); activity(false); say(__('新しい相談を始められます。')); input.focus(); } catch (error) { say(error.message); } finally { fresh.disabled = false; }
    });
    history.addEventListener('click', async () => {
      if (!state.conversation_id || busy || state.pending) { say(__('送信結果を確認した会話の履歴を読み込めます。')); return; }
      history.disabled = true;
      try { const data = await options.history(state.conversation_id); state.messages = (data.messages || []).filter((row) => ['user', 'assistant'].includes(row.role)).map((row) => ({role: row.role, content: row.content, data: row.data})); save(); redraw(); say(__('会話履歴を読み込みました。')); } catch (error) { say(error.message); } finally { history.disabled = false; }
    });
    const leave = () => { if (busy) { ++generation; controller?.abort(); controller = null; save(); unknown(); } };
    const resume = (event) => { if (event.persisted && state.pending) unknown(); };
    let follow = true;
    log.addEventListener('scroll', () => { follow = log.scrollHeight - log.scrollTop - log.clientHeight < 80; });
    const resize = () => { const end = follow; if (end) requestAnimationFrame(scroll); };
    window.addEventListener('resize', resize);
    window.addEventListener('pagehide', leave);
    window.addEventListener('pageshow', resume);
    redraw(); activity(false); if (state.pending) unknown();
    return {dispose() { disposed = true; ++generation; controller?.abort(); window.removeEventListener('pagehide', leave); window.removeEventListener('pageshow', resume); window.removeEventListener('resize', resize); }, input};
  }
  window.FourmixIntelligenceChat = {mount, content};
})();
