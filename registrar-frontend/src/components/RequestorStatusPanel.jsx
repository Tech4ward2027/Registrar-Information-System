import React, { useCallback, useEffect, useState } from 'react';
import { useTheme } from '../context/ThemeContext';
import {
  getRequestRepresentative,
  addRequestRepresentative,
  flagRequestor,
  liftRequestorFlag,
} from '../services/api';
import {
  FLAG_STATUS_OPTIONS,
  RELATIONSHIP_OPTIONS,
  PROOF_KINDS,
  buildRepresentativeFormData,
  formatBytes,
  hasLiveRepresentative,
  parseApiError,
  relationshipLabel,
  representativeStatusLabel,
  validateProofFile,
  validateRepresentativeForm,
} from '../utils/representativeHelpers';

const EMPTY_FORM = { fullName: '', email: '', relationship: '', files: {} };

/**
 * RequestorStatusPanel — Notification Policy §3.6, staff side.
 *
 * 1. Mark the requestor deceased / permanently incapacitated.
 * 2. Record their authorized representative with three proofs. The
 *    representative stays PENDING until a Super Admin verifies it; until
 *    then a closure notice is not emailed to anyone and is logged
 *    "sent-on-file" instead.
 * 3. Super Admin only: lift the flag.
 *
 * It mounts only when its section is expanded, so a normal request view makes
 * no extra API call. The server enforces every rule again.
 *
 * Props
 *   request     the open request (needs user_id of the requestor)
 *   requestRef  public_id used in URLs (utils/requestRef.js)
 *   user        the signed-in staff user
 *   onNotify    ({ type: 'success' | 'error', message }) => void
 */
