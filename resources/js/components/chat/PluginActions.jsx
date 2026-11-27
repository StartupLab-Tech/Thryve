import { useState } from 'react';
import { send } from '../../lib/api';
import { Button } from '../ui/Button';

const show = (v) => (typeof v === 'string' ? v : JSON.stringify(v, null, 2));

/** Changes the AI wants to make. Nothing happens until the person approves; the exact details are one click away. */
export default function PluginActions({ actions, onDecided, onError }) {
    const [busy, setBusy] = useState(null);
    const decide = async (a, verb) => {
        setBusy(a.id);
        try { onDecided(a, await send('POST', `/plugin-actions/${a.id}/${verb}`)); } catch (err) { onError(err, a); }
        setBusy(null);
    };
    if (!actions?.length) return null;
    return (
        <div className="cw-approvals" role="region" aria-label="Changes waiting for your approval">
            {actions.map((a) => (
                <div key={a.id} className="cw-approval">
                    <p className="cw-approval-title"><strong>{a.app ?? 'App'} change waiting for you</strong></p>
                    <p>{a.summary}</p>
                    <details>
                        <summary>Details</summary>
                        <dl>{Object.entries(a.arguments ?? {}).filter(([k]) => !k.startsWith('_')).map(([k, v]) => <div key={k}><dt>{k}</dt><dd>{show(v)}</dd></div>)}</dl>
                    </details>
                    <div className="cw-approval-btns">
                        <Button variant="primary" size="sm" disabled={busy === a.id} onClick={() => decide(a, 'approve')}>{busy === a.id ? 'Working...' : 'Approve'}</Button>
                        <Button size="sm" disabled={busy === a.id} onClick={() => decide(a, 'deny')}>Deny</Button>
                    </div>
                </div>
            ))}
        </div>
    );
}
