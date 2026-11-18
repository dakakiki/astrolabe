/**
 * Tooltips for buttons: `v-tip="t('tips.clients.addConsultation')"` (registered in app.js and
 * portal/main.js). One bubble for the whole page, appended to <body> so no card or scroll box
 * clips it. It shows after a short pause under the mouse and at once on keyboard focus, hides on
 * leave, blur, click, Escape, scroll and resize, and is linked with aria-describedby while it
 * shows. Touch screens get none (no hover); the button's own words still say what it does.
 */

const DELAY = 300;
const GAP = 8;
const MARGIN = 8;
const ID = 'app-tooltip';

/**
 * Where the bubble goes: centred above the element, below it when there is no room above, and
 * kept inside the viewport sideways. Pure, so it is tested without a browser.
 *
 * @param {{top: number, bottom: number, left: number, width: number}} anchor  element's box
 * @param {{width: number, height: number}} size  the bubble's size
 * @param {{width: number, height: number}} viewport
 */
export function placeTooltip(anchor, size, viewport) {
    let side = 'top';
    let top = anchor.top - GAP - size.height;
    if (top < MARGIN && anchor.bottom + GAP + size.height <= viewport.height - MARGIN) {
        side = 'bottom';
        top = anchor.bottom + GAP;
    }

    const centred = anchor.left + anchor.width / 2 - size.width / 2;
    const left = Math.max(MARGIN, Math.min(centred, viewport.width - MARGIN - size.width));

    return { top: Math.max(MARGIN, top), left, side };
}

let bubble = null;
let current = null;
let timer = null;

function element() {
    if (!bubble) {
        bubble = document.createElement('div');
        bubble.id = ID;
        bubble.className = 'tooltip';
        bubble.setAttribute('role', 'tooltip');
        bubble.hidden = true;
        document.body.appendChild(bubble);
    }

    return bubble;
}

function show(el) {
    clearTimeout(timer);
    const text = el._tip?.text;
    if (!text || !el.isConnected) return;

    const tip = element();
    tip.textContent = text;
    tip.hidden = false;
    tip.dataset.side = 'top';

    const place = placeTooltip(
        el.getBoundingClientRect(),
        { width: tip.offsetWidth, height: tip.offsetHeight },
        { width: document.documentElement.clientWidth, height: window.innerHeight },
    );
    tip.style.top = `${place.top}px`;
    tip.style.left = `${place.left}px`;
    tip.dataset.side = place.side;

    if (current && current !== el) unlink(current);
    current = el;
    el._tip.describedBy = el.getAttribute('aria-describedby');
    el.setAttribute('aria-describedby', [el._tip.describedBy, ID].filter(Boolean).join(' '));

    document.addEventListener('keydown', onKey, true);
    window.addEventListener('scroll', hide, true);
    window.addEventListener('resize', hide);
}

function unlink(el) {
    if (!el._tip) return;
    if (el._tip.describedBy) el.setAttribute('aria-describedby', el._tip.describedBy);
    else el.removeAttribute('aria-describedby');
}

function hide() {
    clearTimeout(timer);
    if (bubble) bubble.hidden = true;
    if (current) unlink(current);
    current = null;
    document.removeEventListener('keydown', onKey, true);
    window.removeEventListener('scroll', hide, true);
    window.removeEventListener('resize', hide);
}

function onKey(event) {
    if (event.key === 'Escape') hide();
}

function textOf(value) {
    return typeof value === 'string' ? value.trim() : '';
}

export const tip = {
    mounted(el, binding) {
        const state = { text: textOf(binding.value), describedBy: null };
        state.enter = (event) => {
            if (event.pointerType !== 'mouse') return;
            clearTimeout(timer);
            timer = setTimeout(() => show(el), DELAY);
        };
        state.leave = () => {
            if (current === el) hide();
            else clearTimeout(timer);
        };
        state.focus = () => {
            if (el.matches(':focus-visible')) show(el);
        };
        el._tip = state;
        el.addEventListener('pointerenter', state.enter);
        el.addEventListener('pointerleave', state.leave);
        el.addEventListener('pointerdown', state.leave);
        el.addEventListener('focus', state.focus);
        el.addEventListener('blur', state.leave);
    },

    updated(el, binding) {
        if (!el._tip) return;
        el._tip.text = textOf(binding.value);
        if (current === el) {
            if (el._tip.text) element().textContent = el._tip.text;
            else hide();
        }
    },

    beforeUnmount(el) {
        if (current === el) hide();
        const state = el._tip;
        if (!state) return;
        el.removeEventListener('pointerenter', state.enter);
        el.removeEventListener('pointerleave', state.leave);
        el.removeEventListener('pointerdown', state.leave);
        el.removeEventListener('focus', state.focus);
        el.removeEventListener('blur', state.leave);
        delete el._tip;
    },
};
