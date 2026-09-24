import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { ar } from './resources';

const attributes = ['placeholder', 'title', 'aria-label'] as const;
const textState = new WeakMap<Node, { original: string; localized: string }>();

function localize(root: ParentNode, arabic: boolean) {
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
  let node: Node | null;
  while ((node = walker.nextNode())) {
    const parent = node.parentElement;
    if (!parent || parent.closest('[data-i18n-skip],script,style')) continue;
    const current = node.textContent ?? '';
    const previous = textState.get(node);
    const original = previous && current === previous.localized ? previous.original : current;
    const key = original.trim();
    if (key && ar[key]) { const next = arabic ? original.replace(key, ar[key]) : original; textState.set(node, { original, localized: next }); if (current !== next) node.textContent = next; }
  }
  root.querySelectorAll<HTMLElement>('*').forEach(element => attributes.forEach(attribute => {
    const current = element.getAttribute(attribute); if (current === null) return;
    const marker = `data-i18n-original-${attribute}`;
    const original = element.getAttribute(marker) ?? current;
    if (!element.hasAttribute(marker)) element.setAttribute(marker, original);
    const next = arabic && ar[original] ? ar[original] : original;
    if (current !== next) element.setAttribute(attribute, next);
  }));
}

export function LegacyLocalizationBridge() {
  const { i18n } = useTranslation();
  useEffect(() => {
    const arabic = i18n.resolvedLanguage === 'ar';
    let queued = false;
    const run = () => { queued = false; localize(document.body, arabic); };
    run();
    const observer = new MutationObserver(() => { if (!queued) { queued = true; queueMicrotask(run); } });
    observer.observe(document.body, { childList: true, subtree: true, characterData: true });
    return () => observer.disconnect();
  }, [i18n.resolvedLanguage]);
  return null;
}
