import React from "react";
import { useParams, useSearchParams } from "react-router-dom";
import { ChatBubbleLeftRightIcon } from "@heroicons/react/24/outline";
import { useTheme } from "../context/ThemeContext";
import { useAuth } from "../context/AuthProvider";
import { MODULE_KEYS, hasModuleAction } from "../utils/policy";
import { isTicketsFeatureEnabled } from "../utils/ticketHelpers";
import StaffTicketQueuePanel from "../components/tickets/StaffTicketQueuePanel.jsx";
import StaffTicketDetailPanel from "../components/tickets/StaffTicketDetailPanel.jsx";
import TicketCategoriesAdminPanel from "../components/tickets/TicketCategoriesAdminPanel.jsx";
import TicketBlocksAdminPanel from "../components/tickets/TicketBlocksAdminPanel.jsx";
import TicketReportsAdminPanel from "../components/tickets/TicketReportsAdminPanel.jsx";

const ALL_TABS = [
  { key: "queue", label: "Ticket Queue", manageOnly: false },
  { key: "categories", label: "Categories", manageOnly: true },
  { key: "blocks", label: "Spam & Blocklist", manageOnly: true },
  { key: "reports", label: "Reports & Audit Export", manageOnly: true },
];

const StaffTicketsPage = () => {
  const { isDark } = useTheme();
  const { user } = useAuth();
  const { ticketId } = useParams();
  const [searchParams, setSearchParams] = useSearchParams();

  const featureOn = isTicketsFeatureEnabled(user);
  const canManage = hasModuleAction(user, MODULE_KEYS.TICKETS, "Manage");

  const baseTicketsPath =
    user?.role_name === "super_admin" ? "/super-admin/tickets" : "/staff/tickets";

  if (!featureOn) {
    return (
      <div
        className={`rounded-2xl p-8 sm:p-12 flex flex-col items-center text-center border ${
          isDark
            ? "bg-[#242526] text-[#e4e6eb] border-[#3e4042]"
            : "bg-white text-gray-900 shadow-md border-gray-200/80"
        }`}
      >
        <div
          className={`w-14 h-14 rounded-full flex items-center justify-center mb-3 ${
            isDark ? "bg-zinc-800 text-gray-400" : "bg-gray-100 text-gray-400"
          }`}
        >
          <ChatBubbleLeftRightIcon className="w-7 h-7" />
        </div>
        <h2 className="text-base font-bold mb-1">
          Support Ticketing is currently disabled
        </h2>
        <p
          className={`text-xs max-w-md ${
            isDark ? "text-gray-400" : "text-gray-500"
          }`}
        >
          Enable <code className="font-mono">FEATURE_TICKETS=true</code> on the
          backend to activate the Registrar Support Ticketing workspace.
        </p>
      </div>
    );
  }

  // Detail route (/staff/tickets/:ticketId or /super-admin/tickets/:ticketId)
  if (ticketId) {
    return (
      <div className={`font-sans ${isDark ? "text-[#e4e6eb]" : ""}`}>
        <StaffTicketDetailPanel
          ticketId={ticketId}
          baseTicketsPath={baseTicketsPath}
        />
      </div>
    );
  }

  const visibleTabs = ALL_TABS.filter((t) => !t.manageOnly || canManage);
  const tabFromUrl = searchParams.get("tab");
  const activeTab = visibleTabs.some((t) => t.key === tabFromUrl)
    ? tabFromUrl
    : "queue";

  const handleTabChange = (tabKey) => {
    setSearchParams({ tab: tabKey });
  };

  return (
    <div className={`font-sans ${isDark ? "text-[#e4e6eb]" : ""}`}>
      {visibleTabs.length > 1 && (
        <>
          {/* Desktop pill tab bar (matches CashierReconciliation.jsx) */}
          <div className="hidden md:flex justify-center mx-4 sm:mx-6 mb-5">
            <div
              className={`inline-flex px-8 py-3.5 rounded-full transition-all duration-300 hover:-translate-y-0.5 ${
                isDark
                  ? "bg-[#242526] border border-[#3e4042] shadow-[0_2px_8px_rgba(0,0,0,0.2)] hover:shadow-[0_4px_16px_rgba(0,0,0,0.35)]"
                  : "bg-white border border-gray-200/80 shadow-[0_2px_8px_rgba(0,0,0,0.05)] hover:shadow-[0_4px_16px_rgba(0,0,0,0.1)]"
              } gap-8 items-center`}
            >
              {visibleTabs.map((tab) => (
                <button
                  key={tab.key}
                  type="button"
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

          {/* Mobile compact tab selector */}
          <div className="flex md:hidden overflow-x-auto gap-2 pb-3 mb-2">
            {visibleTabs.map((tab) => (
              <button
                key={tab.key}
                type="button"
                onClick={() => handleTabChange(tab.key)}
                className={`px-3.5 py-2 rounded-xl text-xs font-bold whitespace-nowrap border shrink-0 cursor-pointer ${
                  activeTab === tab.key
                    ? isDark
                      ? "bg-yellow-400 text-black border-yellow-400"
                      : "bg-pup-dark-maroon text-white border-pup-dark-maroon"
                    : isDark
                      ? "bg-[#242526] text-[#b0b3b8] border-[#3e4042]"
                      : "bg-white text-gray-600 border-gray-200"
                }`}
              >
                {tab.label}
              </button>
            ))}
          </div>
        </>
      )}

      {activeTab === "queue" && (
        <StaffTicketQueuePanel baseTicketsPath={baseTicketsPath} />
      )}
      {activeTab === "categories" && canManage && <TicketCategoriesAdminPanel />}
      {activeTab === "blocks" && canManage && <TicketBlocksAdminPanel />}
      {activeTab === "reports" && canManage && <TicketReportsAdminPanel />}
    </div>
  );
};

export default StaffTicketsPage;

