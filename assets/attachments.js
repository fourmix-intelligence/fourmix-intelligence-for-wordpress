(function () {
  'use strict';
  const __ = (text) => wp.i18n.__(text, 'fourmix-intelligence');
  const node = (tag, cls, text) => { const el = document.createElement(tag); if (cls) el.className = cls; if (text) el.textContent = text; return el; };
  const button = (label) => { const el = node('button', 'fmi-chat__button', label); el.type = 'button'; return el; };
  const requestId = () => Date.now() + ':' + crypto.randomUUID();
  function mount(form, options) {
    const policy = options.policy; if (!policy?.enabled) return null;
    const key = 'fourmix_intelligence_attachment_drafts_' + options.scope;
    let items = [], disposed = false, preparing = null, prepareKey = requestId(); const urls = new Map(), controllers = new Set();
    const imageItems = new WeakMap();
    const observer = new IntersectionObserver((entries) => { entries.forEach(({target, isIntersecting}) => {
      if (!target.isConnected) { observer.unobserve(target); return; }
      if (!isIntersecting) return; observer.unobserve(target);
      blob(imageItems.get(target)).then((url) => { if (target.isConnected && !disposed) target.src = url; }).catch(() => { target.alt = __('画像を取得できません。期限と権限を確認してください。'); });
    }); }, {root: form.parentElement.querySelector('.fmi-chat__messages'), rootMargin: '160px'});
    function cache(id, pending) {
      urls.delete(id); urls.set(id, pending);
      while (urls.size > 12) { const oldest = urls.keys().next().value; urls.get(oldest).then((url) => URL.revokeObjectURL(url)).catch(() => {}); urls.delete(oldest); }
    }
    try { const saved = JSON.parse(sessionStorage.getItem(key) || 'null'); if (saved && Date.now() - saved.at < (options.ttl || 900000)) items = saved.items.map((item) => ({...item, file: null, uploading: false, error: item.id ? '' : __('送信結果を確認してください。')})); } catch (_) {}
    const group = node('div', 'fmi-attachments'), list = node('div', 'fmi-attachments-list'), status = node('p', 'fmi-attachments-status'); status.setAttribute('role', 'status');
    const choose = button(__('画像・ファイルを添付')), input = document.createElement('input'); input.type = 'file'; input.multiple = true; input.accept = policy.extensions.join(','); input.hidden = true; input.setAttribute('aria-label', __('添付ファイルを選択'));
    group.append(choose, input, list, status); form.insertBefore(group, form.querySelector('.fmi-chat__controls'));
    const identity = (extra = {}) => ({...options.identity(), ...extra});
    const save = () => { try { sessionStorage.setItem(key, JSON.stringify({at: Date.now(), items: items.map(({file, controller, preview, ...item}) => item)})); } catch (_) {} };
    async function json(action, extra = {}) {
      const reply = await fetch(options.endpoint + 'attachment_' + action, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', ...(options.nonce ? {'X-WP-Nonce': options.nonce} : {})}, body: JSON.stringify(identity(extra))});
      const data = await reply.json(); if (!reply.ok) throw new Error(data.message || __('添付を確認できませんでした。')); return data;
    }
    const metadata = (item) => ({id: item.id, name: item.name, mime: item.mime, size: item.size, conversation_id: item.conversation_id});
    function accept(item, data) { if (!data.attachment?.id) throw new Error(__('添付の保存結果を確認できませんでした。')); Object.assign(item, data.attachment, {conversation_id: data.conversation_id, progress: 100, error: '', unknown: false}); }
    async function ensureConversation() {
      if (options.identity().conversation_id) return options.identity().conversation_id;
      if (preparing) return preparing;
      preparing = (async () => { const data = await json('prepare', {request_id: prepareKey}); if (!data.conversation_id) throw new Error(__('添付する会話を確認できませんでした。再送せず設定を確認してください。')); options.onConversation(data.conversation_id); return data.conversation_id; })().finally(() => { preparing = null; }); return preparing;
    }
    function notify() { if (disposed) return; save(); draw(); options.onChange(); }
    async function recover(item) {
      const value = await json('status', {request_id: item.request_id});
      if (value.state === 'succeeded') { accept(item, value.response); return true; }
      if (value.state === 'not_started') { item.unknown = false; item.error = item.file ? __('未到達を確認しました。同じ添付を再送できます。') : __('未到達でした。ファイルを選び直してください。'); return false; }
      item.unknown = true; throw new Error(__('結果がまだ不明です。重複送信せず時間をおいて確認してください。'));
    }
    async function upload(item) {
      if (item.uploading || disposed) return;
      item.uploading = true; item.error = ''; notify();
      try {
        if (item.unknown && await recover(item)) return;
        if (!item.file) throw new Error(__('元のファイルを選び直してください。'));
        await ensureConversation(); if (disposed) return;
        await new Promise((resolve, reject) => {
          const xhr = new XMLHttpRequest(); item.controller = xhr; controllers.add(xhr);
          xhr.open('POST', options.endpoint + 'attachment_upload'); xhr.timeout = 185000; if (options.nonce) xhr.setRequestHeader('X-WP-Nonce', options.nonce);
          xhr.upload.onprogress = (event) => { if (event.lengthComputable) { item.progress = Math.round(event.loaded / event.total * 100); notify(); } };
          const failure = () => { item.unknown = true; reject(new Error(__('保存結果を確認できません。再送せず送信結果を確認してください。'))); };
          xhr.onerror = failure; xhr.ontimeout = failure; xhr.onabort = failure;
          xhr.onload = () => { try { const data = JSON.parse(xhr.responseText); if (xhr.status < 200 || xhr.status >= 300) { item.unknown = xhr.status >= 500; throw new Error(data.message || __('添付を受付できませんでした。')); } if (data.state === 'unknown_effect') { item.unknown = true; throw new Error(__('添付の保存結果が不明です。再送せず確認してください。')); } accept(item, data); resolve(); } catch (error) { reject(error); } };
          xhr.onloadend = () => controllers.delete(xhr);
          const body = new FormData(); Object.entries(identity({request_id: item.request_id})).forEach(([name, value]) => body.append(name, String(value || ''))); body.append('file', item.file); xhr.send(body);
        });
      } catch (error) { item.error = error.message; status.textContent = error.message; }
      finally { item.uploading = false; notify(); }
    }
    async function remove(item) {
      item.controller?.abort();
      try {
        if (item.unknown) { const done = await recover(item); if (!done && item.unknown) return; }
        if (item.id) { const value = await json('remove', {id: item.id, request_id: item.remove_key ||= requestId(), conversation_id: item.conversation_id}); if (!value.removed) throw new Error(__('削除結果を確認できません。添付を残して再送を止めています。')); }
        items = items.filter((value) => value !== item); if (item.preview) URL.revokeObjectURL(item.preview); status.textContent = ''; notify();
      } catch (error) { item.error = error.message; status.textContent = error.message; notify(); }
    }
    function draw() {
      list.replaceChildren();
      items.forEach((item) => {
        const card = node('div', 'fmi-attachment');
        if (item.preview) { const preview = node('img', 'fmi-attachment-preview'); preview.src = item.preview; preview.alt = item.name; card.append(preview); }
        const details = node('div', 'fmi-attachment-details'); details.append(node('strong', '', item.name), node('small', '', Math.ceil(item.size / 1024) + ' KB'));
        if (item.uploading) { const progress = node('progress'); progress.max = 100; progress.value = item.progress || 0; progress.setAttribute('aria-label', __('添付の転送進捗')); details.append(progress, node('small', '', item.progress === 100 ? __('転送済み・保存結果を確認中') : __('転送中: ') + (item.progress || 0) + '%')); }
        else details.append(node('small', '', item.error || __('添付の保存を確認しました')));
        const actions = node('div', 'fmi-attachment-actions'), cancel = button(__('取り除く')); cancel.addEventListener('click', () => remove(item)); actions.append(cancel);
        if (item.error && !item.uploading) { const retry = button(item.unknown || !item.file ? __('送信結果を確認') : __('再試行')); retry.addEventListener('click', async () => { if (item.unknown || !item.file) { try { await recover(item); } catch (error) { item.error = error.message; } notify(); } else upload(item); }); actions.append(retry); }
        card.append(details, actions); list.append(card);
      });
    }
    function add(files) {
      if (choose.disabled || disposed) return; status.textContent = '';
      for (const file of files) {
        const extension = '.' + file.name.split('.').pop().toLowerCase();
        if (items.length >= policy.max_files) { status.textContent = __('一度に添付できる件数を超えています。'); break; }
        if (!policy.extensions.includes(extension) || !file.size || file.size > policy.max_bytes || items.reduce((total, item) => total + item.size, file.size) > policy.context_bytes) { status.textContent = __('形式またはサイズの上限を確認してください。') + ' ' + file.name; continue; }
        const item = {localId: crypto.randomUUID(), request_id: requestId(), name: file.name, size: file.size, mime: file.type, file, progress: 0, uploading: false, error: '', unknown: false};
        if (['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) item.preview = URL.createObjectURL(file);
        items.push(item); upload(item);
      } notify();
    }
    choose.title = __('対応形式: ') + policy.extensions.join(', ') + ' / ' + Math.floor(policy.max_bytes / 1024 / 1024) + ' MB';
    choose.addEventListener('click', () => input.click()); input.addEventListener('change', () => { add([...input.files]); input.value = ''; });
    form.addEventListener('dragover', (event) => { if ([...event.dataTransfer.types].includes('Files')) { event.preventDefault(); form.classList.add('fmi-chat__composer--drop'); } });
    form.addEventListener('dragleave', () => form.classList.remove('fmi-chat__composer--drop'));
    form.addEventListener('drop', (event) => { form.classList.remove('fmi-chat__composer--drop'); if (event.dataTransfer.files.length) { event.preventDefault(); add([...event.dataTransfer.files]); } });
    form.addEventListener('paste', (event) => { const files = [...(event.clipboardData?.items || [])].filter((item) => item.kind === 'file').map((item) => item.getAsFile()).filter(Boolean); if (files.length) { event.preventDefault(); add(files); } });
    async function blob(item) {
      if (urls.has(item.id)) return urls.get(item.id);
      const pending = (async () => { const reply = await fetch(options.endpoint + 'attachment_content', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', ...(options.nonce ? {'X-WP-Nonce': options.nonce} : {})}, body: JSON.stringify(identity({id: item.id, conversation_id: item.conversation_id}))}); if (!reply.ok) throw new Error(__('添付を取得できません。期限と権限を確認してください。')); return URL.createObjectURL(new Blob([await reply.arrayBuffer()], {type: item.mime || 'application/octet-stream'})); })();
      cache(item.id, pending); try { return await pending; } catch (error) { urls.delete(item.id); throw error; }
    }
    function drawMessage(target, files) {
      if (!files?.length) return; const group = node('div', 'fmi-message-attachments'); target.append(group);
      files.forEach((item) => {
        const card = node('div', 'fmi-attachment'), open = button(item.name || __('添付ファイル')); card.append(open); group.append(card);
        open.addEventListener('click', async () => { open.disabled = true; try { const link = document.createElement('a'); link.href = await blob(item); link.download = item.name || 'attachment'; link.click(); } catch (error) { open.textContent = error.message; } finally { open.disabled = false; } });
        if (['image/png', 'image/jpeg', 'image/webp'].includes(item.mime)) { const image = node('img', 'fmi-message-image'); image.alt = item.name; image.width = 80; image.height = 80; imageItems.set(image, item); card.prepend(image); observer.observe(image); }
      });
    }
    draw();
    return {hasBlocking: () => items.some((item) => item.uploading || item.error || item.unknown), readyCount: () => items.filter((item) => item.id).length, setBusy(value) { choose.disabled = value; list.querySelectorAll('button').forEach((el) => { el.disabled = value; }); },
      consume() { const values = items.filter((item) => item.id).map(metadata); items.forEach((item) => { if (item.id && item.preview) cache(item.id, Promise.resolve(item.preview)); }); items = []; status.textContent = ''; notify(); return values; }, drawMessage,
      async reset() { for (const item of [...items]) await remove(item); if (items.length) throw new Error(__('未確認の添付を確認してから新しい相談を始めてください。')); prepareKey = requestId(); },
      dispose() { disposed = true; observer.disconnect(); controllers.forEach((xhr) => xhr.abort()); items.forEach((item) => { if (item.preview) URL.revokeObjectURL(item.preview); }); urls.forEach((value) => value.then((url) => URL.revokeObjectURL(url)).catch(() => {})); urls.clear(); save(); }
    };
  }
  window.FourmixIntelligenceAttachments = {mount};
})();
