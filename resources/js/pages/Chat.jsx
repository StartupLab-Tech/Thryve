import { useCallback, useEffect, useRef, useState } from 'react';
import { get, send, upload } from '../lib/api';
import { postJson } from '../lib/chatApi';
import { errorText } from '../lib/util';
import { useAuth } from '../lib/auth';
import { useRouter } from '../lib/router';
import { useMedia } from '../lib/useMedia';
import { usePanelState } from '../lib/panelState';
import { useToast } from '../components/ui/Feedback';
import { Alert, Spinner } from '../components/ui/Feedback';
import { Button } from '../components/ui/Button';
import { Dialog, ConfirmDialog, Menu } from '../components/ui/Overlay';
import { TextField } from '../components/ui/Form';
import Icon from '../components/Icon';
import ChatPanel from '../components/chat/ChatPanel';
import Messages from '../components/chat/Messages';
import DetailsPanel from '../components/chat/DetailsPanel';
import { SUGGESTIONS, extOf, firstName, fmt, hue, sizeText } from '../components/chat/chatUtil';
import AgentAvatar from '../components/chat/AgentAvatar';

export default function Chat() {
    const toast = useToast();
    const { user } = useAuth();
    const { path, navigate } = useRouter();
    const routeId = (() => { const m = path.match(/^\/chat\/(\d+)$/); return m ? Number(m[1]) : null; })();

    // ---- side panels: columns on wide screens (remembered per browser), overlays on narrow ones -----------------------
    const narrowLeft = useMedia('(max-width: 1023px)');
    const narrowRight = useMedia('(max-width: 1279px)');
    const phone = useMedia('(max-width: 767px)');
    const [leftSaved, , toggleLeftSaved] = usePanelState('chat-left', true);
    const [rightSaved, , toggleRightSaved] = usePanelState('chat-details', false);
    const [leftOv, setLeftOv] = useState(false);
    const [rightOv, setRightOv] = useState(false);
    const leftOpen = narrowLeft ? leftOv : leftSaved;
    const rightOpen = narrowRight ? rightOv : rightSaved;
    // On narrow screens the two panels are overlays: opening one closes the other, so they never stack.
    const toggleLeft = () => { if (narrowLeft) { setLeftOv((v) => !v); setRightOv(false); } else toggleLeftSaved(); };
    const toggleRight = () => { if (narrowRight) { setRightOv((v) => !v); setLeftOv(false); } else toggleRightSaved(); };
    const leftBtn = useRef(null);
    // Phones: the chats panel is the home screen, so opening /chat with nothing selected starts on the list.
    useEffect(() => { if (phone && routeId === null) setLeftOv(true); }, [phone]); // eslint-disable-line react-hooks/exhaustive-deps
    const rightBtn = useRef(null);
    const detailsClose = useRef(null);
    const closeDetails = () => { setRightOv(false); rightBtn.current?.focus(); };
    // The details overlay covers the header button that opened it, so focus moves to the panel's own close button.
    useEffect(() => { if (narrowRight && rightOv) detailsClose.current?.focus({ preventScroll: true }); }, [narrowRight, rightOv]);

    // ---- data ----------------------------------------------------------------------------------------------------------
    const [list, setList] = useState(null);
    const [showArchived, setShowArchived] = useState(false);
    const [query, setQuery] = useState('');
    const [current, setCurrent] = useState(null); // {id, title, agent, messages}
    const [loadingConv, setLoadingConv] = useState(false);
    const [usage, setUsage] = useState(null);
    const [agents, setAgents] = useState(null); // chatbots this person may use
    const [picked, setPicked] = useState(null); // who the next new chat is with
    const [filter, setFilter] = useState(null); // assistant whose chats the list is limited to
    const [draft, setDraft] = useState('');
    const [busy, setBusy] = useState(false);
    const [liveText, setLiveText] = useState('');
    const [stopping, setStopping] = useState(false);
    const [canStop, setCanStop] = useState(false);
    const [error, setError] = useState(null); // {message, code}
    const [pendingText, setPendingText] = useState(null);
    const [sensitive, setSensitive] = useState(null);
    const [rename, setRename] = useState(null);
    const [removing, setRemoving] = useState(null);
    const [editing, setEditing] = useState(null);
    const [files, setFiles] = useState([]); // {key, id?, name, size, status: uploading|ready|error, error?}
    const [limits, setLimits] = useState({ max_count: 6, max_mb: 15, types: ['txt', 'md', 'csv', 'docx', 'xlsx', 'pdf', 'png', 'jpg', 'jpeg'] });
    const pickerRef = useRef(null);
    const abort = useRef(null);
    // An answer keeps running when the person opens another chat: it moves to `bg` and finishes quietly (the server saves it).
    const bg = useRef(new Set());
    const mounted = useRef(true);
    const backgroundStream = useCallback(() => { const ctl = abort.current; if (!ctl) return; bg.current.add(ctl); abort.current = null; setBusy(false); setLiveText(''); setPendingText(null); setCanStop(false); setStopping(false); }, []);
    const pathRef = useRef(path); pathRef.current = path;
    useEffect(() => {
        const ctl = abort.current;
        if (ctl && ctl.origin !== path) {
            backgroundStream();
        }
    }, [path]);
    useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);
    const endRef = useRef(null);
    const box = useRef(null);
    const stick = useRef(true);

    const loadUsage = useCallback(() => get('/usage/today').then(setUsage).catch(() => {}), []);
    const loadList = useCallback(() => get(`/conversations?archived=${showArchived ? 1 : 0}${query ? `&q=${encodeURIComponent(query)}` : ''}`).then(setList).catch(() => setList([])), [showArchived, query]);

    useEffect(() => {
        loadUsage();
        get('/attachments/limits').then(setLimits).catch(() => {});
        get('/chat/agents').then((l) => { setAgents(l); setPicked((p) => p ?? (l.find((a) => a.is_default) ?? l[0])?.id ?? null); }).catch(() => setAgents([]));
    }, [loadUsage]);
    useEffect(() => { const t = setTimeout(loadList, query ? 250 : 0); return () => clearTimeout(t); }, [loadList, query]);
    useEffect(() => { if (stick.current) endRef.current?.scrollIntoView({ block: 'end' }); }, [current?.messages?.length, busy, pendingText, liveText]);

    // The address decides which conversation is open: /chat is a new chat, /chat/12 is conversation 12.
    useEffect(() => {
        if (routeId === null) { setCurrent(null); setError(null); setEditing(null); setLoadingConv(false); return undefined; }
        if (current?.id === routeId) { setLoadingConv(false); return undefined; }
        let cancelled = false;
        setLoadingConv(true); setError(null); setEditing(null);
        get(`/conversations/${routeId}`)
            .then((c) => {
                if (cancelled) return;
                setCurrent(c); stick.current = true; if (c.agent) setPicked(c.agent.id);
                const live = [...bg.current].find((x) => x.conversationId === c.id);
                if (live && !abort.current) { bg.current.delete(live); abort.current = live; setBusy(true); setCanStop(!!live.streamId); setLiveText(live.text || ''); setPendingText(null); }
            })
            .catch((e) => { if (!cancelled) { toast(errorText(e)); navigate('/chat', { replace: true }); } })
            .finally(() => { if (!cancelled) setLoadingConv(false); });
        return () => { cancelled = true; };
    }, [routeId]); // eslint-disable-line react-hooks/exhaustive-deps

    // Escape closes an overlay panel and returns focus to the button that opened it.
    useEffect(() => {
        const onKey = (e) => {
            if (e.key !== 'Escape') return;
            if (narrowLeft && leftOv) { setLeftOv(false); leftBtn.current?.focus(); }
            else if (narrowRight && rightOv) { setRightOv(false); rightBtn.current?.focus(); }
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [narrowLeft, narrowRight, leftOv, rightOv]);

    // ---- navigation helpers ----------------------------------------------------------------------------------------------
    const closeOverlays = () => { setLeftOv(false); setRightOv(false); };
    const openChat = (id) => { if (pathRef.current !== `/chat/${id}`) backgroundStream(); setSensitive(null); closeOverlays(); navigate(`/chat/${id}`); };
    const startNew = (agentId) => {
        backgroundStream(); setSensitive(null);
        closeOverlays();
        if (agentId) setPicked(agentId);
        setCurrent(null); setError(null); setEditing(null); setDraft('');
        navigate('/chat');
        setTimeout(() => box.current?.focus(), 0);
    };
    const pickAgent = (id) => { setPicked(id); setFilter(id); };

    // ---- files -----------------------------------------------------------------------------------------------------------
    const attach = async (chosen) => {
        let slots = limits.max_count - files.length;
        for (const f of chosen) {
            const key = `${Date.now()}-${Math.random()}`;
            const bad = slots <= 0 ? `Up to ${limits.max_count} files per message.`
                : !limits.types.includes(extOf(f.name)) ? `Not supported. Use ${limits.types.map((t) => `.${t}`).join(', ')}.`
                : f.size > limits.max_mb * 1048576 ? `Larger than ${limits.max_mb} MB.` : null;
            if (bad) { setFiles((l) => [...l, { key, name: f.name, size: f.size, status: 'error', error: bad }]); continue; }
            slots -= 1;
            setFiles((l) => [...l, { key, name: f.name, size: f.size, status: 'uploading' }]);
            const form = new FormData(); form.append('file', f);
            try {
                const a = await upload('/attachments', form);
                setFiles((l) => l.map((x) => (x.key === key ? { ...x, id: a.id, status: 'ready', truncated: a.truncated } : x)));
            } catch (e) {
                setFiles((l) => l.map((x) => (x.key === key ? { ...x, status: 'error', error: errorText(e) } : x)));
            }
        }
    };
    const dropFile = async (f) => {
        setFiles((l) => l.filter((x) => x.key !== f.key));
        if (f.id) { try { await send('DELETE', `/attachments/${f.id}`); } catch { /* it will simply stay unsent */ } }
    };

    // ---- sending ---------------------------------------------------------------------------------------------------------
    const onScroll = (e) => { const el = e.currentTarget; stick.current = el.scrollHeight - el.scrollTop - el.clientHeight < 80; };

    // Every send, edit and retry goes through here. A failed send puts the text back so nothing typed is lost.
    // The chat moves to the top of the list with a fresh time the moment the person sends, not only after the answer arrives.
    const touchCurrent = () => setList((l) => {
        if (!l || !current) return l;
        const now = new Date().toISOString();
        const hit = l.find((c) => c.id === current.id);
        return hit ? [{ ...hit, last_message_at: now }, ...l.filter((c) => c.id !== current.id)] : l;
    });
    const run = async (fn, restore) => {
        if (abort.current) return false;
        touchCurrent();
        setBusy(true); setError(null); setLiveText(''); setStopping(false); setCanStop(false);
        const ctl = new AbortController(); abort.current = ctl;
        ctl.origin = pathRef.current; ctl.text = '';
        const active = () => abort.current === ctl && mounted.current;
        try {
            const r = await fn(ctl.signal, false, (event, data) => {
                if (event === 'started') { ctl.streamId = data.stream_id; ctl.conversationId = data.conversation.id; if (active()) { setCanStop(true); setCurrent(previous => ({ ...data.conversation, messages: previous?.id === data.conversation.id ? previous.messages : [] })); } }
                if (event === 'delta') { ctl.text += data.text; if (active()) setLiveText(text => text + data.text); }
            });
            const conv = r.data.conversation;
            if (mounted.current) { loadList(); loadUsage(); }
            if (!active()) return true;
            const saved = await get(`/conversations/${conv.id}`); if (!active()) return true; setCurrent(saved);
            stick.current = true;
            if (routeId !== conv.id) navigate(`/chat/${conv.id}`);
            return true;
        } catch (e) {
            if (!active()) { if (mounted.current) { loadList(); loadUsage(); } return false; }
            if (e.name === 'AbortError') {
                if (ctl.stopRequested && ctl.conversationId) {
                    try { const saved = await get(`/conversations/${ctl.conversationId}`); if (!active()) return false; setCurrent(saved); loadList(); loadUsage(); if (routeId !== saved.id) navigate(`/chat/${saved.id}`); return true; }
                    catch { if (!active()) return false; }
                }
                setError({ message: 'Stopped. The assistant may still finish in the background: reopen this conversation in a minute to see it.', code: 'stopped' });
                restore?.();
            } else if (e.code === 'sensitive_warning') {
                setSensitive({ message: e.message, retry: () => run((s, _ack, event) => fn(s, true, event), restore) });
                restore?.();
            } else {
                setError({ message: errorText(e), code: e.code });
                const cid = e.details?.conversation?.id || ctl.conversationId;
                if (cid) { try { const saved = await get(`/conversations/${cid}`); if (!active()) return false; setCurrent(saved); loadList(); if (routeId !== cid) navigate(`/chat/${cid}`); } catch { /* keep going */ } }
                if (!cid || e.status === 409 || ['sensitive_blocked', 'validation_failed'].includes(e.code)) restore?.();
                loadUsage();
            }
            return false;
        } finally {
            bg.current.delete(ctl);
            if (abort.current === ctl) { abort.current = null; setBusy(false); setPendingText(null); setLiveText(''); setStopping(false); setCanStop(false); }
        }
    };

    const uploading = files.some((f) => f.status === 'uploading');
    const submit = async (text = draft) => {
        const t = text.trim();
        if (!t || busy || uploading) return;
        const ids = files.filter((f) => f.status === 'ready').map((f) => f.id);
        const restore = () => setDraft(t);
        setDraft(''); setPendingText(t);
        // Files are cleared only after a successful send, so a failed send keeps both the text and the files.
        const ok = await run((signal, ack, onEvent) => postJson('/chat', 'POST', { message: t, conversation_id: current?.id, agent_id: current ? undefined : (picked ?? undefined), acknowledge_sensitive: ack || undefined, attachment_ids: ids }, signal, onEvent), restore);
        if (ok) setFiles((l) => l.filter((f) => !ids.includes(f.id)));
    };
    const stop = async () => { const ctl = abort.current; if (!ctl?.streamId || stopping) return; ctl.stopRequested = true; setStopping(true); const fallback = setTimeout(() => { if (abort.current === ctl) ctl.abort(); }, 2500); try { await send('POST', `/chat/streams/${ctl.streamId}/stop`); } catch (e) { if (!ctl.signal.aborted && abort.current === ctl) { toast(errorText(e)); setStopping(false); } } finally { clearTimeout(fallback); } };
    const regenerate = () => { setPendingText(null); run((signal, _ack, onEvent) => postJson(`/conversations/${current.id}/regenerate`, 'POST', {}, signal, onEvent)); };
    const saveEdit = () => {
        const { id, text } = editing; setEditing(null); setPendingText(text);
        run((signal, ack, onEvent) => postJson(`/conversations/${current.id}/messages/${id}`, 'PATCH', { message: text, acknowledge_sensitive: ack || undefined }, signal, onEvent));
    };
    const onKey = (e) => { if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) { e.preventDefault(); submit(); } };
    // ---- speech to text (the browser's built-in recognition; the button is hidden where it is not available) ----------
    const Speech = typeof window !== 'undefined' ? (window.SpeechRecognition || window.webkitSpeechRecognition) : null;
    const rec = useRef(null);
    const [listening, setListening] = useState(false);
    const stopListening = useCallback(() => { try { rec.current?.stop(); } catch { /* already stopped */ } }, []);
    const toggleListening = () => {
        if (listening) { stopListening(); return; }
        const r = new Speech();
        r.lang = navigator.language || 'en-US';
        r.interimResults = true;
        r.continuous = true;
        const base = draft ? `${draft.replace(/\s+$/, '')} ` : '';
        r.onresult = (e) => {
            const said = Array.from(e.results).map((x) => x[0].transcript).join('').trim();
            setDraft((base + said).slice(0, 20000));
            if (box.current) { box.current.style.height = 'auto'; box.current.style.height = `${Math.min(box.current.scrollHeight, 180)}px`; }
        };
        r.onerror = (e) => { if (e.error === 'not-allowed' || e.error === 'service-not-allowed') toast('Microphone access was blocked. Allow it in the browser to dictate.'); else if (e.error !== 'no-speech' && e.error !== 'aborted') toast('Voice typing stopped. Try again.'); };
        r.onend = () => { setListening(false); rec.current = null; };
        rec.current = r;
        try { r.start(); setListening(true); box.current?.focus(); } catch { rec.current = null; }
    };
    useEffect(() => stopListening, [stopListening]);
    useEffect(() => { if (busy) stopListening(); }, [busy, stopListening]);
    // The box stretches while typing; once the draft is cleared (sent), shrink it back.
    useEffect(() => { if (!draft && box.current) box.current.style.height = ''; }, [draft]);
    const grow = (el) => { el.style.height = 'auto'; el.style.height = `${Math.min(el.scrollHeight, 180)}px`; };
    const copy = async (text) => { try { await navigator.clipboard.writeText(text); toast('Copied'); } catch { toast('Copy was blocked by the browser.'); } };
    const rate = async (m, value) => {
        const next = m.rating === value ? null : value;
        try {
            await send('POST', `/conversations/${current.id}/messages/${m.id}/rating`, { rating: next });
            setCurrent((c) => ({ ...c, messages: c.messages.map((x) => (x.id === m.id ? { ...x, rating: next } : x)) }));
        } catch (e) { toast(errorText(e)); }
    };

    // ---- chat list actions ---------------------------------------------------------------------------------------------------
    const patchConv = async (c, body, done) => {
        try { await send('PATCH', `/conversations/${c.id}`, body); done?.(); loadList(); if (current?.id === c.id) setCurrent((x) => ({ ...x, ...body })); }
        catch (e) { toast(errorText(e)); }
    };
    const doRename = async () => {
        const title = rename.title.trim();
        if (!title) { setRename({ ...rename, error: 'Give it a name.' }); return; }
        await patchConv(rename.c, { title }, () => setRename(null));
    };
    const doArchive = (c) => patchConv(c, { archived: !c.archived }, () => { if (current?.id === c.id && !c.archived) startNew(); });
    const doDelete = async () => {
        const c = removing; setRemoving(null);
        try { await send('DELETE', `/conversations/${c.id}`); if (current?.id === c.id) startNew(); loadList(); }
        catch (e) { toast(errorText(e)); }
    };

    // ---- derived state ---------------------------------------------------------------------------------------------------------
    const messages = current?.messages ?? [];
    const lastIsUser = messages.length > 0 && messages[messages.length - 1].role === 'user';
    const several = (agents?.length ?? 0) > 1;
    const pickedAgent = agents?.find((a) => a.id === picked) ?? null;
    const agent = current?.agent ? (agents?.find((a) => a.id === current.agent.id) ?? current.agent) : pickedAgent;
    const exhausted = !!usage && usage.remaining <= 0;
    // A conversation whose assistant the person can no longer use stays readable but cannot continue.
    const locked = !!(current?.agent && agents && !agents.some((a) => a.id === current.agent.id));
    const blocked = exhausted || locked;
    const showWelcome = !loadingConv && !current && !pendingText;
    const headerSwitch = several && !leftOpen; // with the chats panel hidden, the assistant chip becomes the switcher
    const dots = (id) => <i className={`dot ${hue(agents, id)}`} />;

    return (
        <div className="cw" data-left={leftOpen ? 'open' : 'closed'} data-right={rightOpen ? 'open' : 'closed'} data-lov={String(narrowLeft)} data-rov={String(narrowRight)}>
            <aside id="cw-left" className="cw-left" aria-label="Chats and assistants" inert={!leftOpen}>
                <ChatPanel agents={agents} picked={picked} filter={filter} onPick={pickAgent} onClearFilter={() => setFilter(null)}
                    list={list} query={query} onQuery={setQuery} archived={showArchived} onArchived={setShowArchived} currentId={current?.id ?? null}
                    onOpen={openChat} onNew={() => startNew()} onRename={(c) => setRename({ c, title: c.title })} onArchive={doArchive} onDelete={setRemoving} />
            </aside>

            <section className="cw-main" aria-label="Conversation">
                <header className="cw-hd">
                    <button ref={leftBtn} type="button" className="cw-tgl" aria-label={phone ? 'Back to chats' : leftOpen ? 'Hide chats' : 'Show chats'} aria-expanded={leftOpen} aria-controls="cw-left" onClick={toggleLeft}>
                        {phone ? <Icon name="arrow" className="flip" /> : <Icon name="panel" />}
                    </button>
                    <h1>{current?.title ?? 'New chat'}</h1>
                    {agent && (headerSwitch ? (
                        <Menu label={`Assistant: ${agent.name}. Choose another`} triggerClass="cw-chip on" triggerContent={<>{dots(agent.id)}{agent.name}<Icon name="chev-down" /></>}
                            items={agents.map((a) => ({ label: current ? `New chat with ${a.name}` : a.name, onSelect: () => startNew(a.id) }))} />
                    ) : (
                        <span className="cw-chip on">{dots(agent.id)}{agent.name}</span>
                    ))}
                    <span className="grow" />
                    {!leftOpen && <button type="button" className="cw-tgl" aria-label="New chat" title="New chat" onClick={() => startNew()}><Icon name="plus" /></button>}
                    <button ref={rightBtn} type="button" className="cw-tgl" aria-label={rightOpen ? 'Hide details' : 'Show details'} aria-expanded={rightOpen} aria-controls="cw-right" onClick={toggleRight}>
                        <Icon name="panel-right" />
                    </button>
                </header>

                <div className={`cw-scroll ${showWelcome ? 'center' : ''}`} onScroll={onScroll} tabIndex={-1}>
                    {loadingConv && <div className="p-8"><Spinner label="Opening conversation" /></div>}
                    {showWelcome && (
                        <div className="cw-welcome">
                            {agent && <AgentAvatar agent={agent} agents={agents} size="lg" />}
                            <h2>{agent && several ? `Hi ${firstName(user.name)}, I'm ${agent.name}` : `Hi ${firstName(user.name)}, how can I help today?`}</h2>
                            <p>{agent?.description || 'Ask for help with everyday office work: writing, summarizing, planning and explaining.'}</p>
                            <div className="cw-suggest">
                                {SUGGESTIONS.map((s) => <button key={s} type="button" disabled={busy || blocked} onClick={() => submit(s)}>{s}</button>)}
                            </div>
                        </div>
                    )}
                    {!loadingConv && (current || pendingText) && (
                        <div className="m-wrap">
                            <Messages messages={messages} busy={busy} liveText={liveText} stopping={stopping} pendingText={pendingText} editing={editing} setEditing={setEditing} saveEdit={saveEdit}
                                lastIsUser={lastIsUser} onCopy={copy} onRate={rate} onRegenerate={regenerate} />
                            <div ref={endRef} />
                        </div>
                    )}
                </div>

                <div className="cw-dock">
                    {error && (
                        <Alert tone={error.code === 'stopped' ? 'info' : 'danger'} title={error.code === 'token_limit' ? 'Out of tokens for today' : error.code === 'stopped' ? 'Stopped' : 'Could not get an answer'}>
                            <p>{error.message}</p>
                            {current && lastIsUser && !busy && !['token_limit', 'stopped', 'sensitive_blocked'].includes(error.code) && <div><Button size="sm" variant="soft" icon="refresh" onClick={regenerate}>Try again</Button></div>}
                        </Alert>
                    )}
                    {locked && <Alert tone="info" title="This assistant is not available to you"><p>You can still read this conversation, but you cannot continue it. Ask an administrator if you need access.</p></Alert>}
                    {usage && usage.percent >= 80 && (
                        <div className={`cw-banner ${usage.percent >= 100 ? 'bad' : 'warn'}`} role="status">
                            <Icon name="alert" />
                            <span>
                                {exhausted
                                    ? 'Today’s tokens are used up. They reset at midnight (Philippine time).'
                                    : `You have used ${usage.percent}% of today's tokens (${fmt(usage.remaining)} left). They reset at midnight (Philippine time).`}
                                {usage.includes_estimates ? ' Some counts are estimates.' : ''}
                            </span>
                        </div>
                    )}

                    <div className="cw-composer">

                        {files.length > 0 && (
                            <div className="chat-chips" aria-label="Attached files">
                                {files.map((f) => (
                                    <span key={f.key} className={`chat-chip ${f.status === 'error' ? 'bad' : ''}`}>
                                        <Icon name="file" /><span className="n">{f.name}</span>{f.size ? <span className="s">{sizeText(f.size)}</span> : null}
                                        {f.status === 'uploading' && <span className="s" role="status">Uploading…</span>}
                                        {f.status === 'ready' && f.truncated && <span className="s" title="Only the first part of this file is sent">shortened</span>}
                                        {f.status === 'error' && <span className="s err" role="alert">{f.error}</span>}
                                        <button type="button" className="x" aria-label={`Remove ${f.name}`} onClick={() => dropFile(f)}><Icon name="x" /></button>
                                    </span>
                                ))}
                            </div>
                        )}
                        <textarea ref={box} rows={1} aria-label="Message" value={draft} disabled={blocked} maxLength={20000}
                            placeholder={locked ? 'You can no longer use this assistant' : exhausted ? 'Your tokens for today are used up' : phone ? 'Message…' : agent ? `Message ${agent.name}…` : 'Message the assistant…'}
                            onChange={(e) => { setDraft(e.target.value); grow(e.target); }} onKeyDown={onKey} />
                        <div className="tools">
                            <input ref={pickerRef} type="file" multiple hidden accept={limits.types.map((t) => `.${t}`).join(',')} onChange={(e) => { attach([...e.target.files]); e.target.value = ''; }} />
                            <button type="button" className="cw-tgl" aria-label="Attach files" disabled={busy || blocked} onClick={() => pickerRef.current?.click()}><Icon name="clip" /></button>
                            {several && !current && agent && <span className="cw-chip on">{dots(agent.id)}{agent.name}</span>}
                            <span className="hint">Enter to send, Shift+Enter for a new line</span>
                            {Speech && (
                                <button type="button" className={`cw-tgl cw-mic ${listening ? 'on' : ''}`} aria-label={listening ? 'Stop voice typing' : 'Start voice typing'} aria-pressed={listening}
                                    disabled={busy || blocked} onClick={toggleListening}><Icon name="mic" /></button>
                            )}
                            {busy
                                ? <button type="button" className="cw-send stop" aria-label={stopping ? 'Stopping response' : 'Stop'} disabled={!canStop || stopping} onClick={stop}><Icon name="stop" /></button>
                                : <button type="button" className="cw-send" aria-label="Send" disabled={!draft.trim() || blocked || uploading} onClick={() => submit()}><Icon name="up" /></button>}
                        </div>
                    </div>
                    <p className="cw-fine">AI answers can be wrong: review files before using them. Images: up to 4 MB each; PDFs: up to 8 MB, text-based only. Do not attach sensitive information.</p>
                </div>
            </section>

            <aside id="cw-right" className="cw-right" aria-label="Details" inert={!rightOpen}>
                <DetailsPanel usage={usage} agent={agent} agents={agents} messages={messages} onClose={narrowRight ? closeDetails : undefined} closeRef={detailsClose} />
            </aside>

            {((narrowLeft && leftOv) || (narrowRight && rightOv)) && <div className="cw-backdrop" onClick={closeOverlays} aria-hidden="true" />}

            <Dialog open={!!rename} onClose={() => setRename(null)} title="Rename chat"
                actions={<><Button onClick={() => setRename(null)}>Cancel</Button><Button variant="primary" onClick={doRename}>Save</Button></>}>
                {rename && <TextField label="Name" value={rename.title} error={rename.error} maxLength={150} onChange={(e) => setRename({ ...rename, title: e.target.value, error: undefined })} />}
            </Dialog>
            <ConfirmDialog open={!!removing} onCancel={() => setRemoving(null)} onConfirm={doDelete} title="Delete this chat?">
                It disappears from your list. Your administrators may keep a copy under the company retention rules.
            </ConfirmDialog>
            <Dialog open={!!sensitive} onClose={() => setSensitive(null)} title="Check before sending"
                actions={<><Button onClick={() => setSensitive(null)}>Go back</Button><Button variant="primary" onClick={() => { const s = sensitive; setSensitive(null); s.retry(); }}>Send anyway</Button></>}>
                <p>{sensitive?.message}</p>
            </Dialog>
        </div>
    );
}
