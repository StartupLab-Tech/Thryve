import Icon from '../Icon';
import { IconButton } from '../ui/Button';
import { Markdown } from '../../lib/markdown';
import { sizeText } from './chatUtil';

// The conversation. AI replies are plain text across the whole column (no bubble, no avatar) because answers are rich
// text; only the person's own message sits in a bubble, on the right.
export default function Messages({ messages, busy, liveText, stopping, pendingText, editing, setEditing, saveEdit, lastIsUser, onCopy, onRate, onRegenerate }) {
    const lastUserIndex = messages.reduce((acc, m, i) => (m.role === 'user' ? i : acc), -1);
    return (
        <ol className="m-thread" aria-live="polite" aria-label="Conversation">
            {messages.map((m, i) => (
                <li key={m.id} className={`m ${m.role === 'user' ? 'user' : 'ai'}`}>
                    {editing?.id === m.id ? (
                        <>
                            <div className="m-edit">
                                <textarea aria-label="Edit your message" autoFocus value={editing.text} onChange={(e) => setEditing({ ...editing, text: e.target.value })} />
                            </div>
                            <div className="m-edit-bar">
                                <p className="m-note">Messages after this one are replaced, and the new answer uses tokens again.</p>
                                <button type="button" className="m-eb" aria-label="Cancel editing" title="Cancel" onClick={() => setEditing(null)}><Icon name="x" /></button>
                                <button type="button" className="m-eb go" aria-label="Save and resend" title="Save and resend" disabled={!editing.text.trim() || busy} onClick={saveEdit}><Icon name="check" /></button>
                            </div>
                        </>
                    ) : m.role === 'user' ? (
                        <div className="bubble">{m.content}</div>
                    ) : (
                        <div className="body"><Markdown text={m.content} /></div>
                    )}

                    {m.attachments?.length > 0 && (
                        <div className="chat-chips">
                            {m.attachments.map((a) => (
                                <a key={a.id} className="chat-chip link" href={`/api/attachments/${a.id}/download`} download aria-label={`Download ${a.name}`}>
                                    <Icon name="file" /><span className="n">{a.name}</span><span className="s">{sizeText(a.size)}</span><Icon name="download" />
                                </a>
                            ))}
                        </div>
                    )}
                    {m.role === 'assistant' && m.model_label && <p className="m-note">Model: {m.model_label}</p>}
                    {['cancelled', 'failed'].includes(m.completion_status) && <p className="m-note">{m.completion_status === 'cancelled' ? 'Stopped' : 'Interrupted'} · {m.content ? 'partial answer saved' : 'no answer text received'}</p>}

                    {editing?.id !== m.id && (
                        <div className="m-acts">
                            <IconButton icon="copy" label={m.role === 'user' ? 'Copy your message' : 'Copy reply'} onClick={() => onCopy(m.content)} />
                            {m.role === 'user' && i === lastUserIndex && i === messages.length - (lastIsUser ? 1 : 2) && !busy && (
                                <IconButton icon="edit" label="Edit message" onClick={() => setEditing({ id: m.id, text: m.content })} />
                            )}
                            {m.role === 'assistant' && (
                                <>
                                    <IconButton icon="thumb-up" label="Good answer" on={m.rating === 'up'} aria-pressed={m.rating === 'up'} onClick={() => onRate(m, 'up')} />
                                    <IconButton icon="thumb-down" label="Poor answer" on={m.rating === 'down'} aria-pressed={m.rating === 'down'} onClick={() => onRate(m, 'down')} />
                                    {i === messages.length - 1 && !busy && <IconButton icon="refresh" label="Regenerate answer" onClick={onRegenerate} />}
                                </>
                            )}
                        </div>
                    )}
                </li>
            ))}

            {pendingText && !messages.some((m) => m.content === pendingText && m.role === 'user' && lastIsUser) && (
                <li className="m user"><div className="bubble">{pendingText}</div></li>
            )}
            {busy && (
                <li className="m ai" aria-label={stopping ? 'Stopping response' : 'The assistant is writing'}>{liveText && <div className="body"><Markdown text={liveText} /></div>}<div className="chat-dots" aria-hidden="true"><i /><i /><i /></div>{stopping && <p className="m-note">Stopping and saving the partial answer…</p>}</li>
            )}
        </ol>
    );
}
