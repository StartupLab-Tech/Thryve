import { useEffect, useState } from 'react';
import { api } from '../../lib/api';
import { useRouter } from '../../lib/router';
import { Button } from '../ui/Button';
import { Alert, Skeleton } from '../ui/Feedback';
import ReportFilters from './ReportFilters';
import { Panel, DataTable } from './ExpandedReports';
import { TrendChart, number } from './ReportCharts';
import { Coverage, DimensionBars, Recommendations, ShareBars, StateNotice, Stat } from './AnalysisParts';
import { TopicList } from '../../pages/Topics';

const INTRO = {
    prompts: ['Prompt quality', 'See how well your team writes prompts', 'Scores from 1 to 5 for clarity, context, specifics, refinement and verification, taken from conversations the Report agent has analyzed.'],
    tasks: ['Tasks and topics', 'See what your team uses AI for', 'The kinds of work and topics found in analyzed conversations. Counts come from stored results, never from a live AI call. The topic list the Report agent chooses from is managed at the bottom of this page.'],
};

// Prompt quality and Tasks & topics. Both read the same stored results; the AI is never called when this page opens.
export default function AnalysisReports({ view }) {
    const { path, search = '', navigate } = useRouter();
    const [result, setResult] = useState(null);
    const [error, setError] = useState('');
    const [retry, setRetry] = useState(0);
    useEffect(() => {
        let active = true;
        setResult(null); setError('');
        api(`/reports/analysis${search || '?days=7'}`).then((r) => { if (active) setResult(r); }).catch((e) => { if (active) setError(e.message); });
        return () => { active = false; };
    }, [search, retry]);
    // After a topic is added, edited or switched on or off, refresh the numbers quietly (no loading screen, no jump).
    const reload = () => api(`/reports/analysis${search || '?days=7'}`).then(setResult).catch(() => {});
    const [label, headline, text] = INTRO[view];
    const d = result?.data;
    const days = result ? Math.round((new Date(result.meta.to) - new Date(result.meta.from)) / 864e5) + 1 : 7;
    return <div className="rp" aria-label={label}>
        <div className="rp-intro"><div><span className="rp-label">{label.toUpperCase()}</span><h2>{headline}</h2><p>{text}</p></div>
            {view === 'tasks' && <Button size="sm" variant="soft" icon="layers" onClick={() => document.getElementById('topic-list')?.scrollIntoView({ behavior: 'smooth', block: 'start' })}>Manage the topic list</Button>}
        </div>
        <ReportFilters search={search} busy={!result && !error} onApply={(q) => navigate(`${path}?${q}`)} />
        {error && <Alert tone="danger" title="Could not load this report"><p>{error}</p><Button size="sm" onClick={() => setRetry((n) => n + 1)}>Try again</Button></Alert>}
        {!result && !error && <div className="sl-card" role="status"><Skeleton lines={8} /></div>}
        {d && <>
            <div className="rp-range"><span>{result.meta.from} to {result.meta.to} · Asia/Manila</span><span>Rubric {d.rubric_version} · current department membership</span></div>
            <StateNotice state={d.state} conversations={d.totals.conversations} />
            {view === 'prompts' ? <Prompts d={d} days={days} /> : <Tasks d={d} />}
            <Panel title="Coverage" subtitle="How many of this period's conversations have a result"><Coverage c={d.coverage} /></Panel>
            {d.notes.map((n) => <p className="rp-note" key={n}>{n}</p>)}
            <p className="rp-note">Exports for these two reports are not available yet.</p>
        </>}
        {view === 'tasks' && <TopicList onChanged={reload} />}
    </div>;
}

function Prompts({ d, days }) {
    const t = d.totals;
    const scored = d.dimensions.filter((x) => x.average != null);
    const weakest = scored.length ? scored.reduce((a, b) => (b.average < a.average ? b : a)) : null;
    const change = t.average != null && t.previous_average != null ? t.average - t.previous_average : null;
    return <>
        <div className="rp-kpis">
            <Stat label="Overall prompt score" value={t.average == null ? '—' : `${number(t.average)} / 5`} note={change == null ? 'No earlier period to compare with' : `${change >= 0 ? '+' : ''}${number(change)} compared with the previous ${days} days`} />
            <Stat label="Conversations scored" value={number(t.conversations)} note={`${number(t.previous_conversations)} in the previous ${days} days`} />
            <Stat label="Analyzed" value={d.coverage.percent_analyzed == null ? '—' : `${number(d.coverage.percent_analyzed)}%`} note={`${number(d.coverage.analyzed)} of ${number(d.coverage.eligible)} eligible conversations`} />
            <Stat label="Lowest area" value={weakest ? weakest.label : '—'} note={weakest ? `${number(weakest.average)} / 5 on average` : 'Not enough results yet'} />
        </div>
        <div className="rp-chart-grid">
            <Panel title="Score over time" subtitle="Average per Philippine date. Gaps mean no scored conversations that day, not zero.">
                <TrendChart rows={d.daily} metric="average" label="Average score" nullable ceiling={5} />
            </Panel>
            <Panel title="Score by area" subtitle="Average out of 5 across scored conversations"><DimensionBars rows={d.dimensions} /></Panel>
        </div>
        <Panel title="What to focus on" subtitle="Rules applied to the numbers above, each with the evidence behind it. Nothing ranks or names a person.">
            <Recommendations items={d.recommendations} minimum={d.min_sample} conversations={t.conversations} />
        </Panel>
    </>;
}

function Tasks({ d }) {
    const t = d.totals;
    const top = d.topics[0];
    return <>
        <div className="rp-kpis">
            <Stat label="Conversations analyzed" value={number(t.conversations)} note="Each counted once, with its newest result" />
            <Stat label="Topics in use" value={number(d.topics.length)} note={`${number(d.state.topics_active)} topics are on in the list`} />
            <Stat label="Most common topic" value={top ? top.name : '—'} note={top ? `${number(top.share)}% of analyzed conversations` : 'Not enough results yet'} />
            <Stat label="Analyzed" value={d.coverage.percent_analyzed == null ? '—' : `${number(d.coverage.percent_analyzed)}%`} note={`${number(d.coverage.analyzed)} of ${number(d.coverage.eligible)} eligible conversations`} />
        </div>
        <div className="rp-bottom-grid">
            <Panel title="Topics" subtitle="Share of analyzed conversations. A conversation can have up to three topics, so shares add up to more than 100%.">
                {d.topics.length ? <ShareBars rows={d.topics} /> : <p className="rp-muted">No topics found yet in this selection.</p>}
            </Panel>
            <Panel title="Most common tasks" subtitle="Short labels the Report agent gave each conversation; the same label in a different case counts as one">
                <DataTable title="Most common tasks" columns={[['label', 'Task'], ['conversations', 'Conversations', (v) => number(v)]]} rows={d.tasks} />
            </Panel>
        </div>
        <Panel title="What to focus on" subtitle="Rules applied to the numbers, each with the evidence behind it">
            <Recommendations items={d.recommendations} minimum={d.min_sample} conversations={t.conversations} />
        </Panel>
    </>;
}
