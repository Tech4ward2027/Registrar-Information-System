import React, { useState, useEffect, useCallback } from "react";
import {
  PlusIcon,
  PencilSquareIcon,
  ArrowPathIcon,
  XMarkIcon,
  TagIcon,
  CheckCircleIcon,
  NoSymbolIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
} from "@heroicons/react/24/outline";
import { useTheme } from "../../context/ThemeContext";
import {
  getTicketCategoriesAdmin,
  createTicketCategory,
  updateTicketCategory,
} from "../../services/api";
import {
  getPriorityBadgeClass,
  formatPriorityLabel,
  parseTicketApiError,
} from "../../utils/ticketHelpers";
import { useRetryAfterCooldown } from "../../hooks/useTicketLiveUpdates";
import InputGroup from "../InputGroup.jsx";
import DropdownGroup from "../DropDown.jsx";
import VoiceTextareaInput from "../VoiceTextareaInput.jsx";
import CheckboxItem from "../Checkbox.jsx";
import SuccessToast from "../SuccessToast.jsx";
import ErrorToast from "../ErrorToast.jsx";

const PRIORITY_SELECT_OPTIONS = [
  { value: "high", label: "High (4h 1st resp / 1d resolution)" },
  { value: "medium", label: "Medium (8h 1st resp / 2d resolution)" },
  { value: "low", label: "Low (8h 1st resp / 2d resolution)" },
];

const suggestNextCode = (categories) => {
  const nums = categories
    .map((c) => {
      const m = /^CAT-(\d{2,4})$/.exec(c.code || "");
      return m ? Number.parseInt(m[1], 10) : 0;
    })
    .filter((n) => Number.isFinite(n));
  const next = (nums.length > 0 ? Math.max(...nums) : 5) + 1;
  return `CAT-${String(next).padStart(2, "0")}`;
};

