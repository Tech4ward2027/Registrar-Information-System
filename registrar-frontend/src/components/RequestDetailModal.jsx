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
import { getProcessingClassification, getItemProgressPercentage, extractSeparatedItems } from '../utils/bulkRequestUtils';

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
  const isUndergrad = activeRequest?.undergrad_requestor_profile != null || request?.userType === 'Undergrad' || activeRequest?.user?.role_id === 5;
  const isStudent = !isUndergrad && (activeRequest?.student_profile != null || request?.userType === 'Student');
  const isAlumni = !isUndergrad && !isStudent && (activeRequest?.alumni_profile != null || request?.userType === 'Alumni'); 
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
      let rawMsg = err.response?.data?.message || Object.values(err.response?.data?.errors ?? {}).flat().join(' ') || 'Unable to withdraw this request.';
      if (rawMsg.includes('superseded_by_request_id does not reference an existing request')) {
        rawMsg = 'The replacement request ID could not be found. Please check the ID and try again.';
      } else if (rawMsg.includes('cannot supersede itself')) {
        rawMsg = 'A request cannot be superseded by itself. Please enter a different request ID.';
      }
      setActionError(rawMsg);
    }
 finally {
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
          
          {/* Requested Documents */}
          <Section title="Requested Documents" isDark={isDark}>
            <div className="space-y-3">
              {(() => {
                const separatedItems = extractSeparatedItems(activeRequest, docTypeName, certName);
                return separatedItems.map((item) => {
                  const itemTicket = {
                    id: item.itemKey,
                    trackLabel: item.name,
                    claimCode: item.claimCode,
                    uuid: item.uuid,
                  };
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
                  return (
                    <div
                      key={item.itemKey}
                      className={`p-3.5 rounded-xl border flex flex-col sm:flex-row sm:items-center justify-between gap-3 ${
                        isDark ? 'bg-[#1f1f1f] border-[#3e4042]' : 'bg-gray-50 border-gray-200'
                      }`}
                    >
                      <div className="flex-1 min-w-0">
                        <h4 className={`font-bold text-sm sm:text-base ${isDark ? 'text-white' : 'text-gray-900'}`}>
                          {item.name}
                          <span className={`ml-2 text-xs font-semibold px-2 py-0.5 rounded-full ${isDark ? 'bg-yellow-900/40 text-yellow-300' : 'bg-yellow-200 text-yellow-900'}`}>
                            {item.copies} {item.copies > 1 ? 'Copies' : 'Copy'}
                          </span>
                        </h4>

                        {/* Progress Bar per item */}
                        <div className="mt-2 w-full max-w-md">
                          <div className="flex justify-between items-center text-xs mb-1">
                            <span className={`font-medium ${isDark ? 'text-gray-400' : 'text-gray-600'}`}>
                              Stage: {item.statusName}
                            </span>
                            <span className="font-bold text-yellow-500">
                              {item.progress}%
                            </span>
                          </div>
                          <div className={`w-full h-2 rounded-full overflow-hidden ${isDark ? 'bg-[#3e4042]' : 'bg-gray-200'}`}>
                            <div
                              className="h-full bg-yellow-500 rounded-full transition-all duration-300"
                              style={{
                                width: `${item.progress}%`,
                              }}
                            />
                          </div>
                        </div>
                      </div>

                      {/* Per-Document Download Ticket / QR Code Action */}
                      <div className="shrink-0 self-start sm:self-center">
                        <TicketDownloadButton ticket={itemTicket} isDark={isDark} />
                      </div>
                    </div>
                  );
                });
              })()}
            </div>
          </Section>

          {/* Undergrad Information */}
          {isUndergrad && (
            <Section title="Undergrad Information" isDark={isDark}>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                <p className="wrap-break-word">
                  <strong>Full Name:</strong>{' '}
                  {activeRequest.undergrad_requestor_profile
                    ? `${activeRequest.undergrad_requestor_profile.first_name} ${activeRequest.undergrad_requestor_profile.middle_name ?? ''} ${activeRequest.undergrad_requestor_profile.last_name}`.trim()
                    : 'N/A'}
                </p>
                <p className="wrap-break-word"><strong>Student Number:</strong> {activeRequest.undergrad_requestor_profile?.student_number ?? 'N/A'}</p>
                <p className="wrap-break-word"><strong>Program:</strong> {activeRequest.undergrad_requestor_profile?.program ?? 'N/A'}</p>
                <p className="wrap-break-word"><strong>Classification:</strong> Undergrad</p>
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