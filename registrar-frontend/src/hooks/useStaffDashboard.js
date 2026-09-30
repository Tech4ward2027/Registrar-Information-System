import { useState, useEffect, useCallback, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient, keepPreviousData } from '@tanstack/react-query';
import {
  getDocumentRequests,
  getDocumentRequestCounts,
  cleanListParams,
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
  const [debouncedSearchTerm, setDebouncedSearchTerm] = useState('');
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

  // 300ms Search Debounce Box
  useEffect(() => {
    const handler = setTimeout(() => {
      setDebouncedSearchTerm(searchTerm);
    }, 300);
    return () => clearTimeout(handler);
  }, [searchTerm]);

  const requestStatuses = referenceStatuses ?? [];
  const resolvedStatusIds = resolveStatusIds(requestStatuses);

  // Reset page when filters change
  useEffect(() => {
    setCurrentPage(1);
  }, [filterStatus, filterClassification, filterDocument, debouncedSearchTerm, sortOrder]);

  const listParams = useMemo(() => {
    return cleanListParams({
      search: debouncedSearchTerm,
      status: filterStatus,
      classification: filterClassification,
      document: filterDocument,
      sort: sortOrder,
      view: viewMode === 'archived' ? 'archived' : 'active',
      page: currentPage,
      per_page: 20,
    });
  }, [debouncedSearchTerm, filterStatus, filterClassification, filterDocument, sortOrder, viewMode, currentPage]);

  /* ---------------- TANSTACK QUERY: FETCH REQUESTS ---------------- */
  const { data: requestsResponse, isLoading: loading } = useQuery({
    queryKey: ['documentRequests', viewMode, listParams],
    queryFn: () => getDocumentRequests(listParams),
    placeholderData: keepPreviousData,
    refetchInterval: 30_000,
    staleTime: 10_000,
  });

  /* ---------------- TANSTACK QUERY: FETCH COUNTS ---------------- */
  const { data: statusCounts = {} } = useQuery({
    queryKey: ['documentRequestsCounts'],
    queryFn: async () => {
      const res = await getDocumentRequestCounts();
      return res.data ?? res;
    },
    staleTime: 30_000,
  });

  const rawList = requestsResponse?.data?.data ?? requestsResponse?.data ?? [];
  const meta = {
    total: requestsResponse?.data?.total ?? rawList.length,
    currentPage: requestsResponse?.data?.current_page ?? currentPage,
    lastPage: requestsResponse?.data?.last_page ?? 1,
    perPage: requestsResponse?.data?.per_page ?? 20,
    from: requestsResponse?.data?.from ?? 1,
    to: requestsResponse?.data?.to ?? rawList.length,
  };

  const requests = useMemo(() => {
    return rawList.map(r => mapDocumentRequest(r, resolvedStatusIds, docTypeName));
  }, [rawList, resolvedStatusIds, docTypeName]);

  // Refetch when a relevant notification arrives via WebSocket.
  useEffect(() => {
    if (notifications.length === 0) return;
    const latest = notifications[0];
    if (latest && DASHBOARD_REFETCH_TRIGGERS.has(latest.type)) {
      queryClient.invalidateQueries({ queryKey: ['documentRequests'] });
      queryClient.invalidateQueries({ queryKey: ['documentRequestsCounts'] });
    }
  }, [notifications[0]?.id, viewMode, queryClient, notifications]);

  /* ---------------- TANSTACK QUERY: MUTATIONS ---------------- */
  const invalidateRequests = () => {
    queryClient.invalidateQueries({ queryKey: ['documentRequests'] });
    queryClient.invalidateQueries({ queryKey: ['documentRequestsCounts'] });
  };

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

  const filteredData = requests;

  return {
    requests,
    filteredData,
    meta,
    statusCounts,
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
  };
};