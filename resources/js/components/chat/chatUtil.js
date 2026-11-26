import { useEffect, useState } from 'react';

export const fmt = (n) => Number(n).toLocaleString();
export const sizeText = (b) => (b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);
export const extOf = (name) => (name.split('.').pop() || '').toLowerCase();
export const firstName = (name = '') => name.trim().split(/\s+/)[0] || '';

// "12 min", "2 h", "Yesterday", "Mon", "Nov 12": short and easy to scan in a list.
// "Yesterday" and the weekday follow the Philippine calendar (PHT, UTC+8), whatever the viewer's own clock says.
const MANILA = 'Asia/Manila';
const manilaDay = (x) => x.toLocaleDateString('en-CA', { timeZone: MANILA }); // YYYY-MM-DD
export function ago(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const seconds = Math.max(0, (Date.now() - d.getTime()) / 1000);
    const days = Math.round((Date.parse(`${manilaDay(new Date())}T00:00:00Z`) - Date.parse(`${manilaDay(d)}T00:00:00Z`)) / 86400000);
    if (seconds < 60) return 'Now';
    if (seconds < 3600) return `${Math.max(1, Math.floor(seconds / 60))} min`;
    if (days <= 0) return `${Math.floor(seconds / 3600)} h`;
    if (days === 1) return 'Yesterday';
    if (days < 7) return d.toLocaleDateString(undefined, { weekday: 'short', timeZone: MANILA });
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', timeZone: MANILA });
}

// Each assistant keeps the same colour everywhere: its position in the list the server returns (default first).
export const hue = (agents, id) => {
    const i = agents ? agents.findIndex((a) => a.id === id) : -1;
    return `h${i >= 0 ? i % 5 : 0}`;
};
export const initial = (name = '') => name.trim()[0]?.toUpperCase() ?? '?';

export const SUGGESTIONS = [
    'Draft a polite follow-up email to a client',
    'Summarize these meeting notes into action items',
    'Explain this spreadsheet formula in plain words',
    'Help me outline a one-page project update',
];

// Re-renders the caller every `ms` so relative times ("5 min") keep moving without any new data arriving. It also
// refreshes when the tab becomes visible again, since timers are throttled in background tabs.
export function useNow(ms = 30000) {
    const [, tick] = useState(0);
    useEffect(() => {
        const bump = () => tick((n) => n + 1);
        const t = setInterval(bump, ms);
        const vis = () => { if (document.visibilityState === 'visible') bump(); };
        document.addEventListener('visibilitychange', vis);
        return () => { clearInterval(t); document.removeEventListener('visibilitychange', vis); };
    }, [ms]);
}
