import React, { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { saveAs } from 'file-saver';
import { useTheme } from '../context/ThemeContext';
import { useAuth } from '../context/AuthProvider';
import {
  listRepresentatives,
  verifyRepresentative,
  revokeRepresentative,
  downloadRepresentativeProof,
} from '../services/api';
import {
  PROOF_KINDS,
  formatBytes,
  parseApiError,
  relationshipLabel,
  representativeStatusLabel,
} from '../utils/representativeHelpers';
import ErrorToast from '../components/ErrorToast';
import SuccessToast from '../components/SuccessToast';

const TABS = [
  { key: 'pending', label: 'Pending' },
  { key: 'verified', label: 'Verified' },
  { key: 'revoked', label: 'Revoked' },
];

const EXTENSIONS = { 'application/pdf': 'pdf', 'image/jpeg': 'jpg', 'image/png': 'png' };

/**
 * RepresentativeVerificationQueue — Super Admin only (Policy §3.6).
 *
 * Reviews who may receive a deceased or incapacitated requestor's closure
 * notice. For each pending representative the Super Admin opens all three
 * proofs (every open is audited server-side), confirms the email matches the
 * ID, and then verifies or revokes.
 *
 * Verification by the person who added the representative is refused by the
 * server (REPRESENTATIVE_REQUIRE_DISTINCT_VERIFIER); its message is shown.
 */
const RepresentativeVerificationQueue = () => {
  const { isDark } = useTheme();
  const { user } = useAuth();
  const queryClient = useQueryClient();

  const [tab, setTab] = useState('pending');
  const [page, setPage] = useState(1);
  const [opened, setOpened] = useState({});          // `${id}:${kind}` -> true once the proof was opened
  const [attested, setAttested] = useState({});      // id -> true
  const [revokeFor, setRevokeFor] = useState(null);  // representative being revoked
  const [revokeReason, setRevokeReason] = useState('');
  const [busyId, setBusyId] = useState(null);
  const [toast, setToast] = useState({ error: null, success: null });

  const queryKey = ['representatives', tab, page];
  const { data, isLoading, isError, error, refetch, isFetching } = useQuery({
    queryKey,
    queryFn: async () => (await listRepresentatives(tab, page)).data,
    placeholderData: (previous) => previous,
  });

  const rows = data?.data ?? [];
  const lastPage = data?.meta?.last_page ?? 1;
  const total = data?.meta?.total ?? rows.length;

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['representatives'] });
  const fail = (err, fallback) => setToast({ error: parseApiError(err, fallback), success: null });
  const ok = (message) => setToast({ error: null, success: message });

  const switchTab = (key) => {
    setTab(key);
    setPage(1);
  };

  const openProof = async (rep, kind) => {
    setBusyId(`${rep.id}:${kind}`);
    try {
      const res = await downloadRepresentativeProof(rep.id, kind);
      const mime = res.headers?.['content-type'] ?? 'application/octet-stream';
      // Name the download by purpose only: never put the person's name in a file name.
      saveAs(res.data, `${kind}-${rep.id}.${EXTENSIONS[mime.split(';')[0]] ?? 'bin'}`);
      setOpened((prev) => ({ ...prev, [`${rep.id}:${kind}`]: true }));
    } catch (err) {
      // With responseType "blob" an error body arrives as a Blob, so use a plain message.
      fail(err?.rateLimited ? err : null, 'Unable to open this proof. It may have been removed or you have opened too many.');
    } finally {
      setBusyId(null);
    }
  };

  const allProofsOpened = (rep) => PROOF_KINDS.every(({ kind }) => opened[`${rep.id}:${kind}`]);

  const handleVerify = async (rep) => {
    setBusyId(`verify:${rep.id}`);
    try {
      await verifyRepresentative(rep.id);
      ok('Representative verified. Closure notices for this requestor will now go to them.');
      refresh();
    } catch (err) {
      fail(err, 'Unable to verify this representative.');
    } finally {
      setBusyId(null);
    }
  };

  const handleRevoke = async (event) => {
    event.preventDefault();
    if (!revokeFor || revokeReason.trim().length < 5) return;
    setBusyId(`revoke:${revokeFor.id}`);
    try {
      await revokeRepresentative(revokeFor.id, revokeReason.trim());
      ok('Representative revoked.');
      setRevokeFor(null);
      setRevokeReason('');
      refresh();
    } catch (err) {
      fail(err, 'Unable to revoke this representative.');
    } finally {
      setBusyId(null);
    }
  };

  const panel = isDark ? 'bg-[#242526] border-[#3e4042] text-[#e4e6eb]' : 'bg-white border-gray-200 text-gray-900';
  const muted = isDark ? 'text-zinc-400' : 'text-gray-500';
  const ghostBtn = `px-3 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer disabled:opacity-50 ${isDark ? 'bg-zinc-800 text-zinc-200 hover:bg-zinc-700' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'}`;

  return (
    <div className="space-y-5">
      <ErrorToast message={toast.error} onClose={() => setToast((t) => ({ ...t, error: null }))} />
      <SuccessToast message={toast.success} onClose={() => setToast((t) => ({ ...t, success: null }))} />

      <div>
        <h1 className={`text-xl sm:text-2xl font-bold ${isDark ? 'text-[#e4e6eb]' : 'text-pup-maroon'}`}>Representative verification</h1>
        <p className={`text-sm mt-1 ${muted}`}>
          Decide who may receive the closure notice of a deceased or incapacitated requestor. Open all three proofs, confirm the email matches the ID, then verify. Every proof you open is recorded in the audit trail.
        </p>
      </div>

      <div role="tablist" className="flex gap-2">
        {TABS.map(({ key, label }) => (
          <button
            key={key}
            role="tab"
            aria-selected={tab === key}
            onClick={() => switchTab(key)}
            className={`px-4 py-2 rounded-xl text-xs font-bold transition cursor-pointer ${
              tab === key ? 'bg-pup-maroon text-white' : (isDark ? 'bg-zinc-800 text-zinc-300 hover:bg-zinc-700' : 'bg-gray-100 text-gray-700 hover:bg-gray-200')
            }`}
          >
            {label}
          </button>
        ))}
        <span className={`ml-auto self-center text-xs ${muted}`}>{isFetching ? 'Refreshing...' : `${total} total`}</span>
      </div>

      {isLoading && <p className={`text-sm ${muted}`}>Loading...</p>}

      {isError && (
        <div className={`rounded-xl border p-4 text-sm ${panel}`}>
          <p role="alert" className="text-red-600">{parseApiError(error, 'Unable to load the queue.')}</p>
          <button type="button" onClick={() => refetch()} className={`${ghostBtn} mt-2`}>Try again</button>
        </div>
      )}

      {!isLoading && !isError && rows.length === 0 && (
        <div className={`rounded-xl border p-6 text-sm text-center ${panel} ${muted}`}>
          Nothing {tab === 'pending' ? 'is waiting for verification' : `is ${tab}`}.
        </div>
      )}

      <ul className="space-y-4">
        {rows.map((rep) => {
          const isOwnEntry = rep.created_by != null && rep.created_by === user?.user_id;
          return (
            <li key={rep.id} className={`rounded-xl border p-4 space-y-3 ${panel}`}>
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <p className="font-bold text-sm">{rep.full_name}</p>
                  <p className={`text-xs ${muted}`}>
                    {relationshipLabel(rep.relationship)} &middot; {rep.email ?? rep.email_masked} &middot; Request #{rep.request_id}
                  </p>
                  <p className={`text-xs ${muted}`}>Added {rep.created_at ? new Date(rep.created_at).toLocaleString() : ''}{isOwnEntry ? ' by you' : ''}</p>
                </div>
                <span className={`px-2 py-0.5 rounded-full text-[11px] font-semibold border ${isDark ? 'border-zinc-600' : 'border-gray-300'}`}>
                  {representativeStatusLabel(rep.status)}
                </span>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
                {PROOF_KINDS.map(({ kind, label }) => {
                  const proof = (rep.proofs ?? []).find((p) => p.kind === kind);
                  const done = opened[`${rep.id}:${kind}`];
                  return (
                    <button
                      key={kind}
                      type="button"
                      disabled={!proof || busyId === `${rep.id}:${kind}`}
                      onClick={() => openProof(rep, kind)}
                      className={`${ghostBtn} text-left ${done ? 'ring-1 ring-emerald-500' : ''}`}
                    >
                      <span className="block">{label.split(' (')[0]}</span>
                      <span className={`block text-[11px] font-normal ${muted}`}>
                        {proof ? `${formatBytes(proof.size)}${done ? ' · opened' : ''}` : 'Missing'}
                      </span>
                    </button>
                  );
                })}
              </div>

              {rep.status === 'revoked' && rep.revoke_reason && (
                <p className={`text-xs ${muted}`}>Revoked: {rep.revoke_reason}</p>
              )}

              {rep.status === 'pending' && (
                <div className="space-y-2">
                  <label className="flex items-start gap-2 text-xs">
                    <input
                      type="checkbox"
                      checked={Boolean(attested[rep.id])}
                      disabled={!allProofsOpened(rep)}
                      onChange={(e) => setAttested((prev) => ({ ...prev, [rep.id]: e.target.checked }))}
                      className="mt-0.5"
                    />
                    <span>
                      I opened all three proofs and confirmed the ID, the relationship and the death or incapacity document support this person, and that the email above belongs to them.
                      {!allProofsOpened(rep) && <span className={muted}> (Open all three proofs first.)</span>}
                    </span>
                  </label>
                  {isOwnEntry && (
                    <p className="text-[11px] text-amber-600">You added this representative. Unless your office allows it, another Super Admin must verify.</p>
                  )}
                  <div className="flex gap-2">
                    <button
                      type="button"
                      disabled={!attested[rep.id] || busyId === `verify:${rep.id}`}
                      onClick={() => handleVerify(rep)}
                      className="px-4 py-2 rounded-xl text-xs font-bold text-white bg-emerald-700 hover:bg-emerald-800 disabled:opacity-50 transition cursor-pointer"
                    >
                      {busyId === `verify:${rep.id}` ? 'Verifying...' : 'Verify'}
                    </button>
                    <button type="button" onClick={() => { setRevokeFor(rep); setRevokeReason(''); }} className={ghostBtn}>Reject / revoke</button>
                  </div>
                </div>
              )}

              {rep.status === 'verified' && (
                <button type="button" onClick={() => { setRevokeFor(rep); setRevokeReason(''); }} className={ghostBtn}>Revoke</button>
              )}
            </li>
          );
        })}
      </ul>

      {lastPage > 1 && (
        <div className="flex items-center justify-center gap-3">
          <button type="button" disabled={page <= 1} onClick={() => setPage((p) => p - 1)} className={ghostBtn}>Previous</button>
          <span className={`text-xs ${muted}`}>Page {page} of {lastPage}</span>
          <button type="button" disabled={page >= lastPage} onClick={() => setPage((p) => p + 1)} className={ghostBtn}>Next</button>
        </div>
      )}

      {revokeFor && (
        <div className="fixed inset-0 z-99999 flex items-center justify-center p-4">
          <div className={`fixed inset-0 backdrop-blur-sm ${isDark ? 'bg-black/70' : 'bg-black/50'}`} onClick={() => setRevokeFor(null)} />
          <form
            onSubmit={handleRevoke}
            role="dialog"
            aria-modal="true"
            aria-labelledby="revoke-title"
            className={`relative w-full max-w-md rounded-2xl p-5 space-y-3 shadow-2xl ${isDark ? 'bg-[#242526] border border-[#3e4042] text-[#e4e6eb]' : 'bg-white text-gray-900'}`}
          >
            <h3 id="revoke-title" className="font-bold text-base">Revoke representative</h3>
            <p className={`text-xs ${muted}`}>
              {revokeFor.full_name} will no longer receive notices for request #{revokeFor.request_id}. The proofs are kept as audit evidence.
            </p>
            <label htmlFor="revoke-reason" className="block text-xs font-semibold">Reason (kept in the audit trail)</label>
            <textarea
              id="revoke-reason"
              rows={3}
              maxLength={500}
              value={revokeReason}
              onChange={(e) => setRevokeReason(e.target.value)}
              className={`w-full rounded-lg border p-2.5 text-sm ${isDark ? 'bg-[#18191a] border-[#3e4042]' : 'bg-white border-gray-300'}`}
            />
            <div className="flex justify-end gap-2">
              <button type="button" onClick={() => setRevokeFor(null)} className={ghostBtn}>Cancel</button>
              <button
                type="submit"
                disabled={revokeReason.trim().length < 5 || busyId === `revoke:${revokeFor.id}`}
                className="px-4 py-2 rounded-xl text-xs font-bold text-white bg-red-600 hover:bg-red-700 disabled:opacity-50 cursor-pointer"
              >
                Revoke
              </button>
            </div>
          </form>
        </div>
      )}
    </div>
  );
};

export default RepresentativeVerificationQueue;
