import React, { useState, useEffect } from 'react';
import { createPortal } from 'react-dom';
import { XCircleIcon, CheckCircleIcon } from '@heroicons/react/24/solid';
import {
  getDocumentRequest,
  issueDeficiencyNotice,
  issueItemDeficiencyNotice,
  withdrawDocumentRequest,
  closeRequestUnableToProcess,
  clearDeficiencyNotice,
  voidDeficiencyNotice,
} from '../services/api';
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
  const [fetchedRequest, setFetchedRequest] = useState(null);

  // Form states (store labels for DropdownGroup)
  const [deficiencyLabel, setDeficiencyLabel] = useState(DEFICIENCY_ITEMS[0].label);
  const [deficiencyDetail, setDeficiencyDetail] = useState('');

  const [withdrawalLabel, setWithdrawalLabel] = useState(WITHDRAWAL_REASONS[0].label);
  const [withdrawalDetail, setWithdrawalDetail] = useState('');
  const [supersededByRequestId, setSupersededByRequestId] = useState('');

  const [closureLabel, setClosureLabel] = useState(CLOSURE_REASONS[0].label);
  const [closureDetail, setClosureDetail] = useState('');

  // Clear / Void states
  const [voidReason, setVoidReason] = useState('');
  const [showVoidSection, setShowVoidSection] = useState(false);

  const requestId = subItem?.parentRequestId || subItem?.parentRequest?.id || req?.rawRequest?.request_id || req?.id;

  // Fetch full request details when modal opens to ensure open notices are loaded
  useEffect(() => {
    if (!isOpen || !requestId || modalType !== 'deficiency') return;
    let mounted = true;
    getDocumentRequest(requestId)
      .then((res) => {
        if (mounted && res?.data) {
          setFetchedRequest(res.data);
        }
      })
      .catch(() => {});
    return () => { mounted = false; };
  }, [isOpen, requestId, modalType]);

  if (!isOpen || !req) return null;

  const realItemId = subItem
    ? (subItem.item?.request_document_id ?? subItem.item?.request_certificate_id ?? subItem.id)
    : null;

  const targetReq = fetchedRequest || req.rawRequest || req;

  const itemNotices = targetReq?.open_item_deficiency_notices ||
                      targetReq?.open_deficiency_notices ||
                      req.open_item_deficiency_notices || [];

  const foundItemNotice = Array.isArray(itemNotices) && realItemId
    ? itemNotices.find((n) =>
        subItem?.type === 'cert'
          ? Number(n.request_certificate_id) === Number(realItemId)
          : Number(n.request_document_id) === Number(realItemId)
      )
    : null;

  const openNotice = subItem?.item?.open_deficiency_notice ||
                     subItem?.open_deficiency_notice ||
                     foundItemNotice ||
                     targetReq?.open_deficiency_notice ||
                     req.open_deficiency_notice;

  const handleClearNotice = async () => {
    if (!openNotice?.remark_id) return;
    setLoading(true);
    try {
      await clearDeficiencyNotice(openNotice.remark_id);
      showSuccess('Deficiency notice cleared successfully.');
      onRefresh?.();
      onClose();
    } catch (err) {
      showError(err.response?.data?.message || 'Unable to clear deficiency notice.');
    } finally {
      setLoading(false);
    }
  };

  const handleVoidNotice = async (e) => {
    e.preventDefault();
    if (!openNotice?.remark_id || !voidReason.trim()) return;
    setLoading(true);
    try {
      await voidDeficiencyNotice(openNotice.remark_id, voidReason.trim());
      showSuccess('Deficiency notice voided successfully.');
      onRefresh?.();
      onClose();
    } catch (err) {
      showError(err.response?.data?.message || 'Unable to void deficiency notice.');
    } finally {
      setLoading(false);
    }
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    try {
      if (modalType === 'deficiency') {
        const itemObj = DEFICIENCY_ITEMS.find((i) => i.label === deficiencyLabel);
        const itemKey = itemObj?.key || 'other';
        const realItemId = subItem
          ? (subItem.item?.request_document_id ?? subItem.item?.request_certificate_id ?? subItem.id)
          : null;

        if (subItem && realItemId && !String(realItemId).startsWith('fallback')) {
          await issueItemDeficiencyNotice(requestId, subItem.type, realItemId, {
            item_key: itemKey,
            ...(itemKey === 'other' ? { detail: deficiencyDetail.trim() } : {}),
          });
        } else {
          await issueDeficiencyNotice(requestId, {
            item_key: itemKey,
            ...(itemKey === 'other' ? { detail: deficiencyDetail.trim() } : {}),
          });
        }
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
    if (modalType === 'deficiency') return openNotice ? 'Deficiency Notice Management' : 'Issue Deficiency Notice';
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
          {modalType === 'deficiency' && openNotice && (
            <div className={`p-4 rounded-xl border space-y-3 ${isDark ? 'bg-amber-950/30 border-amber-800/50 text-amber-200' : 'bg-amber-50 border-amber-200 text-amber-900'}`}>
              <div className="flex items-start justify-between">
                <div>
                  <p className="text-xs font-bold uppercase tracking-wider text-amber-600 dark:text-amber-400">Active Deficiency Hold</p>
                  <p className="text-sm font-semibold mt-0.5">{openNotice.item_key ? openNotice.item_key.replace(/_/g, ' ').toUpperCase() : 'Deficiency Notice Open'}</p>
                  {openNotice.detail && <p className="text-xs mt-1 opacity-80">{openNotice.detail}</p>}
                </div>
              </div>

              <div className="flex flex-wrap gap-2 pt-2 border-t border-amber-200/60 dark:border-amber-800/40">
                <button
                  type="button"
                  disabled={loading}
                  onClick={handleClearNotice}
                  className="px-3.5 py-1.5 text-xs font-bold rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white transition-colors flex items-center gap-1.5 cursor-pointer disabled:opacity-50"
                >
                  <CheckCircleIcon className="w-4 h-4" />
                  Mark Resolved / Clear Notice
                </button>

                <button
                  type="button"
                  disabled={loading}
                  onClick={() => setShowVoidSection(!showVoidSection)}
                  className="px-3.5 py-1.5 text-xs font-bold rounded-lg bg-gray-200 hover:bg-gray-300 dark:bg-zinc-700 dark:hover:bg-zinc-600 text-gray-800 dark:text-gray-100 transition-colors flex items-center gap-1.5 cursor-pointer disabled:opacity-50"
                >
                  <XCircleIcon className="w-4 h-4 text-red-500" />
                  Void Notice
                </button>
              </div>

              {showVoidSection && (
                <div className="pt-2 space-y-3.5 border-t border-amber-200/60 dark:border-amber-800/40">
                  <InputGroup
                    label="Void Reason (Required)"
                    name="voidReason"
                    value={voidReason}
                    onChange={(e) => setVoidReason(e.target.value)}
                    placeholder="e.g. Issued by mistake"
                    labelColor={labelColor}
                    voiceEnabled={false}
                    isDark={isDark}
                  />
                  <button
                    type="button"
                    disabled={loading || !voidReason.trim()}
                    onClick={handleVoidNotice}
                    className="px-3.5 py-1.5 text-xs font-bold rounded-lg bg-red-600 hover:bg-red-700 text-white transition-colors cursor-pointer disabled:opacity-50"
                  >
                    Confirm Void Notice
                  </button>
                </div>
              )}
            </div>
          )}

          <form onSubmit={handleSubmit} id="request-action-form" className="space-y-4">
            {modalType === 'deficiency' && (
              <>
                <p className="text-xs font-semibold text-gray-500 dark:text-zinc-400 uppercase tracking-wider">
                  {openNotice ? 'Issue Additional Deficiency Notice' : 'Issue New Deficiency Notice'}
                </p>

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
