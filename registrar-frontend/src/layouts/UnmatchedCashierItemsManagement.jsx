import React, { useState, useEffect, useCallback, useMemo, useRef } from "react";
import { XMarkIcon, FunnelIcon, ChevronDownIcon, ChevronUpIcon } from "@heroicons/react/24/outline";
import { useTheme } from "../context/ThemeContext";
import { useReferenceData } from "../context/ReferenceDataContext";
import SuccessToast from "../components/SuccessToast.jsx";
import ErrorToast from "../components/ErrorToast.jsx";
import DropdownGroup from "../components/DropDown.jsx";
import ConfirmationModal from "../components/ConfirmationModal.jsx";
import VoiceSearchInput from "../components/VoiceSearchInput.jsx";
import DashboardDropdown from "../components/DashboardDropdown.jsx";
import {
  getUnmatchedCashierItems,
  resolveUnmatchedCashierItem,
  dismissUnmatchedCashierItem,
} from "../services/api";

/**
 * Admin review screen for CashierDocumentSuggester's naming-drift fix
 * (Phase 2/5 of the OR-first reorder). Every cashier receipt label that
 * couldn't be matched to a document/certificate type lands here, ordered
 * by how often it's been seen. Attaching one to the right type in
 * "Resolve" appends it to that type's cashier_document_patterns — every
 * future receipt using that exact label auto-matches from then on, no
 * code deploy required. "Dismiss" is for labels that aren't a real
 * document at all (a one-off misc fee) and just need to stop cluttering
 * the queue.
 */
