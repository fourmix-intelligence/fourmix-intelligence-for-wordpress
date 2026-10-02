(function () {
  'use strict';
  const __ = (text) => wp.i18n.__(text, 'fourmix-intelligence');
  const config = window.FourmixIntelligenceAnswer || {};
  const md = window.markdownit({html: false, breaks: true, linkify: true});
  const node = (tag, cls, text) => { const el = document.createElement(tag); if (cls) el.className = cls; if (text) el.textContent = text; return el; };
  const button = (label) => { const el = node('button', 'fmi-answer-button', label); el.type = 'button'; return el; };
  const linkOpen = md.renderer.rules.link_open || ((tokens, index, options, env, self) => self.renderToken(tokens, index, options));
  md.renderer.rules.link_open = (tokens, index, options, env, self) => { tokens[index].attrSet('target', '_blank'); tokens[index].attrSet('rel', 'noopener noreferrer'); return linkOpen(tokens, index, options, env, self); };
  md.renderer.rules.image = (tokens, index) => {
    const image = tokens[index], url = image.attrGet('src') || '', title = md.utils.escapeHtml(image.content || __('画像'));
    // モデルが指定した画像を自動取得せず、明示操作で開くリンクにします。
    if (!/^https?:\/\//i.test(url)) return title;
    return '<a target="_blank" rel="noopener noreferrer" href="' + md.utils.escapeHtml(url) + '">' + title + ' (' + md.utils.escapeHtml(__('画像を開く')) + ')</a>';
  };
  let serial = 0, mermaidLoading = null, diagramQueue = Promise.resolve();
  const diagramCache = new Map();
  function loadMermaid() {
    if (window.mermaid) return Promise.resolve(window.mermaid);
    if (!mermaidLoading) mermaidLoading = new Promise((resolve, reject) => { const script = document.createElement('script'); script.src = config.mermaidUrl; script.onload = () => resolve(window.mermaid); script.onerror = () => { script.remove(); mermaidLoading = null; reject(new Error(__('図の表示部品を読み込めませんでした。'))); }; document.head.append(script); });
    return mermaidLoading;
  }
  async function diagram(source, dark) {
    if (source.length > 20000 || /%%\s*\{|^\s*---|https?:|data:|javascript:|url\s*\(|\bclick\s/im.test(source)) throw new Error(__('図の設定またはサイズを確認してください。'));
    const key = dark + ':' + source; if (diagramCache.has(key)) return diagramCache.get(key);
    const task = diagramQueue.then(async () => {
      if (diagramCache.has(key)) return diagramCache.get(key);
      const mermaid = await loadMermaid();
      mermaid.initialize({startOnLoad: false, securityLevel: 'strict', theme: dark ? 'dark' : 'default', htmlLabels: false, flowchart: {htmlLabels: false, useMaxWidth: false}, sequence: {useMaxWidth: false}, maxTextSize: 20000, maxEdges: 300, suppressErrorRendering: true});
      const host = node('div'); host.style.cssText = 'position:fixed;left:-20000px;top:0;width:1200px;visibility:hidden'; document.body.append(host);
      try {
        const {svg} = await mermaid.render('fmi-diagram-' + ++serial, source, host);
        const parsed = new DOMParser().parseFromString(svg, 'image/svg+xml'), view = parsed.documentElement.getAttribute('viewBox')?.split(/[ ,]+/).map(Number);
        const ratio = view?.length === 4 && view[2] > 0 && view[3] > 0 ? view[3] / view[2] : 0.6;
        const documentHtml = '<!doctype html><html><head><meta http-equiv="Content-Security-Policy" content="default-src &#39;none&#39;; style-src &#39;unsafe-inline&#39;; base-uri &#39;none&#39;; form-action &#39;none&#39;"><style>html,body{margin:0;width:100%;height:100%;overflow:hidden;background:' + (dark ? '#1d2327' : '#fff') + '}body{display:flex;align-items:center;justify-content:center}svg{display:block;width:100%!important;height:100%!important;max-width:none!important}a{pointer-events:none}</style></head><body>' + svg + '</body></html>';
        const result = {documentHtml, ratio}; diagramCache.set(key, result); if (diagramCache.size > 30) diagramCache.delete(diagramCache.keys().next().value); return result;
      } finally { host.remove(); }
    }); diagramQueue = task.catch(() => {}); return task;
  }
  async function copy(text, trigger) {
    try { await navigator.clipboard.writeText(text); const label = trigger.textContent; trigger.textContent = __('コピーしました'); setTimeout(() => { if (trigger.isConnected) trigger.textContent = label; }, 1800); }
    catch (_) { trigger.textContent = __('コピーできませんでした'); }
  }
  function expand(content, title) {
    const dialog = node('dialog', 'fmi-answer-dialog'), heading = node('h2', '', title), close = button(__('閉じる'));
    const header = node('header'); header.append(heading, close); dialog.append(header, content); document.body.append(dialog); close.addEventListener('click', () => dialog.close()); dialog.addEventListener('close', () => dialog.remove()); dialog.showModal();
  }
  function render(target, source, streaming = false) {
    const text = String(source || '').slice(0, 200000);
    const fences = [];
    const priorFence = md.renderer.rules.fence;
    md.renderer.rules.fence = (tokens, index) => { const token = tokens[index]; fences.push({source: token.content, language: token.info.trim().split(/\s+/)[0].toLowerCase(), open: token.map?.[1] >= text.split('\n').length && !/^[ \t]*(?:`{3,}|~{3,})[ \t]*$/.test(text.split('\n').at(-1) || '')}); return '<div data-fmi-fence="' + (fences.length - 1) + '"></div>'; };
    try { target.innerHTML = md.render(text.replace(/(^|\n)([ \t]*(?:(?:[-+*]|\d+[.)])[ \t]+)?)(\*\*[^\n*]+\*\*)(?=[\p{L}\p{N}])/gu, '$1$2$3 ')); } finally { md.renderer.rules.fence = priorFence; }
    target.querySelectorAll('table').forEach((table) => {
      const wrap = node('div', 'fmi-answer-table'), controls = node('div', 'fmi-answer-code-header'); wrap.tabIndex = 0; wrap.setAttribute('role', 'region'); wrap.setAttribute('aria-label', __('表（横にスクロールできます）'));
      const trigger = button(__('表をコピー')); trigger.addEventListener('click', () => copy([...table.rows].map((row) => [...row.cells].map((cell) => cell.textContent.replace(/[\t\n]/g, ' ')).join('\t')).join('\n'), trigger)); controls.append(node('span', '', __('表')), trigger); table.before(controls, wrap); wrap.append(table);
    });
    target.querySelectorAll('[data-fmi-fence]').forEach((slot) => {
      const fence = fences[Number(slot.dataset.fmiFence)], header = node('div', 'fmi-answer-code-header'), copyCode = button(__('コピー'));
      copyCode.addEventListener('click', () => copy(fence.source, copyCode)); header.append(node('span', '', fence.language || __('コード')), copyCode);
      const code = node('pre', 'fmi-chat__code'), value = node('code', '', fence.source);
      if (fence.source.length <= 100000 && window.FourmixIntelligenceHighlight?.getLanguage(fence.language)) { try { value.innerHTML = window.FourmixIntelligenceHighlight.highlight(fence.source, {language: fence.language, ignoreIllegals: true}).value; } catch (_) {} }
      code.append(value); slot.className = 'fmi-answer-code'; slot.append(header, code);
      if (fence.language === 'mermaid') {
        const note = node('p', 'fmi-answer-note', streaming && fence.open ? __('図の続きを受信しています…') : __('図を描画しています…')); slot.append(note);
        if (streaming) { note.textContent = __('回答の完了後に図を表示します…'); return; }
        const retry = button(__('図を再表示')); retry.hidden = true; slot.append(retry);
        const draw = () => { retry.hidden = true; diagram(fence.source, matchMedia('(prefers-color-scheme: dark)').matches).then((value) => {
          if (!slot.isConnected) return;
          const frame = node('iframe', 'fmi-answer-diagram'); frame.setAttribute('sandbox', ''); frame.title = __('回答の図'); frame.srcdoc = value.documentHtml; frame.style.aspectRatio = String(1 / Math.max(0.2, Math.min(value.ratio, 2)));
          code.hidden = true; note.hidden = true; slot.querySelector('iframe')?.remove(); slot.append(frame);
          const zoom = button(__('図を拡大')); zoom.addEventListener('click', () => { const larger = frame.cloneNode(true); larger.className = 'fmi-answer-diagram-expanded'; expand(larger, __('回答の図')); }); header.append(zoom);
        }).catch((error) => { if (slot.isConnected) { note.textContent = error.message; retry.hidden = false; } }); };
        retry.addEventListener('click', draw); draw();
      }
    });
    if (!streaming && text) { const trigger = button(__('回答をコピー')); trigger.addEventListener('click', () => copy(text, trigger)); target.append(trigger); }
  }
  window.FourmixIntelligenceAnswer = {...config, render};
})();
