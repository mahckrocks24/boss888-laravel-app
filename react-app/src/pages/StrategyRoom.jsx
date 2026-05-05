import React, { useEffect, useState, useRef } from 'react';
import * as api from '../services/api';

const AC = { sarah: '#6C5CE7', james: '#3B82F6', priya: '#A78BFA', marcus: '#F59E0B', elena: '#F87171', alex: '#00E5A8' };
const PHASE_LABELS = { opening: '📢 Meeting Opened', contribution: '💡 Expert Input', debate: '⚔️ Discussion', synthesis: '📋 Plan Formed', complete: '✅ Meeting Complete' };

export default function StrategyRoom() {
  const [view, setView] = useState('start'); // start | meeting | history
  const [goal, setGoal] = useState('');
  const [meetingId, setMeetingId] = useState(null);
  const [messages, setMessages] = useState([]);
  const [phase, setPhase] = useState('');
  const [plan, setPlan] = useState(null);
  const [loading, setLoading] = useState(false);
  const [advancing, setAdvancing] = useState(false);
  const [history, setHistory] = useState([]);
  const [userMsg, setUserMsg] = useState('');
  const [sending, setSending] = useState(false);
  const chatEndRef = useRef(null);

  useEffect(() => { chatEndRef.current?.scrollIntoView({ behavior: 'smooth' }); }, [messages]);

  // User sends message into active meeting
  const sendUserMessage = async () => {
    if (!userMsg.trim() || !meetingId || sending) return;
    setSending(true);
    const msg = userMsg; setUserMsg('');
    // Optimistic: show user message immediately
    setMessages(prev => [...prev, { id: Date.now(), sender_type: 'user', sender_name: 'You', sender_slug: 'user', sender_color: '#FFFFFF', message: msg, phase: 'user_input' }]);
    try {
      const res = await api.api('POST', `/sarah/meeting/${meetingId}/message`, { message: msg });
      await refreshMessages(meetingId);
    } catch (e) { console.error(e); }
    setSending(false);
  };

  // User ends meeting early
  const handleEndMeeting = async () => {
    if (!meetingId) return;
    if (!confirm('End the meeting? Sarah will wrap up with what was discussed so far.')) return;
    await api.api('POST', `/sarah/meeting/${meetingId}/end`);
    await refreshMessages(meetingId);
    setPhase('complete');
  };

  const startMeeting = async () => {
    if (!goal.trim()) return;
    setLoading(true); setMessages([]); setPlan(null);
    try {
      const res = await api.api('POST', '/sarah/meeting/start', { goal });
      setMeetingId(res.meeting_id);
      setPhase(res.phase);
      setView('meeting');
      // Fetch initial messages
      await refreshMessages(res.meeting_id);
    } catch (e) { alert(e.message); }
    setLoading(false);
  };

  const advanceMeeting = async () => {
    if (!meetingId || advancing) return;
    setAdvancing(true);
    try {
      const res = await api.api('POST', `/sarah/meeting/${meetingId}/advance`);
      setPhase(res.phase);
      await refreshMessages(meetingId);
      if (res.phase === 'complete') {
        const transcript = await api.api('GET', `/sarah/meeting/${meetingId}`);
        if (transcript.plan) setPlan(transcript.plan);
      }
    } catch (e) { console.error(e); }
    setAdvancing(false);
  };

  const runFull = async () => {
    if (!goal.trim()) return;
    setLoading(true); setMessages([]); setPlan(null); setView('meeting');
    try {
      const res = await api.api('POST', '/sarah/meeting/full', { goal });
      setMeetingId(res.meeting?.id);
      setPhase('complete');
      setMessages(res.messages || []);
      if (res.plan) setPlan(res.plan);
    } catch (e) { alert(e.message); }
    setLoading(false);
  };

  const refreshMessages = async (id) => {
    const res = await api.api('GET', `/sarah/meeting/${id || meetingId}`);
    setMessages(res.messages || []);
    if (res.plan) setPlan(res.plan);
    if (res.phase) setPhase(res.phase);
  };

  const approvePlan = async () => {
    if (!plan || !meetingId) return;
    // Create execution plan from meeting plan
    const res = await api.api('POST', '/sarah/receive', { goal, context: { from_meeting: meetingId, plan } });
    alert(res.message || 'Plan submitted for execution!');
  };

  const loadHistory = async () => {
    const res = await api.api('GET', '/sarah/plans?limit=20');
    setHistory(res.plans || []);
    setView('history');
  };

  const viewMeeting = async (id) => {
    setMeetingId(id);
    await refreshMessages(id);
    setView('meeting');
  };

  // Group messages by phase for visual separators
  let lastPhase = '';

  return (
    <div className="max-w-3xl mx-auto">
      <div className="flex justify-between items-center mb-6">
        <div>
          <h1 className="font-heading text-xl font-bold">🎯 Strategy Room</h1>
          <p className="text-gray-500 text-sm">Real-time agent collaboration</p>
        </div>
        <div className="flex gap-2">
          {view !== 'start' && <button onClick={() => { setView('start'); setMessages([]); setPlan(null); setMeetingId(null); }} className="text-gray-400 text-[13px] border border-border rounded-lg px-3 py-1.5">New Meeting</button>}
          <button onClick={loadHistory} className="text-gray-400 text-[13px] border border-border rounded-lg px-3 py-1.5">History</button>
        </div>
      </div>

      {/* START — Goal Input */}
      {view === 'start' && (
        <div className="bg-s1 border border-border rounded-2xl p-8 text-center">
          <div className="text-5xl mb-4">🏢</div>
          <h2 className="font-heading text-lg font-bold mb-2">Start a Strategy Meeting</h2>
          <p className="text-gray-500 text-sm mb-6 max-w-md mx-auto">
            Sarah will gather the team, discuss your goal, and create an action plan. 
            You'll see the actual conversation between your AI marketing agents.
          </p>
          <textarea value={goal} onChange={e => setGoal(e.target.value)} rows={3} placeholder="What do you want to achieve? e.g. 'We just launched our interior design website in Dubai. Help us get our first 50 customers.'" className="w-full bg-s2 border border-border rounded-xl p-4 text-[14px] text-gray-200 resize-none outline-none focus:border-primary mb-4" />
          <div className="flex justify-center gap-3">
            <button onClick={startMeeting} disabled={loading || !goal.trim()} className="bg-primary text-white font-heading font-bold text-sm rounded-xl px-8 py-3 hover:bg-primary/90 disabled:opacity-40">
              {loading ? 'Gathering team...' : 'Start Meeting →'}
            </button>
            <button onClick={runFull} disabled={loading || !goal.trim()} className="border border-border text-gray-400 text-sm rounded-xl px-6 py-3 hover:text-gray-200">
              Run Full Meeting
            </button>
          </div>
        </div>
      )}

      {/* MEETING — Live Chat */}
      {view === 'meeting' && (
        <div>
          {/* Goal banner */}
          <div className="bg-s1 border border-border rounded-xl p-4 mb-4">
            <div className="text-[11px] text-gray-500 uppercase tracking-wide mb-1">Meeting Goal</div>
            <div className="text-[14px] text-gray-200">{goal}</div>
          </div>

          {/* Messages */}
          <div className="space-y-1 mb-4">
            {messages.map((msg, i) => {
              const showPhaseLabel = msg.phase && msg.phase !== lastPhase;
              if (msg.phase) lastPhase = msg.phase;

              return (
                <React.Fragment key={msg.id || i}>
                  {showPhaseLabel && (
                    <div className="flex items-center gap-3 py-3">
                      <div className="h-px flex-1 bg-border" />
                      <span className="text-[11px] text-gray-500 font-semibold whitespace-nowrap">{PHASE_LABELS[msg.phase] || msg.phase}</span>
                      <div className="h-px flex-1 bg-border" />
                    </div>
                  )}
                  <div className={`flex gap-3 py-2 ${msg.sender_type === 'user' ? 'flex-row-reverse' : ''}`}>
                    <div className="w-8 h-8 rounded-full flex items-center justify-center text-[11px] font-bold text-white flex-shrink-0 mt-0.5"
                      style={{ background: msg.sender_type === 'user' ? '#4B5563' : (AC[msg.sender_slug] || '#6B7280') }}>
                      {msg.sender_type === 'user' ? '👤' : (msg.sender_name || '?')[0]}
                    </div>
                    <div className={`flex-1 min-w-0 ${msg.sender_type === 'user' ? 'text-right' : ''}`}>
                      <div className="flex items-center gap-2 mb-1" style={msg.sender_type === 'user' ? {justifyContent: 'flex-end'} : {}}>
                        <span className="text-[13px] font-bold" style={{ color: msg.sender_type === 'user' ? '#9CA3AF' : (AC[msg.sender_slug] || '#9CA3AF') }}>{msg.sender_name}</span>
                        {msg.sender_title && <span className="text-[10px] text-gray-500">{msg.sender_title}</span>}
                      </div>
                      <div className={`text-[13px] leading-relaxed whitespace-pre-wrap ${msg.sender_type === 'user' ? 'text-gray-400 bg-s2 rounded-xl px-4 py-2 inline-block' : 'text-gray-300'}`}>{msg.message}</div>
                    </div>
                  </div>
                </React.Fragment>
              );
            })}
            {loading && (
              <div className="flex gap-3 py-2">
                <div className="w-8 h-8 rounded-full bg-s2 flex items-center justify-center animate-pulse">💭</div>
                <div className="text-[13px] text-gray-500 animate-pulse self-center">Agents are thinking...</div>
              </div>
            )}
            <div ref={chatEndRef} />
          </div>

          {/* Controls — user can participate, advance, or end meeting */}
          <div className="sticky bottom-0 bg-bg pt-2 pb-4 space-y-2">
            {/* User chat input — always visible during active meeting */}
            {phase !== 'complete' && (
              <div className="flex gap-2">
                <input value={userMsg} onChange={e => setUserMsg(e.target.value)} onKeyDown={e => e.key === 'Enter' && sendUserMessage()}
                  placeholder="Ask a question, give direction, or @mention an agent..."
                  className="flex-1 bg-s1 border border-border rounded-xl px-4 py-2.5 text-[13px] text-gray-200 outline-none focus:border-primary" />
                <button onClick={sendUserMessage} disabled={!userMsg.trim() || sending} className="bg-primary text-white text-[12px] font-bold px-4 rounded-xl disabled:opacity-40">Send</button>
              </div>
            )}

            {phase !== 'complete' ? (
              <div className="flex items-center gap-3">
                <button onClick={advanceMeeting} disabled={advancing} className="bg-s1 border border-primary/30 text-primary font-heading font-bold text-[12px] rounded-xl px-5 py-2.5 hover:bg-primary/10 disabled:opacity-40 flex-shrink-0">
                  {advancing ? 'Thinking...' : phase === 'opening' ? 'Get Team Input →' : phase === 'contributions' ? 'Open Discussion →' : phase === 'debate' ? 'Create Plan →' : 'Next →'}
                </button>
                <button onClick={handleEndMeeting} className="text-gray-500 text-[12px] border border-border rounded-xl px-4 py-2.5 hover:text-red hover:border-red/30">End Meeting</button>
                <div className="flex-1" />
                <span className="text-[10px] text-gray-500">{PHASE_LABELS[phase] || phase}</span>
              </div>
            ) : (
              <div className="space-y-3">
                {plan && (
                  <div className="bg-s1 border border-accent/30 rounded-xl p-5">
                    <h3 className="font-heading font-bold text-sm text-accent mb-3">📋 Action Plan Ready</h3>
                    {Array.isArray(plan) && plan.map((task, i) => (
                      <div key={i} className="flex items-center gap-3 py-2 border-b border-s2 last:border-0">
                        <span className="w-5 h-5 rounded-full bg-primary/20 text-primary text-[10px] font-bold flex items-center justify-center">{i + 1}</span>
                        <div className="w-6 h-6 rounded-full flex items-center justify-center text-[9px] font-bold text-white" style={{ background: AC[task.agent] || '#6B7280' }}>{(task.agent || '?')[0].toUpperCase()}</div>
                        <div className="flex-1">
                          <div className="text-[12px] font-medium">{task.description}</div>
                          <div className="text-[10px] text-gray-500">{task.engine}/{task.action} · {task.priority}{task.requires_approval ? ' · needs approval' : ''}</div>
                        </div>
                      </div>
                    ))}
                    <div className="flex gap-3 mt-4">
                      <button onClick={approvePlan} className="bg-accent text-black font-heading font-bold text-sm rounded-lg px-6 py-2.5">✅ Approve & Execute</button>
                      <button className="border border-border text-gray-400 text-sm rounded-lg px-4 py-2.5">✏️ Edit Plan</button>
                      <button onClick={() => setView('start')} className="text-gray-500 text-sm">Cancel</button>
                    </div>
                  </div>
                )}
                {!plan && <div className="text-gray-500 text-[13px]">Meeting complete.</div>}
              </div>
            )}
          </div>
        </div>
      )}

      {/* HISTORY */}
      {view === 'history' && (
        <div className="bg-s1 border border-border rounded-xl p-5">
          <h3 className="font-heading font-bold text-sm mb-4">Meeting History</h3>
          {history.length ? history.map(p => (
            <div key={p.id} onClick={() => viewMeeting(p.id)} className="flex items-center justify-between py-3 border-b border-s2 last:border-0 cursor-pointer hover:bg-s2 -mx-2 px-2 rounded-lg">
              <div><div className="text-[13px] font-medium">{p.title || p.goal}</div><div className="text-[11px] text-gray-500">{new Date(p.created_at).toLocaleString()}</div></div>
              <span className={`text-[10px] px-2 py-0.5 rounded-full font-semibold ${p.status === 'completed' ? 'bg-accent/10 text-accent' : 'bg-blue/10 text-blue'}`}>{p.status}</span>
            </div>
          )) : <div className="text-gray-500 text-center py-8">No meetings yet</div>}
        </div>
      )}
    </div>
  );
}
