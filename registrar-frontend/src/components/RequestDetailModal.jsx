import React, { useState, useEffect, useMemo } from 'react';
import { createPortal } from 'react-dom';
import { ChevronDownIcon, ChevronUpIcon, XCircleIcon, ArrowRightIcon, PrinterIcon, ArrowDownTrayIcon } from '@heroicons/react/24/solid';
import { toPng } from 'html-to-image';
import { getDocumentTypes, getDocumentRequest, updateRequestDocumentStatus, updateRequestCertificateStatus, withdrawDocumentRequest, issueDeficiencyNotice, clearDeficiencyNotice, voidDeficiencyNotice, closeRequestUnableToProcess } from "../services/api";
import { PROGRESS_MAP } from '../utils/constants';
import { useTheme } from '../context/ThemeContext';
import { useReferenceData } from '../context/ReferenceDataContext';
import { hasModuleAction } from '../utils/policy';
import ClaimTicket from './ClaimTicket';
import DropDown from './DropDown';
import InputGroup from './InputGroup';
import ErrorToast from './ErrorToast';
import SuccessToast from './SuccessToast';

/**
 * Item-level "next action" for a single request_document/request_certificate
 * row — mirrors RequestStatusEnum::allowedTransitions() on the backend, but
 * only surfaces the single sensible forward action per stage (same
 * convention the whole-request buttons in StaffDashboard.jsx already use)
 * rather than a generic transition picker. The backend is still the
 * authority: it validates the transition independently and this list only
 * decides what a button is offered for, same as every other status button
 * in this app.
 *
 * requiredAction mirrors RequestItemStatusService::authorizeItemStatusChange()
 * — 'Complete' only for the move into Completed, 'Process' for everything
 * else — so a button is hidden here exactly when the backend would reject
 * it for lack of permission.
 */
const ITEM_NEXT_ACTIONS = {
  12: [{ label: 'Confirm Received',      target: 1, requiredAction: 'Process'  }], // AwaitingSubmission -> Processing
  1:  [
    { label: 'Send for Signature',       target: 6, requiredAction: 'Process'  }, // Processing -> PendingSignature
    { label: 'Mark Ready to Claim',      target: 2, requiredAction: 'Process'  }, // Processing -> ReadyToClaim
  ],
  6:  [{ label: 'Mark Ready to Claim',    target: 2, requiredAction: 'Process'  }], // PendingSignature -> ReadyToClaim
  2:  [{ label: 'Mark Completed',         target: 3, requiredAction: 'Complete' }], // ReadyToClaim -> Completed
};

const WITHDRAWAL_REASONS = [
  ['wrong_item_paid', 'Wrong item paid'],
  ['duplicate_submission', 'Duplicate submission'],
  ['student_no_longer_needs', 'Student no longer needs it'],
  ['other', 'Other'],
];

const DEFICIENCY_ITEMS = [
  ['missing_signature', 'Missing signature'],
  ['missing_valid_id', 'Missing valid ID'],
  ['other', 'Other'],
];

const CLOSURE_REASONS = [
  ['requestor_deceased', 'Requestor deceased'],
  ['requestor_incapacitated', 'Requestor permanently unable to respond'],
  ['other', 'Other'],
];

const STALE_NOTICE_DAYS = 14;

const isWithdrawnRequest = (request) => (
  Number(request?.status_id ?? request?.statusId) === 13 ||
  String(request?.status?.status_name ?? request?.statusName ?? request?.status ?? '').toLowerCase() === 'withdrawn'
);

const isTerminalRequest = (request) => (
  isWithdrawnRequest(request) || Number(request?.status_id ?? request?.statusId) === 14 ||
  String(request?.status?.status_name ?? request?.statusName ?? request?.status ?? '').toLowerCase() === 'closed - unable to process'
);

