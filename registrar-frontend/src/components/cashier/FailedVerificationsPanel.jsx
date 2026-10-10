import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
  ArrowPathIcon, CheckCircleIcon, ChevronLeftIcon, ChevronRightIcon, XCircleIcon, XMarkIcon,
} from '@heroicons/react/24/outline';
import { useTheme } from '../../context/ThemeContext';
import {
  getFailedCashierVerifications,
  getFailedCashierVerification,
  recheckFailedCashierVerification,
} from '../../services/api';

/**
 * Cashier Reconciliation > Failed verifications.
 *
 * One row per failed OR verification, with the likely contributing factors
 * the enrichment job attached. Opening a row loads its detail, which the
 * server records in the audit trail (who looked at whose failure record).
 *
 * Wording rule: codes are "likely contributing factors", never a fault
 * verdict. The Cashier API does not return the name it has on file.
 *
 * Re-check re-runs the Cashier lookup for the STORED failure only (there is
 * no input for an OR number or a name). It is advisory and never approves
 * anything.
 */

const KNOWN_CODES = [
  'OGOS_NOT_FOUND', 'OGOS_UNREACHABLE', 'ALUMNI_LOOKUP_FAILED', 'NO_SNAPSHOT',
  'PROFILE_DRIFT', 'ALL_CANDIDATES_EXHAUSTED',
  'FMT_MISSING_MIDDLE', 'FMT_SUFFIX_IN_SURNAME', 'FMT_NON_ASCII', 'FMT_HYPHEN_OR_SPACING', 'FMT_CASE_INCONSISTENT',
];

const REASON_LABEL = { NOT_FOUND: 'OR not found', API_ERROR: 'Cashier API error' };
const OUTCOME_COPY = {
  matched: 'The Cashier API accepted one of the name formats now. The earlier failure may have been temporary or the receipt was corrected. Nothing was changed.',
  not_found: 'The Cashier API still does not recognise this receipt under any name format RIS can generate. Nothing was changed.',
  api_error: 'The Cashier API could not be reached just now, so this check is inconclusive. Nothing was changed.',
  error: 'The re-check could not be completed. Nothing was changed.',
};

const msg = (err, fallback) => err?.response?.data?.message || fallback;

const formatWhen = (iso) => {
  if (!iso) return '—';
  const d = new Date(iso);
  return isNaN(d.getTime())
    ? iso
    : d.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
};

const CodeChip = ({ code, isDark }) => (
  <span className={`inline-block px-2 py-0.5 rounded-md text-[10px] font-bold ${isDark ? 'bg-zinc-800 text-amber-300' : 'bg-amber-50 text-amber-800'}`}>
    {code}
  </span>
);

