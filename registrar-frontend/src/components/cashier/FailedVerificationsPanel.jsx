import React, { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import {
  ArrowPathIcon, CheckCircleIcon, ChevronLeftIcon, ChevronRightIcon, InformationCircleIcon, XCircleIcon, XMarkIcon,
} from '@heroicons/react/24/outline';
import { useSearchParams } from 'react-router-dom';
import { useTheme } from '../../context/ThemeContext';
import DropdownGroup from '../DropDown.jsx';
import InputGroup from '../InputGroup.jsx';
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

const CODE_LABELS = {
  OGOS_NOT_FOUND: 'OGOS not found',
  OGOS_UNREACHABLE: 'Cashier unreachable',
  ALUMNI_LOOKUP_FAILED: 'Alumni lookup failed',
  NO_SNAPSHOT: 'No snapshot',
  PROFILE_DRIFT: 'Profile changed',
  ALL_CANDIDATES_EXHAUSTED: 'No name match',
  FMT_MISSING_MIDDLE: 'Missing middle name',
  FMT_SUFFIX_IN_SURNAME: 'Suffix in surname',
  FMT_NON_ASCII: 'Special characters',
  FMT_HYPHEN_OR_SPACING: 'Spacing/hyphen',
  FMT_CASE_INCONSISTENT: 'Casing mismatch',
};

const formatRelativeWhen = (iso) => {
  if (!iso) return { primary: '—', secondary: '' };
  const d = new Date(iso);
  if (isNaN(d.getTime())) return { primary: iso, secondary: '' };

  const now = new Date();
  const isToday = d.toDateString() === now.toDateString();
  const yesterday = new Date(now);
  yesterday.setDate(yesterday.getDate() - 1);
  const isYesterday = d.toDateString() === yesterday.toDateString();

  const timeStr = d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  const dateStr = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

  if (isToday) {
    return { primary: `Today, ${timeStr}`, secondary: dateStr };
  }
  if (isYesterday) {
    return { primary: `Yesterday, ${timeStr}`, secondary: dateStr };
  }
  return { primary: dateStr, secondary: timeStr };
};

const CodeChip = ({ code, isDark }) => {
  const label = CODE_LABELS[code] || code;
  return (
    <span
      className={`inline-block px-2.5 py-1 rounded-md text-[11px] font-medium transition-colors ${isDark
          ? 'bg-zinc-800 text-zinc-300 border border-zinc-700/60'
          : 'bg-gray-100 text-gray-700 border border-gray-200/80'
        }`}
    >
      {label}
    </span>
  );
};

const ReasonBadge = ({ reason, isDark }) => {
  const isNotFound = reason === 'NOT_FOUND';
  const label = REASON_LABEL[reason] ?? reason ?? 'Not recorded';

  if (isNotFound) {
    return (
      <span
        className={`inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[11px] font-semibold ${isDark
            ? 'bg-rose-950/40 text-rose-300 border border-rose-800/50'
            : 'bg-[#fdf1f0] text-[#c0392b] border border-[#f8d7da]'
          }`}
      >
        <span className="w-1.5 h-1.5 rounded-full bg-[#c0392b]" />
        {label}
      </span>
    );
  }

  return (
    <span
      className={`inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[11px] font-semibold ${isDark
          ? 'bg-amber-950/40 text-amber-300 border border-amber-800/50'
          : 'bg-[#fef9e7] text-[#b7950b] border border-[#fcf3cf]'
        }`}
    >
      <span className="w-1.5 h-1.5 rounded-full bg-[#b7950b]" />
      {label}
    </span>
  );
};

const FailedVerificationsPanel = () => {
  const { isDark } = useTheme();

  const [searchParams, setSearchParams] = useSearchParams();
  const userParam = Number.parseInt(searchParams.get('user') ?? '', 10);
  const userFilter = Number.isInteger(userParam) && userParam > 0 ? userParam : null;
  const clearUserFilter = () => {
    const next = new URLSearchParams(searchParams);
    next.delete('user');
    setSearchParams(next);
    setPage(1);
  };

  const emptyFilters = { search: '', reason: '', code: '', days: '30' };
  const [draft, setDraft] = useState(emptyFilters);
  const [applied, setApplied] = useState(emptyFilters);
  const [page, setPage] = useState(1);
  const [copiedOr, setCopiedOr] = useState(null);

  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const [openId, setOpenId] = useState(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);

    const params = { page, per_page: 5, days: applied.days };
    if (userFilter) params.user_id = userFilter;

    const trimmedSearch = applied.search.trim();
    if (trimmedSearch !== '') {
      if (/^\d+$/.test(trimmedSearch)) {
        params.or_number = trimmedSearch;
      } else {
        params.email = trimmedSearch;
      }
    }

    if (applied.reason) params.reason = applied.reason;
    if (applied.code) params.code = applied.code;

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
  }, [applied, page, userFilter]);

  const apply = (overrideDraft = draft) => {
    setPage(1);
    setApplied({ ...overrideDraft });
  };

  const resetAll = () => {
    setDraft(emptyFilters);
    setApplied(emptyFilters);
    setPage(1);
  };

  const removeDaysFilter = () => {
    const next = { ...draft, days: '30' };
    setDraft(next);
    setApplied(next);
    setPage(1);
  };

  const handleCopy = (e, text) => {
    e.stopPropagation();
    navigator.clipboard?.writeText(text);
    setCopiedOr(text);
    setTimeout(() => setCopiedOr(null), 1500);
  };

  const onSearchKey = (e) => {
    if (e.key === 'Enter') {
      apply();
    }
  };

  const dateOptions = [
    { label: '24 hours', value: '1' },
    { label: '7 days', value: '7' },
    { label: '30 days', value: '30' },
    { label: 'Custom', value: '90' },
  ];

  const hasActiveFilters = Boolean(
    applied.search || applied.reason || applied.code || applied.days !== '30' || userFilter
  );

  const totalPages = Math.max(1, meta.last_page || 1);
  const pageNumbers = () => {
    if (totalPages <= 6) return Array.from({ length: totalPages }, (_, i) => i + 1);
    const pages = [1, 2, 3];
    if (page > 4) pages.push('...');
    if (page > 3 && page < totalPages - 2) pages.push(page);
    pages.push('...', totalPages - 1, totalPages);
    return [...new Set(pages)];
  };

  return (
    <div className={`rounded-2xl p-4 sm:p-5 space-y-4 ${isDark ? 'bg-[#242526] text-[#e4e6eb] border border-[#3e4042]' : 'bg-white text-gray-900 shadow-sm border border-gray-200/80'}`}>
      {/* Title & Subtitle */}
      <div>
        <h2 className={`text-lg font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>Failed verifications</h2>
        <p className={`text-xs mt-0.5 max-w-3xl ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
          Official receipts that could not be verified against the Cashier system, with the likely contributing factors.
          Codes are not a fault verdict: the Cashier API does not return the name it has on file.
        </p>
      </div>

      {userFilter && (
        <div className={`flex items-center justify-between gap-3 rounded-xl border px-3 py-1.5 text-xs ${isDark ? 'border-amber-400/40 text-amber-300' : 'border-amber-300 bg-amber-50 text-amber-800'}`}>
          <span>Showing failures for one account only (from a System Health alert).</span>
          <button type="button" onClick={clearUserFilter} className="font-bold underline cursor-pointer shrink-0">Show all</button>
        </div>
      )}

      {/* Top Filter Bar with reusable fields */}
      <div className="flex flex-wrap items-end gap-3 justify-between">
        {/* Search using InputGroup */}
        <form
          className="flex-1 min-w-[240px] max-w-sm"
          onSubmit={(e) => {
            e.preventDefault();
            apply();
          }}
        >
          <InputGroup
            label="Search"
            name="search"
            value={draft.search}
            onChange={(e) => setDraft({ ...draft, search: e.target.value })}
            placeholder="OR number or email (min. 3 characters)"
            voiceEnabled={false}
            isDark={isDark}
            labelColor="text-gray-700"
          />
        </form>

        {/* Diagnosis code select using DropdownGroup */}
        <div className="w-52">
          <DropdownGroup
            label="Diagnosis code"
            name="code"
            value={CODE_LABELS[draft.code] || (draft.code === '' ? 'All codes' : draft.code)}
            onChange={(e) => {
              const selectedLabel = e.target.value;
              const foundKey = Object.keys(CODE_LABELS).find((k) => CODE_LABELS[k] === selectedLabel);
              const nextCode = selectedLabel === 'All codes' ? '' : (foundKey || selectedLabel);
              const next = { ...draft, code: nextCode };
              setDraft(next);
              apply(next);
            }}
            options={['All codes', ...KNOWN_CODES.map((c) => CODE_LABELS[c] || c)]}
            labelColor="text-gray-700"
            isDark={isDark}
          />
        </div>

        {/* Failure reason select using DropdownGroup */}
        <div className="w-48">
          <DropdownGroup
            label="Failure reason"
            name="reason"
            value={REASON_LABEL[draft.reason] || (draft.reason === '' ? 'All reasons' : draft.reason)}
            onChange={(e) => {
              const selectedLabel = e.target.value;
              const nextReason = selectedLabel === 'OR not found'
                ? 'NOT_FOUND'
                : selectedLabel === 'Cashier API error'
                  ? 'API_ERROR'
                  : '';
              const next = { ...draft, reason: nextReason };
              setDraft(next);
              apply(next);
            }}
            options={['All reasons', 'OR not found', 'Cashier API error']}
            labelColor="text-gray-700"
            isDark={isDark}
          />
        </div>

        {/* Date range pills */}
        <div className="space-y-1">
          <label className={`block text-xs font-semibold ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>
            Date range
          </label>
          <div className={`inline-flex rounded-xl border p-0.5 ${isDark ? 'border-[#3e4042] bg-[#18191a]' : 'border-gray-200 bg-white'}`}>
            {dateOptions.map((opt) => {
              const isActive = draft.days === opt.value;
              return (
                <button
                  key={opt.value}
                  type="button"
                  onClick={() => {
                    const next = { ...draft, days: opt.value };
                    setDraft(next);
                    apply(next);
                  }}
                  className={`px-3 py-1 text-xs font-semibold rounded-lg transition-all cursor-pointer ${isActive
                      ? isDark
                        ? 'bg-pup-maroon text-white shadow-xs font-bold'
                        : 'bg-white text-pup-maroon shadow-xs font-bold border border-gray-200/60'
                      : isDark
                        ? 'text-gray-400 hover:text-white'
                        : 'text-gray-600 hover:text-gray-900'
                    }`}
                >
                  {opt.label}
                </button>
              );
            })}
          </div>
        </div>
      </div>

      {/* Active filters row */}
      <div className="flex items-center gap-2 text-xs pt-0.5">
        <span className={isDark ? 'text-gray-400' : 'text-gray-500'}>Active filters:</span>
        <span
          className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium ${isDark ? 'bg-zinc-800 text-zinc-200 border border-zinc-700' : 'bg-gray-100 text-gray-700'
            }`}
        >
          {applied.days === '1'
            ? 'Last 24 hours'
            : applied.days === '7'
              ? 'Last 7 days'
              : applied.days === '90'
                ? 'Custom'
                : 'Last 30 days'}
          <button
            type="button"
            onClick={removeDaysFilter}
            aria-label="Remove date filter"
            className="hover:opacity-75 transition"
          >
            <XMarkIcon className="w-3.5 h-3.5" />
          </button>
        </span>

        {hasActiveFilters && (
          <button
            type="button"
            onClick={resetAll}
            className="font-bold text-pup-maroon dark:text-pup-yellow hover:underline cursor-pointer ml-1"
          >
            Clear all
          </button>
        )}
      </div>

      {error && (
        <div className={`px-4 py-2.5 rounded-xl text-xs font-bold border ${isDark ? 'bg-rose-950/40 border-rose-900 text-rose-300' : 'bg-rose-50 border-rose-200 text-rose-700'}`} role="alert">
          {error}
        </div>
      )}

      {/* Data Table */}
      <div className="overflow-x-auto">
        <table className="w-full text-left text-xs">
          <thead>
            <tr className={`border-b text-[11px] font-bold uppercase tracking-wider ${isDark ? 'border-[#3e4042] text-gray-400' : 'border-gray-200 text-gray-500'}`}>
              <th className="py-2.5 px-3">
                <span className="inline-flex items-center gap-1">
                  WHEN <span className="text-[10px]">↓</span>
                </span>
              </th>
              <th className="py-2.5 px-3">EMAIL</th>
              <th className="py-2.5 px-3">OR NUMBER</th>
              <th className="py-2.5 px-3">REASON</th>
              <th className="py-2.5 px-3">LIKELY CONTRIBUTING FACTORS</th>
              <th className="py-2.5 px-3 text-right">ACTION</th>
            </tr>
          </thead>
          <tbody className={`divide-y ${isDark ? 'divide-[#3e4042]' : 'divide-gray-100'}`}>
            {loading && (
              <tr><td colSpan={6} className="py-8 text-center italic text-gray-400">Loading…</td></tr>
            )}
            {!loading && rows.length === 0 && !error && (
              <tr><td colSpan={6} className="py-8 text-center italic text-gray-400">No failed verifications match these filters.</td></tr>
            )}
            {!loading && rows.map((r) => {
              const when = formatRelativeWhen(r.created_at);
              return (
                <tr
                  key={r.id}
                  className={`transition-colors ${isDark ? 'hover:bg-zinc-800/40' : 'hover:bg-gray-50/60'}`}
                >
                  {/* When */}
                  <td className="py-3 px-3 align-top whitespace-nowrap">
                    <div className={`font-semibold text-xs ${isDark ? 'text-gray-200' : 'text-gray-900'}`}>{when.primary}</div>
                    {when.secondary && (
                      <div className={`text-[10px] mt-0.5 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>{when.secondary}</div>
                    )}
                  </td>

                  {/* Email */}
                  <td className="py-3 px-3 align-top text-gray-700 dark:text-gray-300 font-medium text-xs">
                    {r.email}
                  </td>

                  {/* OR Number */}
                  <td className="py-3 px-3 align-top whitespace-nowrap">
                    <span className="font-mono text-gray-900 dark:text-white font-semibold text-xs">
                      {r.or_number ?? '—'}
                    </span>
                    {r.or_number && (
                      <button
                        type="button"
                        onClick={(e) => handleCopy(e, r.or_number)}
                        title={copiedOr === r.or_number ? 'Copied!' : 'Copy OR number'}
                        className="ml-1.5 inline-flex items-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 align-middle"
                      >
                        <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2" />
                        </svg>
                      </button>
                    )}
                  </td>

                  {/* Reason */}
                  <td className="py-3 px-3 align-top whitespace-nowrap">
                    <ReasonBadge reason={r.failure_reason} isDark={isDark} />
                  </td>

                  {/* Contributing Factors */}
                  <td className="py-3 px-3 align-top">
                    {r.diagnosis_codes.length > 0 ? (
                      <div className="flex flex-wrap gap-1">
                        {r.diagnosis_codes.map((c) => (
                          <CodeChip key={c} code={c} isDark={isDark} />
                        ))}
                      </div>
                    ) : (
                      <span className="italic text-gray-400">
                        {r.enrichment_status ? 'None found' : 'Not enriched'}
                      </span>
                    )}
                  </td>

                  {/* Action */}
                  <td className="py-3 px-3 align-top text-right whitespace-nowrap">
                    <button
                      type="button"
                      onClick={() => setOpenId(r.id)}
                      className={`px-3.5 py-1 rounded-lg text-xs font-semibold border transition cursor-pointer ${isDark
                          ? 'border-[#3e4042] text-gray-200 hover:bg-zinc-800'
                          : 'border-gray-300 text-gray-800 hover:bg-gray-100 shadow-2xs'
                        }`}
                    >
                      View
                    </button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {/* Pagination Footer (Centered Admin Management Style) */}
      <div className={`flex items-center justify-center gap-1 pt-3 border-t text-xs ${isDark ? 'border-[#3e4042]' : 'border-gray-100'}`}>
        <button
          type="button"
          disabled={page <= 1}
          onClick={() => setPage((p) => Math.max(1, p - 1))}
          className={`flex items-center gap-1 text-xs px-2.5 py-1.5 rounded-lg disabled:opacity-40 cursor-pointer transition-colors ${isDark ? 'text-[#b0b3b8] hover:text-white hover:bg-[#2a2a2f]' : 'text-gray-500 hover:text-gray-800 hover:bg-gray-100'
            }`}
        >
          <ChevronLeftIcon className="w-3.5 h-3.5" /> Previous
        </button>
        {pageNumbers().map((p, i) => (
          <button
            key={i}
            type="button"
            onClick={() => typeof p === 'number' && setPage(p)}
            disabled={p === '...'}
            className={`w-7 h-7 rounded-lg text-xs font-medium transition-colors cursor-pointer flex items-center justify-center ${page === p
                ? isDark
                  ? 'bg-yellow-400 text-gray-900 font-bold'
                  : 'bg-pup-dark-maroon text-white font-bold'
                : isDark
                  ? 'text-[#b0b3b8] hover:bg-[#2a2a2f]'
                  : 'text-gray-500 hover:bg-gray-100'
              } ${p === '...' ? 'cursor-default pointer-events-none' : ''}`}
          >
            {p}
          </button>
        ))}
        <button
          type="button"
          disabled={page >= totalPages}
          onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
          className={`flex items-center gap-1 text-xs px-2.5 py-1.5 rounded-lg disabled:opacity-40 cursor-pointer transition-colors ${isDark ? 'text-[#b0b3b8] hover:text-white hover:bg-[#2a2a2f]' : 'text-gray-500 hover:text-gray-800 hover:bg-gray-100'
            }`}
        >
          Next <ChevronRightIcon className="w-3.5 h-3.5" />
        </button>
      </div>

      {openId !== null && (
        <FailedVerificationDetailModal
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

const FailedVerificationDetailModal = ({ id, isDark, onClose, onFilterEmail }) => {
  const [detail, setDetail] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const [rechecking, setRechecking] = useState(false);
  const [recheck, setRecheck] = useState(null);
  const [recheckError, setRecheckError] = useState(null);
  const [copiedOr, setCopiedOr] = useState(false);

  const closeRef = useRef(null);

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
      if (body?.outcome) setRecheck(body);
      else setRecheckError(msg(err, 'Could not re-check this record.'));
    } finally {
      setRechecking(false);
    }
  }, [id]);

  const handleCopyOr = (text) => {
    navigator.clipboard?.writeText(text);
    setCopiedOr(true);
    setTimeout(() => setCopiedOr(false), 1500);
  };

  const when = detail ? formatRelativeWhen(detail.created_at) : { primary: '', secondary: '' };
  const systemName = detail?.source_system === 'alumni_system' ? 'PUPTAPS' : detail?.source_system === 'ogos' ? 'OGOS' : 'the source system';

  return createPortal(
    <div className="fixed inset-0 z-99999 flex items-center justify-center p-3 sm:p-4 overflow-y-auto" role="dialog" aria-modal="true" aria-label="Failed verification detail">
      {/* Backdrop */}
      <div
        className={`fixed inset-0 backdrop-blur-xs transition-opacity ${isDark ? 'bg-black/70' : 'bg-black/50'}`}
        onClick={onClose}
      />

      {/* Modal Dialog */}
      <div
        className={`relative my-auto rounded-2xl shadow-2xl w-full max-w-4xl max-h-[calc(100vh-64px)] overflow-hidden flex flex-col ${
          isDark ? 'bg-[#242526] border border-[#3e4042] text-[#e4e6eb]' : 'bg-white text-gray-900'
        }`}
      >
        {/* Header (matches RequestDetailModal header design & color) */}
        <div className={`relative px-5 sm:px-6 py-4 flex justify-between items-center shrink-0 ${isDark ? 'bg-[#3a3b3c]' : 'bg-pup-maroon'}`}>
          <div>
            <h3 className="text-base sm:text-lg font-bold text-white">
              Failed verification
            </h3>
            {detail && (
              <p className={`text-xs sm:text-sm mt-0.5 ${isDark ? 'text-[#b0b3b8]' : 'text-yellow-200'}`}>
                {when.primary} · {when.secondary}
              </p>
            )}
          </div>
          <button
            ref={closeRef}
            type="button"
            onClick={onClose}
            aria-label="Close failed verification modal"
            className="text-white hover:text-yellow-200 transition cursor-pointer shrink-0"
          >
            <XCircleIcon className="w-7 h-7" />
          </button>
        </div>

        {/* Content Body */}
        <div className="flex-1 overflow-y-auto p-5 sm:p-6 space-y-6">
          {loading && (
            <div className="py-16 text-center italic text-gray-400 text-sm">
              Loading verification details…
            </div>
          )}

          {error && (
            <div className={`p-4 rounded-xl text-xs font-bold border ${isDark ? 'bg-rose-950/40 border-rose-900 text-rose-300' : 'bg-rose-50 border-rose-200 text-rose-700'}`} role="alert">
              {error}
            </div>
          )}

          {detail && (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
              {/* Left Column */}
              <div className="space-y-6">
                {/* SUMMARY */}
                <div className="space-y-3">
                  <h4 className={`text-[11px] font-bold uppercase tracking-wider ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                    SUMMARY
                  </h4>
                  <div className="space-y-2.5 text-xs">
                    <div className="flex items-center">
                      <span className={`w-28 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Email</span>
                      <span className={`font-semibold ${isDark ? 'text-gray-200' : 'text-gray-900'}`}>{detail.email}</span>
                    </div>
                    <div className="flex items-center">
                      <span className={`w-28 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>OR number</span>
                      <div className="flex items-center gap-2">
                        <span className={`font-mono font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>
                          {detail.or_number ?? '—'}
                        </span>
                        {detail.or_number && (
                          <button
                            type="button"
                            onClick={() => handleCopyOr(detail.or_number)}
                            title={copiedOr ? 'Copied!' : 'Copy OR number'}
                            className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition cursor-pointer"
                          >
                            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2" />
                            </svg>
                          </button>
                        )}
                      </div>
                    </div>
                    <div className="flex items-center">
                      <span className={`w-28 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Reason</span>
                      <ReasonBadge reason={detail.failure_reason} isDark={isDark} />
                    </div>
                  </div>
                </div>

                {/* NAME FORMATS TRIED */}
                <div className="space-y-3">
                  <h4 className={`text-[11px] font-bold uppercase tracking-wider ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                    NAME FORMATS TRIED
                  </h4>
                  {detail.attempts.length === 0 ? (
                    <p className="text-xs italic text-gray-400">No attempts were recorded.</p>
                  ) : (
                    <div className="space-y-2">
                      {detail.attempts.map((a, i) => (
                        <div
                          key={i}
                          className={`flex items-center justify-between px-3.5 py-2.5 rounded-xl border text-xs ${
                            isDark
                              ? 'bg-[#18191a] border-[#3e4042]'
                              : 'bg-white border-gray-200 shadow-2xs'
                          }`}
                        >
                          <div className="flex items-center gap-2.5 min-w-0 pr-2">
                            {a.valid ? (
                              <CheckCircleIcon className="w-4 h-4 text-emerald-500 shrink-0" />
                            ) : (
                              <XCircleIcon className="w-4 h-4 text-rose-500 shrink-0" />
                            )}
                            <span className="font-mono text-xs truncate">
                              {a.name}
                            </span>
                          </div>
                          <span className={`text-[11px] shrink-0 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                            {a.reason ? (REASON_LABEL[a.reason] ?? a.reason) : (a.valid ? 'Accepted' : 'Not found')}
                          </span>
                        </div>
                      ))}
                    </div>
                  )}
                </div>

                {/* ON FILE IN OGOS */}
                <div className="space-y-2">
                  <h4 className={`text-[11px] font-bold uppercase tracking-wider ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                    ON FILE IN {systemName}
                  </h4>
                  <div
                    className={`rounded-2xl p-4 border text-xs space-y-2.5 ${
                      isDark ? 'bg-[#18191a] border-[#3e4042]' : 'bg-[#fafafa] border-gray-200'
                    }`}
                  >
                    {detail.on_file_snapshot ? (
                      <div className="space-y-2">
                        <div className="flex items-center">
                          <span className={`w-28 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Last name</span>
                          <span className="font-bold uppercase tracking-wide">
                            {detail.on_file_snapshot.last_name || <span className="italic text-gray-400">None</span>}
                          </span>
                        </div>
                        <div className="flex items-center">
                          <span className={`w-28 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>First name</span>
                          <span className="font-bold uppercase tracking-wide">
                            {detail.on_file_snapshot.first_name || <span className="italic text-gray-400">None</span>}
                          </span>
                        </div>
                        <div className="flex items-center">
                          <span className={`w-28 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Middle name</span>
                          <div className="flex items-center gap-2">
                            <span className="font-bold uppercase tracking-wide">
                              {detail.on_file_snapshot.middle_name || <span className="italic text-gray-400">None</span>}
                            </span>
                            {detail.diagnosis_codes?.includes('FMT_MISSING_MIDDLE') && (
                              <span className={`px-2 py-0.5 rounded-md text-[10px] font-semibold ${
                                isDark ? 'bg-amber-950/60 text-amber-300 border border-amber-800/40' : 'bg-[#fef9e7] text-[#b7950b] border border-[#fcf3cf]'
                              }`}>
                                Not in local profile
                              </span>
                            )}
                          </div>
                        </div>
                        <div className="flex items-center">
                          <span className={`w-28 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>Suffix</span>
                          <span className="italic text-gray-400">
                            {detail.on_file_snapshot.suffix || 'None'}
                          </span>
                        </div>
                      </div>
                    ) : (
                      <p className="italic text-gray-400">
                        {detail.enrichment_status ? `No snapshot (${detail.enrichment_status.replace('_', ' ')}).` : 'Not enriched yet.'}
                      </p>
                    )}
                  </div>
                  <p className={`text-[11px] ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>
                    Snapshot taken after the failure.
                  </p>
                </div>
              </div>

              {/* Right Column */}
              <div className="space-y-6">
                {/* LIKELY CONTRIBUTING FACTORS */}
                <div className="space-y-3">
                  <h4 className={`text-[11px] font-bold uppercase tracking-wider ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                    LIKELY CONTRIBUTING FACTORS
                  </h4>
                  {detail.diagnosis.length === 0 ? (
                    <div className={`p-4 rounded-xl border text-xs italic ${isDark ? 'border-[#3e4042] text-gray-400' : 'border-gray-200 text-gray-500'}`}>
                      No diagnostic factors were found.
                    </div>
                  ) : (
                    <div className="space-y-3">
                      {detail.diagnosis.map((d) => (
                        <div
                          key={d.code}
                          className={`p-3.5 rounded-2xl border text-xs space-y-1.5 ${
                            isDark ? 'bg-[#18191a] border-[#3e4042]' : 'bg-white border-gray-200 shadow-2xs'
                          }`}
                        >
                          <div className="flex items-center gap-2">
                            <span className={`font-bold ${isDark ? 'text-gray-200' : 'text-gray-900'}`}>
                              {CODE_LABELS[d.code] || d.code}
                            </span>
                            <span className={`px-2 py-0.5 rounded text-[10px] font-mono uppercase ${
                              isDark ? 'bg-zinc-800 text-gray-400' : 'bg-gray-100 text-gray-500'
                            }`}>
                              {d.code}
                            </span>
                          </div>
                          {d.description && (
                            <p className={`text-[11px] leading-relaxed ${isDark ? 'text-gray-400' : 'text-gray-600'}`}>
                              {d.description}
                            </p>
                          )}
                        </div>
                      ))}
                    </div>
                  )}

                  {/* Informational callout box */}
                  <div
                    className={`p-3.5 rounded-2xl border flex items-start gap-2.5 text-xs ${
                      isDark
                        ? 'bg-blue-950/20 border-blue-900/50 text-blue-300'
                        : 'bg-[#f0f7ff] border-blue-100 text-blue-900'
                    }`}
                  >
                    <InformationCircleIcon className="w-4 h-4 shrink-0 text-blue-500 mt-0.5" />
                    <p className="text-[11px] leading-relaxed">
                      These are likely factors, not a fault verdict. The Cashier API does not return the name it has on file, so the cashier-side cause cannot be determined from RIS.
                    </p>
                  </div>
                </div>

                {/* ALSO IN THE LAST X DAYS */}
                <div className="space-y-3">
                  <h4 className={`text-[11px] font-bold uppercase tracking-wider ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                    ALSO IN THE LAST {detail.cross_links.window_days} DAYS
                  </h4>
                  <div className="space-y-2">
                    <button
                      type="button"
                      disabled={detail.cross_links.other_failed_verifications === 0}
                      onClick={() => onFilterEmail(detail.email)}
                      className={`w-full flex items-center justify-between px-4 py-3 rounded-xl border text-xs font-semibold transition cursor-pointer disabled:cursor-default ${
                        isDark
                          ? 'border-[#3e4042] bg-[#18191a] hover:bg-zinc-800 disabled:hover:bg-[#18191a] text-gray-200'
                          : 'border-gray-200 bg-white hover:bg-gray-50 disabled:hover:bg-white text-gray-800 shadow-2xs'
                      }`}
                    >
                      <span>
                        {detail.cross_links.other_failed_verifications} other failed verification{detail.cross_links.other_failed_verifications === 1 ? '' : 's'}
                      </span>
                      <ChevronRightIcon className="w-4 h-4 text-gray-400" />
                    </button>

                    <div
                      className={`w-full flex items-center justify-between px-4 py-3 rounded-xl border text-xs font-semibold ${
                        isDark
                          ? 'border-[#3e4042] bg-[#18191a] text-gray-200'
                          : 'border-gray-200 bg-white text-gray-800 shadow-2xs'
                      }`}
                    >
                      <span>
                        {detail.cross_links.provisioning_failures} provisioning failure{detail.cross_links.provisioning_failures === 1 ? '' : 's'}
                      </span>
                      <ChevronRightIcon className="w-4 h-4 text-gray-400" />
                    </div>
                  </div>
                </div>
              </div>
            </div>
          )}

          {/* Re-check error or status feedback */}
          {recheckError && (
            <div className={`p-3 rounded-xl text-xs font-bold border ${isDark ? 'bg-rose-950/40 border-rose-900 text-rose-300' : 'bg-rose-50 border-rose-200 text-rose-700'}`} role="alert">
              {recheckError}
            </div>
          )}
          {recheck && (
            <div className={`rounded-xl border p-3.5 text-xs space-y-2 ${isDark ? 'border-[#3e4042] bg-[#18191a]' : 'border-gray-200 bg-gray-50'}`} role="status">
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
        </div>

        {/* Modal Footer */}
        <div className={`px-5 sm:px-6 py-4 border-t flex flex-col sm:flex-row items-center justify-between gap-4 ${isDark ? 'border-[#3e4042] bg-[#1e1f20]' : 'border-gray-200 bg-white'}`}>
          <p className={`text-[11px] max-w-md ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
            <strong className={isDark ? 'text-gray-200' : 'text-gray-700'}>Re-check</strong> runs the Cashier lookup again for this stored failure, using name formats generated from the account's current profile. It is advisory only.
          </p>

          <div className="flex items-center gap-2.5 shrink-0 w-full sm:w-auto justify-end">
            <button
              type="button"
              onClick={onClose}
              className={`px-4 py-2 rounded-xl text-xs font-bold border transition cursor-pointer ${
                isDark
                  ? 'border-[#3e4042] text-gray-200 hover:bg-zinc-800'
                  : 'border-gray-300 text-gray-800 hover:bg-gray-100 shadow-2xs'
              }`}
            >
              Close
            </button>
            <button
              type="button"
              onClick={runRecheck}
              disabled={rechecking}
              className="inline-flex items-center justify-center gap-2 px-5 py-2 rounded-xl text-xs font-bold bg-[#800000] text-white hover:bg-[#6b0000] disabled:opacity-50 transition shadow-xs cursor-pointer"
            >
              <ArrowPathIcon className={`w-3.5 h-3.5 ${rechecking ? 'animate-spin' : ''}`} />
              {rechecking ? 'Re-checking…' : 'Re-check with Cashier'}
            </button>
          </div>
        </div>
      </div>
    </div>,
    document.body
  );
};

export default FailedVerificationsPanel;