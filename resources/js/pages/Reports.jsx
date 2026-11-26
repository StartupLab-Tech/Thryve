import { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { useRouter } from '../lib/router';
import { Button } from '../components/ui/Button';
import { Alert, EmptyState, Pill, Skeleton } from '../components/ui/Feedback';
import { Table } from '../components/ui/Layout';
import Icon from '../components/Icon';
import ReportFilters from '../components/reports/ReportFilters';
import ExpandedReports, { ExportReport, AttendanceDetails } from '../components/reports/ExpandedReports';
import AnalysisReports from '../components/reports/AnalysisReports';
import { TrendChart, Outcomes, AgentBars, number, hours } from '../components/reports/ReportCharts';

function Metric({ label, value, note, icon }) {
    return <section className="sl-card rp-metric"><div><span className="rp-label">{label}</span><span className="rp-metric-icon"><Icon name={icon} /></span></div><strong>{value}</strong><p>{note}</p></section>;
}

function Panel({ title, subtitle, children, className = '', action }) {
    return <section className={`sl-card rp-panel ${className}`}><div className="rp-panel-heading"><div><h2>{title}</h2>{subtitle && <p>{subtitle}</p>}</div>{action}</div>{children}</section>;
}

function Costs({ data }) {
    return <Panel title="Recorded costs" subtitle="Historical prices · each currency kept separate">
        <div className="rp-cost-list">{data.costs.length ? data.costs.map((c) => <div key={c.currency}><span>{c.currency}</span><strong>{new Intl.NumberFormat('en', { minimumFractionDigits: 2, maximumFractionDigits: 6 }).format(Number(c.amount))}</strong><small>{number(c.priced_requests)} priced attempts · {number(c.estimated_requests)} based on estimated tokens</small></div>) : <p className="rp-muted">No priced attempts in this period.</p>}</div>
        <p className="rp-note">{data.totals.unpriced ? `${number(data.totals.unpriced)} completed attempts have unknown costs and are excluded above.` : 'No unpriced completed attempts in this period.'} Pending reservations and background analysis are excluded.</p>
    </Panel>;
}

function PeopleReport({ search, navigate, path }) {
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);
    const [retry, setRetry] = useState(0);
    useEffect(() => {
        let active = true;
        setResult(null); setError(null);
        api(`/reports/people${search || '?days=7'}`).then((r) => { if (active) setResult(r); }).catch((e) => { if (active) setError(e.message); });
        return () => { active = false; };
    }, [search, retry]);
    const page = (n) => { const p = new URLSearchParams(search); p.set('page', n); navigate(`${path}?${p}`); };
    return <Panel title="People and recorded activity" subtitle="Sorted by recorded chat tokens. Activity is not a measure of work quality.">
        {error ? <Alert tone="danger" title="Could not load people">{error}<Button size="sm" onClick={() => setRetry((n) => n + 1)}>Retry</Button></Alert> : !result ? <Skeleton /> : <>
            {!result.data.length ? <EmptyState title="No people match these filters" /> : <Table caption="People report" columns={[{ key: 'person', label: 'Person' }, { key: 'requests', label: 'Requests', num: true }, { key: 'tokens', label: 'Chat tokens', num: true }, { key: 'active', label: 'Active time', num: true }, { key: 'idle', label: 'Idle time', num: true }]}
                rows={result.data.map((r) => ({ person: <span className="rp-person"><strong>{r.name}</strong><small>@{r.username}{!r.is_active ? ' · inactive' : ''}</small></span>, requests: number(r.requests), tokens: number(r.tokens), active: r.attendance_days ? hours(r.active_seconds) : 'Not recorded', idle: r.attendance_days ? hours(r.idle_seconds) : 'Not recorded' }))} />}
            <div className="rp-pagination"><span>{number(result.meta.total)} people · page {result.meta.page} of {Math.max(1, result.meta.last_page)}</span><Button size="sm" disabled={result.meta.page <= 1} onClick={() => page(result.meta.page - 1)}>Previous</Button><Button size="sm" disabled={result.meta.page >= result.meta.last_page} onClick={() => page(result.meta.page + 1)}>Next</Button></div>
        </>}
    </Panel>;
}

export default function Reports({ view = 'overview' }) {
    if (view === 'prompts' || view === 'tasks') return <AnalysisReports key={view} view={view} />;
    return ['usage', 'quality', 'adoption', 'conversations', 'audit'].includes(view) ? <ExpandedReports key={view} view={view} /> : <CoreReports view={view} />;
}