const FailedVerificationsPanel = () => {
  const { isDark } = useTheme();

  const emptyFilters = { or_number: '', email: '', reason: '', code: '', days: '30' };
  const [draft, setDraft] = useState(emptyFilters);
  const [applied, setApplied] = useState(emptyFilters);
  const [page, setPage] = useState(1);

  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const [openId, setOpenId] = useState(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);

    const params = { page, per_page: 20, days: applied.days };
    ['or_number', 'email', 'reason', 'code'].forEach((k) => {
      if (applied[k].trim() !== '') params[k] = applied[k].trim();
    });

    getFailedCashierVerifications(params)
      .then((res) => {
        if (cancelled) return;
        setRows(res.data.data ?? []);
        setMeta(res.data.meta ?? { current_page: 1, last_page: 1, total: 0 });
      })
      .catch((err) => {
        if (cancelled) return;
        const status = err?.response?.status;
        if (status === 404) setError('Failed-verification tracking is not enabled in this environment.');
        else if (status === 422) setError(Object.values(err.response.data?.errors ?? {}).flat()[0] ?? 'Check the filters and try again.');
        else setError(msg(err, 'Could not load failed verifications.'));
        setRows([]);
      })
      .finally(() => { if (!cancelled) setLoading(false); });

    return () => { cancelled = true; };
  }, [applied, page]);

  const apply = () => { setPage(1); setApplied({ ...draft }); };
  const reset = () => { setDraft(emptyFilters); setApplied(emptyFilters); setPage(1); };
  const onKey = (e) => { if (e.key === 'Enter') apply(); };

  const inputCls = `w-full text-xs rounded-lg px-3 py-2 border ${isDark ? 'bg-[#18191a] border-[#3e4042] text-[#e4e6eb] placeholder-gray-500' : 'bg-white border-gray-200 text-gray-900'}`;
  const labelCls = `block text-[10px] font-bold uppercase tracking-wider mb-1 ${isDark ? 'text-[#9a9a9a]' : 'text-slate-400'}`;

  return (
    <div className={`rounded-2xl p-4 sm:p-6 space-y-5 ${isDark ? 'bg-[#242526] text-[#e4e6eb] border border-[#3e4042]' : 'bg-white text-gray-900 shadow-md border border-gray-200/80'}`}>
      <div>
        <h2 className={`text-xl font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>Failed verifications</h2>
        <p className={`text-xs mt-1 max-w-3xl ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
          Official receipts that could not be verified against the Cashier system, with the likely contributing factors.
          Codes are not a fault verdict: the Cashier API does not return the name it has on file.
        </p>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
        <div>
          <label className={labelCls} htmlFor="fv-or">OR number (exact)</label>
          <input id="fv-or" className={inputCls} value={draft.or_number} maxLength={50} onKeyDown={onKey}
            onChange={(e) => setDraft({ ...draft, or_number: e.target.value })} />
        </div>
        <div>
          <label className={labelCls} htmlFor="fv-email">Email starts with</label>
          <input id="fv-email" className={inputCls} value={draft.email} maxLength={100} placeholder="min. 3 characters" onKeyDown={onKey}
            onChange={(e) => setDraft({ ...draft, email: e.target.value })} />
        </div>
        <div>
          <label className={labelCls} htmlFor="fv-code">Diagnosis code</label>
          <select id="fv-code" className={inputCls} value={draft.code} onChange={(e) => setDraft({ ...draft, code: e.target.value })}>
            <option value="">Any</option>
            {KNOWN_CODES.map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
        </div>
        <div>
          <label className={labelCls} htmlFor="fv-reason">Failure reason</label>
          <select id="fv-reason" className={inputCls} value={draft.reason} onChange={(e) => setDraft({ ...draft, reason: e.target.value })}>
            <option value="">Any</option>
            <option value="NOT_FOUND">OR not found</option>
            <option value="API_ERROR">Cashier API error</option>
          </select>
        </div>
        <div className="flex gap-2">
          <select aria-label="Date range" className={inputCls} value={draft.days} onChange={(e) => setDraft({ ...draft, days: e.target.value })}>
            <option value="7">7 days</option>
            <option value="30">30 days</option>
            <option value="90">90 days</option>
          </select>
          <button type="button" onClick={apply} className="text-xs font-bold px-4 py-2 rounded-lg bg-[#800000] text-white hover:bg-[#6b0000] cursor-pointer">Apply</button>
          <button type="button" onClick={reset} className={`text-xs font-bold px-3 py-2 rounded-lg border cursor-pointer ${isDark ? 'border-[#3e4042] text-gray-300 hover:bg-zinc-800' : 'border-gray-200 text-gray-600 hover:bg-gray-50'}`}>Reset</button>
        </div>
      </div>

      {error && (
        <div className={`px-4 py-3 rounded-2xl text-sm font-bold border ${isDark ? 'bg-rose-950/40 border-rose-900 text-rose-300' : 'bg-rose-50 border-rose-200 text-rose-700'}`} role="alert">
          {error}
        </div>
      )}

      <div className={`overflow-x-auto rounded-2xl border ${isDark ? 'border-[#3e4042]' : 'border-gray-200'}`}>
        <table className="w-full text-left text-xs">
          <thead className={isDark ? 'bg-[#18191a] text-gray-400' : 'bg-gray-50 text-gray-500'}>
            <tr>
              <th className="px-4 py-3 font-bold">When</th>
              <th className="px-4 py-3 font-bold">Email</th>
              <th className="px-4 py-3 font-bold">OR number</th>
              <th className="px-4 py-3 font-bold">Reason</th>
              <th className="px-4 py-3 font-bold">Likely contributing factors</th>
            </tr>
          </thead>
          <tbody>
            {loading && (
              <tr><td colSpan={5} className="px-4 py-8 text-center italic text-gray-400">Loading…</td></tr>
            )}
            {!loading && rows.length === 0 && !error && (
              <tr><td colSpan={5} className="px-4 py-8 text-center italic text-gray-400">No failed verifications match these filters.</td></tr>
            )}
            {!loading && rows.map((r) => (
              <tr
                key={r.id}
                tabIndex={0}
                onClick={() => setOpenId(r.id)}
                onKeyDown={(e) => { if (e.key === 'Enter') setOpenId(r.id); }}
                className={`cursor-pointer border-t ${isDark ? 'border-[#3e4042] hover:bg-zinc-800/60' : 'border-gray-100 hover:bg-amber-50/40'}`}
              >
                <td className="px-4 py-3 whitespace-nowrap">{formatWhen(r.created_at)}</td>
                <td className="px-4 py-3">{r.email}</td>
                <td className="px-4 py-3 font-mono">{r.or_number ?? '—'}</td>
                <td className="px-4 py-3 whitespace-nowrap">{REASON_LABEL[r.failure_reason] ?? 'Not recorded'}</td>
                <td className="px-4 py-3">
                  {r.diagnosis_codes.length > 0
                    ? <div className="flex flex-wrap gap-1">{r.diagnosis_codes.map((c) => <CodeChip key={c} code={c} isDark={isDark} />)}</div>
                    : <span className="italic text-gray-400">{r.enrichment_status ? 'None found' : 'Not enriched'}</span>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="flex items-center justify-between text-xs">
        <span className={isDark ? 'text-gray-400' : 'text-gray-500'}>{meta.total.toLocaleString()} failed verification{meta.total === 1 ? '' : 's'}</span>
        <div className="flex items-center gap-2">
          <button type="button" disabled={page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}
            className={`inline-flex items-center gap-1 px-2 py-1 disabled:opacity-40 cursor-pointer ${isDark ? 'text-[#b0b3b8] hover:text-white' : 'text-gray-500 hover:text-gray-800'}`}>
            <ChevronLeftIcon className="w-3.5 h-3.5" /> Previous
          </button>
          <span className={isDark ? 'text-gray-300' : 'text-gray-600'}>Page {meta.current_page} of {meta.last_page}</span>
          <button type="button" disabled={page >= meta.last_page} onClick={() => setPage((p) => p + 1)}
            className={`inline-flex items-center gap-1 px-2 py-1 disabled:opacity-40 cursor-pointer ${isDark ? 'text-[#b0b3b8] hover:text-white' : 'text-gray-500 hover:text-gray-800'}`}>
            Next <ChevronRightIcon className="w-3.5 h-3.5" />
          </button>
        </div>
      </div>

      {openId !== null && (
        <DetailDrawer
          id={openId}
          isDark={isDark}
          onClose={() => setOpenId(null)}
          onFilterEmail={(email) => {
            const next = { ...draft, email };
            setDraft(next); setApplied(next); setPage(1); setOpenId(null);
          }}
        />
      )}
    </div>
  );
};

// ── Detail drawer ─────────────────────────────────────────────────────────

const Section = ({ title, isDark, children }) => (
  <section className="space-y-2">
    <h4 className={`text-[10px] font-bold uppercase tracking-wider ${isDark ? 'text-[#9a9a9a]' : 'text-slate-400'}`}>{title}</h4>
    {children}
  </section>
);

const DetailDrawer = ({ id, isDark, onClose, onFilterEmail }) => {
  const [detail, setDetail] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const [rechecking, setRechecking] = useState(false);
  const [recheck, setRecheck] = useState(null);
  const [recheckError, setRecheckError] = useState(null);

  const closeRef = useRef(null);

  // Loading the detail is what the server audits ("viewed"). One load per open.
  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);
    setDetail(null);
    setRecheck(null);
    setRecheckError(null);

    getFailedCashierVerification(id)
      .then((res) => { if (!cancelled) setDetail(res.data.data); })
      .catch((err) => { if (!cancelled) setError(msg(err, 'Could not load this record.')); })
      .finally(() => { if (!cancelled) setLoading(false); });

    return () => { cancelled = true; };
  }, [id]);

  // Keep the latest onClose in a ref so the listener (and the initial
  // focus) are set up once per open, not on every parent re-render.
  const onCloseRef = useRef(onClose);
  onCloseRef.current = onClose;

  useEffect(() => {
    closeRef.current?.focus();
    const onEsc = (e) => { if (e.key === 'Escape') onCloseRef.current(); };
    window.addEventListener('keydown', onEsc);
    return () => window.removeEventListener('keydown', onEsc);
  }, []);

  const runRecheck = useCallback(async () => {
    setRechecking(true);
    setRecheck(null);
    setRecheckError(null);
    try {
      const res = await recheckFailedCashierVerification(id);
      setRecheck(res.data.data);
    } catch (err) {
      const body = err?.response?.data?.data;
      if (body?.outcome) setRecheck(body); // 502 still carries an outcome
      else setRecheckError(msg(err, 'Could not re-check this record.'));
    } finally {
      setRechecking(false);
    }
  }, [id]);

  const panel = isDark ? 'bg-[#242526] text-[#e4e6eb] border-l border-[#3e4042]' : 'bg-white text-gray-900 border-l border-gray-200';

  return (
    <div className="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" aria-label="Failed verification detail">
      <button type="button" aria-label="Close detail" className="absolute inset-0 bg-black/40 cursor-default" onClick={onClose} />
      <aside className={`relative w-full sm:w-[28rem] h-full overflow-y-auto p-5 space-y-5 shadow-2xl ${panel}`}>
        <div className="flex items-start justify-between gap-3">
          <div>
            <h3 className="text-base font-bold">Failed verification</h3>
            {detail && <p className={`text-xs mt-0.5 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>{formatWhen(detail.created_at)}</p>}
          </div>
          <button ref={closeRef} type="button" onClick={onClose} aria-label="Close"
            className={`p-1.5 rounded-full cursor-pointer ${isDark ? 'hover:bg-zinc-800' : 'hover:bg-gray-100'}`}>
            <XMarkIcon className="w-5 h-5" />
          </button>
        </div>

        {loading && <p className="text-xs italic text-gray-400">Loading…</p>}
        {error && <p className={`text-xs font-bold ${isDark ? 'text-rose-300' : 'text-rose-700'}`} role="alert">{error}</p>}

        {detail && (
          <>
            <Section title="Summary" isDark={isDark}>
              <dl className="grid grid-cols-[7rem_1fr] gap-y-1 text-xs">
                <dt className="text-gray-400">Email</dt><dd className="break-all">{detail.email}</dd>
                <dt className="text-gray-400">OR number</dt><dd className="font-mono">{detail.or_number ?? '—'}</dd>
                <dt className="text-gray-400">Reason</dt><dd>{REASON_LABEL[detail.failure_reason] ?? 'Not recorded'}</dd>
              </dl>
            </Section>

            <Section title="Name formats tried" isDark={isDark}>
              {detail.attempts.length === 0 ? <p className="text-xs italic text-gray-400">No attempts were recorded.</p> : (
                <ul className="space-y-1">
                  {detail.attempts.map((a, i) => (
                    <li key={i} className="flex items-start gap-2 text-xs">
                      {a.valid
                        ? <CheckCircleIcon className="w-4 h-4 text-emerald-500 shrink-0" aria-label="accepted" />
                        : <XCircleIcon className="w-4 h-4 text-rose-500 shrink-0" aria-label="not accepted" />}
                      <span className="font-mono break-all">{a.name}</span>
                      <span className="text-gray-400 shrink-0">{a.reason ? REASON_LABEL[a.reason] ?? a.reason : ''}</span>
                    </li>
                  ))}
                </ul>
              )}
            </Section>

            <Section title={`On file in ${detail.source_system === 'alumni_system' ? 'PUPTAPS' : detail.source_system === 'ogos' ? 'OGOS' : 'the source system'}`} isDark={isDark}>
              {detail.on_file_snapshot ? (
                <dl className="grid grid-cols-[7rem_1fr] gap-y-1 text-xs">
                  {['last_name', 'first_name', 'middle_name', 'suffix'].map((k) => (
                    <React.Fragment key={k}>
                      <dt className="text-gray-400">{k.replace('_', ' ')}</dt>
                      <dd>{detail.on_file_snapshot[k] || <span className="italic text-gray-400">empty</span>}</dd>
                    </React.Fragment>
                  ))}
                </dl>
              ) : (
                <p className="text-xs italic text-gray-400">
                  {detail.enrichment_status ? `No snapshot (${detail.enrichment_status.replace('_', ' ')}).` : 'Not enriched yet.'}
                </p>
              )}
              <p className={`text-[11px] ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>A point-in-time snapshot taken after the failure.</p>
            </Section>

            <Section title="Likely contributing factors" isDark={isDark}>
              {detail.diagnosis.length === 0 ? <p className="text-xs italic text-gray-400">No codes were found.</p> : (
                <ul className="space-y-2">
                  {detail.diagnosis.map((d) => (
                    <li key={d.code} className="text-xs">
                      <CodeChip code={d.code} isDark={isDark} />
                      {d.description && <p className={`mt-1 ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>{d.description}</p>}
                    </li>
                  ))}
                </ul>
              )}
              <p className={`text-[11px] ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>{detail.note}</p>
            </Section>

            <Section title={`Also in the last ${detail.cross_links.window_days} days`} isDark={isDark}>
              <div className="flex flex-wrap gap-2 text-xs">
                <button
                  type="button"
                  disabled={detail.cross_links.other_failed_verifications === 0}
                  onClick={() => onFilterEmail(detail.email)}
                  className={`px-3 py-1 rounded-full border font-semibold disabled:opacity-60 disabled:cursor-default cursor-pointer ${isDark ? 'border-[#3e4042] hover:bg-zinc-800' : 'border-gray-200 hover:bg-gray-50'}`}
                >
                  {detail.cross_links.other_failed_verifications} other failed verification{detail.cross_links.other_failed_verifications === 1 ? '' : 's'}
                </button>
                <span className={`px-3 py-1 rounded-full border font-semibold ${isDark ? 'border-[#3e4042]' : 'border-gray-200'}`}>
                  {detail.cross_links.provisioning_failures} provisioning failure{detail.cross_links.provisioning_failures === 1 ? '' : 's'}
                </span>
              </div>
            </Section>

            <Section title="Re-check" isDark={isDark}>
              <p className={`text-[11px] ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                Runs the Cashier lookup again for this stored failure, using name formats generated from the account's current profile.
                It is advisory only and never approves a request or changes any record.
              </p>
              <button
                type="button"
                onClick={runRecheck}
                disabled={rechecking}
                className="inline-flex items-center gap-1.5 text-xs font-bold px-4 py-2 rounded-lg bg-[#800000] text-white hover:bg-[#6b0000] disabled:opacity-50 cursor-pointer"
              >
                <ArrowPathIcon className={`w-4 h-4 ${rechecking ? 'animate-spin' : ''}`} />
                {rechecking ? 'Re-checking…' : 'Re-check now'}
              </button>
              {recheckError && <p className={`text-xs font-bold ${isDark ? 'text-rose-300' : 'text-rose-700'}`} role="alert">{recheckError}</p>}
              {recheck && (
                <div className={`rounded-xl border p-3 text-xs space-y-2 ${isDark ? 'border-[#3e4042] bg-[#18191a]' : 'border-gray-200 bg-gray-50'}`} role="status">
                  <p className="font-bold">{OUTCOME_COPY[recheck.outcome] ?? OUTCOME_COPY.error}</p>
                  {recheck.matched_name && <p>Accepted format: <span className="font-mono">{recheck.matched_name}</span></p>}
                  {recheck.attempts?.length > 0 && (
                    <ul className="space-y-0.5">
                      {recheck.attempts.map((a, i) => (
                        <li key={i} className="flex items-center gap-1.5">
                          {a.valid ? <CheckCircleIcon className="w-3.5 h-3.5 text-emerald-500" /> : <XCircleIcon className="w-3.5 h-3.5 text-rose-500" />}
                          <span className="font-mono break-all">{a.name}</span>
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
              )}
            </Section>
          </>
        )}
      </aside>
    </div>
  );
};

export default FailedVerificationsPanel;
