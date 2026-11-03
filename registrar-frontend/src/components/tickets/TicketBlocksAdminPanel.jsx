import React, { useState, useEffect, useCallback } from "react";
import {
  ShieldExclamationIcon,
  PlusIcon,
  TrashIcon,
  ArrowPathIcon,
  XMarkIcon,
  EnvelopeIcon,
  GlobeAltIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
} from "@heroicons/react/24/outline";
import { useTheme } from "../../context/ThemeContext";
import {
  getTicketBlocks,
  createTicketBlock,
  deleteTicketBlock,
} from "../../services/api";
import {
  formatTicketDateTime,
  parseTicketApiError,
} from "../../utils/ticketHelpers";
import { useRetryAfterCooldown } from "../../hooks/useTicketLiveUpdates";
import InputGroup from "../InputGroup.jsx";
import DropdownGroup from "../DropDown.jsx";
import ConfirmationModal from "../ConfirmationModal.jsx";
import SuccessToast from "../SuccessToast.jsx";
import ErrorToast from "../ErrorToast.jsx";

const STATE_FILTER_OPTIONS = [
  { value: "active", label: "Active Blocks" },
  { value: "expired", label: "Expired Blocks" },
  { value: "all", label: "All Recorded Blocks" },
];

const TYPE_FILTER_OPTIONS = [
  { value: "", label: "All Types (Email & IP)" },
  { value: "email", label: "Email Addresses Only" },
  { value: "ip", label: "IP Addresses Only" },
];

const BLOCK_TYPE_FORM_OPTIONS = [
  { value: "email", label: "Email Address" },
  { value: "ip", label: "IP Address (IPv4 / IPv6)" },
];

