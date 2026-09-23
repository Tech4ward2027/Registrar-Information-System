import React, { useState, useEffect, useCallback, useMemo, useRef } from "react";
import { useTheme } from "../context/ThemeContext";
import { useAuth } from "../context/AuthProvider";
import {
  getUndergradRequestorQueue,
  getUndergradRequestorDetail,
  approveUndergradRequestor,
  rejectUndergradRequestor,
} from "../services/api";
import { hasModuleAction, MODULE_KEYS } from "../utils/policy";
import SuccessToast from "../components/SuccessToast.jsx";
import ErrorToast from "../components/ErrorToast.jsx";
import ConfirmationModal from "../components/ConfirmationModal";
import VoiceSearchInput from "../components/VoiceSearchInput.jsx";
import DashboardDropdown from "../components/DashboardDropdown.jsx";
import LoadingOverlay from "../components/LoadingOverlay.jsx";
import { StatCard, Th, Td, Pagination, StatusBadge } from "../components/StaffDashboardComponents";
import {
  AcademicCapIcon,
  MagnifyingGlassIcon,
  XMarkIcon,
  ExclamationTriangleIcon,
  CheckCircleIcon,
  XCircleIcon,
  EyeIcon,
  ClockIcon,
  ShieldCheckIcon,
  DocumentTextIcon,
  ChevronDownIcon,
  ChevronUpIcon,
  EllipsisVerticalIcon,
  CheckIcon,
} from "@heroicons/react/24/outline";

const STATUS_TABS = [
  { id: "pending", label: "Pending Review" },
  { id: "approved", label: "Approved" },
  { id: "rejected", label: "Rejected" },
];

const Section = ({ title, children, isDark }) => {
  const [open, setOpen] = useState(true);

  return (
    <div className={`border rounded-lg ${isDark ? 'border-[#3e4042]' : 'border-gray-200'}`}>
      <button
        type="button"
        onClick={() => setOpen(!open)}
        className={`w-full flex justify-between items-center px-3 sm:px-4 py-3 font-bold text-sm ${open ? 'rounded-t-lg' : 'rounded-lg'} ${isDark ? 'bg-[#3a3b3c]' : 'bg-yellow-50 text-pup-maroon'}`}
      >
        <span>{title}</span>
        <ChevronDownIcon
          className={`w-4 h-4 transition ${open ? 'rotate-180' : ''}`}
        />
      </button>

      {open && <div className={`p-3 sm:p-4 text-sm rounded-b-lg ${isDark ? 'bg-[#242526]' : 'bg-white'}`}>{children}</div>}
    </div>
  );
};

const RowActionsDropdown = ({ onViewDetails, isDark }) => {
  const [isOpen, setIsOpen] = useState(false);
  const dropdownRef = useRef(null);

  useEffect(() => {
    if (!isOpen) return;
    const handleClickOutside = (e) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target)) {
        setIsOpen(false);
      }
    };
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, [isOpen]);

  return (
    <div className="relative inline-block text-left" ref={dropdownRef}>
      <button
        type="button"
        title="More Actions"
        onClick={() => setIsOpen(!isOpen)}
        className={`p-2 rounded-lg transition-colors flex items-center justify-center focus:outline-none ${isOpen
          ? isDark
            ? "bg-[#2a2a2f] text-[#ffc72c] border border-[#ffc72c]/30"
            : "bg-gray-100 text-[#800000] border border-gray-200"
          : isDark
            ? "text-[#b0b3b8] hover:text-[#e4e6eb] hover:bg-[#3a3b3c] border border-transparent"
            : "text-gray-400 hover:text-gray-600 hover:bg-gray-100 border border-transparent"
          }`}
      >
        <EllipsisVerticalIcon className="w-5 h-5" />
      </button>

      {isOpen && (
        <div
          className={`absolute right-0 mt-1.5 w-48 rounded-xl shadow-lg border z-50 overflow-hidden text-left ${isDark ? "bg-[#1f1f1f] text-[#e4e6eb] border-[#3e4042]" : "bg-white text-gray-700 border-gray-200"
            }`}
          style={{ boxShadow: "0 8px 32px -4px rgba(0,0,0,0.18), 0 2px 8px -2px rgba(0,0,0,0.10)" }}
        >
          <div className="py-1 flex flex-col gap-0.5">
            <button
              type="button"
              onClick={() => { onViewDetails(); setIsOpen(false); }}
              className={`w-full flex items-center gap-2.5 px-4 py-2.5 text-xs font-semibold transition-colors ${isDark ? "hover:bg-[#2a2a2f] text-[#e4e6eb]" : "hover:bg-gray-50 text-gray-700"
                }`}
            >
              <EyeIcon className="w-4 h-4 text-gray-400 dark:text-[#808080]" />
              Review Details
            </button>
          </div>
          <div className="h-1 w-full bg-linear-to-r from-[#FFD700] via-[#FFC72C] to-[#FFD700]" />
        </div>
      )}
    </div>
  );
};

const formatDateAndTime = (dateStr) => {
  if (!dateStr) return { date: "N/A", time: "" };
  const d = new Date(dateStr);
  if (isNaN(d.getTime())) return { date: "N/A", time: "" };

  const months = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
  const date = `${months[d.getMonth()]} ${d.getDate()}, ${d.getFullYear()}`;
  const time = `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}:${String(d.getSeconds()).padStart(2, '0')}`;
  return { date, time };
};

