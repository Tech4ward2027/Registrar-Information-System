import React from "react";
import { useSearchParams } from "react-router-dom";
import { ExclamationTriangleIcon } from "@heroicons/react/24/outline";
import { useTheme } from "../context/ThemeContext";
import UnmatchedCashierItemsManagement from "../layouts/UnmatchedCashierItemsManagement.jsx";

/**
 * Cashier Reconciliation — the single home for cashier-related review
 * work (Phase 1 of the Cashier Reconciliation / System Health plan).
 *
 *   ?tab=unmatched  Unmatched receipt labels (existing screen, reused as-is)
 *   ?tab=failed     Failed verifications (placeholder until Phase 5)
 *
 * Mounted for both staff (/staff/cashier-reconciliation, behind
 * ModuleRoute) and super admin (/super-admin/cashier-reconciliation).
 * Like the rest of the policy UI this is a UX layer only: the real
 * boundary is the backend's `module:cashier_reconciliation` middleware.
 */
const TABS = [
  { key: "unmatched", label: "Unmatched Labels" },
  { key: "failed", label: "Failed Verifications" },
];
const DEFAULT_TAB = "unmatched";

const CashierReconciliation = () => {
  const { isDark } = useTheme();
  const [searchParams, setSearchParams] = useSearchParams();

  const tabFromUrl = searchParams.get("tab");
  const activeTab = TABS.some((t) => t.key === tabFromUrl) ? tabFromUrl : DEFAULT_TAB;

  const handleTabChange = (tabKey) => setSearchParams({ tab: tabKey });

  return (
    <div className={`font-sans ${isDark ? "text-[#e4e6eb]" : ""}`}>
      {/* Tab switcher (hidden on mobile — the sidebar children cover it) */}
      <div className="hidden md:flex justify-center mx-4 sm:mx-6 mb-5">
        <div
          className={`inline-flex px-8 py-3.5 rounded-full transition-all duration-300 hover:-translate-y-0.5 ${
            isDark
              ? "bg-[#242526] border border-[#3e4042] shadow-[0_2px_8px_rgba(0,0,0,0.2)] hover:shadow-[0_4px_16px_rgba(0,0,0,0.35)]"
              : "bg-white border border-gray-200/80 shadow-[0_2px_8px_rgba(0,0,0,0.05)] hover:shadow-[0_4px_16px_rgba(0,0,0,0.1)]"
          } gap-8 items-center`}
        >
          {TABS.map((tab) => (
            <button
              key={tab.key}
              onClick={() => handleTabChange(tab.key)}
              className={`text-sm relative rounded-full flex items-center justify-center shrink-0 font-semibold transition-all duration-200 hover:scale-105 active:scale-95 cursor-pointer whitespace-nowrap ${
                activeTab === tab.key
                  ? isDark
                    ? "text-yellow-400 font-bold"
                    : "text-pup-dark-maroon font-black"
                  : isDark
                    ? "text-[#b0b3b8] hover:text-white"
                    : "text-gray-500 hover:text-gray-900"
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>
      </div>

      {activeTab === "unmatched" && <UnmatchedCashierItemsManagement />}
      {activeTab === "failed" && <FailedVerificationsPlaceholder isDark={isDark} />}
    </div>
  );
};

const FailedVerificationsPlaceholder = ({ isDark }) => (
  <div
    className={`rounded-2xl p-6 sm:p-10 flex flex-col items-center text-center ${
      isDark
        ? "bg-[#242526] text-[#e4e6eb] border border-[#3e4042]"
        : "bg-white text-gray-900 shadow-md border border-gray-200/80"
    }`}
  >
    <div
      className={`w-14 h-14 rounded-full flex items-center justify-center mb-3 ${
        isDark ? "bg-zinc-800 text-gray-400" : "bg-gray-100 text-gray-400"
      }`}
    >
      <ExclamationTriangleIcon className="w-7 h-7" />
    </div>
    <h2 className="text-base font-bold mb-1">Failed verifications — coming soon</h2>
    <p className={`text-xs max-w-md ${isDark ? "text-gray-400" : "text-gray-500"}`}>
      Receipts that could not be verified against the Cashier system will be
      listed here, with the likely contributing factors for each failure.
    </p>
  </div>
);

export default CashierReconciliation;
