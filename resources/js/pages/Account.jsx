import { useEffect, useState } from 'react';
import { useAuth } from '../lib/auth';
import { get, send } from '../lib/api';
import { Card } from '../components/ui/Layout';
import { Button } from '../components/ui/Button';
import { TextField, TextareaField } from '../components/ui/Form';
import { Alert, Pill, Skeleton, useToast } from '../components/ui/Feedback';
import { ROLE_LABEL } from '../lib/roles';

const EMPTY = { current_password: '', password: '', password_confirmation: '' };
const NO_PREFERENCES = { call_me: '', role: '', style: '', notes: '' };

/** The person's own preferences for the AI. Private: only they can read or change them. */
function PersonalizationCard() {
    const toast = useToast();
    const [loaded, setLoaded] = useState(null);
    const [form, setForm] = useState(NO_PREFERENCES);
    const [errors, setErrors] = useState({});
    const [formError, setFormError] = useState(null);
    const [warning, setWarning] = useState(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        get('/account/personalization')
            .then((d) => { setLoaded(d); setForm(d.personalization); })
            .catch((err) => setFormError(err.message));
    }, []);

    const used = Object.values(form).reduce((n, v) => n + v.length, 0);
    const left = loaded ? loaded.limit - used : 0;
    const set = (k) => (e) => { setForm({ ...form, [k]: e.target.value }); setWarning(null); };

    const save = async (values, acknowledged = false, done = 'Preferences saved') => {
        setBusy(true);
        setErrors({});
        setFormError(null);
        setWarning(null);
        try {
            const d = await send('PUT', '/account/personalization', acknowledged ? { ...values, acknowledge_sensitive: true } : values);
            setLoaded(d);
            setForm(d.personalization);
            toast(done);
        } catch (err) {
            if (err.code === 'sensitive_warning') setWarning(err.message);
            else if (Object.keys(err.details ?? {}).length && !err.details.found) setErrors(Object.fromEntries(Object.entries(err.details).map(([k, v]) => [k, v[0]])));
            else setFormError(err.message);
        }
        setBusy(false);
    };

    const submit = (e) => {
        e.preventDefault();
        save(form);
    };

    return (
        <Card title="Personalization">
            <p className="sl-help" style={{ marginTop: 0 }}>Tell the AI about you once and it keeps your answers in this style in every chat. Only you can see this. It never overrides company rules.</p>
            {formError && <Alert tone="danger" title="Could not save">{formError}</Alert>}
            {warning && (
                <div className="grid gap-2">
                    <Alert tone="warning" title="Check before saving">{warning}</Alert>
                    <div><Button type="button" disabled={busy} onClick={() => save(form, true)}>Save anyway</Button></div>
                </div>
            )}
            {!loaded && !formError && <Skeleton lines={4} />}
            {loaded && (
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <TextField label="What should the AI call you?" value={form.call_me} maxLength={loaded.fields.call_me} onChange={set('call_me')} error={errors.call_me} />
                    <TextareaField label="What do you do?" rows={2} value={form.role} maxLength={loaded.fields.role} onChange={set('role')} error={errors.role} />
                    <TextareaField label="How should the AI answer?" rows={3} help="For example: short answers, bullet points, plain language." value={form.style} maxLength={loaded.fields.style} onChange={set('style')} error={errors.style} />
                    <TextareaField label="Anything else it should know?" rows={4} value={form.notes} maxLength={loaded.fields.notes} onChange={set('notes')} error={errors.notes} />
                    <p className="sl-help" style={{ margin: 0, color: left < 0 ? 'var(--color-danger)' : undefined }}>{errors.personalization ?? (left < 0 ? `${-left} ${left === -1 ? 'character' : 'characters'} over the ${loaded.limit} limit. Shorten your text to save.` : `${left} of ${loaded.limit} characters left. Do not include passwords or card numbers.`)}</p>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="primary" type="submit" disabled={busy || left < 0}>{busy ? 'Saving...' : 'Save preferences'}</Button>
                        <Button type="button" disabled={busy || used === 0} onClick={() => save(NO_PREFERENCES, false, 'Preferences cleared')}>Clear</Button>
                    </div>
                </form>
            )}
        </Card>
    );
}

export default function Account() {
    const { user } = useAuth();
    const toast = useToast();
    const [form, setForm] = useState(EMPTY);
    const [errors, setErrors] = useState({});
    const [formError, setFormError] = useState(null);
    const [busy, setBusy] = useState(false);
    const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });

    const submit = async (e) => {
        e.preventDefault();
        setErrors({});
        setFormError(null);
        const local = {};
        if (form.password.length < 12) local.password = 'Use at least 12 characters.';
        if (form.password !== form.password_confirmation) local.password_confirmation = 'The passwords do not match.';
        if (form.password && form.password === form.current_password) local.password = 'Choose a password different from your current one.';
        if (Object.keys(local).length) return setErrors(local);

        setBusy(true);
        try {
            await send('POST', '/account/password', form);
            setForm(EMPTY);
            toast('Password changed');
        } catch (err) {
            const d = err.details ?? {};
            if (Object.keys(d).length) setErrors(Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v[0]])));
            else setFormError(err.message);
        }
        setBusy(false);
    };

    return (
        <>
            <div className="grid gap-4 lg:grid-cols-2">
                <Card title="Profile">
                    <dl className="grid gap-3" style={{ margin: 0 }}>
                        <div><dt className="sl-help">Name</dt><dd style={{ margin: 0 }}>{user.name}</dd></div>
                        <div><dt className="sl-help">Username</dt><dd style={{ margin: 0 }}>{user.username}</dd></div>
                        <div><dt className="sl-help">Email</dt><dd style={{ margin: 0 }}>{user.email ?? 'Not set'}</dd></div>
                        <div><dt className="sl-help">Role</dt><dd style={{ margin: 0 }}><Pill tone="info">{ROLE_LABEL[user.role]}</Pill></dd></div>
                        <div><dt className="sl-help">Departments</dt><dd style={{ margin: 0 }} className="flex flex-wrap gap-2">{user.departments.length ? user.departments.map((d) => <Pill key={d.id}>{d.name}</Pill>) : 'None'}</dd></div>
                    </dl>
                    <p className="sl-help">Ask an administrator to change your name, email or departments.</p>
                </Card>
                <Card title="Change password">
                    {formError && <Alert tone="danger" title="Could not change password">{formError}</Alert>}
                    <form onSubmit={submit} className="grid gap-4" noValidate>
                        <TextField label="Current password" type="password" value={form.current_password} onChange={set('current_password')} error={errors.current_password} autoComplete="current-password" />
                        <TextField label="New password" type="password" help="At least 12 characters." value={form.password} onChange={set('password')} error={errors.password} autoComplete="new-password" />
                        <TextField label="Confirm new password" type="password" value={form.password_confirmation} onChange={set('password_confirmation')} error={errors.password_confirmation} autoComplete="new-password" />
                        <div><Button variant="primary" type="submit" disabled={busy || !form.current_password || !form.password}>{busy ? 'Saving...' : 'Update password'}</Button></div>
                    </form>
                </Card>
                {!user.viewing_as_by && <PersonalizationCard />}
            </div>
        </>
    );
}
