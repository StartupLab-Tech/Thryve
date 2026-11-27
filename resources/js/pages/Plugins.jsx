import { useCallback, useEffect, useState } from 'react';
import { useAuth } from '../lib/auth';
import { useRouter } from '../lib/router';
import { get, send } from '../lib/api';
import { formatDateTime } from '../lib/util';
import { Button } from '../components/ui/Button';
import { ConfirmDialog, Menu } from '../components/ui/Overlay';
import { Alert, EmptyState, ErrorState, Pill, Skeleton, useToast } from '../components/ui/Feedback';
import Icon from '../components/Icon';
import PluginLogo from '../components/PluginLogo';

const PROBLEMS = {
    denied: 'You did not allow access, so nothing was connected.',
    failed: 'The app could not be connected. Please try again.',
    not_available: 'That app is not available yet. Ask a Super Admin to set it up.',
};

/** The apps a person can connect, like a plugin directory. Each person connects their own account; the AI can only act as them. */
export default function Plugins() {
    const { user } = useAuth();
    const { search, navigate } = useRouter();
    const toast = useToast();
    const [apps, setApps] = useState(null);
    const [error, setError] = useState(null);
    const [problem, setProblem] = useState(null);
    const [confirm, setConfirm] = useState(null);
    const [find, setFind] = useState('');
    const load = useCallback(() => { setError(null); get('/plugins').then((d) => setApps(d.apps)).catch(setError); }, []);
    useEffect(load, [load]);

    // The app sends the browser back here with ?connected=github or ?error=...
    useEffect(() => {
        const q = new URLSearchParams(search ?? '');
        if (q.get('connected')) toast('Connected');
        if (q.get('error')) setProblem(PROBLEMS[q.get('error')] ?? PROBLEMS.failed);
        if (q.has('connected') || q.has('error')) navigate('/plugins', { replace: true });
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    const disconnect = async () => {
        const a = confirm;
        setConfirm(null);
        try { await send('DELETE', `/plugins/${a.slug}/connection`); toast(`${a.name} disconnected`); load(); } catch (err) { toast(err.message); }
    };
    const connect = (a) => { window.location.href = `/api/plugins/${a.slug}/connect`; };

    if (user.viewing_as_by) return <Alert tone="info" title="Not available while viewing as someone">Connections belong to each person. Stop viewing as them to manage your own.</Alert>;
    if (error) return <ErrorState onRetry={load}>{error.message}</ErrorState>;
    if (!apps) return <Skeleton lines={4} />;

    const q = find.trim().toLowerCase();
    const shown = apps.filter((a) => !q || `${a.name} ${a.description}`.toLowerCase().includes(q));
    const isConnected = (a) => a.connected && a.status === 'active';
    const groups = [['Connected', shown.filter(isConnected)], ['Available', shown.filter((a) => !isConnected(a))]].filter(([, list]) => list.length > 0);

    return (
        <>
            <p>Connect an app so the AI can use it for you. It only ever acts as you, and it asks you to approve every change before anything is made.</p>
            {problem && <Alert tone="danger" title="Not connected">{problem}</Alert>}
            <div className="pl-search">
                <Icon name="search" />
                <input type="search" className="sl-input" aria-label="Search plugins" placeholder="Search plugins" value={find} onChange={(e) => setFind(e.target.value)} />
            </div>
            {groups.length === 0 && <EmptyState icon="search" title="No plugins found">Try a different word.</EmptyState>}
            {groups.map(([title, list]) => (
                <section key={title} className="pl-group" aria-label={title}>
                    <h3>{title}</h3>
                    <ul className="pl-grid">
                        {list.map((a) => (
                            <li key={a.slug} className="pl-row">
                                <PluginLogo slug={a.slug} name={a.name} />
                                <div className="pl-text">
                                    <strong>{a.name}</strong>
                                    <span>{isConnected(a) ? `Connected${a.login ? ` as ${a.login}` : ''}${a.connected_at ? ` · ${formatDateTime(a.connected_at)}` : ''}` : a.description}</span>
                                    {a.connected && a.status !== 'active' && <Pill tone="warning">Needs reconnecting</Pill>}
                                </div>
                                <div className="pl-act">
                                    {isConnected(a)
                                        ? <Menu label={`More for ${a.name}`} items={[{ label: 'Disconnect', onSelect: () => setConfirm(a) }]} />
                                        : a.available
                                            ? <Button variant="primary" size="sm" onClick={() => connect(a)}>{a.connected ? 'Reconnect' : 'Connect'}</Button>
                                            : <Pill>Not set up yet</Pill>}
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>
            ))}
            <ConfirmDialog open={!!confirm} onCancel={() => setConfirm(null)} onConfirm={disconnect} title={`Disconnect ${confirm?.name ?? ''}?`} confirmLabel="Disconnect">
                The AI will no longer be able to use your {confirm?.name} account. Changes waiting for approval will not run.
            </ConfirmDialog>
        </>
    );
}
