import React, { useState } from 'react';
import { createPortal } from 'react-dom';
import { XCircleIcon } from '@heroicons/react/24/solid';
import { issueDeficiencyNotice, withdrawDocumentRequest, closeRequestUnableToProcess } from '../services/api';
import { useAlertToast } from '../context/AlertToastContext';
import DropdownGroup from './DropDown';
import InputGroup from './InputGroup';

const DEFICIENCY_ITEMS = [
  { key: 'missing_signature', label: 'Missing Signature / Thumbmark' },
  { key: 'missing_valid_id', label: 'Missing / Invalid ID' },
  { key: 'other', label: 'Other (specify below)' },
];

const WITHDRAWAL_REASONS = [
  { key: 'wrong_item_paid', label: 'Wrong Item Paid For' },
  { key: 'duplicate_submission', label: 'Duplicate Submission' },
  { key: 'student_no_longer_needs', label: 'No Longer Needed' },
  { key: 'other', label: 'Other (specify below)' },
];

const CLOSURE_REASONS = [
  { key: 'requestor_deceased', label: 'Requestor Deceased' },
  { key: 'requestor_incapacitated', label: 'Requestor Incapacitated' },
  { key: 'other', label: 'Other (specify below)' },
];

const RequestActionModal = ({ isOpen, onClose, modalType, req, subItem, isDark, onRefresh }) => {
  const { showSuccess, showError } = useAlertToast();
  const [loading, setLoading] = useState(false);

  // Form states (store labels for DropdownGroup)
  const [deficiencyLabel, setDeficiencyLabel] = useState(DEFICIENCY_ITEMS[0].label);
  const [deficiencyDetail, setDeficiencyDetail] = useState('');

  const [withdrawalLabel, setWithdrawalLabel] = useState(WITHDRAWAL_REASONS[0].label);
  const [withdrawalDetail, setWithdrawalDetail] = useState('');
  const [supersededByRequestId, setSupersededByRequestId] = useState('');

  const [closureLabel, setClosureLabel] = useState(CLOSURE_REASONS[0].label);
  const [closureDetail, setClosureDetail] = useState('');

  if (!isOpen || !req) return null;

  const requestId = subItem?.parentRequestId || subItem?.parentRequest?.id || req.rawRequest?.request_id || req.id;

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    try {
      if (modalType === 'deficiency') {
        const itemObj = DEFICIENCY_ITEMS.find((i) => i.label === deficiencyLabel);
        const itemKey = itemObj?.key || 'other';
        await issueDeficiencyNotice(requestId, {
          item_key: itemKey,
          ...(itemKey === 'other' ? { detail: deficiencyDetail.trim() } : {}),
        });
        showSuccess('Deficiency notice issued successfully.');
      } else if (modalType === 'withdraw') {
        const itemObj = WITHDRAWAL_REASONS.find((i) => i.label === withdrawalLabel);
        const reasonKey = itemObj?.key || 'other';
        await withdrawDocumentRequest(requestId, {
          withdrawal_reason: reasonKey,
          ...(reasonKey === 'other' ? { withdrawal_detail: withdrawalDetail.trim() } : {}),
          ...(supersededByRequestId ? { superseded_by_request_id: Number(supersededByRequestId) } : {}),
        });
        showSuccess('Request withdrawn successfully.');
      } else if (modalType === 'close') {
        const itemObj = CLOSURE_REASONS.find((i) => i.label === closureLabel);
        const reasonKey = itemObj?.key || 'other';
        await closeRequestUnableToProcess(requestId, {
          closure_reason: reasonKey,
          closure_proof_reference: 'N/A',
          ...(reasonKey === 'other' ? { closure_detail: closureDetail.trim() } : {}),
        });
        showSuccess('Request closed successfully.');
      }
      onRefresh?.();
      onClose();
    } catch (err) {
      const msg = err.response?.data?.message || Object.values(err.response?.data?.errors ?? {}).flat().join(' ') || 'Action failed.';
      showError(msg);
    } finally {
      setLoading(false);
    }
  };

  const getTitle = () => {
    if (modalType === 'deficiency') return 'Issue Deficiency Notice';
    if (modalType === 'withdraw') return 'Withdraw Request';
    if (modalType === 'close') return 'Close Request (Unable to Process)';
    return '';
  };

  const labelColor = isDark ? 'text-[#e4e6eb]' : 'text-gray-700';

  const currentDeficiencyKey = DEFICIENCY_ITEMS.find((i) => i.label === deficiencyLabel)?.key;
  const currentWithdrawalKey = WITHDRAWAL_REASONS.find((i) => i.label === withdrawalLabel)?.key;
  const currentClosureKey = CLOSURE_REASONS.find((i) => i.label === closureLabel)?.key;

  return createPortal(
    <div className="fixed inset-0 z-99999 flex items-center justify-center p-4">
      <div
        className={`absolute inset-0 backdrop-blur-sm ${isDark ? 'bg-black/70' : 'bg-black/50'}`}
        onClick={onClose}
      />
      <div
        className={`relative rounded-2xl shadow-2xl w-full max-w-lg overflow-visible flex flex-col ${isDark ? 'bg-[#242526] border border-[#3e4042]' : 'bg-white'
          }`}
        onClick={(e) => e.stopPropagation()}
      >
        {/* Header */}
        <div className={`relative px-4 sm:px-6 py-3 sm:py-4 flex justify-between items-center shrink-0 rounded-t-2xl ${isDark ? 'bg-[#3a3b3c]' : 'bg-pup-maroon'}`}>
          <div>
            <h3 className="text-base sm:text-lg font-bold text-white">{getTitle()}</h3>
            <p className={`text-xs sm:text-sm wrap-break-word ${isDark ? 'text-[#b0b3b8]' : 'text-yellow-200'}`}>
              Student: {req.studentName || req.user_name || 'N/A'} | Request ID: {req.uuid || `#${req.id}`}{subItem ? ` (${subItem.name})` : ''}
            </p>
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close modal"
            className="absolute top-2 right-2 sm:top-3 sm:right-3 text-white hover:text-yellow-200 transition cursor-pointer"
          >
            <XCircleIcon className="w-7 h-7" />
          </button>
        </div>

        {/* Body */}
        <div className={`p-4 sm:p-6 space-y-4 overflow-visible ${isDark ? 'text-[#e4e6eb]' : 'text-gray-900'}`}>
          <form onSubmit={handleSubmit} id="request-action-form" className="space-y-4">
            {modalType === 'deficiency' && (
              <>
                <DropdownGroup
                  label="Deficiency Item"
                  name="deficiencyLabel"
                  value={deficiencyLabel}
                  onChange={(e) => setDeficiencyLabel(e.target.value)}
                  options={DEFICIENCY_ITEMS.map((i) => i.label)}
                  required
                  labelColor={labelColor}
                  isDark={isDark}
                />

                {currentDeficiencyKey === 'other' && (
                  <InputGroup
                    label="Missing Item Detail"
                    name="deficiencyDetail"
                    value={deficiencyDetail}
                    onChange={(e) => setDeficiencyDetail(e.target.value)}
                    placeholder="Specify missing item"
                    required
                    labelColor={labelColor}
                    voiceEnabled={false}
                    isDark={isDark}
                  />
                )}
              </>
            )}

            {modalType === 'withdraw' && (
              <>
                <DropdownGroup
                  label="Withdrawal Reason"
                  name="withdrawalLabel"
                  value={withdrawalLabel}
                  onChange={(e) => setWithdrawalLabel(e.target.value)}
                  options={WITHDRAWAL_REASONS.map((i) => i.label)}
                  required
                  labelColor={labelColor}
                  isDark={isDark}
                />

                <InputGroup
                  label="Corrected Request ID (Optional)"
                  name="supersededByRequestId"
                  value={supersededByRequestId}
                  onChange={(e) => setSupersededByRequestId(e.target.value.replace(/\D/g, ''))}
                  placeholder="e.g. 12345"
                  labelColor={labelColor}
                  voiceEnabled={false}
                  isDark={isDark}
                />

                {currentWithdrawalKey === 'other' && (
                  <InputGroup
                    label="Withdrawal Reason Detail"
                    name="withdrawalDetail"
                    value={withdrawalDetail}
                    onChange={(e) => setWithdrawalDetail(e.target.value)}
                    placeholder="Reason for withdrawal"
                    required
                    labelColor={labelColor}
                    voiceEnabled={false}
                    isDark={isDark}
                  />
                )}
              </>
            )}

            {modalType === 'close' && (
              <>
                <DropdownGroup
                  label="Closure Reason"
                  name="closureLabel"
                  value={closureLabel}
                  onChange={(e) => setClosureLabel(e.target.value)}
                  options={CLOSURE_REASONS.map((i) => i.label)}
                  required
                  labelColor={labelColor}
                  isDark={isDark}
                />

                {currentClosureKey === 'other' && (
                  <InputGroup
                    label="Closure Detail"
                    name="closureDetail"
                    value={closureDetail}
                    onChange={(e) => setClosureDetail(e.target.value)}
                    placeholder="Reason for closure"
                    required
                    labelColor={labelColor}
                    voiceEnabled={false}
                    isDark={isDark}
                  />
                )}
              </>
            )}

            {/* Footer Buttons */}
            <div className="flex justify-end space-x-3 pt-4 border-t border-gray-200 dark:border-[#3e4042]">
              <button
                type="button"
                onClick={onClose}
                disabled={loading}
                className={`px-4 py-2 text-sm font-medium rounded-lg transition cursor-pointer ${isDark
                    ? 'bg-[#3a3b3c] text-[#e4e6eb] hover:bg-[#4e4f50]'
                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                  }`}
              >
                Cancel
              </button>
              <button
                type="submit"
                disabled={loading}
                className="px-4 py-2 text-sm font-medium rounded-lg bg-pup-maroon hover:bg-maroon-700 text-white transition cursor-pointer disabled:opacity-50"
              >
                {loading ? 'Submitting...' : 'Submit Action'}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>,
    document.body
  );
};

export default RequestActionModal;
