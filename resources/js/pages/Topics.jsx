import { useCallback, useEffect, useState } from 'react';
import { get, send } from '../lib/api';
import { errorText } from '../lib/util';
import { Button } from '../components/ui/Button';
import { TextField } from '../components/ui/Form';
import { Alert, EmptyState, ErrorState, Pill, Skeleton, useToast } from '../components/ui/Feedback';
import { Table } from '../components/ui/Layout';
import { Dialog } from '../components/ui/Overlay';
import { Panel } from '../components/reports/ExpandedReports';

const fieldErrors = (err) => Object.fromEntries(Object.entries(err.details ?? {}).map(([k, v]) => [k, v[0]]));

function TopicForm({ topic, onClose, onSaved }) {
    const [form, setForm] = useState({ name: topic?.name ?? '', description: topic?.description ?? '', priority: topic?.priority ?? '' });
    const [errors, setErrors] = useState({});
    const [formError, setFormError] = useState(null);
    const [busy, setBusy] = useState(false);
    const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });
    const submit = async (e) => {
        e.preventDefault();
        setBusy(true); setErrors({}); setFormError(null);
        try {
            const body = { name: form.name.trim(), description: form.description.trim() };
            if (form.priority !== '') body.priority = Number(form.priority);
            await send(topic ? 'PATCH' : 'POST', topic ? `/analysis/topics/${topic.id}` : '/analysis/topics', body);
            onSaved(!topic);
        } catch (err) {
            if (Object.keys(err.details ?? {}).length) setErrors(fieldErrors(err)); else setFormError(err.message);
            setBusy(false);
        }
    };
    return (
        <Dialog open title={topic ? 'Edit topic' : 'Add a topic'} onClose={onClose}
            actions={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" type="submit" form="topic-form" disabled={busy || !form.name || !form.description}>{busy ? 'Saving...' : 'Save'}</Button></>}>
            <form id="topic-form" onSubmit={submit} className="grid gap-4" noValidate>
                {formError && <Alert tone="danger" title="Could not save">{formError}</Alert>}
                <TextField label="Name" value={form.name} onChange={set('name')} error={errors.name} maxLength={80} disabled={topic?.is_fallback} placeholder="Customer support replies"
                    help={topic?.is_fallback ? 'This topic is the fallback and keeps its name.' : 'Shown in the reports.'} />
                <TextField label="What it covers" value={form.description} onChange={set('description')} error={errors.description} maxLength={200} placeholder="Answering customer questions by email or chat"
                    help="The Report agent reads this to decide when the topic fits a conversation, so say what kind of work belongs here." />
                <TextField label="Order" type="number" min="0" max="65535" value={form.priority} onChange={set('priority')} error={errors.priority} help="Lower numbers come first. Leave empty to add it at the end." />
            </form>
        </Dialog>
    );
}

// The list the Report agent chooses from, shown at the bottom of the Tasks and topics report. Admins and Super Admins can
// change it; the server enforces that. `onChanged` lets the report above refresh after a topic is added, edited or switched.
export function TopicList({ onChanged }) {
    const toast = useToast();
    const [topics, setTopics] = useState(null);
    const [error, setError] = useState(null);
    const [form, setForm] = useState(null); // {topic?}
    const [busyId, setBusyId] = useState(null);

    const load = useCallback(() => get('/analysis/topics').then((r) => { setTopics(r); setError(null); }).catch((e) => setError(errorText(e))), []);
    useEffect(() => { load(); }, [load]);
    const changed = async () => { await load(); onChanged?.(); };

    const toggle = async (t) => {
        setBusyId(t.id);
        try { await send('PATCH', `/analysis/topics/${t.id}`, { is_active: !t.is_active }); toast(t.is_active ? 'Topic turned off' : 'Topic turned on'); await changed(); } catch (e) { toast(errorText(e)); } finally { setBusyId(null); }
    };

    return (
        <div id="topic-list">
            <Panel title="Topic list" subtitle="Each analyzed conversation gets one to three of these topics. The Report agent sees only the topics that are on, in this order, and changes apply from the next analysis. The filters above do not change this list."
                action={<Button variant="primary" icon="plus" onClick={() => setForm({})}>Add topic</Button>}>
                {error ? <ErrorState onRetry={load}>{error}</ErrorState> : !topics ? <Skeleton lines={4} /> : topics.length === 0 ? <EmptyState icon="layers" title="No topics yet" /> : (
                    <Table caption="Topics" columns={[{ key: 'topic', label: 'Topic' }, { key: 'order', label: 'Order', num: true }, { key: 'uses', label: 'All conversations', num: true }, { key: 'actions', label: 'Actions' }]}
                        rows={topics.map((t) => ({
                            topic: <span className="rp-person"><strong>{t.name} {t.is_fallback && <Pill tone="info">Fallback</Pill>} {!t.is_active && <Pill>Off</Pill>}</strong><small>{t.description}</small></span>,
                            order: t.priority, uses: t.conversations,
                            actions: <span className="flex flex-wrap gap-2">
                                <Button size="sm" onClick={() => setForm({ topic: t })}>Edit</Button>
                                {!t.is_fallback && <Button size="sm" variant="ghost" disabled={busyId === t.id} onClick={() => toggle(t)}>{t.is_active ? 'Turn off' : 'Turn on'}</Button>}
                            </span>,
                        }))} />
                )}
                <p className="rp-note">The fallback topic is used when nothing else fits, so it always stays on and keeps its name. "All conversations" counts every analyzed conversation, not only the period above. Turning a topic off stops new conversations from using it; results already stored keep theirs.</p>
            </Panel>
            {form && <TopicForm topic={form.topic} onClose={() => setForm(null)} onSaved={(created) => { setForm(null); toast(created ? 'Topic added' : 'Topic saved'); changed(); }} />}
        </div>
    );
}
