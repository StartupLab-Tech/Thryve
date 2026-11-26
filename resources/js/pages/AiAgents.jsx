import { useCallback, useEffect, useState } from 'react';
import { api, get, send } from '../lib/api';
import { addDays, formatDateTime, manilaToday } from '../lib/util';
import { Table } from '../components/ui/Layout';
import { Dialog } from '../components/ui/Overlay';
import { Button } from '../components/ui/Button';
import { Checkbox, TextField } from '../components/ui/Form';
import { Alert, EmptyState, ErrorState, Pill, Skeleton, useToast } from '../components/ui/Feedback';

const fieldErrors = (err) => Object.fromEntries(Object.entries(err.details ?? {}).map(([k, v]) => [k, v[0]]));
const num = (n) => Number(n ?? 0).toLocaleString();

export function Usage() {
    const [range, setRange] = useState({ from: addDays(manilaToday(), -6), to: manilaToday() });
    const [rows, setRows] = useState(null);
    const [error, setError] = useState(null);
    const load = useCallback(() => { setError(null); api(`/usage/summary?from=${range.from}&to=${range.to}`).then((r) => setRows(r.data)).catch(setError); }, [range]);
    useEffect(load, [load]);
    return (
        <>
            <div className="grid items-end gap-3 sm:grid-cols-3">
                <TextField label="From" type="date" value={range.from} max={range.to} onChange={(e) => setRange({ ...range, from: e.target.value })} />
                <TextField label="To" type="date" value={range.to} min={range.from} onChange={(e) => setRange({ ...range, to: e.target.value })} />
            </div>
            {error ? <ErrorState onRetry={load}>{error.code === 'validation_failed' ? 'Check the dates.' : error.message}</ErrorState> : !rows ? <Skeleton lines={4} /> : rows.length === 0 ? <EmptyState icon="chip" title="No usage in this period">Usage appears here once people use the chatbots.</EmptyState> : (
                <Table caption="Usage by agent" columns={[{ key: 'n', label: 'Agent' }, { key: 'r', label: 'Requests', num: true }, { key: 'f', label: 'Failed', num: true }, { key: 't', label: 'Tokens', num: true }, { key: 'l', label: 'Avg speed', num: true }]}
                    rows={rows.map((r) => ({
                        n: <strong>{r.name}</strong>, r: num(r.requests), f: num(r.failures),
                        t: <span>{num(r.input_tokens + r.output_tokens)}{r.estimated_requests > 0 && <><br /><span className="sl-help">{r.estimated_requests} estimated</span></>}</span>,
                        l: r.avg_latency_ms === null ? '-' : `${num(r.avg_latency_ms)} ms`,
                    }))} />
            )}
        </>
    );
}

const BLANK = { label: '', hint: '', supports_images: false };

