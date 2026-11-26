export const qs = (obj) => {
    const p = new URLSearchParams();
    Object.entries(obj).forEach(([k, v]) => { if (v !== '' && v !== null && v !== undefined) p.set(k, v); });
    const s = p.toString();
    return s ? `?${s}` : '';
};

// Readable random password for admins to hand over; ambiguous characters (0/O, 1/l/I) are left out.
export function generatePassword(length = 16) {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    const bytes = crypto.getRandomValues(new Uint32Array(length));
    return Array.from(bytes, (b) => chars[b % chars.length]).join('');
}

// All validation messages from an ApiError as one list, or the plain message.
export function errorText(err) {
    // Only plain messages: some errors carry extra data (such as the conversation) in `details`.
    const details = Object.values(err?.details ?? {}).flat().filter((v) => typeof v === 'string');
    return details.length ? details.join(' ') : err?.message ?? 'Something went wrong.';
}

// Times are stored in UTC and shown in Philippine time (PHT, UTC+8, the same as Singapore), whatever the viewer's own clock says.
const MANILA = 'Asia/Manila';
// Raw database values have no zone ("2026-11-22 03:00:00"), which a browser would read as its own local time: mark them UTC.
const asUtc = (v) => (typeof v === 'string' && /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/.test(v) ? `${v.replace(' ', 'T')}Z` : v);
export const formatManilaDateTime = (iso, empty = 'Never') => (iso ? new Date(asUtc(iso)).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short', timeZone: MANILA }) : empty);
export const formatDateTime = (iso) => formatManilaDateTime(iso);

export const formatManilaTime = (iso) => (iso ? new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', timeZone: MANILA }) : '-');
export const formatManilaDate = (ymd) => new Date(`${ymd}T00:00:00+08:00`).toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric', timeZone: MANILA });
export const manilaToday = () => new Date().toLocaleDateString('en-CA', { timeZone: MANILA }); // YYYY-MM-DD
export const addDays = (ymd, n) => new Date(new Date(`${ymd}T00:00:00Z`).getTime() + n * 86400000).toISOString().slice(0, 10);

// 3725 seconds -> "1h 02m"
export function formatDuration(seconds) {
    const s = Math.max(0, Math.round(seconds || 0));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    return h ? `${h}h ${String(m).padStart(2, '0')}m` : `${m}m`;
}
