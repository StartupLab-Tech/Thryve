import { useState } from 'react';
import { send } from '../../lib/api';
import { Button } from '../ui/Button';

const show = (v) => (typeof v === 'string' ? v : JSON.stringify(v, null, 2));
const plain = (text) => (text ?? '').replace(/\*\*/g, '');

/**
 * What waits for the person: changes the AI wants to make, and an app's own permission question before it shows something
 * (Google asks before an agent reads mail other people sent). Nothing happens until the person answers.
 */
export default function PluginActions({ actions, onDecided, onError }) {
    const [busy, setBusy] = useState(null);
    const decide = async (a, verb, body) => {
        setBusy(a.id);
        try { onDecided(a, await send('POST', `/plugin-actions/${a.id}/${verb}`, body)); } catch (err) { onError(err, a); }
        setBusy(null);
    };
    if (!actions?.length) return null;
    return (
        <div className="cw-approvals" role="region" aria-label="Waiting for your answer">
            {actions.map((a) => (a.kind === 'access' ? (
                <div key={a.id} className="cw-approval">
                    <p className="cw-approval-title"><strong>{a.app ?? 'App'} asks for your permission</strong></p>
                    <p>{plain(a.message ?? a.summary)}</p>
                    <div className="cw-approval-btns">
                        {(a.choices ?? []).map((c, i) => (
                            <Button key={c.value} variant={i === 0 ? 'primary' : undefined} size="sm" disabled={busy === a.id} onClick={() => decide(a, 'approve', { choice: c.value })}>
                                {busy === a.id ? 'Working...' : c.label}
                            </Button>
                        ))}
                        <Button size="sm" disabled={busy === a.id} onClick={() => decide(a, 'deny')}>Don&apos;t allow</Button>
                    </div>
                </div>
            ) : (
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
            )))}
        </div>
    );
}
