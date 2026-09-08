/**
 * CreatePolicyModal — Create / Edit Policy modal
 * -----------------------------------------------
 * Extracted from PolicyManagement so the parent stays focused on data
 * and table logic. All state lives in PolicyManagement; this component
 * is purely presentational (controlled form).
 *
 * Dashboard & Admin Logbook permissions use a segmented button group
 * instead of per-action checkboxes — clicking a segment toggles that
 * action while still honouring the View-implied-by-Process/Complete and
 * View-implied-by-Export rules enforced in the parent's toggle handlers.
 */
import { XMarkIcon } from "@heroicons/react/24/outline";
import { useTheme } from "../context/ThemeContext";
import MultiSelection from "./MultiSelection";
import { MODULE_ACTIONS } from "../utils/policy";

const ACTION_LABELS = {
  View: "View",
  Process: "Process",
  Complete: "Complete",
  Export: "Export",
  File: "File",
  Verify: "Verify",
  Override: "Override",
};

// Segmented button group for a single granular-action module
// (Dashboard, Admin Logbook, or Free Requests).
const ActionSegmentGroup = ({ actions, selectedActions, onToggle, isDark }) => (
  <div
    className={`flex flex-wrap rounded-xl overflow-hidden border ${
      isDark ? "border-[#3e4042] bg-[#1c1c1e]" : "border-gray-200 bg-gray-100"
    }`}
  >
    {actions.map((action, index) => {
      const isSelected = selectedActions.includes(action);
      const isFirst = index === 0;
      const isLast = index === actions.length - 1;
      return (
        <button
          key={action}
          type="button"
          onClick={() => onToggle(action)}
          className={`flex-1 min-w-18.75 px-3 py-2.5 text-sm font-semibold transition-all duration-200 cursor-pointer
            ${!isFirst ? (isDark ? "border-l border-[#3e4042]" : "border-l border-gray-200") : ""}
            ${isFirst ? "rounded-l-xl" : ""}
            ${isLast ? "rounded-r-xl" : ""}
            ${
              isSelected
                ? isDark
                  ? "bg-[#6b0f0f] text-white shadow-inner"
                  : "bg-pup-dark-maroon text-white shadow-inner"
                : isDark
                ? "bg-transparent text-[#b0b3b8] hover:bg-[#2e2e30] hover:text-white"
                : "bg-transparent text-gray-600 hover:bg-gray-200 hover:text-gray-900"
            }`}
        >
          {ACTION_LABELS[action] || action}
        </button>
      );
    })}
  </div>
);

