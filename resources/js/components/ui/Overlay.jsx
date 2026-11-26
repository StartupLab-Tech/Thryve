import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import Icon from '../Icon';
import { Button } from './Button';

// Native <dialog>: focus trap, Esc to close and inert background come from the browser.
export function Dialog({ open, onClose, title, children, actions }) {
    const ref = useRef(null);
    const titleId = useId();
    useEffect(() => {
        const d = ref.current;
        if (open && !d.open) d.showModal();
        if (!open && d.open) d.close();
    }, [open]);
    return (
        <dialog ref={ref} className="sl-dialog" aria-labelledby={titleId} onClose={onClose} onClick={(e) => e.target === ref.current && onClose()}>
            <div className="sl-modal" style={{ margin: 'auto' }}>
                <h5 id={titleId}>{title}</h5>
                <div>{open && children}</div>
                <div className="row">{actions}</div>
            </div>
        </dialog>
    );
}

// Destructive actions always confirm.
export function ConfirmDialog({ open, onCancel, onConfirm, title, children, confirmLabel = 'Delete' }) {
    return (
        <Dialog open={open} onClose={onCancel} title={title}
            actions={<><Button onClick={onCancel}>Cancel</Button><Button variant="danger" onClick={onConfirm}>{confirmLabel}</Button></>}>
            <p>{children}</p>
        </Dialog>
    );
}

// Menu button + popup. Arrow keys move, Esc closes and returns focus, outside click closes.
// placement: 'below-end' (default, under the button, right edges aligned), 'right-end' (to the right of the button, bottoms
// aligned: for the left rail) or 'above-end' (above the button: for the phone tab bar). triggerClass / triggerContent let
// the shell restyle the button (avatar, rail icon with a caption) without a second menu component.
export function popupPosition(rect, placement) {
    if (placement === 'right-end') return { left: rect.right + 10, bottom: Math.max(8, window.innerHeight - rect.bottom) };
    if (placement === 'above-end') return { bottom: window.innerHeight - rect.top + 8, right: Math.max(8, window.innerWidth - rect.right) };

    return { top: rect.bottom + 6, right: Math.max(8, window.innerWidth - rect.right) };
}

// Where a menu goes so that it stays inside the window. `rect` is the button's box and `size` the menu's natural size.
// It opens where `placement` says when it fits there; if not, on the side with more room (below or above the button);
// if it fits on neither it is capped (`maxHeight`) and scrolls inside itself. Always at least `margin` px from every edge.
export function fitPopup(rect, size, placement, view = { width: window.innerWidth, height: window.innerHeight }, margin = 8) {
    const clamp = (v, lo, hi) => Math.max(lo, Math.min(v, hi));
    const maxLeft = Math.max(margin, view.width - size.width - margin);
    const minCap = 96; // never squeeze a menu below a few rows

    if (placement === 'right-end') {
        const room = view.height - 2 * margin;
        if (size.height > room) return { top: margin, left: clamp(rect.right + 10, margin, maxLeft), maxHeight: room };

        return { top: clamp(rect.bottom - size.height, margin, view.height - size.height - margin), left: clamp(rect.right + 10, margin, maxLeft), maxHeight: null };
    }

    const gap = placement === 'above-end' ? 8 : 6;
    const below = view.height - rect.bottom - gap - margin;
    const above = rect.top - gap - margin;
    const fitsBelow = size.height <= below;
    const fitsAbove = size.height <= above;
    const openAbove = placement === 'above-end' ? fitsAbove || (!fitsBelow && above > below) : !fitsBelow && (fitsAbove || above > below);
    const left = clamp(rect.right - size.width, margin, maxLeft);

    if (openAbove) {
        const height = fitsAbove ? size.height : Math.max(above, minCap);

        return { top: rect.top - gap - height, left, maxHeight: fitsAbove ? null : height };
    }

    return { top: rect.bottom + gap, left, maxHeight: fitsBelow ? null : Math.max(below, minCap) };
}

export function Menu({ label, icon = 'more', items, placement = 'below-end', triggerClass = 'sl-iconbtn', triggerContent = null }) {
    const [open, setOpen] = useState(false);
    const wrap = useRef(null);
    const pop = useRef(null);
    const trigger = useRef(null);
    const toggle = () => setOpen(!open);

    // Fixed positioning lets the popup escape scrolling table wrappers and clipped cards. It is placed from its measured size,
    // before the browser paints, so it never shows in the wrong place.
    const place = () => {
        const el = pop.current;
        const btn = trigger.current;
        if (!el || !btn) return;
        el.style.maxHeight = '';
        const p = fitPopup(btn.getBoundingClientRect(), { width: el.offsetWidth, height: el.offsetHeight }, placement);
        el.style.top = `${p.top}px`;
        el.style.left = `${p.left}px`;
        el.style.maxHeight = p.maxHeight ? `${p.maxHeight}px` : '';
        el.style.visibility = 'visible';
    };
    useLayoutEffect(() => { if (open) place(); }, [open]); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        if (!open) return;
        pop.current?.querySelector('[role=menuitem]')?.focus({ preventScroll: true });
        const away = (e) => !wrap.current.contains(e.target) && !pop.current?.contains(e.target) && setOpen(false);
        // Scrolling the page moves the menu with its button (and closes it once the button is out of sight). Scrolling inside
        // the menu itself, for a tall menu in a short window, leaves it alone.
        const follow = (e) => {
            if (pop.current?.contains(e.target)) return;
            const r = trigger.current?.getBoundingClientRect();
            if (!r || r.bottom < 0 || r.top > window.innerHeight) return setOpen(false);
            place();
        };
        const resize = () => place();
        document.addEventListener('mousedown', away);
        window.addEventListener('scroll', follow, true);
        window.addEventListener('resize', resize);
        return () => {
            document.removeEventListener('mousedown', away);
            window.removeEventListener('scroll', follow, true);
            window.removeEventListener('resize', resize);
        };
    }, [open]); // eslint-disable-line react-hooks/exhaustive-deps
    const onKey = (e) => {
        const els = [...(pop.current?.querySelectorAll('[role=menuitem]') ?? [])];
        const i = els.indexOf(document.activeElement);
        if (e.key === 'Escape') { setOpen(false); trigger.current.focus(); }
        else if (e.key === 'ArrowDown') { e.preventDefault(); els[(i + 1) % els.length]?.focus(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); els[(i - 1 + els.length) % els.length]?.focus(); }
    };
    return (
        <div ref={wrap} style={{ position: 'relative', display: 'inline-block' }} onKeyDown={open ? onKey : undefined}>
            <button ref={trigger} type="button" className={triggerClass} aria-label={label} aria-haspopup="menu" aria-expanded={open} onClick={toggle}>
                {triggerContent ?? <Icon name={icon} />}
            </button>
            {open && createPortal(
                // Rendered on the page itself: the navigation bar blurs its background, which would otherwise make "fixed" mean "inside the bar".
                <div ref={pop} className="sl-menu" role="menu" style={{ position: 'fixed', top: 0, left: 0, visibility: 'hidden', zIndex: 40 }}>
                    {items.map((it, i) => it === '-' ? <hr key={i} /> : (
                        <button key={it.label} type="button" role="menuitem" className={`it ${it.danger ? 'danger' : ''}`} disabled={it.disabled} title={it.hint} onClick={() => { setOpen(false); trigger.current.focus(); it.onSelect?.(); }}>
                            {it.icon && <Icon name={it.icon} />}{it.label}
                        </button>
                    ))}
                </div>,
                document.body,
            )}
        </div>
    );
}
