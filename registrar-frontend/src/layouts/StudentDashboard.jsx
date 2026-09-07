import React, { useState, useEffect} from "react";
import { useQuery } from "@tanstack/react-query";
import { getDocumentRequests } from "../services/api"; 
import { EyeIcon, ChevronLeftIcon, ChevronRightIcon, ChevronDownIcon } from '@heroicons/react/24/solid';
import RequestDetailsModal from '../components/RequestDetailModal';
import LoadingOverlay from "../components/LoadingOverlay";
import { useNavigate } from "react-router-dom";
import { useAuth } from '../context/AuthProvider';
import { STATUS_CONFIG, TAB_MAP, TABS } from '../utils/constants';
import { useTheme } from '../context/ThemeContext';
import { useNotificationsContext } from '../context/NotificationsContext';
import { DocumentMagnifyingGlassIcon, MagnifyingGlassIcon } from '@heroicons/react/24/outline';

import { useReferenceData } from '../context/ReferenceDataContext';
import VoiceSearchInput from "../components/VoiceSearchInput.jsx";
const StudentDashboard = () => {
  const { docTypeName, purposeName, certName } = useReferenceData();
  const [activeTab, setActiveTab] = useState("pending");
  const [selectedRequest, setSelectedRequest] = useState(null);
  const [currentPage, setCurrentPage] = useState(1);
  const [searchTerm, setSearchTerm] = useState("");
  const itemsPerPage = 5;
  const { user } = useAuth();
  const { isDark } = useTheme();

  const navigate = useNavigate();
  const { notifications } = useNotificationsContext();

  useEffect(() => {
    if (!user) {
      navigate("/");
    }
  }, [user, navigate]);

  const { data: requests = [], isLoading: loading, refetch } = useQuery({
    queryKey: ['studentRequests', user?.user_id],
    queryFn: getDocumentRequests,
    enabled: !!user,
    select: (res) => {
      const rawRequests = res.data.data ?? res.data ?? [];
      return rawRequests
      .filter(r => r.user_id === user.user_id)
      .map(r => {
        const baseConfig = STATUS_CONFIG[r.status_id] || {
          label: "Unknown",
          classes: "bg-gray-100 text-gray-400 border-gray-200"
        };

        const config = {
          ...baseConfig,
          label: r.status?.status_name ?? baseConfig.label
        };

        const purposeLabel = r.request_purpose?.purpose_name ?? purposeName(r.request_purpose_id) ?? "N/A";

        //Will check first the database, before proceeding to the constant
        const docNames = r.documents?.map(d => {
        const dynamicName = d.document_type?.document_name;
        return dynamicName ?? docTypeName(d.document_type_id) ?? "Unknown Document";
      })
      .filter(Boolean) || [];

        return {
          ...r,
          config,
          purpose_label: purposeLabel,
          type: TAB_MAP[r.status_id] || "history",
          doc_names: docNames,
        };
      })
      .sort((a, b) => new Date(b.requested_at) - new Date(a.requested_at));
    },
  });

// Refetch only when a notification type that actually affects the request
// list arrives. Prevents unnecessary API calls on unrelated events
// (announcements, generic alerts, etc.) — mirrors StaffDashboard behaviour.
const STUDENT_REFETCH_TRIGGERS = new Set([
  'request_submitted',      // confirmation: own submission landed
  'awaiting_submission',    // told to bring in the source document
  'request_processing',     // admin started processing
  'pending_signature',      // registrar done, awaiting external signature
  'ready_to_claim',         // ready for pickup
  'request_completed',      // done
  'request_forfeited',      // forfeited / expired
  'request_withdrawn',      // request closed by staff
  'deficiency_notice_issued',
  'deficiency_notice_cleared',
  'deficiency_notice_voided',
]);

// Hoist into a stable primitive so React's dep-array comparison is reliable.
// Optional-chaining directly in the dep array re-evaluates to `undefined`
// every render when the array is empty, which triggers the effect spuriously.
const latestNotificationId = notifications[0]?.id ?? null;

useEffect(() => {
  if (!user || latestNotificationId === null) return;
  const latest = notifications[0];
  if (latest && STUDENT_REFETCH_TRIGGERS.has(latest.type)) {
    refetch();
  }
// refetch is provided by useQuery and is stable;
// include it here to satisfy the exhaustive-deps rule and avoid stale closures.
}, [latestNotificationId, refetch]);

  useEffect(() => { 
    setCurrentPage(1);
  }, [activeTab, searchTerm]);

  const filteredRequests = requests.filter((req) => {
    // 1. Tab filter
    if (req.type !== activeTab) return false;

    // 2. Search Term filter
    if (searchTerm.trim() !== "") {
      const term = searchTerm.toLowerCase();
      const matchesDocNames = req.doc_names && req.doc_names.some(name => name.toLowerCase().includes(term));
      const matchesCertNames = req.certificates && req.certificates.some(c => {
         const cert = c.certification_type?.certificate_name ?? certName(c.certification_type_id) ?? "";
         return cert.toLowerCase().includes(term);
      });
      const matchesUuid = req.uuid && req.uuid.toLowerCase().includes(term);
      const matchesId = req.request_id && req.request_id.toString().includes(term);
      const matchesPurpose = req.purpose_label && req.purpose_label.toLowerCase().includes(term);

      if (!matchesDocNames && !matchesCertNames && !matchesUuid && !matchesId && !matchesPurpose) {
        return false;
      }
    }

    return true;
  });

  const totalPages = Math.ceil(filteredRequests.length / itemsPerPage);
  const indexOfLastItem = currentPage * itemsPerPage;
  const indexOfFirstItem = indexOfLastItem - itemsPerPage;
  const currentItems = filteredRequests.slice(indexOfFirstItem, indexOfLastItem);

  const handlePrevPage = () => setCurrentPage((prev) => Math.max(prev - 1, 1));
  const handleNextPage = () => setCurrentPage((prev) => Math.min(prev + 1, totalPages));

  return (
    <main className={`max-w-6xl mx-auto -mt-1 relative z-20 ${isDark ? 'text-[#e4e6eb]' : 'text-gray-900'}`}>
      <LoadingOverlay isVisible={loading} message="Syncing Requested Documents..." />

      <div className="grid grid-cols-3 gap-4 place-items-center mb-5">
        {TABS.map((tab) => (
          <div key={`${tab.value}-${isDark}`} className="w-full flex justify-center">
            <button
              onClick={() => setActiveTab(tab.value)}
              className={`relative w-full max-w-xs p-2.5 sm:p-4 rounded-xl border-2 transition-all duration-300 flex items-center justify-center gap-3 group ${
                activeTab === tab.value
                  ? `${isDark ? tab.darkActive : tab.active} shadow-lg scale-105`
                  : `${isDark ? tab.darkInactive : tab.inactive} hover:shadow-md`
              }`}
            >
              <span className="font-bold text-sm sm:text-base md:text-lg whitespace-nowrap">{tab.label}</span>
              <span className="absolute -top-2 -right-2 bg-red-600 text-white text-xs font-bold w-6 h-6 flex items-center justify-center rounded-full shadow-sm ring-2 ring-white">
                {requests.filter((req) => req.type === tab.value).length}
              </span>
            </button>
          </div>
        ))}
      </div>
      {/* Document Requests List */}
      <div className={`flex flex-col rounded-2xl shadow-sm border overflow-hidden min-h-175 ${isDark ? 'bg-[#242526] border-[#3e4042]' : 'bg-white border-gray-200'}`}>
        <div className={`p-6 border-b flex flex-col lg:flex-row lg:items-center justify-between gap-4 shrink-0 ${isDark ? 'border-[#3e4042] bg-[#18191a]/80' : 'border-gray-100 bg-gray-50/50'}`}>
          <div className="flex flex-col md:flex-row md:items-center gap-4 flex-1">
            <h3 className={`font-bold text-lg shrink-0 ${isDark ? 'text-[#e4e6eb]' : 'text-gray-800'}`}>
              {activeTab === "pending" && "Processing Requests"}
              {activeTab === "ready" && "Documents Ready for Pickup"}
              {activeTab === "history" && "Transaction Archive"}
            </h3>
            {/* Search Box */}
            <div className="flex-1 max-w-md w-full text-xs">
              <VoiceSearchInput
                value={searchTerm}
                onChange={setSearchTerm}
                placeholder="Search by Name, ID, or Ref..."
                language="en-US"
              />
            </div>
          </div>

          <span className={`text-xs ${isDark ? 'text-[#b0b3b8]' : 'text-gray-400'}`}>
            Showing {filteredRequests.length} records
          </span>
        </div>

        <div className={`flex-1 flex flex-col justify-start divide-y overflow-y-auto ${isDark ? 'divide-[#3e4042]' : 'divide-gray-100'}`}>
            {loading ? (
              <div className={`p-10 text-center ${isDark ? 'text-[#b0b3b8]' : 'text-gray-400'}`}>Loading...</div>
            ) : currentItems.length === 0 ? (
                <div className="flex-1 flex flex-col items-center justify-center p-12 min-h-75">                <div className={`w-20 h-20 mb-4 flex items-center justify-center rounded-full transition-colors ${isDark ? 'bg-[#3a3b3c]/40' : 'bg-gray-100'}`}>
                  <DocumentMagnifyingGlassIcon className={`w-10 h-10 ${isDark ? 'text-[#b0b3b8]' : 'text-gray-400'}`} />
                </div>
                <h3 className={`text-base font-bold mb-1 tracking-tight ${isDark ? 'text-[#e4e6eb]' : 'text-gray-800'}`}>
                  No Records Found
                </h3>
                <p className={`text-xs text-center max-w-xs leading-relaxed ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>
                  You don't have any document requests in this category at the moment.
                </p>
              </div>
            ) : (
              currentItems.map((req) => (
                <div
                  key={req.request_id}
                  onClick={() => setSelectedRequest(req)}
                  className={`p-5 transition flex flex-row justify-between items-center gap-4 ${isDark ? 'hover:bg-[#3a3b3c]' : 'hover:bg-gray-50'}`}
                >
                  {/* Item Content */}
                  <div className="flex-1 min-w-0">
                    <div className="flex flex-wrap items-center gap-2 mb-1">
                      <span className={`inline-block px-2 py-0.5 rounded-full text-[10px] font-black uppercase border shrink-0 whitespace-nowrap ${req.config.classes}`}>
                        {req.config.label}
                      </span>
                      <span className={`text-xs truncate ${isDark ? 'text-[#b0b3b8]' : 'text-gray-400'}`}>
                        {req.uuid ?? `#${req.request_id}`} • {new Date(req.requested_at).toLocaleDateString()}
                      </span>
                    </div>
                    <h4 className={`font-bold text-base md:text-lg uppercase flex items-center gap-2 flex-wrap ${isDark ? 'text-[#e4e6eb]' : 'text-gray-800'}`}>
                      {req.doc_names?.[0] || (req.certificates?.length ? 'CERTIFICATION' : 'N/A')}
                      {(() => {
                        const totalDocs = req.doc_names?.length || 0;
                        const totalCerts = req.certificates?.length || 0;
                        const total = totalDocs + totalCerts;
                        const shownFirst = req.doc_names?.[0] ? 1 : (totalCerts > 0 ? 1 : 0);
                        const remaining = total - shownFirst;
                        return remaining > 0 ? (
                          <span className={`text-[10px] font-black px-2 py-0.5 rounded-full normal-case border ${isDark ? 'bg-[#3a3b3c] text-[#e4e6eb] border-[#4e4f50]' : 'bg-gray-100 text-gray-500 border-gray-200'}`}>
                            +{remaining} more
                          </span>
                        ) : null;
                      })()}
                    </h4>
                    <p className={`text-xs mt-0.5 ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>
                      Purpose: {req.purpose_label}
                    </p>
                  </div>

                  {/* Action Icon */}
                  <div className="shrink-0">
                    <button onClick={() => setSelectedRequest(req)} className={`p-2 transition ${isDark ? 'text-[#b0b3b8] hover:text-[#e4e6eb]' : 'text-gray-400 hover:text-gray-600'}`}>
                      <EyeIcon className="w-5 h-5" />
                    </button>
                  </div>
                </div>
              ))
            )}
          </div>

      {/* Paginated Footer */}
      {!loading && filteredRequests.length > 0 && (
        <div className={`px-4 sm:px-8 py-4 text-[11px] sm:text-sm flex flex-col sm:flex-row justify-between items-center gap-4 print:hidden border-t ${isDark ? 'bg-[#18191a] text-[#b0b3b8] border-[#3e4042]' : 'bg-gray-50 text-gray-500 border-gray-200'}`}>
          <span className="text-center sm:text-left">
            Showing {filteredRequests.length > 0 ? indexOfFirstItem + 1 : 0} to {Math.min(indexOfLastItem, filteredRequests.length)} of {filteredRequests.length} results
          </span>

          <div className="flex gap-4 items-center">
            <button
              onClick={handlePrevPage}
              disabled={currentPage === 1}
              className={`p-1 rounded transition-colors ${currentPage === 1 ? (isDark ? 'text-[#4e4f50] cursor-not-allowed' : 'text-gray-300 cursor-not-allowed') : (isDark ? 'text-[#b0b3b8] hover:bg-[#3a3b3c]' : 'text-gray-600 hover:bg-gray-200')}`}
            >
              <ChevronLeftIcon className="w-5 h-5" />
            </button>

            <span className={`text-xs font-semibold whitespace-nowrap ${isDark ? 'text-[#e4e6eb]' : 'text-gray-700'}`}>
              Page {currentPage} of {totalPages}
            </span>

            <button
              onClick={handleNextPage}
              disabled={currentPage === totalPages || totalPages === 0}
              className={`p-1 rounded transition-colors ${currentPage === totalPages || totalPages === 0 ? (isDark ? 'text-[#4e4f50] cursor-not-allowed' : 'text-gray-300 cursor-not-allowed') : (isDark ? 'text-[#b0b3b8] hover:bg-[#3a3b3c]' : 'text-gray-600 hover:bg-gray-200')}`}
            >
              <ChevronRightIcon className="w-5 h-5" />
            </button>
          </div>
        </div>
      )}
      </div>
      <RequestDetailsModal
        request={selectedRequest}
        onClose={() => setSelectedRequest(null)}
        user={user}
        onRequestUpdated={() => refetch()}
      />
    </main>
  );
};

export default StudentDashboard;