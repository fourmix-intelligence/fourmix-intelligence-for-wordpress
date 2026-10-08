(function () {
  'use strict';
  const __ = text => wp.i18n.__(text, 'fourmix-intelligence');
  const uuid = value => typeof value === 'string' && /^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i.test(value);
  const node = (tag, text) => { const el = document.createElement(tag); if (text) el.textContent = text; return el; };
  function mount(options, current) {
    if (!options) return null;
    const controllers = new Set(); let revision = 0;
    const identity = () => ({...options.identity(), ...current()});
    function abort() { ++revision; controllers.forEach(value => value.abort()); controllers.clear(); }
    function drawMessage(target, files) {
      if (!Array.isArray(files)) return;
      const items = files.slice(0, 20).filter(item => uuid(item?.id) && typeof item.name === 'string' && item.name.length <= 240 && !/[\\/\x00-\x1f]/.test(item.name) && Number.isInteger(item.size) && item.size > 0 && item.size <= 20 * 1024 * 1024);
      if (!items.length) return;
      const group = node('section'); group.className = 'fmi-generated-files'; group.setAttribute('aria-label', __('生成ファイル')); target.append(group);
      const scope = identity(), handlers = new Map();
      items.forEach(item => {
        const card = node('div'); card.className = 'fmi-generated-file';
        const name = node('span', item.name + ' · ' + Math.ceil(item.size / 1024) + ' KB'), open = node('button', __('ダウンロード')), status = node('div');
        open.type = 'button'; open.className = 'fmi-chat__button'; status.className = 'fmi-attachment-error'; status.setAttribute('role', 'status'); card.append(name, open, status); group.append(card);
        if (typeof item.download_url === 'string') handlers.set(item.download_url, () => open.click());
        open.addEventListener('click', async () => {
          status.replaceChildren();
          if (!uuid(scope.conversation_id) || JSON.stringify(scope) !== JSON.stringify(identity())) { status.textContent = __('現在の会話を選び直してから取得してください。'); return; }
          if (item.expires_at && Number.isFinite(Date.parse(item.expires_at)) && Date.parse(item.expires_at) <= Date.now()) { status.textContent = __('生成ファイルの保存期限が切れています。'); return; }
          const controller = new AbortController(), version = revision; controllers.add(controller); open.disabled = true;
          try {
            const reply = await fetch(options.endpoint + 'artifact_content', {method: 'POST', credentials: 'same-origin', redirect: 'error', signal: controller.signal, headers: {'Content-Type': 'application/json', 'X-WP-Nonce': options.nonce}, body: JSON.stringify({...scope, id: item.id})});
            if (!reply.ok) {
              const data = await reply.json().catch(() => ({}));
              const labels = {401: __('Fourmix Intelligence に再度ログインしてください。'), 403: __('生成ファイルを取得する権限を確認してください。'), 404: __('この会話の生成ファイルを確認できません。'), 410: __('生成ファイルの保存期限が切れています。')};
              const error = new Error(labels[reply.status] || __('生成ファイルを取得できません。時間をおいてお試しください。'));
              if (reply.status === 401 && typeof data.login_url === 'string') {
                try { const url = new URL(data.login_url, location.href); if (url.origin === location.origin && url.pathname.endsWith('/wp-admin/admin-post.php') && url.searchParams.get('action') === 'fourmix_intelligence_identity_start') error.loginUrl = url.href; } catch (_) {}
              }
              throw error;
            }
            const body = await reply.blob();
            if (controller.signal.aborted || version !== revision || JSON.stringify(scope) !== JSON.stringify(identity())) return;
            if (body.size !== item.size) throw new Error(__('生成ファイルの取得結果を確認できません。'));
            const url = URL.createObjectURL(body), link = node('a'); link.href = url; link.download = item.name; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
          } catch (error) {
            if (controller.signal.aborted || version !== revision) return;
            status.append(node('p', error.message));
            if (error.loginUrl) { const login = node('a', __('Fourmix Intelligence に再度ログイン')); login.href = error.loginUrl; login.rel = 'noreferrer noopener'; status.append(login); }
          } finally { controllers.delete(controller); open.disabled = false; }
        });
      });
      window.FourmixIntelligenceAnswer?.bindArtifactLinks(target, handlers);
    }
    return {drawMessage, abort};
  }
  window.FourmixIntelligenceArtifacts = {mount};
})();
