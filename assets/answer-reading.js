(() => {
'use strict';
/**
 * 新しい回答の先頭を一度だけ表示し、追記や再描画では読書位置を保持する。
 * 余白は回答の後ろに置き、商品カード等と本文の間を分断しない。
 */
function createAnswerReader(viewport, content = viewport) {
  const view = viewport.ownerDocument.defaultView;
  const spacer = viewport.ownerDocument.createElement('div');
  spacer.dataset.answerReadingSpace = '';
  spacer.setAttribute('aria-hidden', 'true');
  spacer.style.flexShrink = '0';
  spacer.hidden = true;
  const originalAnchor = viewport.style.overflowAnchor;
  viewport.style.overflowAnchor = 'none';
  let target = null, pending = true, frame = 0, disposed = false, revision = 0;
  const manual = event => {
    if (event.type !== 'keydown' || ['ArrowUp', 'ArrowDown', 'PageUp', 'PageDown', 'Home', 'End', ' '].includes(event.key)) { pending = false; revision++; }
  };
  for (const type of ['wheel', 'touchstart', 'pointerdown', 'keydown']) viewport.addEventListener(type, manual, { passive: true });
  function update(next = target, position) {
    if (disposed || !next || !viewport.contains(next)) return;
    if (target !== next) {
      if (target && target !== viewport && target !== content) observer?.unobserve(target);
      target = next;
      observer?.observe(target);
    }
    if (!viewport.clientHeight || !target.getClientRects().length) return;
    const top = position?.revision === revision ? position.top : Number.isFinite(position) ? position : viewport.scrollTop;
    spacer.hidden = true;
    spacer.style.height = '0px';
    if (spacer.parentElement !== content || spacer !== content.lastElementChild) content.append(spacer);
    const start = Math.max(0, viewport.scrollTop + target.getBoundingClientRect().top - viewport.getBoundingClientRect().top - viewport.clientTop - 12);
    const space = Math.max(0, Math.ceil(start + viewport.clientHeight - viewport.scrollHeight));
    if (space) { spacer.style.height = space + 'px'; spacer.hidden = false; }
    viewport.scrollTop = pending ? start : top;
    if (pending) revision++;
    pending = false;
  }
  const schedule = () => {
    if (disposed || frame) return;
    frame = view.requestAnimationFrame(() => { frame = 0; update(); });
  };
  const observer = typeof view.ResizeObserver === 'function' ? new view.ResizeObserver(schedule) : null;
  observer?.observe(viewport);
  if (content !== viewport) observer?.observe(content);
  return {
    capture() { return { top: viewport.scrollTop, revision }; },
    begin() {
      revision++;
      if (frame) view.cancelAnimationFrame(frame);
      frame = 0;
      if (target && target !== viewport && target !== content) observer?.unobserve(target);
      target = null; pending = true;
      spacer.hidden = true; spacer.style.height = '0px';
    },
    update,
    hold() { pending = false; },
    reset() { this.begin(); pending = false; },
    dispose() {
      if (disposed) return;
      disposed = true;
      if (frame) view.cancelAnimationFrame(frame);
      observer?.disconnect();
      for (const type of ['wheel', 'touchstart', 'pointerdown', 'keydown']) viewport.removeEventListener(type, manual);
      spacer.remove();
      viewport.style.overflowAnchor = originalAnchor;
    },
  };
}

window.FourmixIntelligenceAnswerReader = { create: createAnswerReader };
})();