const TicketBlocksAdminPanel = () => {
  const { isDark } = useTheme();
  const cooldown = useRetryAfterCooldown();

  const [typeFilter, setTypeFilter] = useState("");
  const [stateFilter, setStateFilter] = useState("active");

  const [blocks, setBlocks] = useState([]);
  const [nextCursor, setNextCursor] = useState(null);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [currentPage, setCurrentPage] = useState(1);
  const [deletingId, setDeletingId] = useState(null);
  const [blockToDelete, setBlockToDelete] = useState(null);

  const [isAddOpen, setIsAddOpen] = useState(false);
  const [form, setForm] = useState({
    type: "email",
    value: "",
    reason: "",
    expires_at: "",
  });
  const [fieldErrors, setFieldErrors] = useState({});
  const [saving, setSaving] = useState(false);

  const [successMsg, setSuccessMsg] = useState("");
  const [errorMsg, setErrorMsg] = useState("");
  const visibleBlocks = blocks.slice((currentPage - 1) * 25, currentPage * 25);

  useEffect(() => {
    const totalPages = Math.max(1, Math.ceil(blocks.length / 25));
    if (currentPage > totalPages) setCurrentPage(totalPages);
  }, [blocks.length, currentPage]);

  const fetchBlocks = useCallback(
    async ({ cursor = null, append = false } = {}) => {
      if (append) {
        setLoadingMore(true);
      } else {
        setLoading(true);
      }
      try {
        const params = { state: stateFilter, per_page: 25 };
        if (typeFilter) params.type = typeFilter;
        if (cursor) params.cursor = cursor;

        const res = await getTicketBlocks(params);
        const rows = Array.isArray(res.data?.data) ? res.data.data : [];
        const next = res.data?.meta?.next_cursor ?? null;

        setBlocks((prev) => {
          if (!append) return rows;
          const seen = new Set(prev.map((b) => b.id));
          return [...prev, ...rows.filter((b) => !seen.has(b.id))];
        });
          if (!append) setCurrentPage(1);
          setNextCursor(next);
      } catch (err) {
        const parsed = await parseTicketApiError(
          err,
          "Unable to load ticket blocklist."
        );
        if (parsed.status === 429) cooldown.startCooldown(parsed.retryAfterSeconds);
        setErrorMsg(parsed.message);
      } finally {
        setLoading(false);
        setLoadingMore(false);
      }
    },
    [typeFilter, stateFilter, cooldown.startCooldown]
  );

  useEffect(() => {
    fetchBlocks({ cursor: null, append: false });
  }, [fetchBlocks]);

  const handleAddSubmit = async (e) => {
    e.preventDefault();
    if (saving || cooldown.isCoolingDown) return;

    if (!form.value.trim()) {
      setFieldErrors({
        value: [
          form.type === "ip"
            ? "Enter a valid IPv4 or IPv6 address."
            : "Enter an email address to block.",
        ],
      });
      return;
    }

    setFieldErrors({});
    setErrorMsg("");
    setSaving(true);

    const payload = {
      type: form.type,
      value: form.value.trim(),
    };
    if (form.reason.trim()) payload.reason = form.reason.trim();
    if (form.expires_at) {
      const dt = new Date(form.expires_at);
      if (!Number.isNaN(dt.getTime())) {
        payload.expires_at = dt.toISOString();
      }
    }

    try {
      const res = await createTicketBlock(payload);
      const wasRenewed = res.status === 200;
      setIsAddOpen(false);
      setForm({ type: "email", value: "", reason: "", expires_at: "" });
      setSuccessMsg(
        wasRenewed
          ? `Existing block for ${payload.value} renewed.`
          : `Added ${payload.value} to the public ticket blocklist.`
      );
      fetchBlocks({ cursor: null, append: false });
    } catch (err) {
      const parsed = await parseTicketApiError(err, "Unable to save block.");
      if (parsed.status === 429) cooldown.startCooldown(parsed.retryAfterSeconds);
      if (Object.keys(parsed.fieldErrors).length > 0) {
        setFieldErrors(parsed.fieldErrors);
      }
      setErrorMsg(parsed.message);
    } finally {
      setSaving(false);
    }
  };

  const handleRemoveBlock = async (block) => {
    if (!block?.id || deletingId || cooldown.isCoolingDown) return;
    setDeletingId(block.id);
    setErrorMsg("");
    try {
      await deleteTicketBlock(block.id);
      setBlocks((prev) => prev.filter((item) => item.id !== block.id));
      setSuccessMsg(`Removed block on ${block.value}.`);
    } catch (err) {
      const parsed = await parseTicketApiError(err, "Unable to remove block.");
      if (parsed.status === 429) cooldown.startCooldown(parsed.retryAfterSeconds);
      setErrorMsg(parsed.message);
    } finally {
      setDeletingId(null);
    }
  };

  return (
    <div className="space-y-4">
      {/* Header & Filter Controls */}
      <div
        className={`rounded-2xl p-4 sm:p-5 border space-y-4 ${
          isDark
            ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]"
            : "bg-white border-gray-200/80 shadow-sm text-gray-900"
        }`}
      >
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div>
            <h2 className="text-base font-extrabold flex items-center gap-2">
              <ShieldExclamationIcon className="w-5 h-5 text-rose-500" />
              Public Submission Blocklist
            </h2>
            <p className={`text-xs mt-0.5 ${isDark ? "text-gray-400" : "text-gray-500"}`}>
              Manage blocked email addresses and IP addresses prevented from submitting
              public support tickets. Adding an already-listed entry renews its block.
            </p>
          </div>

          <div className="flex items-center gap-2 shrink-0">
            <button
              type="button"
              onClick={() => fetchBlocks({ cursor: null, append: false })}
              disabled={loading || cooldown.isCoolingDown}
              className={`inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold border cursor-pointer disabled:opacity-50 ${
                isDark
                  ? "bg-[#18191a] border-[#3e4042] text-gray-300 hover:bg-[#303134]"
                  : "bg-white border-gray-200 text-gray-700 hover:bg-gray-50"
              }`}
            >
              <ArrowPathIcon className={`w-4 h-4 ${loading ? "animate-spin" : ""}`} />
              Refresh
            </button>

            <button
              type="button"
              onClick={() => {
                setFieldErrors({});
                setIsAddOpen(true);
              }}
              className={`inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold shadow cursor-pointer ${
                isDark
                  ? "bg-yellow-400 text-black hover:bg-yellow-300"
                  : "bg-pup-dark-maroon text-white hover:bg-[#5c0000]"
              }`}
            >
              <PlusIcon className="w-4 h-4" />
              Add / Renew Block
            </button>
          </div>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 lg:max-w-xl gap-2.5">
          <DropdownGroup
            name="block_state_filter"
            value={
              STATE_FILTER_OPTIONS.find((o) => o.value === stateFilter)?.label ||
              STATE_FILTER_OPTIONS[0].label
            }
            options={STATE_FILTER_OPTIONS.map((o) => o.label)}
            onChange={(e) => {
              const found = STATE_FILTER_OPTIONS.find((o) => o.label === e.target.value);
              if (found) setStateFilter(found.value);
            }}
          />

          <DropdownGroup
            name="block_type_filter"
            value={
              TYPE_FILTER_OPTIONS.find((o) => o.value === typeFilter)?.label ||
              TYPE_FILTER_OPTIONS[0].label
            }
            options={TYPE_FILTER_OPTIONS.map((o) => o.label)}
            onChange={(e) => {
              const found = TYPE_FILTER_OPTIONS.find((o) => o.label === e.target.value);
              setTypeFilter(found ? found.value : "");
            }}
          />
        </div>
      </div>

      {/* Blocklist Table */}
      <div
        className={`rounded-2xl border overflow-hidden ${
          isDark
            ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]"
            : "bg-white border-gray-200/80 shadow-sm text-gray-900"
        }`}
      >
        {loading ? (
          <div className="p-10 text-center">
            <ArrowPathIcon
              className={`w-7 h-7 mx-auto mb-2 animate-spin ${
                isDark ? "text-yellow-400" : "text-pup-dark-maroon"
              }`}
            />
            <p className="text-xs opacity-70">Loading blocklist…</p>
          </div>
        ) : blocks.length === 0 ? (
          <div className="p-12 text-center">
            <ShieldExclamationIcon className="w-8 h-8 mx-auto mb-2 opacity-40" />
            <h3 className="text-sm font-bold mb-1">No blocklist entries found</h3>
            <p className={`text-xs ${isDark ? "text-gray-400" : "text-gray-500"}`}>
              No {stateFilter !== "all" ? stateFilter : ""} email or IP blocks match your
              selected filters.
            </p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead
                className={`uppercase text-[11px] font-bold border-b ${
                  isDark
                    ? "bg-[#18191a] border-[#3e4042] text-gray-400"
                    : "bg-gray-50 border-gray-200 text-gray-600"
                }`}
              >
                <tr>
                  <th className="px-4 py-3">Type</th>
                  <th className="px-4 py-3">Blocked Value</th>
                  <th className="px-4 py-3">Reason</th>
                  <th className="px-4 py-3">Added</th>
                  <th className="px-4 py-3">Expires</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3 text-right">Action</th>
                </tr>
              </thead>
              <tbody
                className={`divide-y ${
                  isDark ? "divide-[#3e4042]" : "divide-gray-100"
                }`}
              >
                {visibleBlocks.map((block) => (
                  <tr key={block.id}>
                    <td className="px-4 py-3.5 whitespace-nowrap">
                      <span
                        className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-md font-bold uppercase text-[10px] border ${
                          block.type === "ip"
                            ? isDark
                              ? "bg-purple-950/50 text-purple-300 border-purple-800/60"
                              : "bg-purple-50 text-purple-700 border-purple-200"
                            : isDark
                              ? "bg-sky-950/50 text-sky-300 border-sky-800/60"
                              : "bg-sky-50 text-sky-700 border-sky-200"
                        }`}
                      >
                        {block.type === "ip" ? (
                          <GlobeAltIcon className="w-3 h-3" />
                        ) : (
                          <EnvelopeIcon className="w-3 h-3" />
                        )}
                        {block.type}
                      </span>
                    </td>
                    <td className="px-4 py-3.5 font-mono font-bold break-all">
                      {block.value}
                    </td>
                    <td className="px-4 py-3.5 max-w-xs">
                      {block.reason || <span className="opacity-50">—</span>}
                    </td>
                    <td className="px-4 py-3.5 whitespace-nowrap">
                      {formatTicketDateTime(block.created_at)}
                    </td>
                    <td className="px-4 py-3.5 whitespace-nowrap">
                      {block.expires_at
                        ? formatTicketDateTime(block.expires_at)
                        : "Until lifted"}
                    </td>
                    <td className="px-4 py-3.5 whitespace-nowrap">
                      {block.in_force ? (
                        <span
                          className={`px-2 py-0.5 rounded-full text-[11px] font-bold border ${
                            isDark
                              ? "bg-rose-950/50 text-rose-300 border-rose-800/60"
                              : "bg-rose-50 text-rose-700 border-rose-200"
                          }`}
                        >
                          In Force
                        </span>
                      ) : (
                        <span
                          className={`px-2 py-0.5 rounded-full text-[11px] font-semibold border ${
                            isDark
                              ? "bg-zinc-800 text-gray-400 border-zinc-700"
                              : "bg-gray-100 text-gray-500 border-gray-200"
                          }`}
                        >
                          Expired
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3.5 text-right whitespace-nowrap">
                      <button
                        type="button"
                        onClick={() => setBlockToDelete(block)}
                        disabled={deletingId === block.id || cooldown.isCoolingDown}
                        className={`inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg font-semibold border cursor-pointer disabled:opacity-50 ${
                          isDark
                            ? "border-rose-800/60 text-rose-300 hover:bg-rose-950/40"
                            : "border-rose-200 text-rose-700 hover:bg-rose-50"
                        }`}
                      >
                        <TrashIcon className="w-3.5 h-3.5" />
                        {deletingId === block.id ? "Removing…" : "Lift Block"}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {!loading && (
          <div
            className={`px-3 py-2 border-t flex items-center justify-center gap-3 ${
              isDark ? "border-[#3e4042] bg-[#1f2022]" : "border-gray-100 bg-gray-50/60"
            }`}
          >
            <button
              type="button"
              onClick={() => setCurrentPage((page) => Math.max(1, page - 1))}
              disabled={currentPage === 1 || loadingMore}
              className={`inline-flex items-center gap-1 text-xs font-medium cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed ${
                isDark
                  ? "text-gray-300 hover:text-white"
                  : "text-gray-400 hover:text-gray-600"
              }`}
            >
              <ChevronLeftIcon className="w-4 h-4" />
              Previous
            </button>

            <span
              className={`inline-flex items-center justify-center w-8 h-8 rounded-lg text-xs font-bold ${
                isDark ? "bg-[#6b0f0f] text-white" : "bg-pup-dark-maroon text-white"
              }`}
            >
              {currentPage}
            </span>

            <button
              type="button"
              onClick={async () => {
                if (currentPage * 25 < blocks.length) {
                  setCurrentPage((page) => page + 1);
                  return;
                }
                if (!nextCursor) return;
                await fetchBlocks({ cursor: nextCursor, append: true });
                setCurrentPage((page) => page + 1);
              }}
              disabled={loadingMore || cooldown.isCoolingDown || (!nextCursor && currentPage * 25 >= blocks.length)}
              className={`inline-flex items-center gap-1 text-xs font-medium cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed ${
                isDark
                  ? "text-gray-300 hover:text-white"
                  : "text-gray-400 hover:text-gray-600"
              }`}
            >
              Next
              <ChevronRightIcon className="w-4 h-4" />
            </button>
          </div>
        )}
      </div>

      {/* Add / Renew Block Modal */}
      {isAddOpen && (
        <div className="fixed inset-0 z-9999 flex items-center justify-center pt-16 sm:pt-4 pb-4 px-3 sm:px-4 backdrop-blur-sm bg-black/60 overflow-y-auto">
          <div
            className={`relative z-9999 w-full max-w-lg my-auto rounded-xl shadow-[0_20px_50px_rgba(0,0,0,0.4)] overflow-hidden border flex flex-col max-h-[calc(100vh-5rem)] sm:max-h-[85vh] animate-in fade-in zoom-in duration-200 ${
              isDark
                ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]"
                : "bg-white border-[#800000]/20 text-gray-900"
            }`}
          >
            <div
              className={`px-4 py-4 sm:px-6 sm:py-5 border-b-4 shrink-0 ${
                isDark ? "bg-[#1f1f1f] border-[#b98b00]" : "bg-[#800000] border-[#FFD700]"
              }`}
            >
              <div className="flex items-center justify-between gap-3">
                <div className="min-w-0 flex-1">
                  <span className="text-[10px] sm:text-xs text-amber-200 font-bold uppercase tracking-wider block">
                    Public Submission Protection
                  </span>
                  <h3 className="text-lg sm:text-xl text-white font-black uppercase tracking-tighter mt-0.5 truncate">
                    Add or Renew Block
                  </h3>
                </div>
                <button
                  type="button"
                  onClick={() => setIsAddOpen(false)}
                  className="p-1 rounded hover:opacity-90 shrink-0 text-white cursor-pointer"
                  title="Close"
                >
                  <XMarkIcon className="w-5 h-5 sm:w-6 sm:h-6" />
                </button>
              </div>
            </div>

            <form onSubmit={handleAddSubmit} noValidate className="flex flex-col flex-1 overflow-hidden">
              <div className="flex-1 overflow-y-auto p-4 sm:p-6 space-y-4">
                <DropdownGroup
                  label="Block Type"
                  name="type"
                  value={
                    BLOCK_TYPE_FORM_OPTIONS.find((o) => o.value === form.type)?.label ||
                    BLOCK_TYPE_FORM_OPTIONS[0].label
                  }
                  options={BLOCK_TYPE_FORM_OPTIONS.map((o) => o.label)}
                  required
                  labelColor="text-gray-700"
                  onChange={(e) => {
                    const found = BLOCK_TYPE_FORM_OPTIONS.find(
                      (o) => o.label === e.target.value
                    );
                    if (found) {
                      setForm((prev) => ({ ...prev, type: found.value, value: "" }));
                      setFieldErrors({});
                    }
                  }}
                />

                <InputGroup
                  type={form.type === "email" ? "email" : "text"}
                  label={form.type === "ip" ? "IP Address" : "Email Address"}
                  name="value"
                  value={form.value}
                  onChange={(e) => {
                    setForm((prev) => ({ ...prev, value: e.target.value }));
                    if (fieldErrors.value) {
                      setFieldErrors((prev) => ({ ...prev, value: undefined }));
                    }
                  }}
                  placeholder={
                    form.type === "ip" ? "203.0.113.42" : "spammer@example.com"
                  }
                  required
                  voiceEnabled={false}
                  labelColor="text-gray-700"
                  error={fieldErrors.value?.[0]}
                />

                <InputGroup
                  label="Reason (Optional)"
                  name="reason"
                  value={form.reason}
                  onChange={(e) => {
                    setForm((prev) => ({ ...prev, reason: e.target.value }));
                    if (fieldErrors.reason) {
                      setFieldErrors((prev) => ({ ...prev, reason: undefined }));
                    }
                  }}
                  placeholder="Repeated automated spam submissions"
                  labelColor="text-gray-700"
                  error={fieldErrors.reason?.[0]}
                />

                <InputGroup
                  type="datetime-local"
                  label="Expiration (Optional — omit to block until manually lifted)"
                  name="expires_at"
                  value={form.expires_at}
                  onChange={(e) => {
                    setForm((prev) => ({ ...prev, expires_at: e.target.value }));
                    if (fieldErrors.expires_at) {
                      setFieldErrors((prev) => ({ ...prev, expires_at: undefined }));
                    }
                  }}
                  voiceEnabled={false}
                  labelColor="text-gray-700"
                  error={fieldErrors.expires_at?.[0]}
                />
              </div>

              <div
                className={`px-4 py-3 sm:px-6 sm:py-4 border-t-2 shrink-0 flex items-center justify-end gap-2.5 ${
                  isDark ? "bg-[#1f1f1f] border-[#3e4042]" : "bg-gray-50 border-gray-200"
                }`}
              >
                <button
                  type="button"
                  onClick={() => setIsAddOpen(false)}
                  className={`px-4 py-2 rounded-lg text-xs font-semibold cursor-pointer ${
                    isDark
                      ? "bg-[#3a3b3c] hover:bg-[#4e4f50] text-[#e4e6eb] border border-[#4e4f50]"
                      : "bg-gray-200/80 hover:bg-gray-300/70 text-gray-700"
                  }`}
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={saving || cooldown.isCoolingDown}
                  className={`px-5 py-2 rounded-lg text-xs font-bold uppercase tracking-wider shadow cursor-pointer disabled:opacity-50 ${
                    isDark
                      ? "bg-yellow-400 text-black hover:bg-yellow-300"
                      : "bg-[#800000] hover:bg-[#4a0000] text-[#FFD700]"
                  }`}
                >
                  {saving ? "Saving…" : "Save Block"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Lift Block Confirmation Modal */}
      <ConfirmationModal
        isOpen={Boolean(blockToDelete)}
        onClose={() => setBlockToDelete(null)}
        onConfirm={() => {
          if (blockToDelete) handleRemoveBlock(blockToDelete);
        }}
        type="danger"
        title="Lift Blocklist Entry?"
        message={
          blockToDelete
            ? `Remove the block on ${blockToDelete.value}? Public ticket submissions from this ${blockToDelete.type === "ip" ? "IP address" : "email"} will be allowed again.`
            : ""
        }
        confirmText="Lift Block"
      />

      <SuccessToast message={successMsg} onClose={() => setSuccessMsg("")} />
      <ErrorToast message={errorMsg} onClose={() => setErrorMsg("")} />
    </div>
  );
};

export default TicketBlocksAdminPanel;
