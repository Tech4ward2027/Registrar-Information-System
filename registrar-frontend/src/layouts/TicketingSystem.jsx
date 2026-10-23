import React, { useState, useMemo } from 'react';
import {
  ChevronRightIcon as ChevronRightOutlineIcon,
  CheckCircleIcon,
  ArrowUturnLeftIcon,
} from '@heroicons/react/24/outline';
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/react/24/solid';
import { useTickets } from '../context/TicketContext';
import { useAuth } from '../context/AuthProvider';
import { useTheme } from '../context/ThemeContext';
import VoiceSearchInput from '../components/VoiceSearchInput.jsx';
import CreateTicketForm from '../components/tickets/CreateTicketForm';
import TicketDetailModal from '../components/tickets/TicketDetailModal';

const TicketingSystem = () => {
  const { isDark } = useTheme();
  const { user } = useAuth();
  const { tickets } = useTickets();

  const [activeView, setActiveView] = useState('list'); // 'list' | 'create'
  const [activeTab, setActiveTab] = useState('All'); // 'All' | 'Open' | 'Needs your reply' | 'Resolved'
  const [search, setSearch] = useState('');
  const [currentPage, setCurrentPage] = useState(1);
  const [selectedTicket, setSelectedTicket] = useState(null);

  const isStaff = user?.role_name === 'admin' || user?.role_name === 'super_admin';

  // System theme tokens matching Cashier OR Overrides & StudentPage
  const cardClasses = isDark ? 'bg-[#18191a] text-[#e4e6eb]' : 'bg-white text-gray-900';
  const rowBorder = isDark ? 'border-[#3e4042]' : 'border-gray-200';
  const subtleText = isDark ? 'text-[#b0b3b8]' : 'text-gray-500';
  const activeTabClasses = isDark ? 'bg-yellow-400 text-gray-900' : 'bg-pup-dark-maroon text-white';

  // Filter scoped tickets: Students only see their own tickets
  const scopedTickets = useMemo(() => {
    if (isStaff) {
      return tickets;
    }
    return tickets.filter(
      (t) =>
        t.creator?.id === user?.user_id ||
        t.creator?.email === user?.email ||
        !t.creator?.id
    );
  }, [tickets, user, isStaff]);

  // Tab counts
  const counts = useMemo(() => {
    return {
      All: scopedTickets.length,
      Open: scopedTickets.filter((t) => t.status === 'Open' || t.status === 'In Progress').length,
      'Needs your reply': scopedTickets.filter((t) => t.status === 'Waiting for Student').length,
      Resolved: scopedTickets.filter((t) => t.status === 'Resolved' || t.status === 'Closed').length,
    };
  }, [scopedTickets]);

  // Filtered tickets based on activeTab & search query
  const filteredTickets = useMemo(() => {
    return scopedTickets.filter((t) => {
      // Tab matching
      if (activeTab === 'Open' && t.status !== 'Open' && t.status !== 'In Progress') {
        return false;
      }
      if (activeTab === 'Needs your reply' && t.status !== 'Waiting for Student') {
        return false;
      }
      if (activeTab === 'Resolved' && t.status !== 'Resolved' && t.status !== 'Closed') {
        return false;
      }

      // Search matching
      const q = search.trim().toLowerCase();
      if (q) {
        const numMatch = String(t.ticket_number || '').toLowerCase().includes(q);
        const subjMatch = String(t.subject || '').toLowerCase().includes(q);
        const catMatch = String(t.category || '').toLowerCase().includes(q);
        if (!numMatch && !subjMatch && !catMatch) return false;
      }
      return true;
    });
  }, [scopedTickets, activeTab, search]);

  const PER_PAGE = 10;
  const totalPages = Math.max(1, Math.ceil(filteredTickets.length / PER_PAGE));
  const safePage = Math.min(currentPage, totalPages);
  const indexOfFirstItem = (safePage - 1) * PER_PAGE;
  const indexOfLastItem = indexOfFirstItem + PER_PAGE;
  const paginatedTickets = filteredTickets.slice(indexOfFirstItem, indexOfLastItem);

  const handleNextPage = () => safePage < totalPages && setCurrentPage((prev) => prev + 1);
  const handlePrevPage = () => safePage > 1 && setCurrentPage((prev) => prev - 1);

  // Short Date Formatter: "Feb 3"
  const formatShortDate = (dateStr) => {
    if (!dateStr) return '—';
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
    });
  };

  // Status Indicator rendering matching screenshot
  const renderStatus = (ticket) => {
    const isNeedsReply = ticket.status === 'Waiting for Student';
    const isResolved = ticket.status === 'Resolved' || ticket.status === 'Closed';

    if (isNeedsReply) {
      return (
        <span className="inline-flex items-center gap-1.5 text-xs font-semibold text-pup-maroon dark:text-[#E57373]">
          <ArrowUturnLeftIcon className="w-3.5 h-3.5" />
          <span>Reply needed</span>
        </span>
      );
    }

    if (isResolved) {
      return (
        <span className="inline-flex items-center gap-1.5 text-xs font-semibold text-[#2E7D32] dark:text-green-400">
          <CheckCircleIcon className="w-4 h-4" />
          <span>Resolved</span>
        </span>
      );
    }

    // Default 'Open' / 'In Progress'
    return (
      <span className="inline-flex items-center gap-2 text-xs font-medium text-gray-700 dark:text-gray-300">
        <span className="w-2 h-2 rounded-full bg-[#1E60D5]" />
        <span>Open</span>
      </span>
    );
  };

  return (
    <div className={`font-sans rounded-2xl p-4 sm:px-6 w-full ${cardClasses}`}>
      {activeView === 'create' ? (
        /* Create Form View */
        <div>
          <CreateTicketForm
            onCancel={() => setActiveView('list')}
            onSuccess={(created) => {
              setActiveView('list');
              setSelectedTicket(created);
            }}
          />
        </div>
      ) : (
        /* Streamlined List View styled with RIS theme */
        <div className="space-y-4 sm:space-y-5">
          {/* 1. Header with single "New ticket" button */}
          <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 py-4 mb-1">
            <div>
              <h1 className="text-xl sm:text-2xl font-bold tracking-tight">
                My tickets
              </h1>
              <p className={`text-xs mt-1 ${subtleText}`}>
                Track your concerns and issues.
              </p>
            </div>

            {/* Single New Ticket Button matching system button style */}
            <button
              type="button"
              onClick={() => setActiveView('create')}
              className={`text-sm font-bold px-4 py-2.5 rounded-full transition-all cursor-pointer shadow shrink-0 ${
                isDark
                  ? 'bg-yellow-400 text-gray-900 hover:bg-yellow-300'
                  : 'bg-pup-dark-maroon text-white hover:bg-[#3a0303]'
              }`}
            >
              + New ticket
            </button>
          </div>

          {/* 2. Reusable VoiceSearchInput Field with clean compact width */}
          <div className="w-full sm:max-w-sm">
            <VoiceSearchInput
              value={search}
              onChange={(val) => {
                setSearch(val);
                setCurrentPage(1);
              }}
              placeholder="Search by ticket #, subject, or category"
              language="en-US"
            />
          </div>

          {/* 3. Underline Tabs (Scrollable on small mobile screens without scrollbar arrows) */}
          <div className={`flex items-center gap-4 sm:gap-6 border-b text-xs sm:text-sm font-semibold overflow-x-auto no-scrollbar pt-1 ${
            isDark ? 'border-[#3e4042]' : 'border-gray-200'
          }`}>
            {[
              { key: 'All', label: 'All', count: counts.All },
              { key: 'Open', label: 'Open', count: counts.Open },
              { key: 'Needs your reply', label: 'Needs your reply', count: counts['Needs your reply'] },
              { key: 'Resolved', label: 'Resolved', count: counts.Resolved },
            ].map((tab) => {
              const isActive = activeTab === tab.key;
              const isNeedsReplyTab = tab.key === 'Needs your reply';

              return (
                <button
                  key={tab.key}
                  type="button"
                  onClick={() => {
                    setActiveTab(tab.key);
                    setCurrentPage(1);
                  }}
                  className={`pb-2.5 -mb-px border-b-2 flex items-center gap-1.5 transition whitespace-nowrap cursor-pointer shrink-0 ${
                    isActive
                      ? isDark
                        ? 'border-yellow-400 text-yellow-400 font-bold'
                        : 'border-pup-dark-maroon text-pup-dark-maroon font-bold'
                      : isDark
                      ? 'border-transparent text-[#b0b3b8] hover:text-white'
                      : 'border-transparent text-gray-500 hover:text-gray-900'
                  }`}
                >
                  <span>{tab.label}</span>
                  {isNeedsReplyTab && tab.count > 0 ? (
                    <span className="px-1.5 py-0.2 rounded-full text-[11px] font-bold bg-[#8B0000]/10 text-pup-maroon border border-[#8B0000]/25 dark:bg-[#660000]/40 dark:text-[#E57373] dark:border-[#8B0000]/60">
                      {tab.count}
                    </span>
                  ) : (
                    <span className="font-normal text-xs text-gray-400">
                      {tab.count}
                    </span>
                  )}
                </button>
              );
            })}
          </div>

          {/* 4. Streamlined Clickable Row List (Without high priority column, fully mobile-responsive) */}
          <div className={`rounded-xl border ${rowBorder} divide-y ${isDark ? 'divide-[#3e4042]' : 'divide-gray-100'} overflow-hidden`}>
            {paginatedTickets.length === 0 ? (
              <div className="py-14 text-center">
                <p className="font-semibold text-sm">No tickets found</p>
                <p className={`text-xs mt-1 ${subtleText}`}>
                  {search ? 'Try clearing your search terms.' : 'No tickets in this section.'}
                </p>
              </div>
            ) : (
              paginatedTickets.map((t) => {
                const isNeedsReply = t.status === 'Waiting for Student';
                const isResolved = t.status === 'Resolved' || t.status === 'Closed';

                return (
                  <div
                    key={t.id}
                    onClick={() => setSelectedTicket(t)}
                    className={`flex items-center justify-between py-3.5 px-3 sm:px-5 gap-3 transition cursor-pointer ${
                      isNeedsReply
                        ? isDark
                          ? 'bg-[#660000]/15 border-l-4 border-l-[#8B0000]'
                          : 'bg-[#8B0000]/5 border-l-4 border-l-[#8B0000]'
                        : isDark
                        ? 'hover:bg-[#252728]'
                        : 'hover:bg-gray-50/80'
                    } ${isResolved ? 'opacity-60 hover:opacity-100' : ''}`}
                  >
                    {/* Left side: Subject, Ticket number and Category */}
                    <div className="min-w-0 flex-1 pr-2">
                      <h3 className="font-semibold text-xs sm:text-sm truncate">
                        {t.subject}
                      </h3>
                      <p className={`text-[11px] sm:text-xs mt-0.5 ${subtleText}`}>
                        {t.ticket_number} • {t.category}
                      </p>
                    </div>

                    {/* Right side: Status, Short date, Chevron */}
                    <div className="flex items-center gap-4 sm:gap-8 shrink-0">
                      {/* Status indicator */}
                      <div className="w-26 sm:w-28 text-left">
                        {renderStatus(t)}
                      </div>

                      {/* Short Date: "Feb 3" */}
                      <span className={`text-xs font-normal whitespace-nowrap ${subtleText}`}>
                        {formatShortDate(t.created_at)}
                      </span>

                      {/* Chevron Icon */}
                      <ChevronRightOutlineIcon className={`w-4 h-4 shrink-0 ${isDark ? 'text-gray-500' : 'text-gray-400'}`} />
                    </div>
                  </div>
                );
              })
            )}
          </div>

          {/* 5. Paginated Footer matching Student Dashboard */}
          {filteredTickets.length > 0 && (
            <div
              className={`px-4 sm:px-6 py-4 text-[11px] sm:text-sm flex flex-col sm:flex-row justify-between items-center gap-4 print:hidden border-t ${
                isDark ? 'bg-[#18191a] text-[#b0b3b8] border-[#3e4042]' : 'bg-gray-50 text-gray-500 border-gray-200'
              } rounded-b-xl -mx-4 -mb-4 sm:-mx-6 sm:-mb-5 mt-4`}
            >
              <span className="text-center sm:text-left">
                Showing {filteredTickets.length > 0 ? indexOfFirstItem + 1 : 0} to{' '}
                {Math.min(indexOfLastItem, filteredTickets.length)} of {filteredTickets.length} results
              </span>

              <div className="flex gap-4 items-center">
                <button
                  type="button"
                  onClick={handlePrevPage}
                  disabled={safePage === 1}
                  className={`p-1 rounded transition-colors ${
                    safePage === 1
                      ? isDark
                        ? 'text-[#4e4f50] cursor-not-allowed'
                        : 'text-gray-300 cursor-not-allowed'
                      : isDark
                      ? 'text-[#b0b3b8] hover:bg-[#3a3b3c] cursor-pointer'
                      : 'text-gray-600 hover:bg-gray-200 cursor-pointer'
                  }`}
                >
                  <ChevronLeftIcon className="w-5 h-5" />
                </button>

                <span className={`text-xs font-semibold whitespace-nowrap ${isDark ? 'text-[#e4e6eb]' : 'text-gray-700'}`}>
                  Page {safePage} of {totalPages}
                </span>

                <button
                  type="button"
                  onClick={handleNextPage}
                  disabled={safePage === totalPages || totalPages === 0}
                  className={`p-1 rounded transition-colors ${
                    safePage === totalPages || totalPages === 0
                      ? isDark
                        ? 'text-[#4e4f50] cursor-not-allowed'
                        : 'text-gray-300 cursor-not-allowed'
                      : isDark
                      ? 'text-[#b0b3b8] hover:bg-[#3a3b3c] cursor-pointer'
                      : 'text-gray-600 hover:bg-gray-200 cursor-pointer'
                  }`}
                >
                  <ChevronRightIcon className="w-5 h-5" />
                </button>
              </div>
            </div>
          )}
        </div>
      )}

      {/* Ticket Details Modal */}
      {selectedTicket && (
        <TicketDetailModal
          ticket={selectedTicket}
          isOpen={!!selectedTicket}
          onClose={() => setSelectedTicket(null)}
        />
      )}
    </div>
  );
};

export default TicketingSystem;