const RequestorStatusPanel = ({ request, requestRef, user, onNotify }) => {
  const { isDark } = useTheme();
  const isSuperAdmin = user?.role_name === 'super_admin';
  const requestorId = request?.user_id ?? request?.user?.user_id ?? request?.rawRequest?.user_id ?? null;

  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState(null);
  const [representative, setRepresentative] = useState(null);
  const [flag, setFlag] = useState(null);

  const [flagStatus, setFlagStatus] = useState('deceased');
  const [flagNote, setFlagNote] = useState('');
  const [confirmFlag, setConfirmFlag] = useState(false);
  const [liftReason, setLiftReason] = useState('');
  const [showLift, setShowLift] = useState(false);

  const [form, setForm] = useState(EMPTY_FORM);
  const [fieldErrors, setFieldErrors] = useState({});
  const [busy, setBusy] = useState(false);

  const notify = useCallback((type, message) => onNotify?.({ type, message }), [onNotify]);

  const load = useCallback(async () => {
    setLoading(true);
    setLoadError(null);
    try {
      const res = await getRequestRepresentative(requestRef);
      setRepresentative(res.data?.data ?? null);
      setFlag(res.data?.requestor_flag ?? null);
    } catch (err) {
      setLoadError(parseApiError(err, 'Unable to load the requestor status.'));
    } finally {
      setLoading(false);
    }
  }, [requestRef]);

  useEffect(() => {
    load();
  }, [load]);

  const card = `rounded-xl border p-3 text-xs space-y-2 ${isDark ? 'bg-[#18191a] border-zinc-800 text-zinc-300' : 'bg-gray-50 border-gray-200 text-gray-700'}`;
  const input = `w-full rounded-lg border px-2.5 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-pup-maroon ${isDark ? 'bg-[#18191a] border-[#3e4042] text-[#e4e6eb]' : 'bg-white border-gray-300 text-gray-900'}`;
  const label = `block text-xs font-semibold mb-1 ${isDark ? 'text-zinc-200' : 'text-gray-700'}`;
  const primaryBtn = 'px-3.5 py-2 rounded-xl text-xs font-bold text-white bg-pup-maroon hover:opacity-90 disabled:opacity-50 transition cursor-pointer';
  const ghostBtn = `px-3.5 py-2 rounded-xl text-xs font-semibold transition cursor-pointer ${isDark ? 'bg-zinc-800 text-zinc-300 hover:bg-zinc-700' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'}`;
  const errText = 'text-[11px] text-red-600 mt-1';

  const handleFlag = async (event) => {
    event.preventDefault();
    if (!requestorId || !confirmFlag || busy) return;
    setBusy(true);
    try {
      await flagRequestor(requestorId, flagStatus, flagNote.trim());
      setConfirmFlag(false);
      setFlagNote('');
      notify('success', 'Requestor marked. Their personal email is now suppressed.');
      await load();
    } catch (err) {
      notify('error', parseApiError(err, 'Unable to mark this requestor.'));
    } finally {
      setBusy(false);
    }
  };

  const handleLift = async (event) => {
    event.preventDefault();
    if (!requestorId || liftReason.trim().length < 5 || busy) return;
    setBusy(true);
    try {
      await liftRequestorFlag(requestorId, liftReason.trim());
      setLiftReason('');
      setShowLift(false);
      notify('success', 'Flag lifted. Any representatives for this requestor were revoked.');
      await load();
    } catch (err) {
      notify('error', parseApiError(err, 'Unable to lift this flag.'));
    } finally {
      setBusy(false);
    }
  };

  const setField = (name, value) => {
    setForm((prev) => ({ ...prev, [name]: value }));
    setFieldErrors((prev) => ({ ...prev, [name]: undefined }));
  };

  const setProof = (kind, file) => {
    const problem = file ? validateProofFile(file) : null;
    setForm((prev) => ({ ...prev, files: { ...prev.files, [kind]: problem ? null : file } }));
    setFieldErrors((prev) => ({ ...prev, [kind]: problem ?? undefined }));
  };

  const handleAddRepresentative = async (event) => {
    event.preventDefault();
    if (busy) return;
    const errors = validateRepresentativeForm(form);
    setFieldErrors(errors);
    if (Object.keys(errors).length > 0) return;

    setBusy(true);
    try {
      await addRequestRepresentative(requestRef, buildRepresentativeFormData(form));
      setForm(EMPTY_FORM);
      notify('success', 'Representative recorded. A Super Admin must verify before any notice is sent to them.');
      await load();
    } catch (err) {
      const serverErrors = err?.response?.data?.errors;
      if (serverErrors) {
        const mapped = {};
        if (serverErrors.full_name) mapped.fullName = serverErrors.full_name[0];
        if (serverErrors.email) mapped.email = serverErrors.email[0];
        if (serverErrors.relationship) mapped.relationship = serverErrors.relationship[0];
        PROOF_KINDS.forEach(({ kind }) => { if (serverErrors[kind]) mapped[kind] = serverErrors[kind][0]; });
        setFieldErrors(mapped);
      }
      notify('error', parseApiError(err, 'Unable to record the representative.'));
    } finally {
      setBusy(false);
    }
  };

  if (loading) {
    return <p className={`text-xs ${isDark ? 'text-zinc-400' : 'text-gray-500'}`}>Loading requestor status...</p>;
  }

  if (loadError) {
    return (
      <div className="space-y-2">
        <p role="alert" className="text-xs text-red-600">{loadError}</p>
        <button type="button" onClick={load} className={ghostBtn}>Try again</button>
      </div>
    );
  }

  // ── Not flagged ──────────────────────────────────────────────────────
  if (!flag) {
    return (
      <form onSubmit={handleFlag} className="space-y-3">
        <p className={`text-xs ${isDark ? 'text-zinc-300' : 'text-gray-600'}`}>
          Use this only when the requestor is deceased or permanently unable to respond. Their personal email will no longer receive any notice, and closure notices go to a verified representative instead.
        </p>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label htmlFor="flag-status" className={label}>Status</label>
            <select id="flag-status" value={flagStatus} onChange={(e) => setFlagStatus(e.target.value)} className={input}>
              {FLAG_STATUS_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
          </div>
          <div>
            <label htmlFor="flag-note" className={label}>Note (optional)</label>
            <input id="flag-note" value={flagNote} maxLength={500} onChange={(e) => setFlagNote(e.target.value)} className={input} placeholder="How was this confirmed?" />
          </div>
        </div>
        <label className={`flex items-start gap-2 text-xs ${isDark ? 'text-zinc-300' : 'text-gray-700'}`}>
          <input type="checkbox" checked={confirmFlag} onChange={(e) => setConfirmFlag(e.target.checked)} className="mt-0.5" />
          <span>I have seen supporting documentation for this status.</span>
        </label>
        <button type="submit" disabled={!confirmFlag || busy || !requestorId} className={primaryBtn}>
          {busy ? 'Saving...' : 'Mark requestor'}
        </button>
        {!requestorId && <p className={errText}>This request has no requestor account to mark.</p>}
      </form>
    );
  }

  // ── Flagged ──────────────────────────────────────────────────────────
  const live = hasLiveRepresentative(representative);

  return (
    <div className="space-y-4">
      <div className={card}>
        <p className="font-bold text-sm text-pup-maroon dark:text-pup-yellow">
          Requestor marked {FLAG_STATUS_OPTIONS.find((o) => o.value === flag.status)?.label.toLowerCase() ?? flag.status}
        </p>
        <p>Their personal email is suppressed. Closure notices go only to a verified representative, or are logged &ldquo;sent-on-file&rdquo; if there is none.</p>
        {isSuperAdmin && !showLift && (
          <button type="button" onClick={() => setShowLift(true)} className={ghostBtn}>Lift flag...</button>
        )}
        {isSuperAdmin && showLift && (
          <form onSubmit={handleLift} className="space-y-2 pt-1">
            <label htmlFor="lift-reason" className={label}>Reason for lifting (kept in the audit trail)</label>
            <input id="lift-reason" value={liftReason} maxLength={500} onChange={(e) => setLiftReason(e.target.value)} className={input} />
            <div className="flex gap-2">
              <button type="submit" disabled={busy || liftReason.trim().length < 5} className={primaryBtn}>Lift flag</button>
              <button type="button" onClick={() => setShowLift(false)} className={ghostBtn}>Cancel</button>
            </div>
          </form>
        )}
      </div>

      {representative && (
        <div className={card}>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="font-bold text-sm">{representative.full_name}</p>
            <span className={`px-2 py-0.5 rounded-full text-[11px] font-semibold border ${
              representative.status === 'verified'
                ? (isDark ? 'bg-emerald-900/40 text-emerald-300 border-emerald-800' : 'bg-emerald-100 text-emerald-800 border-emerald-300')
                : representative.status === 'revoked'
                  ? (isDark ? 'bg-red-900/40 text-red-300 border-red-800' : 'bg-red-100 text-red-800 border-red-300')
                  : (isDark ? 'bg-amber-900/40 text-amber-300 border-amber-800' : 'bg-amber-100 text-amber-800 border-amber-300')
            }`}>
              {representativeStatusLabel(representative.status)}
            </span>
          </div>
          <p>{relationshipLabel(representative.relationship)} &middot; {isSuperAdmin ? representative.email : representative.email_masked}</p>
          <p>
            Proofs on file:{' '}
            {(representative.proofs ?? []).map((p) => PROOF_KINDS.find((k) => k.kind === p.kind)?.label.split(' (')[0] ?? p.kind).join(', ') || 'none'}
          </p>
          {representative.status === 'pending' && (
            <p className={isDark ? 'text-amber-300' : 'text-amber-800'}>
              Awaiting a Super Admin. Verification is done in the Super Admin &ldquo;Representative verification&rdquo; queue, by someone other than the person who added this representative.
            </p>
          )}
          {representative.status === 'revoked' && representative.revoke_reason && <p>Revoked: {representative.revoke_reason}</p>}
        </div>
      )}

      {!live && (
        <form onSubmit={handleAddRepresentative} className="space-y-3" noValidate>
          <p className={`text-sm font-bold ${isDark ? 'text-zinc-200' : 'text-pup-maroon'}`}>Record authorized representative</p>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label htmlFor="rep-name" className={label}>Full name</label>
              <input id="rep-name" value={form.fullName} maxLength={150} onChange={(e) => setField('fullName', e.target.value)} className={input} autoComplete="off" />
              {fieldErrors.fullName && <p className={errText}>{fieldErrors.fullName}</p>}
            </div>
            <div>
              <label htmlFor="rep-email" className={label}>Email (checked against their ID)</label>
              <input id="rep-email" type="email" value={form.email} maxLength={100} onChange={(e) => setField('email', e.target.value)} className={input} autoComplete="off" />
              {fieldErrors.email && <p className={errText}>{fieldErrors.email}</p>}
            </div>
            <div className="sm:col-span-2">
              <label htmlFor="rep-relationship" className={label}>Relationship to the requestor</label>
              <select id="rep-relationship" value={form.relationship} onChange={(e) => setField('relationship', e.target.value)} className={input}>
                <option value="">Select...</option>
                {RELATIONSHIP_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
              </select>
              {fieldErrors.relationship && <p className={errText}>{fieldErrors.relationship}</p>}
            </div>
          </div>

          <div className="space-y-3">
            {PROOF_KINDS.map(({ kind, label: proofLabel }) => (
              <div key={kind}>
                <label htmlFor={`rep-${kind}`} className={label}>{proofLabel}</label>
                <input
                  id={`rep-${kind}`}
                  type="file"
                  accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                  onChange={(e) => setProof(kind, e.target.files?.[0] ?? null)}
                  className={`block w-full text-xs ${isDark ? 'text-zinc-300' : 'text-gray-700'}`}
                />
                {form.files[kind] && <p className={`text-[11px] mt-1 ${isDark ? 'text-zinc-400' : 'text-gray-500'}`}>{form.files[kind].name} ({formatBytes(form.files[kind].size)})</p>}
                {fieldErrors[kind] && <p className={errText}>{fieldErrors[kind]}</p>}
              </div>
            ))}
            <p className={`text-[11px] ${isDark ? 'text-zinc-400' : 'text-gray-500'}`}>PDF, JPG or PNG, up to 5 MB each. Files are encrypted and visible only to a Super Admin.</p>
          </div>

          <button type="submit" disabled={busy} className={primaryBtn}>{busy ? 'Uploading...' : 'Submit for verification'}</button>
        </form>
      )}
    </div>
  );
};

export default RequestorStatusPanel;