function CoreReports({ view = 'overview' }) {
    const { path, search = '', navigate } = useRouter();
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(true);
    const [retry, setRetry] = useState(0);
    const [measure, setMeasure] = useState('tokens');
    const params = new URLSearchParams(search);
    params.delete('page'); params.delete('per_page');
    const query = params.toString();
    useEffect(() => {
        let active = true;
        setLoading(true); setError(null); setResult(null);
        api(`/reports/overview?${query || 'days=7'}`).then((r) => { if (active) setResult(r); }).catch((e) => { if (active) setError(e.message); }).finally(() => { if (active) setLoading(false); });
        return () => { active = false; };
    }, [query, retry]);
    const data = result?.data;
    const t = data?.totals;
    const attendance = view === 'attendance';
    const metric = attendance ? 'active_seconds' : measure;
    const label = attendance ? 'Active time' : measure === 'tokens' ? 'Total tokens' : 'Requests';
    const formatter = attendance ? hours : number;
    const completed = t ? t.successful + t.failed : 0;
    return <div className="rp" aria-label="Admin reports">
        <div className="rp-intro"><div><span className="rp-label">WORKSPACE INSIGHTS</span><h2>A clearer view of your team’s AI activity</h2><p>Usage and attendance from recorded activity, all in one place.</p></div><Pill tone="info">Asia/Manila</Pill></div>
        <ReportFilters search={search} busy={loading} onApply={(q) => navigate(`${path}?${q}`)} />
        <ExportReport report={view} search={search} />
        {error && <Alert tone="danger" title="Could not load this report"><p>{error}</p><Button size="sm" onClick={() => setRetry((n) => n + 1)}>Try again</Button></Alert>}
        {loading && <div className="rp-loading" role="status" aria-label="Loading report"><div className="rp-kpis">{[0, 1, 2, 3].map((i) => <div className="sl-card" key={i}><Skeleton /></div>)}</div><div className="sl-card"><Skeleton lines={6} /></div></div>}
        {data && <>
            <div className="rp-range"><span><strong>{result.meta.from}</strong> to <strong>{result.meta.to}</strong> · Philippine calendar dates</span><span>As of {new Date(result.meta.generated_at).toLocaleTimeString('en', { hour: 'numeric', minute: '2-digit', timeZone: 'Asia/Manila' })}</span></div>
            <div className="rp-kpis">
                <Metric label="Chat tokens" value={number(t.tokens)} note={`${number(t.estimated_tokens)} estimated · excludes pending`} icon="chip" />
                <Metric label="Requests" value={number(t.requests)} note={`${number(t.pending)} pending · ${completed ? `${number(t.failed / completed * 100)}% failure rate on completed` : 'no completed success/failure attempts'}`} icon="chat" />
                <Metric label="People using AI" value={number(t.active_users)} note="Distinct people with recorded chat attempts" icon="users" />
                <Metric label="Active work time" value={t.attendance_days ? hours(t.active_seconds) : '—'} note={t.attendance_days ? `${hours(t.idle_seconds)} idle · recorded attendance` : 'No recorded attendance'} icon="clock" />
            </div>
            {t.requests === 0 && t.active_seconds === 0 && t.idle_seconds === 0 && <div className="rp-empty"><Icon name="info" /><span>No recorded activity for these filters. Try another period or person.</span></div>}
            {view !== 'people' && <div className="rp-chart-grid">
                <Panel title={attendance ? 'Attendance over time' : 'Usage over time'} subtitle={attendance ? 'Official active time and measured idle time' : 'Explore each date with your pointer or arrow keys'} className="rp-main-chart"
                    action={!attendance && <div className="rp-presets" aria-label="Chart measure">{['tokens', 'requests'].map((v) => <button key={v} type="button" className={measure === v ? 'on' : ''} aria-pressed={measure === v} onClick={() => setMeasure(v)}>{v === 'tokens' ? 'Tokens' : 'Requests'}</button>)}</div>}>
                    <TrendChart rows={data.daily} metric={metric} label={label} formatter={formatter} secondary={attendance ? 'idle_seconds' : measure === 'tokens' ? 'estimated_tokens' : null} secondaryLabel={attendance ? 'Idle time' : 'Estimated portion'} />
                </Panel>
                {attendance ? <Panel title="Attendance context" subtitle="Read the numbers with their source in mind"><div className="rp-context"><span className="rp-context-stat">{number(t.corrected_days)}<small>corrected daily records</small></span><p>Corrections are included in active time. Idle time comes from measured activity.</p><p>Today’s records may still change as sessions are reconciled. A missing record does not establish absence.</p><a href="/attendance/team" onClick={(e) => { e.preventDefault(); navigate('/attendance/team'); }}>Open team attendance →</a></div></Panel>
                    : <Panel title="Request outcomes" subtitle="All recorded attempts in this period"><Outcomes totals={t} /><p className="rp-note">Failed attempts can still consume tokens. Pending requests are reservations, not confirmed usage.</p></Panel>}
            </div>}
            {view === 'overview' || view === 'usage' ? <div className="rp-bottom-grid"><Panel title="Assistant usage" subtitle="Top 8 assistants by recorded tokens"><AgentBars rows={data.agents} /></Panel><Costs data={data} /></div> : null}
            {view === 'people' && <PeopleReport search={search} navigate={navigate} path={path} />}
            {attendance && <AttendanceDetails search={search} navigate={navigate} path={path} />}
            <div className="rp-footnote"><Icon name="info" /><p>Department filters use current membership. Historical and inactive accounts are retained. Chat metrics exclude system analysis. Tasks and topics, and prompt quality, have their own tabs.</p></div>
        </>}
    </div>;
}
