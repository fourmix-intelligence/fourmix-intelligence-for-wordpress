(function () {
  'use strict';
  const __ = (text) => wp.i18n.__(text, 'fourmix-intelligence');
  const node = (tag, cls, text) => { const el = document.createElement(tag); if (cls) el.className = cls; if (text) el.textContent = text; return el; };
  const button = (label) => { const el = node('button', 'fmi-chat__button', label); el.type = 'button'; return el; };
  const actionIds = (value, depth = 0) => {
    if (!value || typeof value !== 'object' || depth > 12) return [];
    const own = value.status === 'confirmation_required' && /^[a-f0-9-]{36}$/i.test(value.id || '') ? [value.id] : [];
    if (value.outcome === 'confirmation_required' && /^[a-f0-9-]{36}$/i.test(value.confirmation?.id || '')) own.push(value.confirmation.id);
    return [...new Set(own.concat(...Object.values(value).map((item) => actionIds(item, depth + 1))))];
  };
  function content(target, text) {
    if (window.FourmixIntelligenceAnswer?.render) { window.FourmixIntelligenceAnswer.render(target, text); return; }
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
    try { const prior = JSON.parse(sessionStorage.getItem(key) || 'null'); if (options.preserveDraft && prior && Date.now() - prior.at < 86400000) state.draft = prior.draft || ''; if (prior && Date.now() - prior.at < (options.ttl || 900000) && (!options.thread_id || prior.thread_id === options.thread_id)) state = prior; } catch (_) {}
    const save = () => { try { sessionStorage.setItem(key, JSON.stringify({...state, at: Date.now()})); } catch (_) {} };
    let controller = null, generation = 0, busy = false, disposed = false, attachments = null, renderTimer = null, visibleCount = 40;
    const messageNodes = new Map();
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
    if (options.preserveDraft) {
      input.value = state.draft || '';
      input.addEventListener('input', () => { state.draft = input.value; save(); });
    }
    if (options.attachments) attachments = window.FourmixIntelligenceAttachments?.mount(form, {...options.attachments, scope: options.scope, ttl: options.ttl,
      identity: () => ({...(options.attachments.identity?.() || {}), conversation_id: state.conversation_id, thread_id: state.thread_id}),
      onConversation: (id) => { state.conversation_id = id; save(); }, onChange: () => activity(busy)});
    if (attachments) input.required = false;
    function say(text) { status.textContent = text; }
    function activity(value) { busy = value; send.disabled = value || !!state.pending || !!attachments?.hasBlocking(); input.disabled = value; fresh.disabled = value; history.disabled = value; stop.hidden = !value; attachments?.setBusy(value); host.setAttribute('aria-busy', String(value)); }
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
      if (messageNodes.has(item)) { log.append(messageNodes.get(item)); return; }
      const article = node('article', 'fmi-chat__message fmi-chat__message--' + (item.role === 'user' ? 'user' : 'assistant'));
      article.append(node('span', 'fmi-chat__speaker', item.role === 'user' ? __('あなた') : options.label || __('AI')));
      const body = node('div', 'fmi-chat__content'); if (window.FourmixIntelligenceAnswer?.render) window.FourmixIntelligenceAnswer.render(body, item.content, !!item.streaming); else content(body, item.content); article.append(body);
      if (item.context) article.append(node('small', 'fmi-chat__hint', item.context));
      attachments?.drawMessage(body, item.attachments);
      if (!item.streaming && item.role === 'assistant' && item.follow_up_questions) {
        const questions = node('div', 'fmi-chat__follow-ups');
        item.follow_up_questions.slice(0, 5).forEach((item) => { const prompt = typeof item === 'string' ? item : item.prompt; if (!prompt) return; const choice = button(prompt); choice.addEventListener('click', () => { if (!busy && !state.pending) { input.value = prompt; input.focus(); } }); questions.append(choice); }); article.append(questions);
      }
      if (options.decorate && item.data) options.decorate(body, item.data);
      if (options.tools) (item.actions || []).forEach((id) => tool(body, id));
      messageNodes.set(item, article); log.append(article);
    }
    function redraw() {
      const position = log.scrollTop, visible = state.messages.slice(-visibleCount), retained = new Set(visible);
      messageNodes.forEach((value, item) => { if (!retained.has(item)) messageNodes.delete(item); }); log.replaceChildren();
      if (!state.messages.length) log.append(node('p', 'fmi-chat__empty', options.welcome || __('ここからAIに相談できます。操作が必要な場合は、内容を確認してから実行します。')));
      if (state.messages.length > visibleCount) { const older = button(__('以前のメッセージを表示')); older.addEventListener('click', () => { const height = log.scrollHeight; follow = false; visibleCount += 40; redraw(); log.scrollTop += log.scrollHeight - height; }); log.append(older); }
      visible.forEach(message); if (follow) scroll(); else log.scrollTop = position;
    }
    function renderPartial() {
      const item = state.messages.at(-1); if (!item?.streaming) return;
      if (!messageNodes.has(item)) redraw();
      else { const body = messageNodes.get(item).querySelector('.fmi-chat__content'); window.FourmixIntelligenceAnswer.render(body, item.content, true); if (follow) scroll(); }
    }
    function finish(payload) {
      clearTimeout(renderTimer); renderTimer = null;
      if (payload.state === 'unknown_effect') { unknown(); return; }
      state.conversation_id = payload.conversation_id || state.conversation_id;
      const answer = payload.result?.answer || payload.answer || __('応答を受け取りました。内容をご確認ください。');
      const partial = state.messages.at(-1); const message = {role: 'assistant', content: answer, data: payload.result?.data, follow_up_questions: payload.result?.follow_up_questions, actions: options.tools ? actionIds(payload) : []};
      if (partial?.role === 'assistant' && partial.streaming) state.messages[state.messages.length - 1] = message; else state.messages.push(message);
      state.pending = null; save(); recover.hidden = true; retry.hidden = true; redraw(); say(__('応答を受け取りました。')); activity(false); if (input.getClientRects().length) input.focus();
    }
    function unknown() { clearTimeout(renderTimer); renderTimer = null; renderPartial(); save(); recover.hidden = false; retry.hidden = true; say(__('受信を完了できませんでした。処理が続いている可能性があります。再送せず、送信結果を確認してください。')); activity(false); }
    async function transmit() {
      if (!state.pending || busy || disposed) return;
      const turn = ++generation; controller = new AbortController(); activity(true); recover.hidden = true; say(__('応答を待っています…'));
      const timeout = setTimeout(() => controller?.abort(), 185000);
      const receive = (event) => {
        if (turn !== generation || disposed) return;
        if (event.type === 'run.created' && event.data.conversation_id) { state.conversation_id = event.data.conversation_id; save(); }
        if (['assistant.delta', 'assistant.message'].includes(event.type)) {
          let item = state.messages.at(-1);
          if (item?.role !== 'assistant' || !item.streaming) { item = {role: 'assistant', content: '', streaming: true}; state.messages.push(item); }
          item.content = event.type === 'assistant.message' ? event.data.text || '' : item.content + (event.data.text || ''); if (!renderTimer) renderTimer = setTimeout(() => { renderTimer = null; if (!disposed) { renderPartial(); save(); } }, 120);
        } else if (['status', 'run.status', 'tool.started', 'tool.completed', 'tool.failed', 'planner.plan', 'planner.replan'].includes(event.type)) {
          const labels = {'tool.started': __('業務の内容を確認しています…'), 'tool.completed': __('操作結果を確認しました。'), 'tool.failed': __('操作結果を確認できませんでした。'), 'planner.plan': __('進め方を整理しています…'), 'planner.replan': __('進め方を見直しています…')}; say(labels[event.type] || event.data.message || __('回答を準備しています…'));
        }
      };
      try { const data = await (options.stream ? options.stream(state.pending, controller.signal, receive) : options.request('chat', state.pending, controller.signal)); if (turn === generation && !disposed) finish(data); }
      catch (error) { if (turn === generation && !disposed) { unknown(); if (error.name !== 'AbortError') say(error.message + ' ' + __('送信結果を確認してから再開してください。')); } }
      finally { clearTimeout(timeout); if (turn === generation && !disposed) { controller = null; activity(false); } }
    }
    form.addEventListener('submit', (event) => {
      event.preventDefault(); if (busy || state.pending || attachments?.hasBlocking() || (!input.value.trim() && !attachments?.readyCount())) return;
      const extra = options.context ? options.context() : {};
      const files = attachments?.consume() || [], text = input.value.trim() || __('添付したファイルについて確認してください。');
      state.pending = {...extra, message: text, attachment_ids: files.map((item) => item.id), request_id: Date.now() + ':' + crypto.randomUUID(), thread_id: state.thread_id, conversation_id: state.conversation_id};
      state.messages.push({role: 'user', content: text, attachments: files, context: options.contextLabel ? options.contextLabel(extra) : ''}); input.value = ''; state.draft = ''; follow = true; save(); redraw(); transmit();
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
      try { await attachments?.reset(); const data = options.newConversation ? await options.newConversation() : {}; state = {messages: [], pending: null, conversation_id: '', thread_id: data.thread_id || ''}; visibleCount = 40; save(); recover.hidden = true; redraw(); activity(false); say(__('新しい相談を始められます。')); input.focus(); } catch (error) { say(error.message); } finally { fresh.disabled = false; }
    });
    history.addEventListener('click', async () => {
      if (!state.conversation_id || busy || state.pending) { say(__('送信結果を確認した会話の履歴を読み込めます。')); return; }
      history.disabled = true;
      const conversation = state.conversation_id, turn = generation;
      try {
        const data = await options.history(conversation);
        if (disposed || state.conversation_id !== conversation || turn !== generation) return;
        const rows = (data.messages || []).filter((row) => ['user', 'assistant'].includes(row.role));
        const same = (left, right) => left.role === right.role && left.content === right.content;
        const prior = state.messages, aligned = rows.length === prior.length && rows.every((row, index) => same(row, prior[index]));
        state.messages = rows.map((row, index) => {
          // 本体の履歴に添付メタデータがない場合も、同じ会話の既知の表示を保つ。
          // 重複文を位置で対応できない場合は、別の添付を推測して付けない。
          const matches = aligned ? [prior[index]] : prior.filter((item) => same(row, item));
          const known = matches.length === 1 && (aligned || rows.filter((item) => same(row, item)).length === 1) ? matches[0] : null;
          return {role: row.role, content: row.content, data: row.data, attachments: row.attachments ?? known?.attachments,
            follow_up_questions: row.follow_up_questions ?? known?.follow_up_questions};
        });
        save(); redraw(); say(__('会話履歴を読み込みました。'));
      } catch (error) { say(error.message); } finally { history.disabled = false; }
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
    return {dispose() { disposed = true; ++generation; controller?.abort(); attachments?.dispose(); clearTimeout(renderTimer); messageNodes.clear(); save(); window.removeEventListener('pagehide', leave); window.removeEventListener('pageshow', resume); window.removeEventListener('resize', resize); }, input};
  }
  async function stream(endpoint, init, signal, onEvent) {
    const reply = await fetch(endpoint, {...init, signal});
    if (!reply.ok) { const value = await reply.json(); const error = new Error(value.message || __('接続と権限を確認してください。')); error.status = reply.status; error.code = value.code; error.loginUrl = value.login_url; throw error; }
    if (!reply.headers.get('Content-Type')?.includes('application/x-ndjson') || !reply.body) throw new Error(__('逐次応答を受信できませんでした。'));
    const reader = reply.body.getReader(), decoder = new TextDecoder(); let buffer = '', completed = null, received = 0;
    function line(value) {
      if (!value.trim()) return; const event = JSON.parse(value); if (!event || typeof event.type !== 'string' || !event.data) throw new Error(__('応答の形式を確認できませんでした。'));
      if (event.type === 'run.failed') { const error = new Error(event.data.message || __('結果を確認できませんでした。')); error.status = event.data.status_code; error.loginUrl = event.data.login_url; throw error; }
      onEvent(event); if (event.type === 'run.completed') completed = event.data.response;
    }
    try { while (true) { const {value, done} = await reader.read(); received += value?.byteLength || 0; if (received > 2 * 1024 * 1024) throw new Error(__('応答が大きすぎます。送信結果を確認してください。')); buffer += decoder.decode(value || new Uint8Array(), {stream: !done}); const lines = buffer.split('\n'); buffer = lines.pop() || ''; lines.forEach(line); if (done) break; } line(buffer); if (!completed) throw new Error(__('応答が途中で切れました。送信結果を確認してください。')); return completed; }
    finally { await reader.cancel().catch(() => {}); reader.releaseLock(); }
  }
  window.FourmixIntelligenceChat = {mount, content, stream};
})();