const UnmatchedCashierItemsManagement = () => {
  const { isDark } = useTheme();
  const { documentTypes, certifications } = useReferenceData();

  const [showResolved, setShowResolved] = useState(false);
  const [items, setItems] = useState([]);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1 });
  const [loading, setLoading] = useState(true);
  const [successMsg, setSuccessMsg] = useState("");
  const [errorMsg, setErrorMsg] = useState("");
  const [search, setSearch] = useState("");
  const [sortField, setSortField] = useState("last_seen_at");
  const [sortOrder, setSortOrder] = useState("desc");

  // { open, item, targetKind: 'document' | 'certificate', targetId }
  const [resolveModal, setResolveModal] = useState({ open: false, item: null, targetKind: "document", targetId: "" });
  // { open, item }
  const [dismissConfirm, setDismissConfirm] = useState({ open: false, item: null });
  const [actionLoading, setActionLoading] = useState(false);

  const activeDocumentTypes = useMemo(
    () => (documentTypes || []).filter((d) => !d.is_archived),
    [documentTypes]
  );
  const activeCertifications = useMemo(
    () => (certifications || []).filter((c) => !c.is_archived),
    [certifications]
  );

  const combinedTargetOptions = useMemo(() => {
    const docs = activeDocumentTypes.map((d) => ({
      label: d.document_name,
      kind: "document",
      id: String(d.document_type_id),
    }));
    const certs = activeCertifications.map((c) => ({
      label: `${c.certificate_name} (Certificate)`,
      kind: "certificate",
      id: String(c.certificate_type_id),
    }));
    return [...docs, ...certs];
  }, [activeDocumentTypes, activeCertifications]);

  const selectedCombinedItem = useMemo(() => {
    if (!resolveModal.targetId) return null;
    return combinedTargetOptions.find(
      (opt) => opt.kind === resolveModal.targetKind && String(opt.id) === String(resolveModal.targetId)
    );
  }, [combinedTargetOptions, resolveModal.targetKind, resolveModal.targetId]);

  const selectedCombinedLabel = selectedCombinedItem ? selectedCombinedItem.label : "";

  const handleDropdownSelect = (e) => {
    const chosenLabel = e?.target?.value;
    const found = combinedTargetOptions.find((opt) => opt.label === chosenLabel);
    if (found) {
      setResolveModal((s) => ({
        ...s,
        targetKind: found.kind,
        targetId: found.id,
      }));
    } else {
      setResolveModal((s) => ({ ...s, targetId: "" }));
    }
  };

  const loadItems = useCallback(async (page = 1) => {
    try {
      setLoading(true);
      const res = await getUnmatchedCashierItems({
        resolved: showResolved ? 1 : 0,
        page,
      });
      setItems(res.data?.data ?? []);
      setMeta({
        current_page: res.data?.current_page ?? 1,
        last_page: res.data?.last_page ?? 1,
      });
    } catch (err) {
      console.error("Failed to load unmatched cashier items:", err);
      setErrorMsg("Couldn't load unmatched cashier items. Please try again.");
      setItems([]);
    } finally {
      setLoading(false);
    }
  }, [showResolved]);

  useEffect(() => {
    loadItems(1);
  }, [loadItems]);

  const openResolveModal = (item) => {
    setResolveModal({ open: true, item, targetKind: "document", targetId: "" });
  };

  const closeResolveModal = () => {
    setResolveModal({ open: false, item: null, targetKind: "document", targetId: "" });
  };

  const handleResolveSubmit = async () => {
    const { item, targetKind, targetId } = resolveModal;
    if (!targetId) {
      setErrorMsg("Please select a document or certificate type first.");
      return;
    }

    try {
      setActionLoading(true);
      const payload = targetKind === "document"
        ? { document_type_id: Number(targetId) }
        : { certificate_type_id: Number(targetId) };

      await resolveUnmatchedCashierItem(item.unmatched_cashier_item_id, payload);

      setItems((prev) => prev.filter((i) => i.unmatched_cashier_item_id !== item.unmatched_cashier_item_id));
      setSuccessMsg(`"${item.raw_label}" resolved — future receipts with this label will auto-match.`);
      closeResolveModal();
    } catch (err) {
      console.error("Failed to resolve unmatched cashier item:", err);
      setErrorMsg(err?.response?.data?.message || "Couldn't resolve this item. Please try again.");
    } finally {
      setActionLoading(false);
    }
  };

  const handleDismissConfirm = async () => {
    const { item } = dismissConfirm;
    if (!item) return;
    try {
      setActionLoading(true);
      await dismissUnmatchedCashierItem(item.unmatched_cashier_item_id);
      setItems((prev) => prev.filter((i) => i.unmatched_cashier_item_id !== item.unmatched_cashier_item_id));
      setSuccessMsg(`"${item.raw_label}" dismissed.`);
      setDismissConfirm({ open: false, item: null });
    } catch (err) {
      console.error("Failed to dismiss unmatched cashier item:", err);
      setErrorMsg(err?.response?.data?.message || "Couldn't dismiss this item. Please try again.");
    } finally {
      setActionLoading(false);
    }
  };

  const filteredItems = useMemo(() => {
    let result = [...items];

    const term = search.trim().toLowerCase();
    if (term) {
      result = result.filter((item) => {
        const label = String(item.raw_label ?? "").toLowerCase();
        const resolver = item.resolved_by_user
          ? String(
              item.resolved_by_user.email ??
                `${item.resolved_by_user.admin_profile?.first_name ?? ""} ${item.resolved_by_user.admin_profile?.last_name ?? ""}`
            ).toLowerCase()
          : "";
        return label.includes(term) || resolver.includes(term);
      });
    }

    result.sort((a, b) => {
      let valA = a[sortField];
      let valB = b[sortField];

      if (sortField === "last_seen_at") {
        valA = new Date(a.last_seen_at || 0).getTime();
        valB = new Date(b.last_seen_at || 0).getTime();
      } else if (sortField === "raw_label") {
        valA = String(a.raw_label || "").toLowerCase();
        valB = String(b.raw_label || "").toLowerCase();
      } else if (sortField === "occurrence_count") {
        valA = Number(a.occurrence_count || 0);
        valB = Number(b.occurrence_count || 0);
      }

      if (valA < valB) return sortOrder === "asc" ? -1 : 1;
      if (valA > valB) return sortOrder === "asc" ? 1 : -1;
      return 0;
    });

    return result;
  }, [items, search, sortField, sortOrder]);

  const cardClasses = isDark
    ? "bg-[#18191a] text-[#e4e6eb]"
    : "bg-white text-gray-900";
  const rowBorder = isDark ? "border-[#3e4042]" : "border-gray-200";
  const subtleText = isDark ? "text-[#b0b3b8]" : "text-gray-500";

  return (
    <div className={`font-sans rounded-2xl p-4 sm:px-6 ${cardClasses}`}>
      {/* Top bar: tab toggle */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 py-5 mb-2">
        <div>
          <h2 className="text-lg font-bold">Unmatched Cashier Items</h2>
          <p className={`text-xs mt-1 ${subtleText}`}>
            Receipt labels the system couldn't automatically match to a document or certificate type.
            Resolving one teaches the system to recognize it going forward.
          </p>
        </div>

        <div className={`inline-flex rounded-full p-1 shrink-0 ${isDark ? "bg-[#242526] border border-[#3e4042]" : "bg-gray-100 border border-gray-200"}`}>
          <button
            onClick={() => setShowResolved(false)}
            className={`text-sm font-semibold px-4 py-2 rounded-full transition-all cursor-pointer ${
              !showResolved
                ? isDark ? "bg-yellow-400 text-gray-900" : "bg-pup-dark-maroon text-white"
                : isDark ? "text-[#b0b3b8]" : "text-gray-500"
            }`}
          >
            Unresolved
          </button>
          <button
            onClick={() => setShowResolved(true)}
            className={`text-sm font-semibold px-4 py-2 rounded-full transition-all cursor-pointer ${
              showResolved
                ? isDark ? "bg-yellow-400 text-gray-900" : "bg-pup-dark-maroon text-white"
                : isDark ? "text-[#b0b3b8]" : "text-gray-500"
            }`}
          >
            Resolved
          </button>
        </div>
      </div>

      {/* Table & Cards Container */}
      <div className={`rounded-xl border overflow-hidden ${rowBorder}`}>
        {/* Search & Sort Header Bar */}
        <div className={`p-4 border-b flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 ${isDark ? 'border-[#3e4042] bg-[#1a1a1c]/20' : 'border-gray-200 bg-gray-50/50'}`}>
          <div className="w-full sm:max-w-md">
            <VoiceSearchInput
              value={search}
              onChange={(val) => setSearch(val)}
              placeholder="Search by receipt label or resolver..."
            />
          </div>

          <div className="flex md:hidden items-center justify-between sm:justify-end gap-2 text-xs">
            <span className={subtleText}>Sort by:</span>
            <button
              type="button"
              onClick={() => {
                if (sortField === "last_seen_at") {
                  setSortOrder((prev) => (prev === "asc" ? "desc" : "asc"));
                } else {
                  setSortField("last_seen_at");
                  setSortOrder("desc");
                }
              }}
              className={`flex items-center gap-1 font-bold px-3 py-1.5 rounded-lg border transition-colors cursor-pointer ${
                isDark ? "bg-[#242526] border-[#3e4042] text-gray-200" : "bg-white border-gray-200 text-gray-700"
              }`}
            >
              <span>Last Seen</span>
              {sortField === "last_seen_at" ? (
                sortOrder === "asc" ? (
                  <ChevronUpIcon className="w-3.5 h-3.5 text-blue-500" />
                ) : (
                  <ChevronDownIcon className="w-3.5 h-3.5 text-blue-500" />
                )
              ) : (
                <ChevronDownIcon className="w-3.5 h-3.5 text-gray-400 opacity-50" />
              )}
            </button>
          </div>
        </div>

        {/* Mobile Cards View (< md) */}
        <div className="md:hidden divide-y divide-gray-200 dark:divide-[#3e4042]">
          {loading ? (
            <div className={`p-6 text-center ${subtleText}`}>Loading...</div>
          ) : filteredItems.length === 0 ? (
            <div className={`p-6 text-center ${subtleText}`}>
              {search.trim() ? "No items matching search." : showResolved ? "No resolved items yet." : "Nothing unresolved right now — all clear!"}
            </div>
          ) : (
            filteredItems.map((item) => (
              <div
                key={item.unmatched_cashier_item_id}
                className={`p-4 space-y-3 ${
                  isDark ? 'bg-[#18191a]' : 'bg-white'
                }`}
              >
                <div className="flex items-start justify-between gap-3">
                  <div className="font-bold text-sm text-gray-900 dark:text-white leading-snug wrap-break-word">
                    {item.raw_label}
                  </div>
                  <span className={`shrink-0 px-2.5 py-0.5 rounded-full text-xs font-bold ${
                    isDark ? 'bg-zinc-800 text-amber-300' : 'bg-amber-50 text-amber-800 border border-amber-200'
                  }`}>
                    {item.occurrence_count} {item.occurrence_count === 1 ? 'occurrence' : 'occurrences'}
                  </span>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-2 text-xs pt-1">
                  <span className={subtleText}>
                    Last Seen: <span className="font-semibold text-gray-800 dark:text-gray-200">{item.last_seen_at ? new Date(item.last_seen_at).toLocaleDateString() : '—'}</span>
                  </span>
                  {showResolved && (
                    <span className={subtleText}>
                      Resolved By: <span className="font-semibold text-gray-800 dark:text-gray-200">
                        {item.resolved_by_user
                          ? (item.resolved_by_user.admin_profile
                              ? `${item.resolved_by_user.admin_profile.first_name} ${item.resolved_by_user.admin_profile.last_name}`
                              : item.resolved_by_user.email)
                          : '—'}
                      </span>
                    </span>
                  )}
                </div>

                {!showResolved && (
                  <div className="flex items-center gap-2 pt-2 border-t border-gray-100 dark:border-[#2a2a2d]">
                    <button
                      onClick={() => openResolveModal(item)}
                      className={`flex-1 text-center text-xs font-semibold py-2 rounded-xl transition-all cursor-pointer shadow-xs active:scale-95 ${
                        isDark ? 'bg-yellow-400 text-gray-900 hover:bg-yellow-300' : 'bg-pup-dark-maroon text-white hover:bg-pup-maroon'
                      }`}
                    >
                      Resolve
                    </button>
                    <button
                      onClick={() => setDismissConfirm({ open: true, item })}
                      className={`flex-1 text-center text-xs font-semibold py-2 rounded-xl border transition-all cursor-pointer active:scale-95 ${
                        isDark ? 'border-[#3e4042] text-[#b0b3b8] hover:bg-[#242526]' : 'border-gray-300 text-gray-600 hover:bg-gray-50'
                      }`}
                    >
                      Dismiss
                    </button>
                  </div>
                )}
              </div>
            ))
          )}
        </div>

        {/* Desktop Table View (≥ md) */}
        <div className="hidden md:block overflow-x-auto">
          <table className="w-full text-sm min-w-137.5">
            <thead>
              <tr className={`text-left ${isDark ? "bg-[#242526]" : "bg-gray-50"}`}>
                <th className="px-4 py-3 font-semibold">Receipt Label</th>
                <th className="px-4 py-3 font-semibold">Occurrences</th>

                {/* Last Seen Header (Sortable) */}
                <th className="px-4 py-3 font-semibold">
                  <button
                    type="button"
                    onClick={() => {
                      if (sortField === "last_seen_at") {
                        setSortOrder((prev) => (prev === "asc" ? "desc" : "asc"));
                      } else {
                        setSortField("last_seen_at");
                        setSortOrder("desc");
                      }
                    }}
                    className={`flex items-center gap-1 text-xs font-bold hover:text-[#800000] dark:hover:text-[#FFC72C] transition-colors focus:outline-none cursor-pointer ${
                      isDark ? "text-[#b0b3b8]" : "text-gray-500"
                    }`}
                  >
                    <span>Last Seen</span>
                    {sortField === "last_seen_at" ? (
                      sortOrder === "asc" ? (
                        <ChevronUpIcon className="w-3.5 h-3.5 text-blue-500" />
                      ) : (
                        <ChevronDownIcon className="w-3.5 h-3.5 text-blue-500" />
                      )
                    ) : (
                      <ChevronDownIcon className="w-3.5 h-3.5 text-gray-400 opacity-50" />
                    )}
                  </button>
                </th>
                {showResolved && <th className="px-4 py-3 font-semibold">Resolved By</th>}
                {!showResolved && <th className="px-4 py-3 font-semibold text-right">Actions</th>}
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr>
                  <td colSpan={showResolved ? 4 : 4} className={`px-4 py-8 text-center ${subtleText}`}>
                    Loading...
                  </td>
                </tr>
              ) : filteredItems.length === 0 ? (
                <tr>
                  <td colSpan={showResolved ? 4 : 4} className={`px-4 py-8 text-center ${subtleText}`}>
                    {search.trim() ? "No items matching search." : showResolved ? "No resolved items yet." : "Nothing unresolved right now — all clear!"}
                  </td>
                </tr>
              ) : (
                filteredItems.map((item) => (
                  <tr key={item.unmatched_cashier_item_id} className={`border-t ${rowBorder}`}>
                    <td className="px-4 py-3 font-medium">{item.raw_label}</td>
                    <td className="px-4 py-3">{item.occurrence_count}</td>
                    <td className={`px-4 py-3 ${subtleText}`}>
                      {item.last_seen_at ? new Date(item.last_seen_at).toLocaleDateString() : "—"}
                    </td>
                    {showResolved ? (
                      <td className={`px-4 py-3 ${subtleText}`}>
                        {item.resolved_by_user
                          ? (item.resolved_by_user.admin_profile
                              ? `${item.resolved_by_user.admin_profile.first_name} ${item.resolved_by_user.admin_profile.last_name}`
                              : item.resolved_by_user.email)
                          : "—"}
                      </td>
                    ) : (
                      <td className="px-4 py-3 text-right space-x-2 whitespace-nowrap">
                        <button
                          onClick={() => openResolveModal(item)}
                          className={`text-xs font-semibold px-3 py-1.5 rounded-full transition-all cursor-pointer ${
                            isDark ? "bg-yellow-400 text-gray-900 hover:bg-yellow-300" : "bg-pup-dark-maroon text-white hover:bg-pup-maroon"
                          }`}
                        >
                          Resolve
                        </button>
                        <button
                          onClick={() => setDismissConfirm({ open: true, item })}
                          className={`text-xs font-semibold px-3 py-1.5 rounded-full border transition-all cursor-pointer ${
                            isDark ? "border-[#3e4042] text-[#b0b3b8] hover:bg-[#242526]" : "border-gray-300 text-gray-600 hover:bg-gray-50"
                          }`}
                        >
                          Dismiss
                        </button>
                      </td>
                    )}
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Pagination */}
      {meta.last_page > 1 && (
        <div className="flex flex-wrap justify-center items-center gap-3 sm:gap-4 mt-4 text-xs sm:text-sm">
          <button
            disabled={meta.current_page <= 1 || loading}
            onClick={() => loadItems(meta.current_page - 1)}
            className={`px-3 py-1.5 rounded-lg border disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer ${rowBorder}`}
          >
            Previous
          </button>
          <span className={subtleText}>Page {meta.current_page} of {meta.last_page}</span>
          <button
            disabled={meta.current_page >= meta.last_page || loading}
            onClick={() => loadItems(meta.current_page + 1)}
            className={`px-3 py-1.5 rounded-lg border disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer ${rowBorder}`}
          >
            Next
          </button>
        </div>
      )}

      {/* Resolve modal */}
      {resolveModal.open && (
        <div className="fixed inset-0 z-9999 flex items-center justify-center pt-16 sm:pt-4 pb-4 px-3 sm:px-4 backdrop-blur-sm bg-black/60 overflow-y-auto">
          <div
            className={`relative z-9999 w-full max-w-md my-auto rounded-xl shadow-[0_20px_50px_rgba(0,0,0,0.4)] border flex flex-col animate-in fade-in zoom-in duration-200 ${
              isDark ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]" : "bg-white border-[#800000]/20 text-gray-900"
            }`}
          >
            {/* Top Header Banner matching CashierOrOverrideModals.jsx */}
            <div
              className={`px-4 py-4 sm:px-6 sm:py-5 border-b-4 shrink-0 rounded-t-xl overflow-hidden ${
                isDark ? "bg-[#1f1f1f] border-[#b98b00]" : "bg-[#800000] border-[#FFD700]"
              }`}
            >
              <div className="flex items-center justify-between gap-2 sm:gap-4">
                <div className="min-w-0 flex-1">
                  <span className="text-[10px] sm:text-xs text-amber-200 font-bold uppercase tracking-wider block">
                    Unmatched Cashier Item
                  </span>
                  <h3 className="text-lg sm:text-xl text-white font-black uppercase tracking-tighter mt-0.5 truncate">
                    Resolve Receipt Label
                  </h3>
                </div>
                <button
                  onClick={closeResolveModal}
                  className="p-1 rounded hover:opacity-90 shrink-0 text-white cursor-pointer"
                  title="Close"
                >
                  <XMarkIcon className="w-5 h-5 sm:w-6 sm:h-6" />
                </button>
              </div>
            </div>

            <div className="p-4 sm:p-6 space-y-4">
              <p className={`text-xs sm:text-sm font-normal leading-relaxed ${isDark ? "text-gray-300" : "text-gray-600"}`}>
                Attach receipt label <strong className={isDark ? "text-white" : "text-gray-900"}>"{resolveModal.item?.raw_label}"</strong> to a document or certificate type:
              </p>

              <div>
                <DropdownGroup
                  label="Document / Certificate Type"
                  name="targetId"
                  value={selectedCombinedLabel}
                  onChange={handleDropdownSelect}
                  options={combinedTargetOptions.map((opt) => opt.label)}
                  labelColor={isDark ? "text-[#e4e6eb]" : "text-gray-700"}
                />
              </div>

              <div className="flex justify-end gap-3 pt-2">
                <button
                  onClick={closeResolveModal}
                  disabled={actionLoading}
                  className={`text-xs sm:text-sm font-semibold px-4 py-2 rounded-lg border cursor-pointer disabled:opacity-50 ${rowBorder}`}
                >
                  Cancel
                </button>
                <button
                  onClick={handleResolveSubmit}
                  disabled={actionLoading}
                  className={`text-xs sm:text-sm font-semibold px-4 py-2 rounded-lg cursor-pointer disabled:opacity-50 shadow-md transition-all active:scale-95 ${
                    isDark ? "bg-yellow-400 text-gray-900 hover:bg-yellow-300" : "bg-pup-dark-maroon text-white hover:bg-pup-maroon"
                  }`}
                >
                  {actionLoading ? "Resolving..." : "Resolve"}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Dismiss confirmation modal using system ConfirmationModal */}
      <ConfirmationModal
        isOpen={dismissConfirm.open}
        onClose={() => setDismissConfirm({ open: false, item: null })}
        onConfirm={handleDismissConfirm}
        title="Dismiss Receipt Label?"
        message={`"${dismissConfirm.item?.raw_label}" will be marked resolved without attaching it to any document or certificate type.`}
        type="danger"
        confirmText="Dismiss"
      />

      <SuccessToast message={successMsg} onClose={() => setSuccessMsg("")} />
      <ErrorToast message={errorMsg} onClose={() => setErrorMsg("")} />
    </div>
  );
};

export default UnmatchedCashierItemsManagement;