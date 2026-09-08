import { useState, useEffect, useCallback, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  getDocumentRequests,
  updateDocumentRequest,
  deleteDocumentRequest,
  archiveDocumentRequest,
  restoreDocumentRequest,
  archiveDocumentRequests,
  restoreDocumentRequests,
  bulkReadyItems,
  bulkDoneItems,
} from '../services/api';
import { useNotificationsContext } from '../context/NotificationsContext';
import { useReferenceData } from '../context/ReferenceDataContext';
import {
  resolveStatusIds,
  mapDocumentRequest,
  filterAndSortRequests,
} from '../utils/staffDashboardUtils';

const DASHBOARD_REFETCH_TRIGGERS = new Set([
  'admin_new_request',
  'admin_payment_verification',
  'admin_incomplete_request',
  'status_updated',
  'awaiting_submission',
  'request_processing',
  'pending_signature',
  'ready_to_claim',
  'request_completed',
  'request_forfeited',
  'request_withdrawn',
  'deficiency_notice_issued',
  'deficiency_notice_cleared',
  'deficiency_notice_voided',
]);

export const useStaffDashboard = (viewMode) => {
  const { docTypeName, statuses: referenceStatuses, documentTypes, certifications } = useReferenceData();
  const queryClient = useQueryClient();
  const { notifications } = useNotificationsContext();

  const [filterStatus, setFilterStatus] = useState('All');
  const [filterClassification, setFilterClassification] = useState('All');
  const [filterDocument, setFilterDocument] = useState('All');
  const [searchTerm, setSearchTerm] = useState('');
  const [updatingId, setUpdatingId] = useState(null);
  const [selectedRequest, setSelectedRequest] = useState(null);
  const [currentPage, setCurrentPage] = useState(1);
  const [sortOrder, setSortOrder] = useState('Recent Requests');
  const [selectedIds, setSelectedIds] = useState([]);
  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
  const [certRequest, setCertRequest] = useState(null);
  const [sortDropdownOpen, setSortDropdownOpen] = useState(false);
  const [statusDropdownOpen, setStatusDropdownOpen] = useState(false);
  const [classificationDropdownOpen, setClassificationDropdownOpen] = useState(false);
  const [documentDropdownOpen, setDocumentDropdownOpen] = useState(false);

  const requestStatuses = referenceStatuses ?? [];
  const resolvedStatusIds = resolveStatusIds(requestStatuses);

  /* ---------------- TANSTACK QUERY: FETCH REQUESTS ---------------- */
  // BUG FIX (client-side auto-forfeit race condition — see routes/console.php
  // for the full writeup): this queryFn used to call updateDocumentRequest()
  // for any request it locally decided was 90+ days old, on every 30s poll,
  // from every open staff dashboard — a write triggered by a read, racing
  // across every open tab, and computed from the wrong clock (requested_at
  // instead of the most recent ReadyToClaim transition the backend actually
  // uses). Forfeiture is now handled exclusively by the backend's
  // ShredExpiredRequests cron (now hourly), which is transactional, audited,
  // and cache-invalidated. This queryFn is a pure read again — it maps and
  // returns whatever status the backend reports, nothing more.
  const { data: requests = [], isLoading: loading } = useQuery({
    queryKey: ['documentRequests', viewMode],
    queryFn: async () => {
      const requestsRes = await getDocumentRequests({
        per_page: 200,
        ...(viewMode === 'archived' ? { view: 'archived' } : { all_statuses: true }),
      });

      const rawList = requestsRes.data?.data ?? requestsRes.data ?? [];
      return rawList.map(r => mapDocumentRequest(r, resolvedStatusIds, docTypeName));
    },
    refetchInterval: 30_000,
    staleTime: 10_000,
  });

  // Refetch when a relevant notification arrives via WebSocket.
  useEffect(() => {
    if (notifications.length === 0) return;
    const latest = notifications[0];
    if (latest && DASHBOARD_REFETCH_TRIGGERS.has(latest.type)) {
      queryClient.invalidateQueries({ queryKey: ['documentRequests', viewMode] });
    }
  }, [notifications[0]?.id, viewMode, queryClient, notifications]);

  useEffect(() => {
    setCurrentPage(1);
  }, [filterStatus, filterClassification, filterDocument, searchTerm, sortOrder]);

  /* ---------------- TANSTACK QUERY: MUTATIONS ---------------- */
  const invalidateRequests = () =>
    queryClient.invalidateQueries({ queryKey: ['documentRequests', viewMode] });

  const statusMutation = useMutation({
    mutationFn: ({ id, statusId }) => updateDocumentRequest(id, { status_id: statusId }),
    onSuccess: () => invalidateRequests(),
    onError: (error) => {
      console.error('Status update failed:', error);
      alert('Error: ' + error.message);
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (ids) => Promise.all(ids.map(id => deleteDocumentRequest(id))),
    onSuccess: () => {
      setSelectedIds([]);
      setShowDeleteConfirm(false);
      invalidateRequests();
    },
    onError: (err) => {
      console.error('Delete failed', err);
    },
  });

  const archiveSelectedMutation = useMutation({
    mutationFn: (ids) => archiveDocumentRequests(ids),
    onSuccess: () => { setSelectedIds([]); invalidateRequests(); },
    onError: (err) => {
      console.error('Archive failed', err);
    },
  });

  const restoreSelectedMutation = useMutation({
    mutationFn: (ids) => restoreDocumentRequests(ids),
    onSuccess: () => { setSelectedIds([]); invalidateRequests(); },
    onError: (err) => alert('Error restoring requests: ' + (err?.response?.data?.message || err.message)),
  });

  const archiveOneMutation = useMutation({
    mutationFn: (id) => archiveDocumentRequest(id),
    onSuccess: () => invalidateRequests(),
    onError: (err) => alert('Error archiving request: ' + (err?.response?.data?.message || err.message)),
  });

  const restoreOneMutation = useMutation({
    mutationFn: (id) => restoreDocumentRequest(id),
    onSuccess: () => invalidateRequests(),
    onError: (err) => alert('Error restoring request: ' + (err?.response?.data?.message || err.message)),
  });

  const bulkReadyMutation = useMutation({
    mutationFn: (ids) => bulkReadyItems(ids),
    onSuccess: () => {
      setSelectedIds([]);
      invalidateRequests();
    },
    onError: (err) => {
      console.error('Bulk ready status update failed:', err);
    },
  });

  const bulkDoneMutation = useMutation({
    mutationFn: (ids) => bulkDoneItems(ids),
    onSuccess: () => {
      setSelectedIds([]);
      invalidateRequests();
    },
    onError: (err) => {
      console.error('Bulk completed status update failed:', err);
    },
  });

  const actionLoading = statusMutation.isPending || deleteMutation.isPending ||
    archiveSelectedMutation.isPending || restoreSelectedMutation.isPending ||
    archiveOneMutation.isPending || restoreOneMutation.isPending ||
    bulkReadyMutation.isPending || bulkDoneMutation.isPending;

  const handleStatusUpdate = (id, newStatusId) => {
    setUpdatingId(id);
    statusMutation.mutate({ id, statusId: newStatusId }, {
      onSettled: () => setUpdatingId(null),
    });
  };

  const handleBulkReady = (targetIds, callbacks = {}) => {
    const ids = targetIds || selectedIds;
    if (!ids || ids.length === 0) return;
    bulkReadyMutation.mutate(ids, {
      onSuccess: callbacks.onSuccess,
      onError: callbacks.onError,
    });
  };

  const handleBulkDone = (targetIds, callbacks = {}) => {
    const ids = targetIds || selectedIds;
    if (!ids || ids.length === 0) return;
    bulkDoneMutation.mutate(ids, {
      onSuccess: callbacks.onSuccess,
      onError: callbacks.onError,
    });
  };



  const handleSelectOne = (id) => {
    setSelectedIds(prev => (
      prev.includes(id) ? prev.filter(itemId => itemId !== id) : [...prev, id]
    ));
  };

  const handleDeleteSelected = () => {
    if (selectedIds.length === 0) return;
    setShowDeleteConfirm(true);
  };

  const confirmDeleteSelected = () => {
    deleteMutation.mutate(selectedIds);
  };

  const handleArchiveSelected = () => {
    if (selectedIds.length === 0) return;
    archiveSelectedMutation.mutate(selectedIds);
  };

  const handleRestoreSelected = () => {
    if (selectedIds.length === 0) return;
    restoreSelectedMutation.mutate(selectedIds);
  };

  const handleArchiveOne = (id) => {
    setUpdatingId(id);
    archiveOneMutation.mutate(id, { onSettled: () => setUpdatingId(null) });
  };

  const handleRestoreOne = (id) => {
    setUpdatingId(id);
    restoreOneMutation.mutate(id, { onSettled: () => setUpdatingId(null) });
  };

  // Replaces the old markCertificateAsPrinted (localStorage-only flag that
  // never reached the server — see staffDashboardUtils.js's
  // certificatesGenerated for the real signal it's been replaced by).
  // GenerateCertificate.jsx already persists the actual generated_at
  // write itself via markCertificatesGenerated(); by the time this fires,
  // the server already knows — this just invalidates the cached request
  // list so the dashboard's next render reflects it instead of waiting
  // out the 30s poll.
  const handleCertificatePrinted = () => {
    invalidateRequests();
  };

  const documentOptions = useMemo(() => {
    const set = new Set();
    (requests || []).forEach(r => {
      (r.documentDetailsArray || []).forEach(doc => {
        if (doc) set.add(doc);
      });
    });
    (documentTypes || []).forEach(d => {
      if (d?.document_name) set.add(d.document_name);
    });
    (certifications || []).forEach(c => {
      if (c?.certificate_name) set.add(c.certificate_name);
    });
    return ['All', ...Array.from(set).sort((a, b) => a.localeCompare(b))];
  }, [requests, documentTypes, certifications]);

  const filteredData = filterAndSortRequests(requests, {
    filterStatus,
    filterClassification,
    filterDocument,
    searchTerm,
    sortOrder,
    viewMode,
    resolvedStatusIds,
  });

  return {
    requests,
    filteredData,
    loading,
    actionLoading,
    filterStatus,
    setFilterStatus,
    searchTerm,
    setSearchTerm,
    updatingId,
    setUpdatingId,
    selectedRequest,
    setSelectedRequest,
    currentPage,
    setCurrentPage,
    sortOrder,
    setSortOrder,
    selectedIds,
    setSelectedIds,
    showDeleteConfirm,
    setShowDeleteConfirm,
    certRequest,
    setCertRequest,
    sortDropdownOpen,
    setSortDropdownOpen,
    statusDropdownOpen,
    setStatusDropdownOpen,
    filterClassification,
    setFilterClassification,
    classificationDropdownOpen,
    setClassificationDropdownOpen,
    filterDocument,
    setFilterDocument,
    documentDropdownOpen,
    setDocumentDropdownOpen,
    documentOptions,
    resolvedStatusIds,
    requestStatuses,
    handleStatusUpdate,
    handleSelectOne,
    handleDeleteSelected,
    confirmDeleteSelected,
    handleArchiveSelected,
    handleRestoreSelected,
    handleArchiveOne,
    handleRestoreOne,
    handleBulkReady,
    handleBulkDone,
    handleCertificatePrinted,
    queryClient,
    setUpdatingId,
    updatingId,
  };
};