const RequestDetailsModal = ({ request, onClose, user, onGenerateCert, onRequestUpdated }) => {
  const { docTypeName, purposeName, certName, statusConfig } = useReferenceData();
  const [docTypes, setDocTypes] = useState([]);
  const [liveRequest, setLiveRequest] = useState(request);
  const [updatingItemKey, setUpdatingItemKey] = useState(null);
  const [itemError, setItemError] = useState(null);
  const [actionError, setActionError] = useState(null);
  const [actionSuccess, setActionSuccess] = useState(null);
  const [actionLoading, setActionLoading] = useState(false);
  const [withdrawalReason, setWithdrawalReason] = useState('wrong_item_paid');
  const [withdrawalDetail, setWithdrawalDetail] = useState('');
  const [supersededByRequestId, setSupersededByRequestId] = useState('');
  const [deficiencyItem, setDeficiencyItem] = useState('missing_signature');
  const [deficiencyDetail, setDeficiencyDetail] = useState('');
  const [voidReason, setVoidReason] = useState('');
  const [showWithdrawForm, setShowWithdrawForm] = useState(false);
  const [closureReason, setClosureReason] = useState('requestor_deceased');
  const [closureDetail, setClosureDetail] = useState('');
  const [closureProofReference, setClosureProofReference] = useState('');
  const { isDark } = useTheme();

  const canProcess  = hasModuleAction(user, 'dashboard', 'Process');
  const canComplete = hasModuleAction(user, 'dashboard', 'Complete');
  const isAdmin     = ['admin', 'super_admin'].includes(user?.role_name) || canProcess || canComplete;

  useEffect(() => {
    const fetchTypes = async () => {
      try {
        const res = await getDocumentTypes();
        setDocTypes(res.data);
      } catch (err) {
        console.error("Failed to load document types:", err);
      }
    };
    fetchTypes();
  }, []);

  // Reset the local "live" copy whenever a different request is opened
  // (or the modal is closed), so per-item status edits don't leak
  // between requests and a freshly-opened request always starts from
  // the parent-provided data.
  useEffect(() => {
    setLiveRequest(request);
    setItemError(null);
    setActionError(null);
    setActionSuccess(null);
    setShowWithdrawForm(false);
    setWithdrawalReason('wrong_item_paid');
    setWithdrawalDetail('');
    setSupersededByRequestId('');
    setDeficiencyItem('missing_signature');
    setDeficiencyDetail('');
    setVoidReason('');
    setClosureReason('requestor_deceased');
    setClosureDetail('');
    setClosureProofReference('');
  }, [request]);

  useEffect(() => {
    if (request) {
      document.body.style.overflow = 'hidden';
    } else {
      document.body.style.overflow = 'unset';
    }
    return () => {
      document.body.style.overflow = 'unset';
    };
  }, [request]);

  const activeRequest = liveRequest ?? request?.rawRequest ?? request;
  const isStudent = activeRequest?.student_profile != null || request?.userType === 'Student';
  const isAlumni = activeRequest?.alumni_profile != null || request?.userType === 'Alumni'; 
  const progress = activeRequest ? (PROGRESS_MAP[activeRequest.status_id ?? request?.statusId] ?? 0) : 0;
  const requestDocs = activeRequest?.documents ?? activeRequest?.rawRequest?.documents ?? request?.documents ?? request?.rawRequest?.documents ?? [];
  const requestCerts = activeRequest?.certificates ?? activeRequest?.rawRequest?.certificates ?? request?.certificates ?? request?.rawRequest?.certificates ?? [];

  // Re-fetches the whole request after a single item's status changes,
  // so the progress bar / aggregate status / other items all reflect
  // whatever RequestItemStatusService::recomputeAggregateStatus() landed
  // on server-side, rather than trying to predict the aggregate
  // client-side.
  const refreshRequest = async () => {
    if (!activeRequest?.request_id) return;
    try {
      const res = await getDocumentRequest(activeRequest.request_id);
      setLiveRequest(res.data);
    } catch (err) {
      console.error("Failed to refresh request after item update:", err);
    }
  };

  const advanceDocumentItem = async (item, targetStatusId) => {
    if (!activeRequest || isTerminalRequest(activeRequest)) return;
    const key = `doc-${item.request_document_id}`;
    setUpdatingItemKey(key);
    setItemError(null);
    setActionError(null);
    try {
      await updateRequestDocumentStatus(activeRequest.request_id, item.request_document_id, targetStatusId);
      await refreshRequest();
      setActionSuccess("Document status updated successfully.");
    } catch (err) {
      setItemError(err.response?.data?.message ?? 'Failed to update this item\'s status.');
    } finally {
      setUpdatingItemKey(null);
    }
  };

  const advanceCertificateItem = async (item, targetStatusId) => {
    if (!activeRequest || isTerminalRequest(activeRequest)) return;
    const key = `cert-${item.request_certificate_id}`;
    setUpdatingItemKey(key);
    setItemError(null);
    setActionError(null);
    try {
      await updateRequestCertificateStatus(activeRequest.request_id, item.request_certificate_id, targetStatusId);
      await refreshRequest();
      setActionSuccess("Certificate status updated successfully.");
    } catch (err) {
      setItemError(err.response?.data?.message ?? 'Failed to update this item\'s status.');
    } finally {
      setUpdatingItemKey(null);
    }
  };

  const getDocName = (doc) => {
    // 1. Try the eager-loaded name from the backend relationship
    // 2. Fallback to searching the docTypes state
    // 3. Final fallback to the constant or "Unknown"
    return doc.document_type?.document_name ?? 
          docTypes.find(t => t.document_type_id === doc.document_type_id)?.document_name ?? 
          docTypeName(doc.document_type_id) ?? 
          "Unknown Document";
  };

  const displayStatus = activeRequest?.status?.status_name || activeRequest?.status || 'N/A';
  const statusId = Number(activeRequest?.status_id ?? request?.statusId);
  const openNotice = activeRequest?.open_deficiency_notice ?? activeRequest?.openDeficiencyNotice;
  const isWithdrawn = statusId === 13 || String(displayStatus).toLowerCase() === 'withdrawn';
  const isClosedUnableToProcess = statusId === 14 || String(displayStatus).toLowerCase() === 'closed - unable to process';
  const isTerminal = isWithdrawn || isClosedUnableToProcess;
  const canWithdraw = canProcess && Boolean(activeRequest) && !activeRequest.is_archived && [1, 6, 12].includes(statusId) && !isTerminal;
  const canManageNotice = canProcess && Boolean(activeRequest) && !activeRequest.is_archived && !isTerminal;
  const noticeIsStale = openNotice?.issued_at
    ? Date.now() - new Date(openNotice.issued_at).getTime() >= STALE_NOTICE_DAYS * 24 * 60 * 60 * 1000
    : false;
  const noticeIsEscalated = Boolean(openNotice?.escalated_at || openNotice?.is_escalated);
  const releaseGroups = activeRequest?.release_groups ?? [];
  const hasReleaseGroups = releaseGroups.length > 0;

  const ticketsList = useMemo(() => {
    if (!activeRequest) return [];
    if (hasReleaseGroups) {
      return releaseGroups.map((group) => {
        const groupStatus = statusConfig(group.status_id);
        const trackLabel = group.fulfillment_track?.name ?? 'Standard';
        return {
          id: group.request_release_group_id,
          trackLabel,
          statusLabel: groupStatus?.label ?? 'Processing',
          claimCode: group.claim_code,
          uuid: group.uuid,
        };
      });
    }

    const docs = requestDocs || [];
    const certs = requestCerts || [];

    const hasCtc = docs.some((d) => {
      const name = String(d.document_type?.document_name || d.document_name || '').toLowerCase();
      return name.includes('ctc') || name.includes('certified true copy');
    }) || certs.some((c) => {
      const name = String(c.certification_type?.certificate_name || c.certificate_name || c.name || '').toLowerCase();
      return name.includes('ctc') || name.includes('certified true copy');
    });

    const hasStandard = docs.some((d) => {
      const name = String(d.document_type?.document_name || d.document_name || '').toLowerCase();
      return !name.includes('ctc') && !name.includes('certified true copy');
    }) || certs.length > 0;

    const list = [];
    if (hasCtc) {
      list.push({
        id: 'ctc-ticket',
        trackLabel: 'CTC',
        statusLabel: displayStatus,
        claimCode: activeRequest.claim_code,
        uuid: activeRequest.uuid,
      });
    }

    if (hasStandard || !hasCtc) {
      list.push({
        id: 'standard-ticket',
        trackLabel: 'Standard',
        statusLabel: displayStatus,
        claimCode: activeRequest.claim_code,
        uuid: activeRequest.uuid,
      });
    }

    return list;
  }, [hasReleaseGroups, releaseGroups, requestDocs, requestCerts, displayStatus, activeRequest, statusConfig]);

  if (!request) return null;

  const updateRequestFromResponse = (response) => {
    const updatedRequest = response?.data ?? response;
    if (updatedRequest?.request_id) setLiveRequest(updatedRequest);
    onRequestUpdated?.(updatedRequest);
  };

  const handleWithdraw = async (event) => {
    event.preventDefault();
    setActionLoading(true);
    setActionError(null);
    try {
      const response = await withdrawDocumentRequest(activeRequest.request_id, {
        withdrawal_reason: withdrawalReason,
        withdrawal_detail: withdrawalReason === 'other' ? withdrawalDetail.trim() : undefined,
        superseded_by_request_id: supersededByRequestId ? Number(supersededByRequestId) : undefined,
      });
      updateRequestFromResponse(response);
      setShowWithdrawForm(false);
      setActionSuccess("Request withdrawn successfully.");
    } catch (err) {
      setActionError(err.response?.data?.message || Object.values(err.response?.data?.errors ?? {}).flat().join(' ') || 'Unable to withdraw this request.');
    } finally {
      setActionLoading(false);
    }
  };

  const handleIssueNotice = async (event) => {
    event.preventDefault();
    setActionLoading(true);
    setActionError(null);
    try {
      const response = await issueDeficiencyNotice(activeRequest.request_id, {
        item_key: deficiencyItem,
        detail: deficiencyItem === 'other' ? deficiencyDetail.trim() : undefined,
      });
      const updatedRequest = { ...activeRequest, open_deficiency_notice: response.data };
      setLiveRequest(updatedRequest);
      onRequestUpdated?.(updatedRequest);
      setDeficiencyDetail('');
      setActionSuccess("Deficiency notice issued successfully.");
    } catch (err) {
      setActionError(err.response?.data?.message || Object.values(err.response?.data?.errors ?? {}).flat().join(' ') || 'Unable to issue the deficiency notice.');
    } finally {
      setActionLoading(false);
    }
  };

  const handleClearNotice = async () => {
    setActionLoading(true);
    setActionError(null);
    try {
      await clearDeficiencyNotice(openNotice.remark_id);
      const updatedRequest = { ...activeRequest, open_deficiency_notice: null };
      setLiveRequest(updatedRequest);
      onRequestUpdated?.(updatedRequest);
      setActionSuccess("Deficiency notice cleared successfully.");
    } catch (err) {
      setActionError(err.response?.data?.message || 'Unable to clear the deficiency notice.');
    } finally {
      setActionLoading(false);
    }
  };

  const handleVoidNotice = async (event) => {
    event.preventDefault();
    if (!voidReason.trim()) return;
    setActionLoading(true);
    setActionError(null);
    try {
      await voidDeficiencyNotice(openNotice.remark_id, voidReason.trim());
      const updatedRequest = { ...activeRequest, open_deficiency_notice: null };
      setLiveRequest(updatedRequest);
      onRequestUpdated?.(updatedRequest);
      setVoidReason('');
      if (canWithdraw) setShowWithdrawForm(true);
      setActionSuccess("Deficiency notice voided successfully.");
    } catch (err) {
      setActionError(err.response?.data?.message || 'Unable to void the deficiency notice.');
    } finally {
      setActionLoading(false);
    }
  };

  const handleCloseUnableToProcess = async (event) => {
    event.preventDefault();
    setActionLoading(true);
    setActionError(null);
    try {
      const response = await closeRequestUnableToProcess(activeRequest.request_id, {
        closure_reason: closureReason,
        closure_detail: closureReason === 'other' ? closureDetail.trim() : undefined,
        closure_proof_reference: closureProofReference.trim(),
      });
      updateRequestFromResponse(response);
      setClosureDetail('');
      setClosureProofReference('');
      setActionSuccess('Request closed as unable to process.');
    } catch (err) {
      setActionError(err.response?.data?.message || Object.values(err.response?.data?.errors ?? {}).flat().join(' ') || 'Unable to close this request.');
    } finally {
      setActionLoading(false);
    }
  };

  return createPortal(
    <>
      <ErrorToast message={actionError || itemError} onClose={() => { setActionError(null); setItemError(null); }} />
      <SuccessToast message={actionSuccess} onClose={() => setActionSuccess(null)} />
      <div className="fixed inset-0 z-99999 flex items-center justify-center p-4">
        <div
          className={`absolute inset-0 backdrop-blur-sm ${isDark ? 'bg-black/70' : 'bg-black/50'}`}
          onClick={onClose}
        />
        <div className={`relative rounded-2xl shadow-2xl w-full max-w-2xl lg:max-w-4xl max-h-[calc(100vh-64px)] overflow-hidden flex flex-col print:w-full print:max-w-none print:shadow-none print:rounded-none ${isDark ? 'bg-[#242526] border border-[#3e4042]' : 'bg-white'}`}>

        {/* Header */}
        <div className={`relative px-4 sm:px-6 py-3 sm:py-4 flex justify-between items-center shrink-0 ${isDark ? 'bg-[#3a3b3c]' : 'bg-pup-maroon'}`}>
          <div>
            <h3 className="text-base sm:text-lg font-bold text-white">Request Details</h3>
            <p className={`text-xs sm:text-sm wrap-break-word ${isDark ? 'text-[#b0b3b8]' : 'text-yellow-200'}`}>
              Transaction ID: {activeRequest.uuid ?? `#${activeRequest.request_id}`}
            </p>
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close request details"
            className="absolute top-2 right-2 sm:top-3 sm:right-3 text-white hover:text-yellow-200 transition"
          >
            <XCircleIcon className="w-7 h-7" />
          </button>
        </div>

        {/* Body */}
        <div className={`flex-1 overflow-y-auto p-3 sm:p-4 lg:p-6 space-y-2 lg:space-y-6 print:p-0 print:mb-4 ${isDark ? 'text-[#e4e6eb]' : 'text-gray-900'}`}>
          
          <Section title="Document Request Progress" isDark={isDark}>            
            <div className="w-full">
              <div className={`rounded-full h-2 sm:h-3 overflow-hidden ${isDark ? 'bg-[#3a3b3c]' : 'bg-gray-100'}`}>
                <div
                  className="bg-yellow-500 h-2 sm:h-3 rounded-full transition-all duration-500 ease-out"
                  style={{ width: `${progress}%` }}
                ></div>
              </div>
                
              <div className="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-1 mt-2">
                <p className={`font-bold text-sm sm:text-md wrap-break-word ${isDark ? 'text-white' : 'text-pup-maroon'}`}>
                    {getProgressLabel(progress, isWithdrawn)}
                </p>
                <span className={`text-xs sm:text-sm font-semibold ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>
                    {progress}%
                </span>
              </div>
          </div>
          </Section>

          {/* Claim Ticket(s) — QR Code Claiming Policy v1.0 §3.2 access point 2
              (dashboard). Shown for the entire lifetime a request is still
              claimable — AwaitingSubmission (10%), Processing (25%),
              PendingSignature (60%), and ReadyToClaim (75%) — matching the
              pop-up shown immediately on submit (RequestForm.jsx/
              AlumniRequest.jsx) and the inbox notification sent at
              request_submitted: the student can access their ticket from
              day one, not only once it's Ready to Claim.
              Staff can only ever *act* on a scan once the request/group is
              actually ReadyToClaim — that restriction is enforced
              server-side in the claim endpoint, not by hiding the ticket
              here. Hidden only once there's nothing left to claim:
              Completed (100%) or Forfeited/Cancelled (0%).

              Phase 3 (fulfillment_track grouping): a request whose items
              span more than one track gets its OWN ticket per track (see
              DocumentRequest::releaseGroups() / RequestReleaseGroupService)
              — each is scanned/claimed independently. The overwhelming
              majority of requests have zero release groups and fall
              through to the single request-level ticket exactly as
              before. */}
          {/* Claim Tickets Section (Student/Alumni view only) */}
          {!isAdmin && progress !== 0 && progress !== 100 && (
            <Section title="Claim Tickets" isDark={isDark}>
              <div className="space-y-1">
                {ticketsList.map((ticket, index) => {
                  const isCtc = ticket.trackLabel.toLowerCase().includes('ctc');
                  return (
                    <div
                      key={ticket.id || index}
                      className={`flex items-center justify-between gap-3 py-3 border-b ${
                        isDark ? 'border-[#3e4042]' : 'border-gray-200'
                      } last:border-b-0 last:pb-0 first:pt-0`}
                    >
                      {/* Left: Icon & Title/Status */}
                      <div className="flex items-center gap-3 min-w-0">
                        <div className={`w-10 h-10 rounded-xl flex items-center justify-center shrink-0 ${
                          isCtc
                            ? (isDark ? 'bg-red-950/50 text-red-300' : 'bg-red-50 text-[#800000]')
                            : (isDark ? 'bg-amber-950/40 text-amber-300' : 'bg-amber-50 text-[#800000]')
                        }`}>
                          <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                          </svg>
                        </div>

                        <div className="flex flex-col min-w-0">
                          <span className={`font-bold text-sm sm:text-base leading-tight truncate ${
                            isDark ? 'text-white' : 'text-gray-900'
                          }`}>
                            {ticket.trackLabel}
                          </span>
                          <span className={`text-xs mt-0.5 truncate ${
                            isDark ? 'text-gray-400' : 'text-gray-500'
                          }`}>
                            {ticket.statusLabel} &middot; code <strong className={isDark ? 'text-gray-200' : 'text-gray-700'}>{ticket.claimCode}</strong>
                          </span>
                        </div>
                      </div>

                      {/* Right: Download Action Button */}
                      <div className="shrink-0">
                        <TicketDownloadButton ticket={ticket} isDark={isDark} />
                      </div>
                    </div>
                  );
                })}
              </div>
            </Section>
          )}

          {/* Student Information */}
          {isStudent && (
            <Section title="Student Information" isDark={isDark}>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                <p className="wrap-break-word">
                  <strong>Full Name:</strong>{' '}
                  {activeRequest.student_profile
                    ? `${activeRequest.student_profile.first_name} ${activeRequest.student_profile.middle_name ?? ''} ${activeRequest.student_profile.last_name}`.trim()
                    : `${activeRequest.alumni_profile?.first_name ?? ''} ${activeRequest.alumni_profile?.middle_name ?? ''} ${activeRequest.alumni_profile?.last_name ?? ''}`.trim() || 'N/A'}
                </p>
                <p className="wrap-break-word"><strong>Student Number:</strong> {activeRequest.academic_record?.student_number ?? activeRequest.alumni_academic_record?.student_number ?? 'N/A'}</p>
                <p className="wrap-break-word"><strong>Date of Birth:</strong> {activeRequest.student_profile?.date_of_birth ?? activeRequest.alumni_profile?.date_of_birth ?? 'N/A'}</p>
                <p className="wrap-break-word"><strong>Course:</strong> {activeRequest.academic_record?.course ?? activeRequest.alumni_academic_record?.course ?? 'N/A'}</p>
                <p className="wrap-break-word"><strong>Year Level:</strong> {activeRequest.academic_record?.year_level ?? 'N/A'}</p>
              </div>
            </Section>
          )}

          {/* Alumni Information*/}
          {isAlumni && (
            <Section title="Alumni Information" isDark={isDark}>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                <p className="wrap-break-word">
                  <strong>Full Name:</strong>{' '}
                  {activeRequest?.alumni_profile
                    ? `${activeRequest.alumni_profile.first_name} ${activeRequest.alumni_profile.middle_name ?? ''} ${activeRequest.alumni_profile.last_name}`
                    : 'N/A'}
                </p>
                <p className="wrap-break-word"><strong>Student Number:</strong> {activeRequest.alumni_academic_record?.student_number ?? 'N/A'}</p>
                <p className="wrap-break-word"><strong>Year of Graduation:</strong> {activeRequest.alumni_academic_record?.year_of_graduation ?? 'N/A'}</p>
                <p className="wrap-break-word"><strong>Course:</strong> {activeRequest.alumni_academic_record?.course ?? 'N/A'}</p>
              </div>
            </Section>
          )}

            {(openNotice || canManageNotice || isWithdrawn || isClosedUnableToProcess) && (
              <Section title="Request Resolution" isDark={isDark}>
                <div className="space-y-3">
                  {isWithdrawn && (
                    <div className={`rounded-lg border p-3 ${isDark ? 'border-red-800 bg-red-950/30' : 'border-red-200 bg-red-50'}`}>
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="font-bold text-red-700 dark:text-red-300">Withdrawn</span>
                        {activeRequest.withdrawal_reason && (
                          <span className="text-xs font-semibold uppercase tracking-wide opacity-75">
                            {WITHDRAWAL_REASONS.find(([value]) => value === activeRequest.withdrawal_reason)?.[1] ?? activeRequest.withdrawal_reason}
                          </span>
                        )}
                      </div>
                      {activeRequest.withdrawal_detail && <p className="mt-1 wrap-break-word">{activeRequest.withdrawal_detail}</p>}
                      {activeRequest.superseded_by_request_id && (
                        <p className="mt-1 text-xs">Superseded by request #{activeRequest.superseded_by_request_id}</p>
                      )}
                    </div>
                  )}

                  {isClosedUnableToProcess && (
                    <div className={`rounded-lg border p-3 ${isDark ? 'border-red-800 bg-red-950/30' : 'border-red-200 bg-red-50'}`}>
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="font-bold text-red-700 dark:text-red-300">Closed - Unable to Process</span>
                        {activeRequest.closure_reason && (
                          <span className="text-xs font-semibold uppercase tracking-wide opacity-75">
                            {CLOSURE_REASONS.find(([value]) => value === activeRequest.closure_reason)?.[1] ?? activeRequest.closure_reason}
                          </span>
                        )}
                      </div>
                      {activeRequest.closure_detail && <p className="mt-1 wrap-break-word">{activeRequest.closure_detail}</p>}
                      {activeRequest.closure_proof_reference && <p className="mt-1 text-xs wrap-break-word">Proof: {activeRequest.closure_proof_reference}</p>}
                    </div>
                  )}

                  {openNotice ? (
                    <div className={`rounded-lg border p-3 ${noticeIsStale ? (isDark ? 'border-orange-700 bg-orange-950/30' : 'border-orange-300 bg-orange-50') : (isDark ? 'border-yellow-700 bg-yellow-950/30' : 'border-yellow-300 bg-yellow-50')}`}>
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                          <p className="font-bold">Deficiency Notice: {openNotice.item_label ?? openNotice.item_key}</p>
                          {openNotice.detail && <p className="mt-1 wrap-break-word text-sm">{openNotice.detail}</p>}
                        </div>
                        <span className={`rounded-full border px-2 py-0.5 text-[10px] font-black uppercase ${noticeIsEscalated ? 'border-red-400 text-red-700 dark:text-red-300' : noticeIsStale ? 'border-orange-400 text-orange-700 dark:text-orange-300' : 'border-yellow-400 text-yellow-700 dark:text-yellow-300'}`}>
                          {noticeIsEscalated ? 'Escalated' : noticeIsStale ? 'Stale: 14+ days' : 'Open'}
                        </span>
                      </div>
                      <p className="mt-2 text-xs opacity-70">
                        Issued {openNotice.issued_at ? new Date(openNotice.issued_at).toLocaleDateString() : 'recently'}
                        {openNotice.issued_by_user?.name ? ` by ${openNotice.issued_by_user.name}` : ''}
                      </p>

                      {canManageNotice && (
                        <div className="mt-3 flex flex-col gap-3 sm:flex-row sm:items-end">
                          <button type="button" disabled={actionLoading} onClick={handleClearNotice} className="rounded-lg bg-green-600 px-4 py-3 text-xs font-bold text-white transition hover:bg-green-700 disabled:opacity-50 shrink-0 cursor-pointer">
                            Clear Notice
                          </button>
                          <form onSubmit={handleVoidNotice} className="flex flex-1 flex-col gap-2 sm:flex-row sm:items-end">
                            <div className="flex-1 min-w-0 w-full">
                              <InputGroup
                                label="Reason for Voiding"
                                name="voidReason"
                                value={voidReason}
                                onChange={(event) => setVoidReason(event.target.value)}
                                placeholder="Reason for voiding"
                                required
                                labelColor="text-gray-700"
                                voiceEnabled={false}
                              />
                            </div>
                            <button type="submit" disabled={actionLoading || !voidReason.trim()} className="rounded-lg border border-red-300 px-4 py-3 text-xs font-bold text-red-700 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/30 transition disabled:opacity-50 shrink-0 cursor-pointer">
                              Void Notice
                            </button>
                          </form>
                        </div>
                      )}
                      {canWithdraw && (
                        <button type="button" onClick={() => setShowWithdrawForm(true)} className="mt-3 text-xs font-bold text-pup-maroon underline dark:text-yellow-300 cursor-pointer">
                          Withdraw this request
                        </button>
                      )}
                    </div>
                  ) : canManageNotice && !isTerminal && !showWithdrawForm ? (
                    <form onSubmit={handleIssueNotice} className={`rounded-lg border p-3 ${isDark ? 'border-[#3e4042]' : 'border-gray-200'}`}>
                      <p className="mb-2 font-bold">Issue Deficiency Notice</p>
                      <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div className="flex-1 min-w-50">
                          <DropDown
                            label="Deficiency Item"
                            name="deficiencyItem"
                            value={DEFICIENCY_ITEMS.find(([val]) => val === deficiencyItem)?.[1] || deficiencyItem}
                            onChange={(e) => {
                              const found = DEFICIENCY_ITEMS.find(([, label]) => label === e.target.value);
                              setDeficiencyItem(found ? found[0] : e.target.value);
                            }}
                            options={DEFICIENCY_ITEMS.map(([, label]) => label)}
                            required
                            labelColor="text-gray-700"
                          />
                        </div>
                        {deficiencyItem === 'other' && (
                          <div className="flex-1 min-w-0">
                            <InputGroup
                              label="Missing Item Detail"
                              name="deficiencyDetail"
                              value={deficiencyDetail}
                              onChange={(event) => setDeficiencyDetail(event.target.value)}
                              placeholder="Specify missing item"
                              required
                              labelColor="text-gray-700"
                              voiceEnabled={false}
                            />
                          </div>
                        )}
                        <button type="submit" disabled={actionLoading} className="rounded-lg bg-yellow-500 px-4 py-3 text-xs font-bold text-gray-900 transition hover:bg-yellow-400 disabled:opacity-50 shrink-0 cursor-pointer">
                          Issue Notice
                        </button>
                      </div>
                    </form>
                  ) : null}

                  {showWithdrawForm && canWithdraw && (
                    <form onSubmit={handleWithdraw} className={`rounded-lg border p-3 ${isDark ? 'border-red-800' : 'border-red-200'}`}>
                      <p className="mb-2 font-bold">Withdraw Request</p>
                      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <DropDown
                          label="Withdrawal Reason"
                          name="withdrawalReason"
                          value={WITHDRAWAL_REASONS.find(([val]) => val === withdrawalReason)?.[1] || withdrawalReason}
                          onChange={(e) => {
                            const found = WITHDRAWAL_REASONS.find(([, label]) => label === e.target.value);
                            setWithdrawalReason(found ? found[0] : e.target.value);
                          }}
                          options={WITHDRAWAL_REASONS.map(([, label]) => label)}
                          required
                          labelColor="text-gray-700"
                        />
                        <InputGroup
                          label="Corrected Request ID (Optional)"
                          name="supersededByRequestId"
                          value={supersededByRequestId}
                          onChange={(event) => setSupersededByRequestId(event.target.value.replace(/\D/g, ''))}
                          placeholder="e.g. 12345"
                          labelColor="text-gray-700"
                          voiceEnabled={false}
                        />
                      </div>
                      {withdrawalReason === 'other' && (
                        <div className="mt-3">
                          <InputGroup
                            label="Withdrawal Reason Detail"
                            name="withdrawalDetail"
                            value={withdrawalDetail}
                            onChange={(event) => setWithdrawalDetail(event.target.value)}
                            placeholder="Reason for withdrawal"
                            required
                            labelColor="text-gray-700"
                            voiceEnabled={false}
                          />
                        </div>
                      )}
                      <div className="mt-3 flex gap-2">
                        <button type="submit" disabled={actionLoading} className="rounded-lg bg-red-600 px-4 py-2.5 text-xs font-bold text-white transition hover:bg-red-700 disabled:opacity-50 cursor-pointer">
                          Confirm Withdrawal
                        </button>
                        <button type="button" onClick={() => setShowWithdrawForm(false)} className={`rounded-lg border px-4 py-2.5 text-xs font-bold transition cursor-pointer ${isDark ? 'border-[#3e4042] text-[#e4e6eb] hover:bg-[#3a3b3c]' : 'border-gray-300 text-gray-700 hover:bg-gray-100'}`}>
                          Cancel
                        </button>
                      </div>
                    </form>
                  )}

                  {canManageNotice && openNotice && !isClosedUnableToProcess && (
                    <form onSubmit={handleCloseUnableToProcess} className={`rounded-lg border p-3 ${isDark ? 'border-red-800' : 'border-red-200'}`}>
                      <p className="mb-2 font-bold">Close - Unable to Process</p>
                      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <DropDown
                          label="Closure Reason"
                          name="closureReason"
                          value={CLOSURE_REASONS.find(([value]) => value === closureReason)?.[1] || closureReason}
                          onChange={(event) => {
                            const found = CLOSURE_REASONS.find(([, label]) => label === event.target.value);
                            setClosureReason(found ? found[0] : event.target.value);
                          }}
                          options={CLOSURE_REASONS.map(([, label]) => label)}
                          required
                          labelColor="text-gray-700"
                        />
                        <InputGroup
                          label="Proof Reference"
                          name="closureProofReference"
                          value={closureProofReference}
                          onChange={(event) => setClosureProofReference(event.target.value)}
                          placeholder="Describe the verified proof"
                          required
                          labelColor="text-gray-700"
                          voiceEnabled={false}
                        />
                      </div>
                      {closureReason === 'other' && (
                        <div className="mt-3">
                          <InputGroup
                            label="Closure Detail"
                            name="closureDetail"
                            value={closureDetail}
                            onChange={(event) => setClosureDetail(event.target.value)}
                            placeholder="Explain why the request cannot be processed"
                            required
                            labelColor="text-gray-700"
                            voiceEnabled={false}
                          />
                        </div>
                      )}
                      <button type="submit" disabled={actionLoading || !closureProofReference.trim() || (closureReason === 'other' && !closureDetail.trim())} className="mt-3 rounded-lg bg-red-600 px-4 py-2.5 text-xs font-bold text-white transition hover:bg-red-700 disabled:opacity-50 cursor-pointer">
                        Confirm Closure
                      </button>
                    </form>
                  )}
                </div>
              </Section>
            )}

          {/* Request Information */}
          <Section title="Request Information" isDark={isDark}>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <p className="wrap-break-word">
                <strong>Date Requested:</strong>{' '}
                  {activeRequest.requested_at ? new Date(activeRequest.requested_at).toLocaleDateString() : 'N/A'}
              </p>
              <p className="wrap-break-word"><strong>Status:</strong> {displayStatus}</p>
              <p className="wrap-break-word"><strong>Purpose:</strong> {activeRequest.request_purpose?.purpose_name ?? purposeName(activeRequest.request_purpose_id) ?? 'N/A'}</p>
            </div>
          </Section>

          {/* Documents Requested */}
          <Section title="Documents Requested" isDark={isDark}>
            <ul className="list-disc ml-4 sm:ml-5 space-y-2">
              {requestDocs.map((doc, index) => (
                <li key={doc.request_document_id ?? index} className="wrap-break-word">
                  <strong className="block sm:inline">{getDocName(doc)}</strong>
                  <span className={`inline-flex mt-1 sm:mt-0 sm:ml-2 text-xs font-semibold px-2 py-0.5 rounded-full ${isDark ? 'bg-yellow-900/40 text-yellow-300' : 'bg-yellow-200'}`}>
                    {doc.number_of_copies || 1} {(doc.number_of_copies || 1) > 1 ? 'Copies' : 'Copy'}
                  </span>
                </li>
              ))}
              {requestCerts.map((c, i) => {
                const cName = c.certification_type?.certificate_name ?? certName(c.certificate_type_id) ?? 'Unknown Certification';
                return (
                  <li key={c.request_certificate_id ?? `cert-${i}`} className="wrap-break-word">
                    <strong className="block sm:inline">{cName}</strong>
                    <span className={`inline-flex mt-1 sm:mt-0 sm:ml-2 text-xs font-semibold px-2 py-0.5 rounded-full ${isDark ? 'bg-yellow-900/40 text-yellow-300' : 'bg-yellow-200'}`}>
                      {c.number_of_copies || 1} {(c.number_of_copies || 1) > 1 ? 'Copies' : 'Copy'}
                    </span>
                  </li>
                );
              })}
              {requestDocs.length === 0 && requestCerts.length === 0 && (request?.documentDetailsArray ?? activeRequest.documentDetailsArray)?.map((detail, index) => (
                <li key={`detail-${index}`} className="wrap-break-word">
                  <strong className="block sm:inline">{detail}</strong>
                </li>
              ))}
            </ul>
          </Section>

          {/* Payment Details */}
          <Section title="Payment Details" isDark={isDark}>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <p className="wrap-break-word">
                <strong>OR Number:</strong>{' '}
                {activeRequest.or_number ?? 'N/A'}
              </p>

              <p className="wrap-break-word">
                <strong>Date of Payment:</strong>{' '}
                {activeRequest.receipt_date
                  ? new Date(activeRequest.receipt_date).toLocaleDateString()
                  : 'N/A'}
              </p>

            </div>
          </Section>

        </div>
      </div>
    </div>
    </>
  , document.body);
};

const getProgressLabel = (progress, isWithdrawn = false) => {
  if (isWithdrawn) return "Request was withdrawn";
  switch (progress) {
    case 0:   return "Request was forfeited";
    case 10:  return "Awaiting submission of source document";
    case 25:  return "Request received and under review";
    case 60:  return "Registrar processing complete — awaiting signature";
    case 75:  return "Document is ready to claim";
    case 100: return "Document Claimed";
    default:  return "Pending";
  }
};

const TicketDownloadButton = ({ ticket, isDark }) => {
  const contentRef = React.useRef(null);
  const [downloading, setDownloading] = useState(false);

  const handleDownload = async (e) => {
    e.stopPropagation();
    if (!contentRef.current) return;
    setDownloading(true);
    try {
      const dataUrl = await toPng(contentRef.current, {
        backgroundColor: '#ffffff',
        cacheBust: true,
        pixelRatio: 2,
        style: { borderRadius: '16px' },
        filter: (node) => !(node.classList && node.classList.contains('download-btn-hide')),
      });
      const link = document.createElement('a');
      link.download = `claim-ticket-${ticket.claimCode}.png`;
      link.href = dataUrl;
      link.click();
    } catch (err) {
      console.error('Failed to download ticket:', err);
    } finally {
      setDownloading(false);
    }
  };

  return (
    <>
      <button
        type="button"
        onClick={handleDownload}
        disabled={downloading}
        className={`px-3.5 py-1.5 rounded-xl border text-xs font-semibold flex items-center gap-1.5 transition-all active:scale-95 cursor-pointer ${
          isDark
            ? 'border-amber-400/50 text-amber-300 hover:bg-amber-400 hover:text-black'
            : 'border-[#660000]/60 text-[#660000] hover:bg-[#660000] hover:text-white'
        }`}
      >
        <ArrowDownTrayIcon className="w-3.5 h-3.5" />
        <span>{downloading ? 'Downloading...' : 'Download'}</span>
      </button>

      <div className="fixed -left-[9999px] -top-[9999px] pointer-events-none opacity-100 w-[480px] z-[-9999]">
        <div ref={contentRef} className="bg-white p-2 rounded-2xl">
          <ClaimTicket uuid={ticket.uuid} claimCode={ticket.claimCode} small />
        </div>
      </div>
    </>
  );
};

const Section = ({ title, children, isDark }) => {
  const [open, setOpen] = useState(true);

  return (
    <div className={`border rounded-lg ${isDark ? 'border-[#3e4042]' : 'border-gray-200'}`}>
      <button
        type="button"
        onClick={() => setOpen(!open)}
        className={`w-full flex justify-between items-center px-3 sm:px-4 py-3 font-bold text-sm ${open ? 'rounded-t-lg' : 'rounded-lg'} ${isDark ? 'bg-[#3a3b3c]' : 'bg-yellow-50 text-pup-maroon'}`}
      >
        {title}
        <ChevronDownIcon
          className={`w-4 h-4 transition ${open ? 'rotate-180' : ''}`}
        />
      </button>

      {open && <div className={`p-3 sm:p-4 text-sm rounded-b-lg ${isDark ? 'bg-[#242526]' : 'bg-white'}`}>{children}</div>}
    </div>
  );
};

export default RequestDetailsModal;