/** The AI models people may choose from. The detected list is read live from Hermes; only approved, enabled models reach employees. */
export function Models({ canManage }) {
    const toast = useToast();
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [dialog, setDialog] = useState(null); // { choice } to edit, or { detected } to approve
    const load = useCallback(() => { setError(null); get('/model-choices').then(setData).catch(setError); }, []);
    useEffect(load, [load]);
    const change = async (c, body, done) => {
        try { await send('PATCH', `/model-choices/${c.id}`, body); toast(done); load(); } catch (err) { toast(err.message); }
    };
    if (error) return <ErrorState onRetry={load}>{error.message}</ErrorState>;
    if (!data) return <Skeleton lines={4} />;
    const fresh = data.detected.filter((m) => !m.approved);
    return (
        <>
            <p>Choose which AI models people can pick in the chat box. The list below is read live from the AI server of {data.agent.name}. Nothing is shown to employees until you turn a model on.</p>
            {!data.live && <Alert tone="warning" title="The AI server could not be reached">The list of available models is unavailable right now. Approved models stay as they are, but people see no model choice until it is reachable again.</Alert>}
            {data.choices.length === 0 ? <EmptyState icon="file" title="No models approved yet">{data.live ? 'Approve a model from the detected list below.' : 'Try again when the AI server is reachable.'}</EmptyState> : (
                <Table caption="Approved models" columns={[{ key: 'm', label: 'Model' }, { key: 'h', label: 'Hint' }, { key: 's', label: 'Status' }, ...(canManage ? [{ key: 'a', label: 'Actions' }] : [])]}
                    rows={data.choices.map((c) => ({
                        m: <><strong>{c.label}</strong><br /><small className="sl-help">{c.model} · {c.provider}{c.supports_images ? ' · reads images' : ''}</small></>,
                        h: c.hint || '–',
                        s: <span className="flex flex-wrap gap-2">{c.is_enabled ? <Pill tone="success">On</Pill> : <Pill>Off</Pill>}{c.is_default && <Pill tone="info">Default</Pill>}{c.available === false && <Pill tone="danger">Not listed now</Pill>}</span>,
                        a: <span className="flex flex-wrap gap-2">
                            <Button onClick={() => change(c, { is_enabled: !c.is_enabled }, c.is_enabled ? 'Model turned off' : 'Model turned on')}>{c.is_enabled ? 'Turn off' : 'Turn on'}</Button>
                            {c.is_enabled && !c.is_default && <Button onClick={() => change(c, { is_default: true }, 'Default model set')}>Make default</Button>}
                            <Button onClick={() => setDialog({ choice: c })}>Edit</Button>
                        </span>,
                    }))} />
            )}
            {data.live && (
                <>
                    <h3>Detected, not approved</h3>
                    {fresh.length === 0 ? <p className="sl-help">Every model the AI server lists is already approved.</p> : (
                        <Table caption="Detected models" columns={[{ key: 'm', label: 'Model' }, { key: 'p', label: 'Provider' }, ...(canManage ? [{ key: 'a', label: '' }] : [])]}
                            rows={fresh.map((m) => ({ m: m.model, p: m.provider_name, a: <Button onClick={() => setDialog({ detected: m })}>Approve</Button> }))} />
                    )}
                </>
            )}
            {dialog && <ModelDialog {...dialog} onClose={() => setDialog(null)} onSaved={() => { setDialog(null); load(); }} />}
        </>
    );
}

function ModelDialog({ choice, detected, onClose, onSaved }) {
    const toast = useToast();
    const [form, setForm] = useState(choice ? { label: choice.label, hint: choice.hint ?? '', supports_images: choice.supports_images } : { ...BLANK, label: detected.model });
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });
    const submit = async (e) => {
        e.preventDefault();
        setBusy(true); setErrors({});
        const body = { label: form.label.trim(), hint: form.hint.trim() || null, supports_images: form.supports_images };
        try {
            if (choice) await send('PATCH', `/model-choices/${choice.id}`, body);
            else await send('POST', '/model-choices', { ...body, provider: detected.provider, model: detected.model });
            toast(choice ? 'Model saved' : 'Model approved. Turn it on to show it in the chat box.');
            onSaved();
        } catch (err) {
            setErrors(Object.keys(err.details ?? {}).length ? fieldErrors(err) : { label: err.message });
            setBusy(false);
        }
    };
    return (
        <Dialog open title={choice ? `Edit ${choice.model}` : `Approve ${detected.model}`} onClose={onClose}
            actions={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" type="submit" form="model-form" disabled={busy || !form.label.trim()}>{busy ? 'Saving...' : 'Save'}</Button></>}>
            <form id="model-form" onSubmit={submit} className="grid gap-4" noValidate>
                <TextField label="Name people see" value={form.label} onChange={set('label')} error={errors.label ?? errors.model} maxLength={100} help="For example: Fast, or Best for hard work." />
                <TextField label="One-line hint (optional)" value={form.hint} onChange={set('hint')} error={errors.hint} maxLength={200} help="Shown next to the name in the model picker." />
                <Checkbox label="This model can read images" checked={form.supports_images} onChange={(e) => setForm({ ...form, supports_images: e.target.checked })} hint="Hermes does not report this, so set it yourself. A model without it cannot be used in a chat that has an image." />
            </form>
        </Dialog>
    );
}
