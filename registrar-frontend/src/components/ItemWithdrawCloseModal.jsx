import React, { useState, useEffect } from 'react';
import { createPortal } from 'react-dom';
import { XCircleIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline';
import { useTheme } from '../context/ThemeContext';
import DropdownGroup from './DropDown';
import InputGroup from './InputGroup';
import { withdrawRequestItem, closeRequestItem } from '../services/api';

const WITHDRAW_REASONS = [
  { key: 'wrong_item_paid', label: 'Wrong item paid / mistyped request' },
  { key: 'duplicate_submission', label: 'Duplicate submission' },
  { key: 'student_no_longer_needs', label: 'Student no longer needs this item' },
  { key: 'other', label: 'Other (specify below)' },
];

const CLOSE_REASONS = [
  { key: 'requestor_deceased', label: 'Requestor Deceased' },
  { key: 'requestor_incapacitated', label: 'Requestor Incapacitated (permanently unable to respond)' },
  { key: 'other', label: 'Other (specify below)' },
];

/**
 * ItemWithdrawCloseModal — Per-Document Withdraw and Close modal for Phase 4.
 * Integrates project-standard DropdownGroup and InputGroup UI components.
 */
const ItemWithdrawCloseModal = ({ open, mode, reqId, subItem, onClose, onSuccess }) => {
  const { isDark } = useTheme();

  const isWithdraw = mode === 'withdraw';
  const reasonsList = isWithdraw ? WITHDRAW_REASONS : CLOSE_REASONS;

  const [selectedLabel, setSelectedLabel] = useState(reasonsList[0].label);
  const [detail, setDetail] = useState('');
  const [proofRef, setProofRef] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => {
    const defaultList = mode === 'withdraw' ? WITHDRAW_REASONS : CLOSE_REASONS;
    setSelectedLabel(defaultList[0].label);
    setDetail('');
    setProofRef('');
    setError(null);
  }, [open, mode, subItem]);

  if (!open || !subItem) return null;

  const currentOption = reasonsList.find((r) => r.label === selectedLabel) || reasonsList[0];
  const isOther = currentOption.key === 'other';
  const itemTitle = subItem.name || 'Selected Item';

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    setError(null);

    const realItemId =
      subItem.item?.request_document_id ??
      subItem.item?.request_certificate_id ??
      subItem.id;

    if (!realItemId || String(realItemId).startsWith('fallback')) {
      setError('Invalid item ID. Unable to perform per-document action.');
      setLoading(false);
      return;
    }

    try {
      if (isWithdraw) {
        if (isOther && !detail.trim()) {
          setError('Please provide detail for the "Other" reason.');
          setLoading(false);
          return;
        }

        const payload = {
          withdrawal_reason: currentOption.key,
          withdrawal_detail: isOther ? detail.trim() : undefined,
        };

        const itemType = subItem.type === 'cert' || subItem.type === 'certificate' ? 'certificate' : 'document';
        const res = await withdrawRequestItem(reqId, itemType, realItemId, payload);
        onSuccess?.(res?.data ?? res, 'Item withdrawn successfully.');
      } else {
        if (isOther && !detail.trim()) {
          setError('Please provide detail for the "Other" reason.');
          setLoading(false);
          return;
        }

        if (!proofRef.trim()) {
          setError('Closure proof reference is required.');
          setLoading(false);
          return;
        }

        const payload = {
          closure_reason: currentOption.key,
          closure_detail: isOther ? detail.trim() : undefined,
          closure_proof_reference: proofRef.trim(),
        };

        const itemType = subItem.type === 'cert' || subItem.type === 'certificate' ? 'certificate' : 'document';
        const res = await closeRequestItem(reqId, itemType, realItemId, payload);
        onSuccess?.(res?.data ?? res, 'Item closed as unable to process.');
      }
      onClose();
    } catch (err) {
      const serverMsg =
        err?.response?.data?.message ||
        Object.values(err?.response?.data?.errors ?? {}).flat().join(' ') ||
        `Unable to ${isWithdraw ? 'withdraw' : 'close'} this item.`;
      setError(serverMsg);
    } finally {
      setLoading(false);
    }
  };

  const labelColor = isDark ? 'text-zinc-200' : 'text-gray-700';

  return createPortal(
    <div className="fixed inset-0 z-99999 flex items-center justify-center p-4">
      <div
        className={`absolute inset-0 backdrop-blur-sm ${isDark ? 'bg-black/70' : 'bg-black/50'}`}
        onClick={onClose}
      />
      <div className={`relative rounded-2xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col ${isDark ? 'bg-[#242526] border border-[#3e4042]' : 'bg-white'}`}>

        {/* Header */}
        <div className={`px-5 py-4 flex justify-between items-center ${isDark ? 'bg-[#3a3b3c]' : 'bg-pup-maroon'}`}>
          <h3 className="text-base font-bold text-white">
            {isWithdraw ? 'Withdraw Item' : 'Close Item — Unable to Process'}
          </h3>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close modal"
            className="text-white hover:text-yellow-200 transition cursor-pointer"
          >
            <XCircleIcon className="w-6 h-6" />
          </button>
        </div>

        {/* Form Body */}
        <form onSubmit={handleSubmit} className="p-5 space-y-4">
          <div className={`p-3.5 rounded-xl border text-xs space-y-1 ${isDark ? 'bg-[#18191a] border-zinc-800 text-zinc-300' : 'bg-gray-50 border-gray-200 text-gray-700'}`}>
            <p className="font-bold text-sm text-pup-maroon dark:text-pup-yellow">
              {itemTitle}
            </p>
            <p className="text-gray-500 dark:text-zinc-400 font-medium">
              Request #{reqId}
            </p>
          </div>

          {error && (
            <div className={`p-3 rounded-xl flex items-start gap-2 text-xs border ${isDark ? 'bg-red-950/40 border-red-800 text-red-300' : 'bg-red-50 border-red-200 text-red-700'}`}>
              <ExclamationTriangleIcon className="w-5 h-5 shrink-0 text-red-500 mt-0.5" />
              <div className="flex-1">{error}</div>
            </div>
          )}

          {/* Reason Selection using project DropdownGroup */}
          <div className="space-y-1">
            <DropdownGroup
              label={isWithdraw ? 'Withdrawal Reason' : 'Closure Reason'}
              name="reasonLabel"
              value={selectedLabel}
              onChange={(e) => setSelectedLabel(e.target.value)}
              options={reasonsList.map((r) => r.label)}
              required
              labelColor={labelColor}
              isDark={isDark}
            />
          </div>

          {/* Detail Input using InputGroup (if reason === 'other') */}
          {isOther && (
            <InputGroup
              label="Reason Detail"
              name="detail"
              value={detail}
              onChange={(e) => setDetail(e.target.value)}
              placeholder="Specify the detailed reason..."
              required
              labelColor={labelColor}
              voiceEnabled={false}
              isDark={isDark}
            />
          )}

          {/* Proof Reference using InputGroup (required for Close) */}
          {!isWithdraw && (
            <InputGroup
              label="Proof Reference"
              name="proofRef"
              value={proofRef}
              onChange={(e) => setProofRef(e.target.value)}
              placeholder="Enter proof or official verification reference details..."
              required
              labelColor={labelColor}
              voiceEnabled={false}
              isDark={isDark}
            />
          )}

          {/* Action Footer */}
          <div className="flex justify-end gap-3 pt-2">
            <button
              type="button"
              onClick={onClose}
              className={`px-4 py-2 rounded-xl text-xs font-semibold transition-all cursor-pointer ${
                isDark ? 'bg-zinc-800 text-zinc-300 hover:bg-zinc-700' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
              }`}
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={loading}
              className={`px-4 py-2 rounded-xl text-xs font-bold text-white transition-all cursor-pointer ${
                isWithdraw
                  ? 'bg-red-600 hover:bg-red-700 disabled:opacity-50'
                  : 'bg-gray-800 hover:bg-gray-900 disabled:opacity-50'
              }`}
            >
              {loading ? 'Processing...' : isWithdraw ? 'Withdraw Item' : 'Close Item'}
            </button>
          </div>
        </form>
      </div>
    </div>,
    document.body
  );
};

export default ItemWithdrawCloseModal;
