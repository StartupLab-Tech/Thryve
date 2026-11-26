import Icon from '../Icon';
import { Skeleton } from '../ui/Feedback';
import { fmt, sizeText } from './chatUtil';
import AgentAvatar from './AgentAvatar';

// The right panel: today's tokens, the files in this chat, and a few words about the assistant. Hidden until opened.
// On narrow screens it slides over the chat and covers the button that opened it, so `onClose` adds a pinned header with its
// own close button (`closeRef` lets the page move focus to it). On wide screens the panel is a column and needs neither.
export default function DetailsPanel({ usage, agent, agents, messages, onClose, closeRef }) {
    const files = messages.flatMap((m) => m.attachments ?? []);
    return (
        <div className="in">
            {onClose && (
                <div className="cw-dhead">
                    <h2 id="cw-d-title">Details</h2>
                    <button ref={closeRef} type="button" className="cw-tgl" aria-label="Close details" onClick={onClose}><Icon name="x" /></button>
                </div>
            )}
            <section aria-labelledby="cw-d-tokens">
                <h2 id="cw-d-tokens" className="cw-h">Today's tokens</h2>
                {!usage ? <Skeleton lines={2} /> : (
                    <div className="cw-tokens">
                        <div className="cw-ring" style={{ '--p': usage.percent }} role="img" aria-label={`${usage.percent} percent used`}><span>{usage.percent}%</span></div>
                        <div>
                            <div className="big">{fmt(usage.remaining)}</div>
                            <div className="muted">of {fmt(usage.total)} left</div>
                            <div className="muted small">Resets at midnight, Philippine time{usage.includes_estimates ? '. Some counts are estimates' : ''}.</div>
                        </div>
                    </div>
                )}
            </section>

            <section aria-labelledby="cw-d-files">
                <h2 id="cw-d-files" className="cw-h">Files in this chat</h2>
                {files.length === 0 ? <p className="muted small">No files yet.</p> : (
                    <ul className="cw-files">
                        {files.map((a) => (
                            <li key={a.id}>
                                <span className="fi"><Icon name="file" /></span>
                                <span className="nm"><b>{a.name}</b><small>{sizeText(a.size)}</small></span>
                                <a className="dl" href={`/api/attachments/${a.id}/download`} download aria-label={`Download ${a.name}`}><Icon name="download" /></a>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {agent && (
                <section aria-labelledby="cw-d-about">
                    <h2 id="cw-d-about" className="cw-h">About this assistant</h2>
                    <div className="cw-about">
                        <div className="top"><AgentAvatar agent={agent} agents={agents} /><b>{agent.name}</b></div>
                        <p>{agent.description || 'Ask it for help with everyday office work: writing, summarizing, planning and explaining.'}</p>
                    </div>
                </section>
            )}
        </div>
    );
}
