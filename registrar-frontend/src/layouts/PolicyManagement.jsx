/**
 * PolicyManagement — Admin Permission Policies
 * -----------------------------------------------------
 * Policies come straight from the backend (policies table) — see
 * PolicyController and PolicyResource. "is_system" (returned per
 * policy) drives which rows can be deleted; system-managed policies
 * are rejected by the backend (422) and filtered out client-side
 * before the delete request is sent. Bulk delete goes through a
 * confirmation modal before calling deletePolicy() for each
 * selected custom policy.
 */
import { useState, useEffect, useCallback, useRef } from "react";
import { useTheme } from "../context/ThemeContext";
import {
  XMarkIcon,
  ShieldCheckIcon,
  ChevronUpIcon,
  ChevronDownIcon
} from "@heroicons/react/24/outline";
import DashboardDropdown from "../components/DashboardDropdown";
import { getSystemUsers, getPolicies, createPolicy, updatePolicy, deletePolicy } from "../services/api";
import SuccessToast from "../components/SuccessToast.jsx";
import ErrorToast from "../components/ErrorToast.jsx";
import ConfirmationModal from "../components/ConfirmationModal";
import { PolicyTableSkeleton } from "../components/LoadingSkeleton";
import VoiceSearchInput from "../components/VoiceSearchInput.jsx";
import CreatePolicyModal from "../components/CreatePolicyModal";

// Full module list — used for the "Filter by Module Access" dropdown
// (permissionFilter), the Assigned Admins summary, and the labels
// helper below. Includes Dashboard/Admin Logbook: filtering by module
// access only ever needs "is this module granted at all" (a non-empty
// permissions[module] array), which is true regardless of whether that
// module is single-token or granular — see baseFiltered's permission
// filter further down, which is unaffected by this change.
const MODULE_OPTIONS = [
  "Dashboard",
  "Inbox",
  "Admin Analytics",
  "Admin Logbook",
  "Admin Profile",
  "Access Requests",
  "Business Calendar",
  "Cashier OR Overrides",
  "Free Requests",
];

const LABEL_TO_KEY = {
  "Dashboard": "dashboard",
  "Inbox": "inbox",
  "Admin Analytics": "analytics",
  "Admin Logbook": "logbook",
  "Admin Profile": "profile",
  "Access Requests": "access_requests",
  "Business Calendar": "business_calendar",
  "Cashier OR Overrides": "cashier_overrides",
  "Free Requests": "free_requests",
};

const KEY_TO_LABEL = Object.fromEntries(
  Object.entries(LABEL_TO_KEY).map(([label, key]) => [key, label])
);

const MODULE_ACTIONS = {
  dashboard: ["View", "Process", "Complete"],
  logbook: ["View", "Export"],
  free_requests: ["View", "File", "Verify", "Override"],
};

// Granular modules get their own action segment controls in CreatePolicyModal;
// single-token modules appear in the MultiSelection dropdown.
const GRANULAR_MODULE_KEYS = Object.keys(MODULE_ACTIONS);
const GRANULAR_MODULE_LABELS = GRANULAR_MODULE_KEYS.map((k) => KEY_TO_LABEL[k]);

const SINGLE_TOKEN_MODULE_OPTIONS = MODULE_OPTIONS.filter(
  (label) => !GRANULAR_MODULE_LABELS.includes(label)
);

