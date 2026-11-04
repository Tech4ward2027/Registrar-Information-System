import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { XCircleIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline';
import { useTheme } from '../context/ThemeContext';
import { flagReceipt } from '../services/api';
import { parseApiError } from '../utils/representativeHelpers';

const MIN_REASON = 5;
const MAX_REASON = 1000;

/**
 * FlagReceiptModal — Policy §3.4 "Mismatched or Altered Receipt Flagged".
 *
 * Staff describe what looks wrong with the receipt; the backend audits the
 * action and alerts Super Admin (request number only, never the reason).
 * It does not change the request's status.
 *
 * onDone(message) is called after a successful flag so the parent can toast.
 */
const FlagReceiptModal = ({ open, requestRef, requestId, onClose, onDone }) => {
  const { isDark } = useTheme();
  const [reason, setReason] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => {
    if (open) {
      setReason('');
      setError(null);
      setLoading(false);
    }
  }, [open, requestRef]);

  if (!open) return null;

  const trimmed = reason.trim();
  const tooShort = trimmed.length < MIN_REASON;

  const handleSubmit = async (event) => {
    event.preventDefault();
    if (loading) return;
    if (tooShort) {
      setError(`Please describe the problem (at least ${MIN_REASON} characters).`);
      return;
    }
    setLoading(true);
    setError(null);
    try {
      const res = await flagReceipt(requestRef, trimmed);
      onDone?.(
        res?.data?.notified === false
          ? 'Receipt flagged. Super Admin was already alerted about this request in the last 24 hours.'
          : 'Receipt flagged. Super Admin has been alerted.',
      );
      onClose();
    } catch (err) {
      setError(parseApiError(err, 'Unable to flag this receipt.'));
    } finally {
      setLoading(false);
    }
  };

  return createPortal(
    <div className="fixed inset-0 z-99999 flex items-center justify-center p-3 sm:p-4 overflow-y-auto">
      <div
        className={`fixed inset-0 backdrop-blur-sm ${isDark ? 'bg-black/70' : 'bg-black/50'}`}
        onClick={loading ? undefined : onClose}
      />
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="flag-receipt-title"
        className={`relative my-auto rounded-2xl shadow-2xl w-full max-w-md flex flex-col ${isDark ? 'bg-[#242526] border border-[#3e4042]' : 'bg-white'}`}
      >
        <div className={`px-4 sm:px-5 py-3.5 sm:py-4 rounded-t-2xl flex justify-between items-center ${isDark ? 'bg-[#3a3b3c]' : 'bg-pup-maroon'}`}>
          <h3 id="flag-receipt-title" className="text-sm sm:text-base font-bold text-white pr-2">
            Flag Mismatched / Altered Receipt
          </h3>
          <button type="button" onClick={onClose} disabled={loading} aria-label="Close" className="text-white hover:text-yellow-200 transition cursor-pointer shrink-0">
            <XCircleIcon className="w-5 h-5 sm:w-6 sm:h-6" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="p-4 sm:p-5 space-y-4">
          <p className={`text-xs ${isDark ? 'text-zinc-300' : 'text-gray-600'}`}>
            Request #{requestId}. Super Admin is alerted with the request number only. The reason below is kept in the audit trail, and the request&rsquo;s status does not change.
          </p>

          {error && (
            <div role="alert" className={`p-3 rounded-xl flex items-start gap-2 text-xs border ${isDark ? 'bg-red-950/40 border-red-800 text-red-300' : 'bg-red-50 border-red-200 text-red-700'}`}>
              <ExclamationTriangleIcon className="w-5 h-5 shrink-0 text-red-500 mt-0.5" />
              <div className="flex-1">{error}</div>
            </div>
          )}

          <div className="space-y-1">
            <label htmlFor="flag-receipt-reason" className={`block text-xs font-semibold ${isDark ? 'text-zinc-200' : 'text-gray-700'}`}>
              What looks wrong with the receipt?
            </label>
            <textarea
              id="flag-receipt-reason"
              value={reason}
              onChange={(e) => setReason(e.target.value.slice(0, MAX_REASON))}
              rows={4}
              required
              className={`w-full rounded-xl border p-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-pup-maroon ${isDark ? 'bg-[#18191a] border-[#3e4042] text-[#e4e6eb]' : 'bg-white border-gray-300 text-gray-900'}`}
              placeholder="e.g. Amount on the receipt differs from the cashier record."
            />
            <p className={`text-[11px] text-right ${isDark ? 'text-zinc-400' : 'text-gray-500'}`}>{reason.length}/{MAX_REASON}</p>
          </div>

          <div className="flex justify-end gap-3 pt-1">
            <button
              type="button"
              onClick={onClose}
              disabled={loading}
              className={`px-4 py-2 rounded-xl text-xs font-semibold transition-all cursor-pointer ${isDark ? 'bg-zinc-800 text-zinc-300 hover:bg-zinc-700' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'}`}
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={loading || tooShort}
              className="px-4 py-2 rounded-xl text-xs font-bold text-white bg-red-600 hover:bg-red-700 disabled:opacity-50 transition-all cursor-pointer"
            >
              {loading ? 'Flagging...' : 'Flag receipt'}
            </button>
          </div>
        </form>
      </div>
    </div>,
    document.body,
  );
};

export default FlagReceiptModal;