const TicketCategoriesAdminPanel = () => {
  const { isDark } = useTheme();
  const cooldown = useRetryAfterCooldown();

  const [categories, setCategories] = useState([]);
  const [currentPage, setCurrentPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [togglingId, setTogglingId] = useState(null);

  const [modalMode, setModalMode] = useState(null); // "create" | "edit" | null
  const [editingCategory, setEditingCategory] = useState(null);
  const [form, setForm] = useState({
    code: "",
    name: "",
    description: "",
    priority: "low",
    sort_order: 10,
    is_active: true,
  });
  const [fieldErrors, setFieldErrors] = useState({});
  const [saving, setSaving] = useState(false);

  const [successMsg, setSuccessMsg] = useState("");
  const [errorMsg, setErrorMsg] = useState("");

  const fetchCategories = useCallback(async () => {
    setLoading(true);
    try {
      const res = await getTicketCategoriesAdmin();
      setCategories(Array.isArray(res.data?.data) ? res.data.data : []);
      setCurrentPage(1);
    } catch (err) {
      const parsed = await parseTicketApiError(err, "Unable to load ticket categories.");
      if (parsed.status === 429) cooldown.startCooldown(parsed.retryAfterSeconds);
      setErrorMsg(parsed.message);
    } finally {
      setLoading(false);
    }
  }, [cooldown.startCooldown]);

  useEffect(() => {
    fetchCategories();
  }, [fetchCategories]);

  const activeCount = categories.filter((c) => c.is_active).length;
  const visibleCategories = categories.slice(
    (currentPage - 1) * 25,
    currentPage * 25
  );
  const hasNextPage = currentPage * 25 < categories.length;

  useEffect(() => {
    const totalPages = Math.max(1, Math.ceil(categories.length / 25));
    if (currentPage > totalPages) setCurrentPage(totalPages);
  }, [categories.length, currentPage]);

  const openCreateModal = () => {
    const maxSort = categories.reduce(
      (acc, c) => Math.max(acc, Number(c.sort_order) || 0),
      0
    );
    setModalMode("create");
    setEditingCategory(null);
    setFieldErrors({});
    setForm({
      code: suggestNextCode(categories),
      name: "",
      description: "",
      priority: "medium",
      sort_order: maxSort + 10,
      is_active: true,
    });
  };

  const openEditModal = (cat) => {
    setModalMode("edit");
    setEditingCategory(cat);
    setFieldErrors({});
    setForm({
      code: cat.code,
      name: cat.name || "",
      description: cat.description || "",
      priority: cat.priority || "low",
      sort_order: cat.sort_order ?? 0,
      is_active: Boolean(cat.is_active),
    });
  };

  const closeModal = () => {
    setModalMode(null);
    setEditingCategory(null);
    setFieldErrors({});
  };

  const handleToggleActive = async (cat) => {
    if (togglingId || cooldown.isCoolingDown) return;
    setTogglingId(cat.id);
    setErrorMsg("");
    try {
      const res = await updateTicketCategory(cat.id, {
        is_active: !cat.is_active,
      });
      const updated = res.data?.data;
      if (updated) {
        setCategories((prev) =>
          prev.map((item) => (item.id === cat.id ? updated : item))
        );
      } else {
        fetchCategories();
      }
      setSuccessMsg(
        `${cat.code} ${!cat.is_active ? "activated" : "deactivated"}.`
      );
    } catch (err) {
      const parsed = await parseTicketApiError(
        err,
        "Unable to update category status."
      );
      if (parsed.status === 429) cooldown.startCooldown(parsed.retryAfterSeconds);
      setErrorMsg(parsed.message);
    } finally {
      setTogglingId(null);
    }
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (saving || cooldown.isCoolingDown) return;

    const localErrors = {};
    if (modalMode === "create" && !/^CAT-\d{2,4}$/.test(form.code.trim())) {
      localErrors.code = ["Code must match CAT-XX (e.g., CAT-06)."];
    }
    if (form.name.trim().length < 3) {
      localErrors.name = ["Name must be at least 3 characters."];
    }
    if (Object.keys(localErrors).length > 0) {
      setFieldErrors(localErrors);
      return;
    }

    setFieldErrors({});
    setErrorMsg("");
    setSaving(true);

    try {
      if (modalMode === "create") {
        const res = await createTicketCategory({
          code: form.code.trim().toUpperCase(),
          name: form.name.trim(),
          description: form.description.trim() || null,
          priority: form.priority,
          sort_order: Number(form.sort_order) || 0,
          is_active: Boolean(form.is_active),
        });
        const created = res.data?.data;
        setSuccessMsg(`Category ${created?.code || form.code} created.`);
      } else if (modalMode === "edit" && editingCategory) {
        await updateTicketCategory(editingCategory.id, {
          name: form.name.trim(),
          description: form.description.trim() || null,
          priority: form.priority,
          sort_order: Number(form.sort_order) || 0,
          is_active: Boolean(form.is_active),
        });
        setSuccessMsg(`Category ${editingCategory.code} updated.`);
      }
      closeModal();
      fetchCategories();
    } catch (err) {
      const parsed = await parseTicketApiError(err, "Unable to save category.");
      if (parsed.status === 429) cooldown.startCooldown(parsed.retryAfterSeconds);
      if (Object.keys(parsed.fieldErrors).length > 0) {
        setFieldErrors(parsed.fieldErrors);
      }
      setErrorMsg(parsed.message);
    } finally {
      setSaving(false);
    }
  };

  const selectedPriorityLabel =
    PRIORITY_SELECT_OPTIONS.find((o) => o.value === form.priority)?.label ||
    PRIORITY_SELECT_OPTIONS[1].label;

  return (
    <div className="space-y-4">
      {/* Header Bar */}
      <div
        className={`rounded-2xl p-4 sm:p-5 border flex flex-col sm:flex-row sm:items-center justify-between gap-3 ${
          isDark
            ? "bg-[#242526] border-[#3e4042] text-[#e4e6eb]"
            : "bg-white border-gray-200/80 shadow-sm text-gray-900"
        }`}
      >
        <div>
          <h2 className="text-base font-extrabold flex items-center gap-2">
            <TagIcon className="w-5 h-5" />
            Ticket Categories
          </h2>
          <p className={`text-xs mt-0.5 ${isDark ? "text-gray-400" : "text-gray-500"}`}>
            Categories determine initial ticket priority and SLA targets. Categories are
            deactivated rather than deleted so historical tickets and audit logs stay intact.
          </p>
        </div>

        <div className="flex items-center gap-2 shrink-0">
          <button
            type="button"
            onClick={fetchCategories}
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
            onClick={openCreateModal}
            className={`inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold shadow cursor-pointer ${
              isDark
                ? "bg-yellow-400 text-black hover:bg-yellow-300"
                : "bg-pup-dark-maroon text-white hover:bg-[#5c0000]"
            }`}
          >
            <PlusIcon className="w-4 h-4" />
            New Category
          </button>
        </div>
      </div>

      {/* Categories Table */}
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
            <p className="text-xs opacity-70">Loading categories…</p>
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
                  <th className="px-4 py-3">Code</th>
                  <th className="px-4 py-3">Category &amp; Description</th>
                  <th className="px-4 py-3">Default Priority</th>
                  <th className="px-4 py-3 text-center">Order</th>
                  <th className="px-4 py-3 text-center">Tickets</th>
                  <th className="px-4 py-3 text-center">Status</th>
                  <th className="px-4 py-3 text-center">Actions</th>
                </tr>
              </thead>
              <tbody
                className={`divide-y ${
                  isDark ? "divide-[#3e4042]" : "divide-gray-100"
                }`}
              >
                {visibleCategories.map((cat) => {
                  const isLastActive = cat.is_active && activeCount <= 1;
                  return (
                    <tr
                      key={cat.id}
                      className={
                        !cat.is_active ? "opacity-65" : undefined
                      }
                    >
                      <td className="px-4 py-3.5 font-mono font-bold whitespace-nowrap">
                        {cat.code}
                      </td>
                      <td className="px-4 py-3.5 max-w-md">
                        <div className="font-bold text-sm">{cat.name}</div>
                        {cat.description && (
                          <div className="opacity-75 mt-0.5">{cat.description}</div>
                        )}
                      </td>
                      <td className="px-4 py-3.5 whitespace-nowrap">
                        <span
                          className={`px-2.5 py-0.5 rounded-full text-[11px] font-bold border ${getPriorityBadgeClass(
                            cat.priority,
                            isDark
                          )}`}
                        >
                          {formatPriorityLabel(cat.priority)}
                        </span>
                      </td>
                      <td className="px-4 py-3.5 font-mono text-center">{cat.sort_order}</td>
                      <td className="px-4 py-3.5 font-semibold text-center">
                        {cat.tickets_count ?? 0}
                      </td>
                      <td className="px-4 py-3.5 whitespace-nowrap text-center">
                        {cat.is_active ? (
                          <span
                            className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold border ${
                              isDark
                                ? "bg-emerald-950/40 text-emerald-300 border-emerald-800/60"
                                : "bg-emerald-50 text-emerald-700 border-emerald-200"
                            }`}
                          >
                            <CheckCircleIcon className="w-3.5 h-3.5" />
                            Active
                          </span>
                        ) : (
                          <span
                            className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold border ${
                              isDark
                                ? "bg-zinc-800 text-gray-400 border-zinc-700"
                                : "bg-gray-100 text-gray-600 border-gray-200"
                            }`}
                          >
                            <NoSymbolIcon className="w-3.5 h-3.5" />
                            Inactive
                          </span>
                        )}
                      </td>
                      <td className="px-4 py-3.5 text-center whitespace-nowrap space-x-2">
                        <button
                          type="button"
                          onClick={() => openEditModal(cat)}
                          className={`inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg font-semibold border cursor-pointer ${
                            isDark
                              ? "bg-[#18191a] border-[#3e4042] text-gray-200 hover:bg-[#303134]"
                              : "bg-white border-gray-200 text-gray-700 hover:bg-gray-50"
                          }`}
                        >
                          <PencilSquareIcon className="w-3.5 h-3.5" />
                          Edit
                        </button>

                        <button
                          type="button"
                          onClick={() => handleToggleActive(cat)}
                          disabled={
                            togglingId === cat.id ||
                            isLastActive ||
                            cooldown.isCoolingDown
                          }
                          title={
                            isLastActive
                              ? "At least one category must remain active."
                              : cat.is_active
                                ? "Deactivate category"
                                : "Activate category"
                          }
                          className={`px-2.5 py-1.5 rounded-lg font-semibold border cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed ${
                            cat.is_active
                              ? isDark
                                ? "border-rose-800/60 text-rose-300 hover:bg-rose-950/40"
                                : "border-rose-200 text-rose-700 hover:bg-rose-50"
                              : isDark
                                ? "border-emerald-800/60 text-emerald-300 hover:bg-emerald-950/40"
                                : "border-emerald-200 text-emerald-700 hover:bg-emerald-50"
                          }`}
                        >
                          {togglingId === cat.id
                            ? "Saving…"
                            : cat.is_active
                              ? "Deactivate"
                              : "Activate"}
                        </button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
        {!loading && (
          <div
            className={`px-3 py-2 text-xs flex flex-wrap justify-center items-center gap-3 border-t ${
              isDark
                ? "bg-[#18191a] text-[#b0b3b8] border-[#3e4042]"
                : "bg-gray-50 text-gray-500 border-gray-200"
            }`}
          >
            <button
              type="button"
              onClick={() => setCurrentPage((page) => Math.max(1, page - 1))}
              disabled={currentPage === 1}
              className="inline-flex items-center gap-1 text-xs font-medium cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed"
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
              onClick={() => setCurrentPage((page) => page + 1)}
              disabled={!hasNextPage}
              className="inline-flex items-center gap-1 text-xs font-medium cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed"
            >
              Next
              <ChevronRightIcon className="w-4 h-4" />
            </button>
          </div>
        )}
      </div>

      {/* Create / Edit Category Modal */}
      {modalMode && (
        <div className="fixed inset-0 z-9999 flex items-center justify-center pt-16 sm:pt-4 pb-4 px-3 sm:px-4 backdrop-blur-sm bg-black/60 overflow-y-auto">
          <div
            className={`relative z-9999 w-full max-w-xl my-auto rounded-xl shadow-[0_20px_50px_rgba(0,0,0,0.4)] overflow-hidden border flex flex-col max-h-[calc(100vh-5rem)] sm:max-h-[85vh] animate-in fade-in zoom-in duration-200 ${
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
                    Ticket Category Administration
                  </span>
                  <h3 className="text-lg sm:text-xl text-white font-black uppercase tracking-tighter mt-0.5 truncate">
                    {modalMode === "create"
                      ? "Create Ticket Category"
                      : `Edit ${editingCategory?.code}`}
                  </h3>
                </div>
                <button
                  type="button"
                  onClick={closeModal}
                  className="p-1 rounded hover:opacity-90 shrink-0 text-white cursor-pointer"
                  title="Close"
                >
                  <XMarkIcon className="w-5 h-5 sm:w-6 sm:h-6" />
                </button>
              </div>
            </div>

            <form onSubmit={handleSubmit} noValidate className="flex flex-col flex-1 overflow-hidden">
              <div className="flex-1 overflow-y-auto p-4 sm:p-6 space-y-4">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 items-start">
                  <div>
                    {modalMode === "create" ? (
                      <InputGroup
                        label="Category Code"
                        name="code"
                        value={form.code}
                        onChange={(e) => {
                          setForm((prev) => ({
                            ...prev,
                            code: e.target.value.toUpperCase(),
                          }));
                          if (fieldErrors.code) {
                            setFieldErrors((prev) => ({ ...prev, code: undefined }));
                          }
                        }}
                        placeholder="CAT-06"
                        required
                        voiceEnabled={false}
                        labelColor="text-gray-700"
                        error={fieldErrors.code?.[0]}
                      />
                    ) : (
                      <div className="w-full">
                        <label
                          className={`block text-sm font-medium mb-1.5 ${
                            isDark ? "text-[#e4e6eb]" : "text-gray-700"
                          }`}
                        >
                          Category Code
                        </label>
                        <div
                          className={`w-full px-3 py-3 rounded-lg text-sm font-mono font-bold border opacity-75 select-all ${
                            isDark
                              ? "bg-[#1f1f1f] border-[#3e4042] text-[#e4e6eb]"
                              : "bg-gray-100 border-gray-200 text-gray-700"
                          }`}
                        >
                          {form.code}
                        </div>
                      </div>
                    )}
                    <span className="block text-[11px] opacity-65 mt-1">
                      {modalMode === "create"
                        ? "Permanent identifier (format CAT-XX)."
                        : "Code is permanent and cannot be modified."}
                    </span>
                  </div>

                  <div>
                    <DropdownGroup
                      label="Assigned Priority"
                      name="priority"
                      value={selectedPriorityLabel}
                      options={PRIORITY_SELECT_OPTIONS.map((o) => o.label)}
                      required
                      labelColor="text-gray-700"
                      error={fieldErrors.priority?.[0]}
                      onChange={(e) => {
                        const found = PRIORITY_SELECT_OPTIONS.find(
                          (o) => o.label === e.target.value
                        );
                        if (found) {
                          setForm((prev) => ({ ...prev, priority: found.value }));
                        }
                      }}
                    />
                    {modalMode === "edit" && (
                      <span className="block text-[11px] opacity-65 mt-1">
                        Applies to newly submitted tickets.
                      </span>
                    )}
                  </div>
                </div>

                <InputGroup
                  label="Category Name"
                  name="name"
                  value={form.name}
                  onChange={(e) => {
                    setForm((prev) => ({ ...prev, name: e.target.value }));
                    if (fieldErrors.name) {
                      setFieldErrors((prev) => ({ ...prev, name: undefined }));
                    }
                  }}
                  placeholder="e.g., Graduation & Credentials Verification"
                  required
                  labelColor="text-gray-700"
                  error={fieldErrors.name?.[0]}
                />

                <VoiceTextareaInput
                  id="admin-category-description"
                  name="description"
                  label="Description"
                  labelColor="text-gray-700"
                  minHeightClass="min-h-20"
                  maxLength={255}
                  value={form.description}
                  onChange={(val) =>
                    setForm((prev) => ({ ...prev, description: val }))
                  }
                  placeholder="Short helper text shown to requesters when choosing a category…"
                  error={fieldErrors.description?.[0]}
                />

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 items-center">
                  <InputGroup
                    type="number"
                    label="Sort Order"
                    name="sort_order"
                    min="0"
                    max="65535"
                    value={form.sort_order}
                    onChange={(e) =>
                      setForm((prev) => ({ ...prev, sort_order: e.target.value }))
                    }
                    voiceEnabled={false}
                    labelColor="text-gray-700"
                    error={fieldErrors.sort_order?.[0]}
                  />

                  <div className="pt-2 sm:pt-6">
                    <CheckboxItem
                      id="admin-category-active"
                      name="is_active"
                      checked={form.is_active}
                      onChange={(e) =>
                        setForm((prev) => ({ ...prev, is_active: e.target.checked }))
                      }
                      text="Active (visible on submission forms)"
                    />
                  </div>
                </div>
                {fieldErrors.is_active && (
                  <p className="text-xs text-rose-500">{fieldErrors.is_active[0]}</p>
                )}
              </div>

              <div
                className={`px-4 py-3 sm:px-6 sm:py-4 border-t-2 shrink-0 flex items-center justify-end gap-2.5 ${
                  isDark ? "bg-[#1f1f1f] border-[#3e4042]" : "bg-gray-50 border-gray-200"
                }`}
              >
                <button
                  type="button"
                  onClick={closeModal}
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
                  {saving
                    ? "Saving…"
                    : modalMode === "create"
                      ? "Create Category"
                      : "Save Changes"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      <SuccessToast message={successMsg} onClose={() => setSuccessMsg("")} />
      <ErrorToast message={errorMsg} onClose={() => setErrorMsg("")} />
    </div>
  );
};

export default TicketCategoriesAdminPanel;