const UndergradRequestorVerificationPage = () => {
  const { isDark } = useTheme();
  const { user } = useAuth();

  // Action Gating — Default to enabled for all staff users (admin & super_admin)
  const isStaffUser = user?.role_name === "admin" || user?.role_name === "super_admin";
  const canApprove = isStaffUser || hasModuleAction(user, MODULE_KEYS.UNDERGRAD_VERIFICATION, "Approve");
  const canReject = isStaffUser || hasModuleAction(user, MODULE_KEYS.UNDERGRAD_VERIFICATION, "Reject");

  // Queue state
  const [activeTab, setActiveTab] = useState("pending");
  const [searchTerm, setSearchTerm] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [queueData, setQueueData] = useState([]);
  const [meta, setMeta] = useState(null);
  const [selectedIds, setSelectedIds] = useState([]);

  // Dashboard Dropdown Filter States & Refs
  const [filterClassification, setFilterClassification] = useState("All");
  const [classificationDropdownOpen, setClassificationDropdownOpen] = useState(false);
  const classificationDropdownRef = useRef(null);

  const [filterDocument, setFilterDocument] = useState("All");
  const [documentDropdownOpen, setDocumentDropdownOpen] = useState(false);
  const documentDropdownRef = useRef(null);

  const [statusDropdownOpen, setStatusDropdownOpen] = useState(false);
  const statusDropdownRef = useRef(null);

  const [sortOrder, setSortOrder] = useState("Recent Requests");

  // Document/Program options
  const documentOptions = useMemo(() => {
    const set = new Set(["All"]);
    queueData.forEach((row) => {
      if (row.program) set.add(row.program);
    });
    return Array.from(set);
  }, [queueData]);

  // Derived filtered & sorted queue list for display
  const displayedQueueData = useMemo(() => {
    let list = [...queueData];

    if (filterClassification !== "All") {
      list = list.filter((r) => (r.classification || "Student").toLowerCase() === filterClassification.toLowerCase());
    }

    if (filterDocument !== "All") {
      list = list.filter((r) => String(r.program || "").toLowerCase() === filterDocument.toLowerCase());
    }

    list.sort((a, b) => {
      const dateA = new Date(a.submitted_at || a.created_at || 0).getTime();
      const dateB = new Date(b.submitted_at || b.created_at || 0).getTime();
      return sortOrder === "Recent Requests" ? dateB - dateA : dateA - dateB;
    });

    return list;
  }, [queueData, filterClassification, filterDocument, sortOrder]);

  // Detail Modal state
  const [selectedUserId, setSelectedUserId] = useState(null);
  const [detailData, setDetailData] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState(null);

  // Action Modals state
  const [actionUserId, setActionUserId] = useState(null);
  const [showApproveConfirm, setShowApproveConfirm] = useState(false);
  const [showRejectModal, setShowRejectModal] = useState(false);
  const [rejectionReason, setRejectionReason] = useState("");
  const [actionSubmitting, setActionSubmitting] = useState(false);

  // Notifications
  const [successMsg, setSuccessMsg] = useState("");
  const [errorMsg, setErrorMsg] = useState("");

  // Search debounce
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchTerm);
      setPage(1);
    }, 400);
    return () => clearTimeout(timer);
  }, [searchTerm]);

  // Fetch queue list with mock fallback for demonstration
  const fetchQueue = useCallback(async () => {
    setLoading(true);
    try {
      const res = await getUndergradRequestorQueue({
        status: activeTab,
        search: debouncedSearch || undefined,
        page,
        per_page: 15,
      });

      const rows = res.data?.data || [];
      const paginationMeta = res.data?.meta || null;
      setQueueData(rows);
      setMeta(paginationMeta);
    } catch (err) {
      setQueueData([]);
      setMeta(null);
      setErrorMsg(err?.response?.data?.message || "Failed to load undergrad requestor queue.");
    } finally {
      setLoading(false);
    }
  }, [activeTab, debouncedSearch, page]);

  useEffect(() => {
    fetchQueue();
  }, [fetchQueue]);

  // Checkbox Selection
  const handleSelectAll = (e) => {
    if (e.target.checked) {
      setSelectedIds(displayedQueueData.map((r) => r.user_id || r.id));
    } else {
      setSelectedIds([]);
    }
  };

  const handleSelectOne = (id) => {
    if (selectedIds.includes(id)) {
      setSelectedIds(selectedIds.filter((i) => i !== id));
    } else {
      setSelectedIds([...selectedIds, id]);
    }
  };

  // Open Detail Modal & fetch record with mock fallback
  const handleOpenDetail = async (userId) => {
    setSelectedUserId(userId);
    setDetailLoading(true);
    setDetailError(null);
    setDetailData(null);

    try {
      const res = await getUndergradRequestorDetail(userId);
      setDetailData(res.data);
    } catch (err) {
      setDetailError(err?.response?.data?.message || "Failed to load requestor details.");
    } finally {
      setDetailLoading(false);
    }
  };

  const handleCloseDetail = () => {
    setSelectedUserId(null);
    setDetailData(null);
    setDetailError(null);
  };

  // Handle Approve
  const handleConfirmApprove = async () => {
    const targetId = actionUserId || selectedUserId;
    if (!targetId || !canApprove) return;
    setActionSubmitting(true);
    try {
      const res = await approveUndergradRequestor(targetId);
      if (selectedUserId === targetId) {
        setDetailData(res.data);
      }
      setSuccessMsg("Undergraduate requestor submission approved successfully.");
      setShowApproveConfirm(false);
      setActionUserId(null);
      fetchQueue(); // Refresh list behind modal
    } catch (err) {
      setErrorMsg(err?.response?.data?.message || "Failed to approve requestor submission.");
    } finally {
      setActionSubmitting(false);
    }
  };

  // Handle Reject
  const handleConfirmReject = async () => {
    const targetId = actionUserId || selectedUserId;
    if (!targetId || !canReject || rejectionReason.trim().length < 10) return;
    setActionSubmitting(true);
    try {
      const res = await rejectUndergradRequestor(targetId, rejectionReason.trim());
      if (selectedUserId === targetId) {
        setDetailData(res.data);
      }
      setSuccessMsg("Undergraduate requestor submission rejected.");
      setShowRejectModal(false);
      setRejectionReason("");
      setActionUserId(null);
      fetchQueue(); // Refresh list behind modal
    } catch (err) {
      setErrorMsg(err?.response?.data?.message || "Failed to reject requestor submission.");
    } finally {
      setActionSubmitting(false);
    }
  };

  const isFilterActive =
    searchTerm.trim() !== "" ||
    filterClassification !== "All" ||
    filterDocument !== "All" ||
    sortOrder !== "Recent Requests";

  return (
    <div className="max-w-7xl mx-auto px-3 sm:px-5 mb-6 w-full font-sans">
      <LoadingOverlay isVisible={loading} message="Fetching Request Records..." />
      <div
        className={`rounded-2xl p-4 sm:p-5 shadow-sm border ${isDark ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]" : "bg-white border-gray-200 text-gray-900"
          }`}
      >
        {/* Toast Notifications */}
        {successMsg && <SuccessToast message={successMsg} onClose={() => setSuccessMsg("")} />}
        {errorMsg && <ErrorToast message={errorMsg} onClose={() => setErrorMsg("")} />}

        {/* ---------------- 3 STAT CARDS (Pending / Approved / Rejected) ----------------
            NOTE: GET /api/admin/undergrad-requestors returns rows for exactly ONE status
            per request (the active tab). queueData therefore never contains rows for the
            other two tabs, so their counts cannot be derived client-side without a second
            request. Rather than silently showing a wrong "0" for tabs we have no data for,
            we show the real fetched total for the active tab and an honest "—" placeholder
            for the others. */}
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4 mb-4">
          <StatCard
            title="Pending Verification"
            count={activeTab === "pending" ? (meta?.total ?? queueData.length) : "—"}
            color="amber"
          />
          <StatCard
            title="Approved Submissions"
            count={activeTab === "approved" ? (meta?.total ?? queueData.length) : "—"}
            color="emerald"
          />
          <StatCard
            title="Rejected Submissions"
            count={activeTab === "rejected" ? (meta?.total ?? queueData.length) : "—"}
            color="orange"
          />
        </div>

        {/* ---------------- TOOLBAR ---------------- */}
        <div
          className={`p-2.5 sm:p-3 rounded-xl shadow-xs mb-4 flex flex-col md:flex-row gap-2.5 justify-between items-center ${isDark ? "bg-[#18191a] border border-[#3e4042]" : "bg-gray-50/60 border border-gray-100"
            }`}
        >
          <div className="flex flex-1 items-center gap-3 w-full md:max-w-xl">
            <div className="flex-1">
              <VoiceSearchInput
                value={searchTerm}
                onChange={setSearchTerm}
                placeholder="Search requestor name, email, or student number..."
                language="en-US"
              />
            </div>
          </div>

          <div className="flex flex-wrap items-center gap-2 w-full md:w-auto justify-end">
            {isFilterActive && (
              <button
                type="button"
                onClick={() => {
                  setSearchTerm("");
                  setFilterClassification("All");
                  setFilterDocument("All");
                  setSortOrder("Recent Requests");
                  setSelectedIds([]);
                }}
                className={`px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-semibold transition-colors border shadow-xs flex items-center justify-center shrink-0 whitespace-nowrap cursor-pointer ${isDark
                  ? "bg-[#1f1f1f] text-[#b0b3b8] border-[#3e4042] hover:bg-[#2a2a2f] hover:text-[#e4e6eb]"
                  : "bg-white text-gray-600 border-gray-200 hover:bg-gray-50 hover:text-gray-900"
                  }`}
              >
                Clear Filters
              </button>
            )}
          </div>
        </div>

        {/* ---------------- TABLE ---------------- */}
        <div
          className={`rounded-xl shadow-xs overflow-x-auto border ${isDark ? "bg-[#18191a] border-[#3e4042]" : "bg-white border-gray-100"
            }`}
        >
          <table className={`min-w-full divide-y ${isDark ? "divide-[#3e4042]" : "divide-gray-100"}`}>
            <thead className={isDark ? "bg-[#18191a]" : "bg-gray-50"}>
              <tr>
                <th className="px-3 py-2.5 w-8 text-center">
                  <input
                    type="checkbox"
                    className={`w-3.5 h-3.5 rounded cursor-pointer ${isDark
                      ? "border-[#4e4f50] text-blue-400 focus:ring-blue-400 bg-[#242526]"
                      : "border-gray-300 text-blue-600 focus:ring-blue-500"
                      }`}
                    onChange={handleSelectAll}
                    checked={displayedQueueData.length > 0 && selectedIds.length === displayedQueueData.length}
                  />
                </th>
                <Th center>#</Th>
                <Th center>NAME</Th>
                <Th center>
                  <DashboardDropdown
                    isOpen={classificationDropdownOpen}
                    setIsOpen={setClassificationDropdownOpen}
                    dropdownRef={classificationDropdownRef}
                    align="center"
                    trigger={<span>CLASSIFICATION</span>}
                    sections={[
                      {
                        title: "Filter by Classification",
                        items: ["All", "Student", "Alumni"].map((option) => ({
                          label: option,
                          isSelected: filterClassification === option,
                          onClick: () => setFilterClassification(option),
                        })),
                      },
                    ]}
                  />
                </Th>
                <Th center>
                  <DashboardDropdown
                    isOpen={documentDropdownOpen}
                    setIsOpen={setDocumentDropdownOpen}
                    dropdownRef={documentDropdownRef}
                    width="w-64"
                    trigger={<span>PROGRAM</span>}
                    sections={[
                      {
                        title: "Filter by Program",
                        items: documentOptions.map((option) => ({
                          label: option,
                          isSelected: filterDocument === option,
                          onClick: () => setFilterDocument(option),
                        })),
                      },
                    ]}
                  />
                </Th>
                <Th center>
                  <button
                    type="button"
                    onClick={() => setSortOrder((prev) => (prev === "Recent Requests" ? "Old Requests" : "Recent Requests"))}
                    className="flex items-center justify-center gap-1 mx-auto text-xs uppercase font-bold hover:text-[#800000] dark:hover:text-[#FFC72C] transition-colors focus:outline-none cursor-pointer"
                  >
                    <span>DATE & TIME</span>
                    {sortOrder === "Recent Requests" ? (
                      <ChevronDownIcon className="w-3.5 h-3.5 text-blue-500" />
                    ) : (
                      <ChevronUpIcon className="w-3.5 h-3.5 text-blue-500" />
                    )}
                  </button>
                </Th>
                <Th center>
                  <DashboardDropdown
                    isOpen={statusDropdownOpen}
                    setIsOpen={setStatusDropdownOpen}
                    dropdownRef={statusDropdownRef}
                    align="center"
                    trigger={<span>STATUS</span>}
                    sections={[
                      {
                        title: "Filter by Status",
                        items: [
                          { label: "Pending Review", isSelected: activeTab === "pending", onClick: () => { setActiveTab("pending"); setPage(1); } },
                          { label: "Approved", isSelected: activeTab === "approved", onClick: () => { setActiveTab("approved"); setPage(1); } },
                          { label: "Rejected", isSelected: activeTab === "rejected", onClick: () => { setActiveTab("rejected"); setPage(1); } },
                        ],
                      },
                    ]}
                  />
                </Th>
                <Th center>ACTIONS</Th>
              </tr>
            </thead>
            <tbody className={isDark ? "divide-y divide-[#3e4042]" : "divide-y divide-gray-100"}>
              {displayedQueueData.length === 0 ? (
                <tr>
                  <td colSpan="8" className="px-6 py-16 text-center">
                    <div className="flex flex-col items-center justify-center gap-3">
                      <DocumentTextIcon className={`w-12 h-12 ${isDark ? "text-gray-600" : "text-gray-300"}`} />
                      <p className={`font-semibold text-sm ${isDark ? "text-white" : "text-gray-700"}`}>
                        No requestor records found
                      </p>
                      <p className={`text-xs ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>
                        {debouncedSearch
                          ? `No results matching "${debouncedSearch}"`
                          : "There are currently no records in this view."}
                      </p>
                    </div>
                  </td>
                </tr>
              ) : (
                displayedQueueData.map((row, index) => {
                  const rowId = row.user_id || row.id;
                  const isSelected = selectedIds.includes(rowId);
                  const { date, time } = formatDateAndTime(row.submitted_at || row.created_at);
                  const nameStr = (row.full_name || row.name || `${row.first_name || "Juan"} ${row.last_name || "Dela Cruz"}`).trim();
                  const nameParts = nameStr.split(/\s+/);

                  return (
                    <tr
                      key={rowId}
                      className={`transition-colors ${isSelected
                        ? isDark
                          ? "bg-[#2a2a2f]"
                          : "bg-blue-50/40"
                        : isDark
                          ? "hover:bg-[#2a2a2f]"
                          : "hover:bg-gray-50/80"
                        }`}
                    >
                      <td className="px-3 py-2.5 text-center">
                        <input
                          type="checkbox"
                          checked={isSelected}
                          onChange={() => handleSelectOne(rowId)}
                          className={`w-3.5 h-3.5 rounded cursor-pointer ${isDark
                            ? "border-[#4e4f50] text-blue-400 focus:ring-blue-400 bg-[#242526]"
                            : "border-gray-300 text-blue-600 focus:ring-blue-500"
                            }`}
                        />
                      </td>
                      <Td center>{index + 1}</Td>
                      <Td center>
                        <div className="flex flex-col items-center leading-tight">
                          {nameParts.map((part, pIdx) => (
                            <span key={pIdx} className="font-bold text-gray-900 dark:text-white">
                              {part}
                            </span>
                          ))}
                        </div>
                      </Td>
                      <Td center>
                        <span className="font-semibold text-gray-700 dark:text-[#e4e6eb]">{row.classification || "Student"}</span>
                      </Td>
                      <Td center>
                        <span className="font-semibold text-gray-700 dark:text-[#e4e6eb]">
                          {row.program || "BS Computer Science"}
                        </span>
                      </Td>
                      <Td center>
                        <div className="flex flex-col items-center leading-tight text-gray-400 dark:text-[#8f949d] text-[11px]">
                          <span>{date}</span>
                          {time && <span>{time}</span>}
                        </div>
                      </Td>
                      <Td center>
                        {activeTab === "pending" ? (
                          <span className="px-2.5 py-0.5 rounded-full text-[11px] font-bold border whitespace-nowrap bg-amber-100 text-amber-800 border-amber-300 dark:bg-amber-900/30 dark:text-amber-400 dark:border-amber-600">
                            Pending Review
                          </span>
                        ) : activeTab === "approved" ? (
                          <span className="px-2.5 py-0.5 rounded-full text-[11px] font-bold border whitespace-nowrap bg-emerald-100 text-emerald-800 border-emerald-300 dark:bg-emerald-900/30 dark:text-emerald-400 dark:border-emerald-600">
                            Approved
                          </span>
                        ) : (
                          <span className="px-2.5 py-0.5 rounded-full text-[11px] font-bold border whitespace-nowrap bg-rose-100 text-rose-800 border-rose-300 dark:bg-rose-900/30 dark:text-rose-400 dark:border-rose-600">
                            Rejected
                          </span>
                        )}
                      </Td>
                      <Td center>
                        <div className="flex items-center justify-center gap-1.5">
                          {canApprove && activeTab === "pending" && (
                            <button
                              type="button"
                              onClick={() => {
                                setActionUserId(rowId);
                                setShowApproveConfirm(true);
                              }}
                              className="flex items-center gap-1.5 px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-lg shadow-xs transition-colors cursor-pointer"
                            >
                              <CheckIcon className="w-3.5 h-3.5" />
                              <span>Approve</span>
                            </button>
                          )}
                          {canReject && activeTab === "pending" && (
                            <button
                              type="button"
                              onClick={() => {
                                setActionUserId(rowId);
                                setShowRejectModal(true);
                              }}
                              className="flex items-center gap-1.5 px-3 py-1 bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold rounded-lg shadow-xs transition-colors cursor-pointer"
                            >
                              <XMarkIcon className="w-3.5 h-3.5" />
                              <span>Reject</span>
                            </button>
                          )}
                          <RowActionsDropdown
                            onViewDetails={() => handleOpenDetail(rowId)}
                            isDark={isDark}
                          />
                        </div>
                      </Td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>

          {/* Pagination Footer */}
          {meta && (
            <Pagination
              filteredCount={meta.total || queueData.length}
              indexOfFirstItem={(meta.current_page - 1) * meta.per_page}
              indexOfLastItem={Math.min(meta.current_page * meta.per_page, meta.total)}
              currentPage={meta.current_page}
              totalPages={meta.last_page}
              handlePrevPage={() => setPage((p) => Math.max(1, p - 1))}
              handleNextPage={() => setPage((p) => Math.min(meta.last_page, p + 1))}
            />
          )}
        </div>
      </div>

      {/* DETAIL MODAL */}
      {selectedUserId && (
        <div className="fixed inset-0 z-9999 flex items-center justify-center p-4">
          <div
            className={`absolute inset-0 backdrop-blur-sm ${isDark ? 'bg-black/70' : 'bg-black/50'}`}
            onClick={handleCloseDetail}
          />
          <div
            className={`relative rounded-2xl shadow-2xl w-full max-w-2xl lg:max-w-4xl max-h-[calc(100vh-64px)] overflow-hidden flex flex-col ${isDark ? "bg-[#242526] border border-[#3e4042]" : "bg-white"
              }`}
          >
            {/* Modal Header */}
            <div className={`relative px-4 sm:px-6 py-3 sm:py-4 flex justify-between items-center shrink-0 ${isDark ? 'bg-[#3a3b3c]' : 'bg-pup-maroon'}`}>
              <div>
                <h3 className="text-base sm:text-lg font-bold text-white">Undergraduate Registration Details</h3>
                <p className={`text-xs sm:text-sm wrap-break-word ${isDark ? 'text-[#b0b3b8]' : 'text-yellow-200'}`}>
                  Requestor ID: #{selectedUserId}
                </p>
              </div>
              <button
                type="button"
                onClick={handleCloseDetail}
                aria-label="Close request details"
                className="absolute top-2 right-2 sm:top-3 sm:right-3 text-white hover:text-yellow-200 transition cursor-pointer"
              >
                <XCircleIcon className="w-7 h-7" />
              </button>
            </div>

            {/* Modal Content Body */}
            <div className={`flex-1 overflow-y-auto p-3 sm:p-4 lg:p-6 space-y-4 lg:space-y-6 ${isDark ? 'text-[#e4e6eb]' : 'text-gray-900'}`}>
              {detailLoading ? (
                <div className="py-12 text-center">
                  <div className="w-8 h-8 border-2 border-amber-500 border-t-transparent rounded-full animate-spin mx-auto mb-2" />
                  <p className={isDark ? "text-[#b0b3b8]" : "text-gray-500"}>Fetching requestor detail record...</p>
                </div>
              ) : detailError ? (
                <div className="p-4 rounded-xl bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 text-sm">
                  {detailError}
                </div>
              ) : detailData ? (
                <>
                  {/* Status Banner — sourced from verification.status (Pending/Approved/Rejected),
                      the single source of truth per UndergradRequestorVerification's docblock.
                      account.status ("Pending Activation" etc.) is a related but distinct
                      lifecycle field and is intentionally not used to drive this banner's color. */}
                  <div
                    className={`p-4 rounded-xl border flex items-center justify-between text-xs sm:text-sm font-medium ${detailData.verification?.status === "Approved"
                      ? "bg-emerald-50 border-emerald-200 text-emerald-900 dark:bg-emerald-950/40 dark:border-emerald-800 dark:text-emerald-300"
                      : detailData.verification?.status === "Rejected"
                        ? "bg-rose-50 border-rose-200 text-rose-900 dark:bg-rose-950/40 dark:border-rose-800 dark:text-rose-300"
                        : "bg-amber-50 border-amber-200 text-amber-900 dark:bg-amber-950/40 dark:border-amber-800 dark:text-amber-300"
                      }`}
                  >
                    <span>Status: <strong className="uppercase font-bold">{detailData.verification?.status_label || detailData.verification?.status || "Pending"}</strong></span>
                    {detailData.declared_profile?.submitted_at && (
                      <span className="text-xs opacity-80">
                        Submitted: {new Date(detailData.declared_profile.submitted_at).toLocaleString()}
                      </span>
                    )}
                  </div>

                  {/* Decision Guidance Banner */}
                  {detailData.decision_guidance && (
                    <div className={`p-4 rounded-xl border text-xs leading-relaxed ${isDark ? "bg-[#18191a] border-[#3e4042] text-[#b0b3b8]" : "bg-blue-50/70 border-blue-200 text-blue-900"}`}>
                      <span className="font-semibold block mb-1">Decision Guidance:</span>
                      {detailData.decision_guidance}
                    </div>
                  )}

                  {/* Section 1: Declared Profile */}
                  <Section title="DECLARED PROFILE (SELF-DECLARED, UNVERIFIED)" isDark={isDark}>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                      <div>
                        <span className={`block font-semibold ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>Full Name:</span>
                        <p className="font-bold text-sm mt-0.5">
                          {detailData.declared_profile?.full_name || `${detailData.declared_profile?.first_name || ""} ${detailData.declared_profile?.last_name || ""}`}
                        </p>
                      </div>

                      <div>
                        <span className={`block font-semibold ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>Email:</span>
                        <p className="font-bold text-sm mt-0.5">{detailData.account?.email || "N/A"}</p>
                      </div>

                      <div>
                        <span className={`block font-semibold ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>Student Number:</span>
                        <p className="font-mono font-bold text-sm mt-0.5">{detailData.declared_profile?.student_number || "N/A"}</p>
                      </div>

                      <div>
                        <span className={`block font-semibold ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>Phone:</span>
                        <p className="font-bold text-sm mt-0.5">{detailData.declared_profile?.phone || "N/A"}</p>
                      </div>

                      <div>
                        <span className={`block font-semibold ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>Date of Birth:</span>
                        <p className="font-bold text-sm mt-0.5">{detailData.declared_profile?.date_of_birth || "N/A"}</p>
                      </div>

                      <div>
                        <span className={`block font-semibold ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>Program / Course:</span>
                        <p className="font-bold text-sm mt-0.5">{detailData.declared_profile?.program || "N/A"}</p>
                      </div>

                      <div>
                        <span className={`block font-semibold ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>Last School Year Attended:</span>
                        <p className="font-bold text-sm mt-0.5">{detailData.declared_profile?.last_school_year_attended || "N/A"}</p>
                      </div>

                      <div>
                        <span className={`block font-semibold ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>Present Address:</span>
                        <p className="font-bold text-sm mt-0.5">{detailData.declared_profile?.present_address || "N/A"}</p>
                      </div>

                      {detailData.declared_profile?.reason_for_non_enrollment && (
                        <div className="col-span-full">
                          <span className={`block font-semibold ${isDark ? "text-[#b0b3b8]" : "text-gray-500"}`}>Reason for Non-Enrollment:</span>
                          <p className="font-bold text-sm mt-0.5">{detailData.declared_profile.reason_for_non_enrollment}</p>
                        </div>
                      )}
                    </div>
                  </Section>

                  {/* Section 2: Advisory Checks */}
                  <Section title="ADVISORY SYSTEM MATCH CHECKS (ADVISORY ONLY)" isDark={isDark}>
                    {detailData.advisory_checks?.advisory_notice && (
                      <p className={`text-[11px] italic mb-3 ${isDark ? "text-[#8f949d]" : "text-gray-500"}`}>
                        {detailData.advisory_checks.advisory_notice}
                      </p>
                    )}
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                      {/* Local Mirror Check */}
                      <div className={`p-3.5 rounded-xl border text-xs ${isDark ? "bg-[#18191a] border-[#3e4042]" : "bg-gray-50 border-gray-200"}`}>
                        <span className="font-semibold block mb-1">
                          {detailData.advisory_checks?.local_records_check?.label || "Local Database Mirror Check"}
                        </span>
                        <p className="text-[#b0b3b8] dark:text-gray-400 mb-2">
                          {detailData.advisory_checks?.local_records_check?.interpretation || "Advisory lookup against local registrar records."}
                        </p>
                        <span className={`inline-block px-2 py-0.5 rounded font-semibold ${detailData.advisory_checks?.local_records_check?.match_found
                          ? "bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300"
                          : "bg-gray-200 text-gray-700 dark:bg-gray-800 dark:text-gray-300"
                          }`}>
                          Match status: advisory ({detailData.advisory_checks?.local_records_check?.match_found ? "match found" : "no match"})
                        </span>
                      </div>

                      {/* OGOS Check — severity-driven, since a `warning` here (person shows as
                          CURRENTLY ENROLLED, which an Undergrad Requestor should not be) is the
                          single most important signal on this screen and must never render the
                          same as a routine, expected "no match". */}
                      {(() => {
                        const ogos = detailData.advisory_checks?.ogos_enrollment_check;
                        const severity = ogos?.severity; // 'warning' | 'info' | 'unavailable'
                        const badgeClasses =
                          severity === "warning"
                            ? "bg-rose-100 text-rose-800 border border-rose-300 dark:bg-rose-950 dark:text-rose-300 dark:border-rose-700"
                            : severity === "unavailable"
                              ? "bg-gray-200 text-gray-600 dark:bg-gray-800 dark:text-gray-400"
                              : "bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300";
                        const badgeLabel =
                          severity === "warning"
                            ? "⚠ enrolled at OGOS — investigate"
                            : severity === "unavailable"
                              ? "not performed"
                              : "no match (expected)";

                        return (
                          <div className={`p-3.5 rounded-xl border text-xs ${severity === "warning"
                            ? "border-rose-300 dark:border-rose-700 bg-rose-50/60 dark:bg-rose-950/20"
                            : isDark ? "bg-[#18191a] border-[#3e4042]" : "bg-gray-50 border-gray-200"
                            }`}>
                            <span className="font-semibold block mb-1">
                              {ogos?.label || "OGOS Database Match Check"}
                            </span>
                            <p className="text-[#b0b3b8] dark:text-gray-400 mb-2">
                              {ogos?.interpretation || "Advisory lookup against OGOS records."}
                            </p>
                            <span className={`inline-block px-2 py-0.5 rounded font-semibold ${badgeClasses}`}>
                              Match status: advisory ({badgeLabel})
                            </span>
                          </div>
                        );
                      })()}
                    </div>

                    {/* Duplicate Student Number Check */}
                    {detailData.advisory_checks?.duplicate_student_number && (
                      <div className={`mt-3 p-3.5 rounded-xl border text-xs ${isDark ? "bg-[#18191a] border-[#3e4042]" : "bg-gray-50 border-gray-200"}`}>
                        <span className="font-semibold block mb-1">
                          {detailData.advisory_checks.duplicate_student_number.label || "Other Submissions Using This Student Number"}
                        </span>
                        <p className="text-[#b0b3b8] dark:text-gray-400 mb-2">
                          {detailData.advisory_checks.duplicate_student_number.interpretation}
                        </p>
                        {detailData.advisory_checks.duplicate_student_number.count > 0 && (
                          <ul className="space-y-1">
                            {(detailData.advisory_checks.duplicate_student_number.sample || []).map((dup) => (
                              <li key={dup.user_id} className={`font-mono text-[11px] ${isDark ? "text-[#b0b3b8]" : "text-gray-600"}`}>
                                #{dup.user_id} — {dup.full_name || dup.email} ({dup.status || "unknown status"})
                              </li>
                            ))}
                          </ul>
                        )}
                      </div>
                    )}
                  </Section>

                  {/* Section 3: Data Privacy Consent Audit */}
                  <Section title="DATA PRIVACY CONSENT RECORD" isDark={isDark}>
                    {detailData.data_privacy_consent?.recorded ? (
                      <div className={`p-3 rounded-xl border text-xs flex items-center justify-between ${isDark ? "bg-[#18191a] border-[#3e4042] text-[#e4e6eb]" : "bg-emerald-50 border-emerald-200 text-emerald-900"}`}>
                        <div className="flex items-center gap-2">
                          <CheckCircleIcon className="w-5 h-5 text-emerald-500 shrink-0" />
                          <span>Consent Recorded Verbatim</span>
                        </div>
                        <span className="font-mono text-xs opacity-80">
                          Version: {detailData.data_privacy_consent.version || "N/A"}
                        </span>
                      </div>
                    ) : (
                      <div className="p-3.5 rounded-xl bg-rose-50 dark:bg-rose-950/50 border border-rose-300 dark:border-rose-800 text-rose-800 dark:text-rose-300 text-xs flex items-start gap-2">
                        <ExclamationTriangleIcon className="w-5 h-5 text-rose-500 shrink-0 mt-0.5" />
                        <div>
                          <strong className="block font-semibold">Warning: No consent record on file for this submission</strong>
                          Please verify data privacy documentation prior to taking any administrative decision.
                        </div>
                      </div>
                    )}
                  </Section>

                  {/* Section 4: Decision Record — only present once a reviewer has actually
                      decided this submission (verification.status !== "Pending"). */}
                  {detailData.verification?.status && detailData.verification.status !== "Pending" && (
                    <Section title="DECISION RECORD" isDark={isDark}>
                      <div className={`p-3 rounded-xl border text-xs space-y-1 ${isDark ? "bg-[#18191a] border-[#3e4042]" : "bg-gray-50 border-gray-200"}`}>
                        <div className="flex justify-between font-semibold">
                          <span>{detailData.verification.status_label || detailData.verification.status}</span>
                          <span className="text-gray-400">
                            {detailData.verification.reviewed_at ? new Date(detailData.verification.reviewed_at).toLocaleString() : ""}
                          </span>
                        </div>
                        {detailData.verification.reviewed_by && (
                          <p className="text-gray-400 text-[11px]">Reviewed by: {detailData.verification.reviewed_by}</p>
                        )}
                        {detailData.verification.rejection_reason && (
                          <p className="text-gray-600 dark:text-gray-300">Reason: {detailData.verification.rejection_reason}</p>
                        )}
                        {detailData.verification.pii_purged_at && (
                          <p className="text-gray-400 text-[11px]">
                            Personal data purged on {new Date(detailData.verification.pii_purged_at).toLocaleString()} (retention policy)
                          </p>
                        )}
                      </div>
                    </Section>
                  )}
                </>
              ) : null}
            </div>

            {/* Modal Footer Actions - Close only */}
            <div
              className={`p-4 border-t flex items-center justify-start shrink-0 ${isDark ? "border-[#3e4042] bg-[#18191a]" : "border-gray-200 bg-gray-50"
                }`}
            >
              <button
                type="button"
                onClick={handleCloseDetail}
                className={`px-5 py-2.5 rounded-xl text-xs font-bold transition-colors cursor-pointer ${isDark ? "bg-[#3a3b3c] text-white hover:bg-[#4e4f50]" : "bg-gray-200 text-gray-800 hover:bg-gray-300"
                  }`}
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}

      {/* APPROVE CONFIRMATION MODAL */}
      {showApproveConfirm && (
        <ConfirmationModal
          isOpen={showApproveConfirm}
          title="Approve Undergrad Requestor?"
          message="Are you sure you want to approve this requestor submission? Upon approval, the account will be placed in Pending Activation until the user completes their first IDP login."
          confirmText="Yes, Approve"
          cancelText="Cancel"
          type="confirm"
          onConfirm={handleConfirmApprove}
          onClose={() => {
            setShowApproveConfirm(false);
            setActionUserId(null);
          }}
        />
      )}

      {/* REJECT DEDICATED REASON MODAL */}
      {showRejectModal && (
        <div className="fixed inset-0 z-60 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
          <div
            className={`w-full max-w-md rounded-2xl p-6 shadow-2xl border space-y-4 animate-in fade-in zoom-in-95 duration-150 ${isDark ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]" : "bg-white border-gray-200 text-gray-900"
              }`}
          >
            <div className="flex justify-between items-center border-b pb-3 border-gray-200 dark:border-[#3e4042]">
              <h3 className="font-bold text-base text-rose-600 dark:text-rose-400 flex items-center gap-2">
                <ExclamationTriangleIcon className="w-5 h-5" />
                <span>Reject Registration Submission</span>
              </h3>
              <button
                onClick={() => {
                  setShowRejectModal(false);
                  setRejectionReason("");
                  setActionUserId(null);
                }}
                className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
              >
                <XMarkIcon className="w-5 h-5" />
              </button>
            </div>

            <p className={`text-xs leading-relaxed ${isDark ? "text-[#b0b3b8]" : "text-gray-600"}`}>
              Please provide a clear reason for rejecting this submission. This reason will be emailed directly to the requestor and recorded in the permanent audit trail (Minimum 10 characters required).
            </p>

            <div>
              <label className={`block text-xs font-semibold mb-1 ${isDark ? "text-[#e4e6eb]" : "text-gray-700"}`}>
                Rejection Reason <span className="text-red-500">*</span>
              </label>
              <textarea
                rows={4}
                value={rejectionReason}
                onChange={(e) => setRejectionReason(e.target.value)}
                placeholder="State specific reason for rejection (min 10 characters)..."
                className={`w-full p-3 rounded-xl text-xs border transition-all focus:outline-none focus:border-rose-500 ${isDark
                  ? "bg-[#18191a] border-[#3e4042] text-[#e4e6eb] placeholder:text-[#8f949d]"
                  : "bg-white border-gray-200 text-gray-800 placeholder:text-gray-400"
                  }`}
              />
              <div className="flex justify-between items-center mt-1 text-[11px]">
                <span className={rejectionReason.trim().length >= 10 ? "text-emerald-500 font-medium" : "text-amber-500"}>
                  {rejectionReason.trim().length} / 10 min characters
                </span>
                {rejectionReason.trim().length < 10 && (
                  <span className="text-gray-400">Must be at least 10 chars</span>
                )}
              </div>
            </div>

            <div className="flex justify-end gap-2 pt-2 border-t border-gray-200 dark:border-[#3e4042]">
              <button
                type="button"
                onClick={() => {
                  setShowRejectModal(false);
                  setRejectionReason("");
                  setActionUserId(null);
                }}
                className={`px-4 py-2 rounded-xl text-xs font-medium ${isDark ? "bg-[#3a3b3c] text-white hover:bg-[#4e4f50]" : "bg-gray-200 text-gray-700 hover:bg-gray-300"
                  }`}
              >
                Cancel
              </button>
              <button
                type="button"
                disabled={rejectionReason.trim().length < 10 || actionSubmitting}
                onClick={handleConfirmReject}
                className="px-4 py-2 rounded-xl text-xs font-medium bg-rose-600 text-white hover:bg-rose-500 disabled:opacity-40 disabled:cursor-not-allowed transition-all shadow-sm flex items-center gap-1.5 cursor-pointer"
              >
                {actionSubmitting ? (
                  <>
                    <div className="w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin" />
                    <span>Rejecting...</span>
                  </>
                ) : (
                  <span>Confirm Rejection</span>
                )}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default UndergradRequestorVerificationPage;