const CreatePolicyModal = ({
  isOpen,
  isEditMode,
  submitting,
  policyName,
  setPolicyName,
  selectedModuleValues,
  setSelectedModuleValues,
  dashboardActions,
  logbookActions,
  freeRequestsActions,
  toggleDashboardAction,
  toggleLogbookAction,
  toggleFreeRequestsAction,
  onClose,
  onSubmit,
  singleTokenModuleOptions,
}) => {
  const { isDark } = useTheme();

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-9999 flex items-center justify-center p-4">
      <div
        className={`absolute inset-0 backdrop-blur-sm ${
          isDark ? "bg-black/70" : "bg-black/50"
        }`}
        onClick={onClose}
      />
      <div
        className={`relative rounded-2xl shadow-2xl w-full max-w-2xl mx-auto flex flex-col max-h-[90vh] overflow-hidden ${
          isDark ? "bg-[#242526] border border-[#3e4042]" : "bg-white"
        }`}
      >
        {/* Header */}
        <div
          className={`px-6 py-5 flex items-center justify-between rounded-t-2xl shrink-0 ${
            isDark
              ? "bg-[#2a2a2f] border-b border-[#3e4042]"
              : "bg-pup-dark-maroon text-white"
          }`}
        >
          <div>
            <h2 className="text-white font-bold text-lg uppercase tracking-wide">
              {isEditMode ? "Edit Policy" : "Create Policy"}
            </h2>
            <p
              className={`text-xs mt-0.5 ${
                isDark ? "text-[#b0b3b8]" : "text-white/60"
              }`}
            >
              Define a reusable set of module permissions, then attach it to any
              admin
            </p>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="p-1.5 rounded-full hover:bg-white/20 transition-colors text-white cursor-pointer"
          >
            <XMarkIcon className="w-5 h-5" />
          </button>
        </div>

        <div className="h-1 w-full shrink-0 bg-linear-to-r from-[#FFD700] via-[#FFC72C] to-[#FFD700]" />

        <form
          onSubmit={onSubmit}
          noValidate
          className="flex flex-col flex-1 overflow-y-auto"
        >
          <div className="p-6 space-y-5 overflow-visible">
            {/* Policy Name */}
            <div>
              <label
                className={`block text-xs font-semibold uppercase tracking-wider ${
                  isDark ? "text-[#b0b3b8]" : "text-gray-655"
                } mb-1.5`}
              >
                Policy Name
              </label>
              <input
                type="text"
                required
                value={policyName}
                onChange={(e) => setPolicyName(e.target.value)}
                placeholder="e.g. Registrar Frontliner"
                className={`w-full px-4 py-2.5 rounded-lg text-sm transition-all focus:outline-none focus:ring-2 ${
                  isDark
                    ? "bg-[#1f1f1f] text-[#e4e6eb] placeholder-[#9a9a9a] focus:ring-[#FFD700] border border-[#3e4042]"
                    : "bg-white text-gray-700 placeholder-gray-400 focus:ring-[#FFC72C] border border-gray-300"
                }`}
              />
            </div>

            {/* Remaining single-token modules */}
            <div
              className={`p-4 rounded-xl border flex flex-col relative overflow-visible ${
                isDark
                  ? "bg-[#1f1f1f] border-[#3e4042]"
                  : "bg-gray-50 border-gray-200"
              }`}
            >
              <div className="flex justify-between items-center mb-2">
                <span
                  className={`text-sm font-bold ${
                    isDark ? "text-white" : "text-gray-900"
                  }`}
                >
                  Select a module/s
                </span>
              </div>

              <div className="mt-1 relative overflow-visible">
                <MultiSelection
                  name="policy-modules"
                  label=""
                  options={singleTokenModuleOptions}
                  selectedValues={selectedModuleValues}
                  onChange={(e) => setSelectedModuleValues(e.target.value)}
                />
              </div>
            </div>

            {/* Dashboard — segmented button permissions */}
            <div
              className={`p-4 rounded-xl border flex flex-col ${
                isDark
                  ? "bg-[#1f1f1f] border-[#3e4042]"
                  : "bg-gray-50 border-gray-200"
              }`}
            >
              <div className="flex justify-between items-center mb-1">
                <span
                  className={`text-sm font-bold ${
                    isDark ? "text-white" : "text-gray-900"
                  }`}
                >
                  Dashboard
                </span>
              </div>
              <p
                className={`text-xs mb-3 ${
                  isDark ? "text-[#9a9a9a]" : "text-gray-500"
                }`}
              >
                Choose which document-request queue actions this policy grants.
                Process and Complete each require View.
              </p>
              <ActionSegmentGroup
                actions={MODULE_ACTIONS.dashboard}
                selectedActions={dashboardActions}
                onToggle={toggleDashboardAction}
                isDark={isDark}
              />
            </div>

            {/* Admin Logbook — segmented button permissions */}
            <div
              className={`p-4 rounded-xl border flex flex-col ${
                isDark
                  ? "bg-[#1f1f1f] border-[#3e4042]"
                  : "bg-gray-50 border-gray-200"
              }`}
            >
              <div className="flex justify-between items-center mb-1">
                <span
                  className={`text-sm font-bold ${
                    isDark ? "text-white" : "text-gray-900"
                  }`}
                >
                  Admin Logbook
                </span>
              </div>
              <p
                className={`text-xs mb-3 ${
                  isDark ? "text-[#9a9a9a]" : "text-gray-500"
                }`}
              >
                Export is a soft, UI-only gate — the underlying data is already
                visible once the logbook can be viewed.
              </p>
              <ActionSegmentGroup
                actions={MODULE_ACTIONS.logbook}
                selectedActions={logbookActions}
                onToggle={toggleLogbookAction}
                isDark={isDark}
              />
            </div>

            {/* Free Requests — segmented button permissions */}
            <div
              className={`p-4 rounded-xl border flex flex-col ${
                isDark
                  ? "bg-[#1f1f1f] border-[#3e4042]"
                  : "bg-gray-50 border-gray-200"
              }`}
            >
              <div className="flex justify-between items-center mb-1">
                <span
                  className={`text-sm font-bold ${
                    isDark ? "text-white" : "text-gray-900"
                  }`}
                >
                  Free Requests
                </span>
              </div>
              <p
                className={`text-xs mb-3 ${
                  isDark ? "text-[#9a9a9a]" : "text-gray-500"
                }`}
              >
                Configure capabilities for filing free requests, performing graduate verification (COG/TOR), and overriding eligibility rules.
              </p>
              <ActionSegmentGroup
                actions={MODULE_ACTIONS.free_requests || ["View", "File", "Verify", "Override"]}
                selectedActions={freeRequestsActions || []}
                onToggle={toggleFreeRequestsAction}
                isDark={isDark}
              />
            </div>
          </div>

          {/* Footer */}
          <div
            className={`px-6 pb-6 pt-4 flex items-center justify-end gap-3 border-t shrink-0 rounded-b-2xl ${
              isDark ? "border-[#3e4042]" : "border-gray-100"
            }`}
          >
            <button
              type="button"
              onClick={onClose}
              className={`px-5 py-2 rounded-lg text-sm font-semibold transition-colors cursor-pointer ${
                isDark
                  ? "text-[#b0b3b8] hover:bg-[#2a2a2f]"
                  : "text-gray-600 hover:bg-gray-100"
              }`}
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={submitting}
              className={`px-6 py-2 rounded-lg text-sm font-bold transition-all shadow disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer ${
                isDark
                  ? "bg-[#2a2a2f] text-[#e4e6eb] hover:bg-[#353539] border border-[#3e4042]"
                  : "bg-pup-dark-maroon text-white hover:bg-[#3a0303]"
              }`}
            >
              {submitting
                ? "Saving..."
                : isEditMode
                ? "Save Changes"
                : "Save Policy"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};

export default CreatePolicyModal;