const PolicyManagement = () => {
  const { isDark } = useTheme();
  const [search, setSearch] = useState("");

  // Policies now come straight from the backend (policies table) —
  // shape per row: { policy_id, name, is_system, type, permissions,
  // permissions_label, admins_count, created_at, updated_at }.
  const [policies, setPolicies] = useState([]);
  const [loading, setLoading] = useState(false);

  const [users, setUsers] = useState([]);
  const [successMsg, setSuccessMsg] = useState("");
  const [errorMsg, setErrorMsg] = useState("");

  // Table selection (multi-selection) & pagination state
  const [selectedPolicyIndices, setSelectedPolicyIndices] = useState([]);
  const [currentPage, setCurrentPage] = useState(1);
  const PER_PAGE = 5;

  // Modals state
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [isEditMode, setIsEditMode] = useState(false);
  const [editingPolicyIndex, setEditingPolicyIndex] = useState(null);
  const [submitting, setSubmitting] = useState(false);

  // Form fields
  const [policyName, setPolicyName] = useState("");
  const [selectedModuleValues, setSelectedModuleValues] = useState([]);
  // Work Item #1 — Granular Per-Action Permissions: Dashboard/Logbook
  // are tracked separately from selectedModuleValues since each is a
  // subset of named actions (see MODULE_ACTIONS), not a single on/off
  // toggle.
  const [dashboardActions, setDashboardActions] = useState([]);
  const [logbookActions, setLogbookActions] = useState([]);
  const [freeRequestsActions, setFreeRequestsActions] = useState([]);

  // Admin list modal
  const [isAdminListOpen, setIsAdminListOpen] = useState(false);
  const [selectedPolicyForAdmins, setSelectedPolicyForAdmins] = useState(null);
  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);

  // Discard changes state
  const [initialFormState, setInitialFormState] = useState(null);
  const [showDiscardConfirm, setShowDiscardConfirm] = useState(false);

  // Filter Dropdowns State & Refs
  const [typeDropdownOpen, setTypeDropdownOpen] = useState(false);
  const [typeFilter, setTypeFilter] = useState("All");
  const typeDropdownRef = useRef(null);

  const [sortOrder, setSortOrder] = useState("asc");

  const [permissionDropdownOpen, setPermissionDropdownOpen] = useState(false);
  const [permissionFilter, setPermissionFilter] = useState("All");
  const permissionDropdownRef = useRef(null);

  // Fetch policies from the backend
  const fetchPolicies = useCallback(async () => {
    setLoading(true);
    try {
      const res = await getPolicies();
      setPolicies(res.data.data || []);
    } catch (err) {
      setErrorMsg(err.response?.data?.message || "Failed to load policies.");
    } finally {
      setLoading(false);
    }
  }, []);

  // Fetch users so we can show real "attached admins" per policy
  const fetchUsers = useCallback(async () => {
    try {
      const res = await getSystemUsers();
      setUsers(res.data.data || []);
    } catch (err) {
      console.warn("Failed to load users for policy assignment count:", err);
    }
  }, []);

  useEffect(() => {
    fetchPolicies();
    fetchUsers();
  }, [fetchPolicies, fetchUsers]);

  // Reset pagination/selections on filter change
  useEffect(() => {
    setCurrentPage(1);
    setSelectedPolicyIndices([]);
  }, [search, typeFilter, sortOrder, permissionFilter]);

  // A policy is "attached" to an admin when user.policy_id matches —
  // this is the real, server-persisted attachment (see users.policy_id
  // and SystemUserController::attachPolicy()), not a name-based guess.
  const getAssignedAdmins = useCallback((policy) => {
    return users.filter(user => user.role_id === 3 && user.policy_id === policy.policy_id);
  }, [users]);

  // Generate rawPermissions object from selected module labels plus
  // the granular action selections.
  const buildPermissions = (selectedLabels, dashboardVal, logbookVal, freeRequestsVal) => {
    const raw = {};
    Object.entries(LABEL_TO_KEY).forEach(([label, key]) => {
      if (GRANULAR_MODULE_KEYS.includes(key)) return; // set explicitly below
      raw[key] = selectedLabels.includes(label) ? ["Access"] : [];
    });
    raw.dashboard = dashboardVal;
    raw.logbook = logbookVal;
    raw.free_requests = freeRequestsVal;
    return raw;
  };

  // Work Item #1 — Granular Per-Action Permissions: toggling Process or
  // Complete implies View (can't act on a queue you can't see), and
  // unchecking View clears whichever of Process/Complete were set —
  // neither one makes sense without it. Mirrors the same logic on the
  // Logbook side for Export.
  const toggleDashboardAction = (action) => {
    setDashboardActions((prev) => {
      if (prev.includes(action)) {
        return action === "View" ? [] : prev.filter((a) => a !== action);
      }
      const next = [...prev, action];
      if ((action === "Process" || action === "Complete") && !next.includes("View")) {
        next.push("View");
      }
      return next;
    });
  };

  const toggleLogbookAction = (action) => {
    setLogbookActions((prev) => {
      if (prev.includes(action)) {
        return action === "View" ? [] : prev.filter((a) => a !== action);
      }
      const next = [...prev, action];
      if (action === "Export" && !next.includes("View")) {
        next.push("View");
      }
      return next;
    });
  };

  const toggleFreeRequestsAction = (action) => {
    setFreeRequestsActions((prev) => {
      if (prev.includes(action)) {
        return action === "View" ? [] : prev.filter((a) => a !== action);
      }
      const next = [...prev, action];
      if ((action === "File" || action === "Verify" || action === "Override") && !next.includes("View")) {
        next.push("View");
      }
      return next;
    });
  };

  const handleOpenCreate = () => {
    setIsEditMode(false);
    setPolicyName("");
    setSelectedModuleValues([]);
    setDashboardActions([]);
    setLogbookActions([]);
    setFreeRequestsActions([]);
    setInitialFormState({
      name: "",
      modules: [],
      dashboardActions: [],
      logbookActions: [],
      freeRequestsActions: [],
    });
    setIsModalOpen(true);
  };

  const handleOpenEdit = (index) => {
    setIsEditMode(true);
    setEditingPolicyIndex(index);
    const p = policies[index];
    const initialName = p.name;

    // Map permissions object back to selectedModuleValues — dashboard
    // and logbook are handled separately below since they're
    // action-array modules, not single on/off toggles.
    const labels = [];
    Object.entries(p.permissions || {}).forEach(([key, val]) => {
      if (GRANULAR_MODULE_KEYS.includes(key)) return;
      if (key !== 'student_staff_switch' && val && val.length > 0) {
        const label = KEY_TO_LABEL[key];
        if (label) labels.push(label);
      }
    });

    // Defensive filter against MODULE_ACTIONS: drops any stale/unknown
    // token (e.g. a legacy "Access" left over pre-migration, or a
    // typo'd value from a raw API call) rather than rendering it as a
    // checked box for an action that doesn't exist.
    const dashboardVal = Array.isArray(p.permissions?.dashboard)
      ? p.permissions.dashboard.filter((a) => MODULE_ACTIONS.dashboard?.includes(a))
      : [];
    const logbookVal = Array.isArray(p.permissions?.logbook)
      ? p.permissions.logbook.filter((a) => MODULE_ACTIONS.logbook?.includes(a))
      : [];
    const freeRequestsVal = Array.isArray(p.permissions?.free_requests)
      ? p.permissions.free_requests.filter((a) => MODULE_ACTIONS.free_requests?.includes(a))
      : [];

    setPolicyName(initialName);
    setSelectedModuleValues(labels);
    setDashboardActions(dashboardVal);
    setLogbookActions(logbookVal);
    setFreeRequestsActions(freeRequestsVal);
    setInitialFormState({
      name: initialName,
      modules: labels,
      dashboardActions: dashboardVal,
      logbookActions: logbookVal,
      freeRequestsActions: freeRequestsVal,
    });
    setIsModalOpen(true);
  };

  const hasUnsavedChanges = () => {
    if (!initialFormState) return false;
    const nameChanged = policyName.trim() !== initialFormState.name.trim();
    const modulesChanged =
      selectedModuleValues.length !== initialFormState.modules.length ||
      !selectedModuleValues.every(val => initialFormState.modules.includes(val));
    const dashboardChanged =
      dashboardActions.length !== initialFormState.dashboardActions.length ||
      !dashboardActions.every((a) => initialFormState.dashboardActions.includes(a));
    const logbookChanged =
      logbookActions.length !== initialFormState.logbookActions.length ||
      !logbookActions.every((a) => initialFormState.logbookActions.includes(a));
    const freeRequestsChanged =
      freeRequestsActions.length !== initialFormState.freeRequestsActions.length ||
      !freeRequestsActions.every((a) => initialFormState.freeRequestsActions.includes(a));
    return nameChanged || modulesChanged || dashboardChanged || logbookChanged || freeRequestsChanged;
  };

  const handleCloseModal = () => {
    if (hasUnsavedChanges()) {
      setShowDiscardConfirm(true);
    } else {
      setIsModalOpen(false);
    }
  };

  const handleConfirmDiscard = () => {
    setShowDiscardConfirm(false);
    setIsModalOpen(false);
  };

  const handleToggleSelectRow = (index) => {
    if (selectedPolicyIndices.includes(index)) {
      setSelectedPolicyIndices(selectedPolicyIndices.filter(i => i !== index));
    } else {
      setSelectedPolicyIndices([...selectedPolicyIndices, index]);
    }
  };

  const handleDeleteSelected = () => {
    if (selectedPolicyIndices.length === 0) return;

    const selectedPolicies = selectedPolicyIndices.map(idx => policies[idx]).filter(Boolean);
    const customSelected = selectedPolicies.filter(p => !p.is_system);

    if (customSelected.length === 0) {
      setErrorMsg("System-managed policies cannot be deleted.");
      return;
    }

    setShowDeleteConfirm(true);
  };

  const handleConfirmDelete = async () => {
    const selectedPolicies = selectedPolicyIndices.map(idx => policies[idx]).filter(Boolean);
    const systemSelected = selectedPolicies.filter(p => p.is_system);
    const customSelected = selectedPolicies.filter(p => !p.is_system);

    try {
      // The backend rejects deletion of is_system policies (422), so only
      // custom ones are sent — matches the guard already shown in the UI.
      await Promise.all(customSelected.map(p => deletePolicy(p.policy_id)));

      if (systemSelected.length > 0) {
        setSuccessMsg(`Deleted ${customSelected.length} custom policy/policies. System-managed policies were preserved.`);
      } else {
        setSuccessMsg(`Successfully deleted ${customSelected.length} policy/policies.`);
      }

      setSelectedPolicyIndices([]);
      await fetchPolicies();
      await fetchUsers();
    } catch (err) {
      setErrorMsg(err.response?.data?.message || "Failed to delete policy/policies.");
    }
  };

  const handleSavePolicy = async (e) => {
    e.preventDefault();
    setErrorMsg("");

    if (!policyName.trim()) {
      setErrorMsg("Policy name is required.");
      return;
    }

    const hasAnySelection =
      selectedModuleValues.length > 0 ||
      dashboardActions.length > 0 ||
      logbookActions.length > 0 ||
      freeRequestsActions.length > 0;

    if (!hasAnySelection) {
      setErrorMsg("Please select at least one module.");
      return;
    }

    if (isEditMode && !hasUnsavedChanges()) {
      setErrorMsg("No changes have been made.");
      return;
    }

    const permissions = buildPermissions(selectedModuleValues, dashboardActions, logbookActions, freeRequestsActions);
    setSubmitting(true);

    try {
      if (isEditMode) {
        const policyId = policies[editingPolicyIndex].policy_id;
        await updatePolicy(policyId, { name: policyName.trim(), permissions });
        setSuccessMsg("Policy updated successfully.");
      } else {
        await createPolicy({ name: policyName.trim(), permissions });
        setSuccessMsg("Policy created successfully.");
      }

      setIsModalOpen(false);
      await fetchPolicies();
    } catch (err) {
      setErrorMsg(err.response?.data?.message || "Failed to save policy.");
    } finally {
      setSubmitting(false);
    }
  };

  const handleOpenAdminList = (policy) => {
    setSelectedPolicyForAdmins(policy);
    setIsAdminListOpen(true);
  };

  const handleFilterChange = () => {
    setCurrentPage(1);
    setSelectedPolicyIndices([]);
  };

  // Filter policies based on Search & Dropdowns
  const baseFiltered = policies.filter((p) => {
    const matchesSearch = p.name.toLowerCase().includes(search.toLowerCase()) ||
      (p.permissions_label || "").toLowerCase().includes(search.toLowerCase());
    if (!matchesSearch) return false;

    // Type Filter
    if (typeFilter !== "All") {
      const isSystemPolicy = !!p.is_system;
      if (typeFilter === "System Managed" && !isSystemPolicy) return false;
      if (typeFilter === "Custom Policy" && isSystemPolicy) return false;
    }

    // Permission Filter
    if (permissionFilter !== "All") {
      const moduleKey = LABEL_TO_KEY[permissionFilter];
      if (moduleKey) {
        const hasAccess = Array.isArray(p.permissions?.[moduleKey]) && p.permissions[moduleKey].length > 0;
        if (!hasAccess) return false;
      }
    }

    return true;
  });

  const filteredPolicies = [...baseFiltered].sort((a, b) => {
    if (sortOrder === "asc") {
      return a.name.localeCompare(b.name);
    } else {
      return b.name.localeCompare(a.name);
    }
  });

  const totalPages = Math.max(1, Math.ceil(filteredPolicies.length / PER_PAGE));
  const safePage = Math.min(currentPage, totalPages);
  const paginated = filteredPolicies.slice((safePage - 1) * PER_PAGE, safePage * PER_PAGE);

  return (
    <div className="w-full flex flex-col font-sans">

      {/* Title & Top button toolbar */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-4">
        <div>
          <div className="flex items-center gap-2">
            <h1 className={`text-xl font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>
              Admin Policies <span className={`text-sm font-semibold ml-1 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>({policies.length})</span>
            </h1>
          </div>
          <p className={`text-xs mt-1 ${isDark ? 'text-gray-455' : 'text-gray-500'}`}>
            A policy is an object that defines module permissions.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2 mt-4 sm:mt-0">
          {/* Delete Action button */}
          <button
            disabled={selectedPolicyIndices.length === 0}
            onClick={handleDeleteSelected}
            className={`px-4 py-2 border rounded-lg text-sm font-semibold transition-all cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed ${isDark ? 'border-gray-700 bg-[#2a2a2f] text-white hover:bg-white/10' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
              }`}
          >
            Delete
          </button>

          {/* Create policy Button */}
          <button
            onClick={handleOpenCreate}
            className={`px-5 py-2.5 rounded-lg text-sm font-bold shadow transition-all cursor-pointer hover:scale-[1.02] active:scale-[0.98] ${isDark ? 'bg-yellow-400 text-black hover:bg-yellow-500' : 'bg-pup-dark-maroon text-white hover:bg-[#3a0303]'
              }`}
          >
            Create policy
          </button>
        </div>
      </div>      {/* Main Container */}
      <div className={`rounded-xl overflow-hidden border mt-4 ${isDark ? 'bg-[#242526] border-[#3e4042]' : 'bg-white border-gray-200 shadow-sm'
        }`}>
        {/* Search & pagination bar inside container */}
        <div className={`flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 p-4 border-b ${isDark ? 'border-[#3e4042] bg-[#1a1a1c]/20' : 'border-gray-200 bg-gray-50/50'
          }`}>
          <div className="flex flex-wrap items-center gap-3 flex-1">
            {/* Search */}
            <div className="w-full sm:max-w-md">
              <VoiceSearchInput
                value={search}
                onChange={(value) => {
                  setSearch(value);
                  setCurrentPage(1);
                }}
                placeholder="Search"
              />
            </div>
          </div>

          {/* Pagination Controls */}
          <div className="flex items-center gap-3 ml-auto sm:ml-0">
            <div className={`flex items-center text-sm ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>
              <button
                disabled={currentPage === 1}
                onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                className="p-1.5 disabled:opacity-40 cursor-pointer font-bold"
              >
                &lt;
              </button>
              <span className="mx-2 font-semibold text-xs">{currentPage} of {totalPages}</span>
              <button
                disabled={currentPage === totalPages}
                onClick={() => setCurrentPage(p => Math.min(totalPages, p + 1))}
                className="p-1.5 disabled:opacity-40 cursor-pointer font-bold"
              >
                &gt;
              </button>
            </div>
          </div>
        </div>

        {/* Table element inside container */}
        <div className="overflow-x-auto">
          <table className="w-full min-w-200 text-sm">
            <thead>
              <tr className={`border-b text-xs font-bold uppercase tracking-wider ${isDark ? 'border-[#3e4042] text-[#a09e9a]' : 'border-gray-200 text-gray-500'
                }`}>
                {/* Checkbox column header */}
                <th className="w-12 px-4 py-3 text-center">
                  <input
                    type="checkbox"
                    checked={paginated.length > 0 && paginated.every((_, i) => {
                      const globalIdx = (safePage - 1) * PER_PAGE + i;
                      return selectedPolicyIndices.includes(globalIdx);
                    })}
                    onChange={(e) => {
                      const pageIndices = paginated.map((_, i) => (safePage - 1) * PER_PAGE + i);
                      if (e.target.checked) {
                        const newSelection = [...new Set([...selectedPolicyIndices, ...pageIndices])];
                        setSelectedPolicyIndices(newSelection);
                      } else {
                        setSelectedPolicyIndices(selectedPolicyIndices.filter(idx => !pageIndices.includes(idx)));
                      }
                    }}
                    className="accent-red-500 rounded cursor-pointer w-4 h-4"
                  />
                </th>
                <th className="w-10 px-2 py-3 text-center"></th>
                {/* Policy name Sorting */}
                <th className="px-4 py-3 text-left">
                  <button
                    type="button"
                    onClick={() => {
                      setSortOrder(prev => prev === 'asc' ? 'desc' : 'asc');
                      handleFilterChange();
                    }}
                    className={`flex items-center gap-1 text-xs uppercase font-bold hover:text-[#800000] dark:hover:text-[#FFC72C] transition-colors focus:outline-none cursor-pointer ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}
                  >
                    <span>Policy name</span>
                    {sortOrder === 'asc' ? (
                      <ChevronDownIcon className="w-3.5 h-3.5 text-blue-500" />
                    ) : (
                      <ChevronUpIcon className="w-3.5 h-3.5 text-blue-500" />
                    )}
                  </button>
                </th>

                {/* Type Filter dropdown */}
                <th className="px-4 py-3 text-left">
                  <DashboardDropdown
                    isOpen={typeDropdownOpen}
                    setIsOpen={setTypeDropdownOpen}
                    dropdownRef={typeDropdownRef}
                    align="left"
                    trigger={
                      <span className={typeFilter !== 'All' ? (isDark ? 'text-yellow-400' : 'text-[#8b0000]') : (isDark ? 'text-[#a09e9a]' : 'text-gray-500')}>
                        Type
                      </span>
                    }
                    sections={[
                      {
                        title: 'Filter by Type',
                        items: ['All', 'System Managed', 'Custom Policy'].map(option => ({
                          label: option,
                          isSelected: typeFilter === option,
                          onClick: () => {
                            setTypeFilter(option);
                            handleFilterChange();
                          }
                        }))
                      }
                    ]}
                  />
                </th>

                {/* Used as (Static) */}
                <th className="px-4 py-3 text-left text-gray-500 dark:text-[#a09e9a] font-bold uppercase tracking-wider">Used as</th>

                {/* Permissions Filter dropdown */}
                <th className="px-4 py-3 text-left">
                  <DashboardDropdown
                    isOpen={permissionDropdownOpen}
                    setIsOpen={setPermissionDropdownOpen}
                    dropdownRef={permissionDropdownRef}
                    align="left"
                    trigger={
                      <span className={permissionFilter !== 'All' ? (isDark ? 'text-yellow-400' : 'text-[#8b0000]') : (isDark ? 'text-[#a09e9a]' : 'text-gray-500')}>
                        Permissions
                      </span>
                    }
                    sections={[
                      {
                        title: 'Filter by Module Access',
                        items: ['All', ...MODULE_OPTIONS].map(option => ({
                          label: option,
                          isSelected: permissionFilter === option,
                          onClick: () => {
                            setPermissionFilter(option);
                            handleFilterChange();
                          }
                        }))
                      }
                    ]}
                  />
                </th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <PolicyTableSkeleton isDark={isDark} count={PER_PAGE} />
              ) : paginated.length === 0 ? (
                <tr>
                  <td colSpan={6} className="py-12 text-center text-gray-500">
                    No policies found matching your criteria.
                  </td>
                </tr>
              ) : (
                paginated.map((policy, idx) => {
                  const admins = getAssignedAdmins(policy);
                  const usedAsText = admins.length > 0
                    ? `Permissions policy (${admins.length})`
                    : "None";

                  const globalIdx = (safePage - 1) * PER_PAGE + idx;

                  return (
                    <tr
                      key={policy.policy_id}
                      className={`border-b last:border-0 transition-colors ${isDark
                          ? 'border-[#3e4042] hover:bg-[#2a2a2f]'
                          : 'border-gray-100 hover:bg-gray-50'
                        } ${selectedPolicyIndices.includes(globalIdx) ? (isDark ? 'bg-yellow-400/5' : 'bg-[#800000]/5') : ''}`}
                    >
                      {/* Checkbox Select cell */}
                      <td className="px-4 py-3 text-center">
                        <div className="flex items-center justify-center">
                          <input
                            type="checkbox"
                            checked={selectedPolicyIndices.includes(globalIdx)}
                            onChange={() => handleToggleSelectRow(globalIdx)}
                            className="accent-red-500 rounded cursor-pointer w-4 h-4"
                          />
                        </div>
                      </td>

                      {/* Policy Shield Icon */}
                      <td className="px-2 py-3 text-center">
                        <div className="flex items-center justify-center">
                          <ShieldCheckIcon className="w-5 h-5 text-amber-500 shrink-0" />
                        </div>
                      </td>

                      {/* Policy name hyperlink */}
                      <td className="px-4 py-3 font-semibold">
                        <div className="flex items-center gap-2">
                          <button
                            onClick={() => handleOpenEdit(globalIdx)}
                            className="text-blue-400 hover:text-blue-300 font-semibold hover:underline text-left text-sm cursor-pointer"
                          >
                            {policy.name}
                          </button>
                        </div>
                      </td>

                      {/* Type */}
                      <td className={`px-4 py-3 ${isDark ? 'text-gray-300' : 'text-gray-700'}`}>
                        {policy.type}
                      </td>

                      {/* Assigned admins count link */}
                      <td className="px-4 py-3">
                        {admins.length > 0 ? (
                          <button
                            onClick={() => handleOpenAdminList(policy)}
                            className="text-blue-400 hover:text-blue-300 font-semibold hover:underline text-left text-sm cursor-pointer"
                          >
                            {usedAsText}
                          </button>
                        ) : (
                          <span className={isDark ? 'text-gray-500' : 'text-gray-400'}>
                            {usedAsText}
                          </span>
                        )}
                      </td>

                      {/* Description */}
                      <td className={`px-4 py-3 max-w-xs truncate ${isDark ? 'text-gray-400' : 'text-gray-600'}`}>
                        {policy.permissions_label}
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Create / Edit Policy Modal */}
      <CreatePolicyModal
        isOpen={isModalOpen}
        isEditMode={isEditMode}
        submitting={submitting}
        policyName={policyName}
        setPolicyName={setPolicyName}
        selectedModuleValues={selectedModuleValues}
        setSelectedModuleValues={setSelectedModuleValues}
        dashboardActions={dashboardActions}
        logbookActions={logbookActions}
        freeRequestsActions={freeRequestsActions}
        toggleDashboardAction={toggleDashboardAction}
        toggleLogbookAction={toggleLogbookAction}
        toggleFreeRequestsAction={toggleFreeRequestsAction}
        onClose={handleCloseModal}
        onSubmit={handleSavePolicy}
        singleTokenModuleOptions={SINGLE_TOKEN_MODULE_OPTIONS}
      />

      {/* Admin Assignment Details Modal */}
      {isAdminListOpen && selectedPolicyForAdmins && (
        <div className="fixed inset-0 z-10000 flex items-center justify-center p-4">
          <div
            className={`absolute inset-0 backdrop-blur-sm ${isDark ? 'bg-black/70' : 'bg-black/50'}`}
            onClick={() => setIsAdminListOpen(false)}
          />
          <div className={`relative rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden ${isDark ? 'bg-[#242526] border border-[#3e4042]' : 'bg-white'}`}>

            {/* Header */}
            <div className={`px-6 py-5 flex items-center justify-between ${isDark ? 'bg-[#2a2a2f] border-b border-[#3e4042]' : 'bg-pup-dark-maroon text-white'}`}>
              <div>
                <h2 className="text-white font-bold text-lg uppercase tracking-wide">
                  Assigned Admins
                </h2>
                <p className={`text-xs mt-0.5 ${isDark ? 'text-[#b0b3b8]' : 'text-white/60'}`}>
                  Admins attached to {selectedPolicyForAdmins.name}
                </p>
              </div>
              <button
                type="button"
                onClick={() => setIsAdminListOpen(false)}
                className="p-1.5 rounded-full hover:bg-white/20 transition-colors text-white"
              >
                <XMarkIcon className="w-5 h-5" />
              </button>
            </div>

            <div className="h-1 w-full bg-linear-to-r from-[#FFD700] via-[#FFC72C] to-[#FFD700]" />

            <div className="p-6 space-y-4 max-h-[50vh] overflow-y-auto">
              {getAssignedAdmins(selectedPolicyForAdmins).length === 0 ? (
                <div className={`text-center py-6 text-sm ${isDark ? 'text-gray-400' : 'text-gray-505'}`}>
                  No admins currently assigned to this policy.
                </div>
              ) : (
                <div className="space-y-3">
                  {getAssignedAdmins(selectedPolicyForAdmins).map((user) => {
                    const fullName = user.admin_profile
                      ? [user.admin_profile.first_name, user.admin_profile.last_name].filter(Boolean).join(" ")
                      : "Unnamed Admin";
                    return (
                      <div
                        key={user.user_id}
                        className={`p-3 rounded-lg border flex flex-col ${isDark ? 'bg-[#1f1f1f] border-[#3e4042]' : 'bg-gray-50 border-gray-200'
                          }`}
                      >
                        <div className={`font-bold text-sm ${isDark ? 'text-white' : 'text-gray-900'}`}>
                          {fullName}
                        </div>
                        <div className="text-xs text-gray-500 mt-0.5">
                          {user.email}
                        </div>
                      </div>
                    );
                  })}
                </div>
              )}
            </div>

            {/* Footer */}
            <div className={`px-6 pb-6 pt-4 flex items-center justify-end border-t ${isDark ? 'border-[#3e4042]' : 'border-gray-100'}`}>
              <button
                type="button"
                onClick={() => setIsAdminListOpen(false)}
                className={`px-6 py-2 rounded-lg text-sm font-bold transition-all shadow ${isDark ? 'bg-[#2a2a2f] text-[#e4e6eb] hover:bg-[#353539] border border-[#3e4042]' : 'bg-pup-dark-maroon text-white hover:bg-[#3a0303]'
                  }`}
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}

      <SuccessToast
        message={successMsg}
        onClose={() => setSuccessMsg("")}
      />

      <ErrorToast
        message={errorMsg}
        onClose={() => setErrorMsg("")}
      />

      <ConfirmationModal
        isOpen={showDeleteConfirm}
        onClose={() => setShowDeleteConfirm(false)}
        onConfirm={handleConfirmDelete}
        title="Delete Selected Policies?"
        message="Are you sure you want to delete the selected policy/policies? This action cannot be undone."
        type="danger"
      />

      <ConfirmationModal
        isOpen={showDiscardConfirm}
        onClose={() => setShowDiscardConfirm(false)}
        onConfirm={handleConfirmDiscard}
        title="Discard Unsaved Changes?"
        message="You have unsaved changes. Are you sure you want to discard them?"
        type="confirm"
      />
    </div>
  );
};

export default PolicyManagement;