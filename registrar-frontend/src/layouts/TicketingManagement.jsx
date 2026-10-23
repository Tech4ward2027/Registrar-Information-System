import React, { useState, useMemo, useRef } from 'react';
import {
  LifebuoyIcon,
  ArchiveBoxIcon,
  QueueListIcon,
  CheckCircleIcon,
  ChevronDownIcon,
  ChevronUpIcon,
  EyeIcon,
  EllipsisVerticalIcon,
  ShieldCheckIcon,
  ArrowPathIcon,
  ExclamationCircleIcon,
} from '@heroicons/react/24/outline';
import { useTickets } from '../context/TicketContext';
import { useTheme } from '../context/ThemeContext';
import { useAuth } from '../context/AuthProvider';
import VoiceSearchInput from '../components/VoiceSearchInput.jsx';
import DashboardDropdown from '../components/DashboardDropdown.jsx';
import { StatCard, Th, Td, Pagination } from '../components/StaffDashboardComponents';
import TicketDetailModal from '../components/tickets/TicketDetailModal';

const ITEMS_PER_PAGE = 15;

const TicketingManagement = () => {
  const { isDark } = useTheme();
  const { user } = useAuth();
  const { tickets, updateTicketStatus, claimActiveHandling, releaseActiveHandling } = useTickets();

  // Mode: 'active' (Open, In Progress, Waiting) vs 'archived' (Resolved, Closed)
  const [viewMode, setViewMode] = useState('active'); // 'active' | 'archived'
  const [searchTerm, setSearchTerm] = useState('');
  const [filterCategory, setFilterCategory] = useState('All');
  const [filterPriority, setFilterPriority] = useState('All');
  const [filterStatus, setFilterStatus] = useState('All');
  const [sortOrder, setSortOrder] = useState('Recent Inquiries');
  const [currentPage, setCurrentPage] = useState(1);
  const [selectedTicket, setSelectedTicket] = useState(null);

  // Dropdown refs & states for table header filters
  const [statusDropdownOpen, setStatusDropdownOpen] = useState(false);
  const [categoryDropdownOpen, setCategoryDropdownOpen] = useState(false);
  const [priorityDropdownOpen, setPriorityDropdownOpen] = useState(false);
  const statusDropdownRef = useRef(null);
  const categoryDropdownRef = useRef(null);
  const priorityDropdownRef = useRef(null);

  // Counts by status for top StatCards
  const statusCounts = useMemo(() => {
    return {
      Open: tickets.filter((t) => t.status === 'Open').length,
      'In Progress': tickets.filter((t) => t.status === 'In Progress').length,
      'Waiting for Student': tickets.filter((t) => t.status === 'Waiting for Student').length,
      Resolved: tickets.filter((t) => t.status === 'Resolved').length,
      Closed: tickets.filter((t) => t.status === 'Closed').length,
      ArchivedTotal: tickets.filter((t) => t.status === 'Resolved' || t.status === 'Closed').length,
    };
  }, [tickets]);

  // Categories list for dropdown
  const categoryOptions = useMemo(() => {
    const set = new Set(tickets.map((t) => t.category).filter(Boolean));
    return ['All', ...Array.from(set)];
  }, [tickets]);

  // Priority options
  const priorityOptions = ['All', 'Urgent', 'High', 'Normal', 'Low'];

  // Status options tailored to viewMode
  const statusOptions = useMemo(() => {
    if (viewMode === 'archived') {
      return ['All', 'Resolved', 'Closed'];
    }
    return ['All', 'Open', 'In Progress', 'Waiting for Student'];
  }, [viewMode]);

  // Filtered & Sorted items
  const filteredTickets = useMemo(() => {
    return tickets.filter((t) => {
      // 1. ViewMode separation
      const isArchivedStatus = t.status === 'Resolved' || t.status === 'Closed';
      if (viewMode === 'active' && isArchivedStatus) return false;
      if (viewMode === 'archived' && !isArchivedStatus) return false;

      // 2. Specific status filter
      if (filterStatus !== 'All' && t.status !== filterStatus) return false;

      // 3. Category filter
      if (filterCategory !== 'All' && t.category !== filterCategory) return false;

      // 4. Priority filter
      if (filterPriority !== 'All' && t.priority !== filterPriority) return false;

      // 5. Search term
      if (searchTerm.trim()) {
        const term = searchTerm.toLowerCase();
        const matchNum = t.ticket_number?.toLowerCase().includes(term);
        const matchSub = t.subject?.toLowerCase().includes(term);
        const matchName = t.creator?.name?.toLowerCase().includes(term);
        const matchEmail = t.creator?.email?.toLowerCase().includes(term);
        const matchStudentNum = t.creator?.student_number?.toLowerCase().includes(term);
        const matchCat = t.category?.toLowerCase().includes(term);
        if (!matchNum && !matchSub && !matchName && !matchEmail && !matchStudentNum && !matchCat) {
          return false;
        }
      }

      return true;
    }).sort((a, b) => {
      if (sortOrder === 'Oldest Inquiries') {
        return new Date(a.created_at) - new Date(b.created_at);
      }
      if (sortOrder === 'Priority (High to Low)') {
        const rank = { Urgent: 4, High: 3, Normal: 2, Low: 1 };
        return (rank[b.priority] || 0) - (rank[a.priority] || 0);
      }
      // Default: 'Recent Inquiries'
      return new Date(b.created_at) - new Date(a.created_at);
    });
  }, [tickets, viewMode, filterStatus, filterCategory, filterPriority, searchTerm, sortOrder]);

  const totalPages = Math.ceil(filteredTickets.length / ITEMS_PER_PAGE) || 1;
  const indexOfFirstItem = (currentPage - 1) * ITEMS_PER_PAGE;
  const indexOfLastItem = indexOfFirstItem + ITEMS_PER_PAGE;
  const currentItems = useMemo(() => {
    return filteredTickets.slice(indexOfFirstItem, indexOfLastItem);
  }, [filteredTickets, indexOfFirstItem, indexOfLastItem]);

  const handleNextPage = () => currentPage < totalPages && setCurrentPage((prev) => prev + 1);
  const handlePrevPage = () => currentPage > 1 && setCurrentPage((prev) => prev - 1);

  const clearAllFilters = () => {
    setFilterStatus('All');
    setFilterCategory('All');
    setFilterPriority('All');
    setSearchTerm('');
    setSortOrder('Recent Inquiries');
    setCurrentPage(1);
    setStatusDropdownOpen(false);
    setCategoryDropdownOpen(false);
    setPriorityDropdownOpen(false);
  };

  const hasActiveFilters =
    filterStatus !== 'All' ||
    filterCategory !== 'All' ||
    filterPriority !== 'All' ||
    searchTerm.trim() !== '' ||
    sortOrder !== 'Recent Inquiries';

  // Status Badge matching Staff Dashboard StatusBadge aesthetic
  const renderStatusBadge = (status) => {
    const styles = isDark
      ? {
          Open: 'bg-yellow-900/20 text-yellow-400 border-yellow-600',
          'In Progress': 'bg-blue-900/20 text-blue-400 border-blue-600',
          'Waiting for Student': 'bg-amber-900/20 text-amber-400 border-amber-600',
          Resolved: 'bg-green-900/20 text-green-400 border-green-600',
          Closed: 'bg-gray-700/20 text-gray-300 border-gray-400',
        }
      : {
          Open: 'bg-yellow-100 text-yellow-700 border-yellow-200',
          'In Progress': 'bg-blue-100 text-blue-700 border-blue-200',
          'Waiting for Student': 'bg-amber-100 text-amber-700 border-amber-200',
          Resolved: 'bg-green-100 text-green-700 border-green-200',
          Closed: 'bg-gray-100 text-gray-700 border-gray-200',
        };

    return (
      <span
        className={`inline-block px-2.5 py-0.5 rounded-full text-[11px] font-bold border whitespace-nowrap ${
          styles[status] || (isDark ? 'bg-gray-700/20 text-gray-300 border-gray-400' : 'bg-gray-100 text-gray-600')
        }`}
      >
        {status}
      </span>
    );
  };

  // Priority indicator matching exact Staff Dashboard status badge colors and rounded-full shape
  const renderPriorityBadge = (priority) => {
    const map = isDark
      ? {
          Urgent: 'bg-red-900/20 text-red-300 border-red-500',
          High: 'bg-yellow-900/20 text-yellow-400 border-yellow-600',
          Normal: 'bg-blue-900/20 text-blue-400 border-blue-600',
          Low: 'bg-gray-700/20 text-gray-300 border-gray-400',
        }
      : {
          Urgent: 'bg-red-100 text-red-700 border-red-200',
          High: 'bg-yellow-100 text-yellow-700 border-yellow-200',
          Normal: 'bg-blue-100 text-blue-700 border-blue-200',
          Low: 'bg-gray-100 text-gray-700 border-gray-200',
        };

    return (
      <span
        className={`inline-block px-2.5 py-0.5 rounded-full text-[11px] font-bold border whitespace-nowrap ${
          map[priority] || (isDark ? 'bg-blue-900/20 text-blue-400 border-blue-600' : 'bg-blue-100 text-blue-700 border-blue-200')
        }`}
      >
        {priority}
      </span>
    );
  };

  const formatDateTime = (dateStr) => {
    if (!dateStr) return '—';
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    });
  };

  return (
    <div className="max-w-7xl mx-auto px-3 sm:px-5 mb-6">
      {/* Outer Card matching Staff Dashboard layout container */}
      <div
        className={`rounded-2xl p-4 sm:p-5 shadow-sm border ${
          isDark ? 'bg-[#242526] border-[#3e4042] text-[#e4e6eb]' : 'bg-white border-gray-200 text-gray-900'
        }`}
      >
        {/* Tab Navigation (Active Inquiries vs Resolved / Archived) */}
        <div className={`flex justify-between items-center border-b mb-4 ${isDark ? 'border-[#3e4042]' : 'border-gray-200'}`}>
          <div className="flex">
            <button
              onClick={() => {
                setViewMode('active');
                setFilterStatus('All');
                setCurrentPage(1);
              }}
              className={`px-4 py-2 font-semibold text-xs sm:text-sm transition-all relative border-b-2 -mb-0.5 focus:outline-none flex items-center gap-1.5 cursor-pointer ${
                viewMode === 'active'
                  ? isDark
                    ? 'text-white border-white font-bold'
                    : 'text-gray-900 border-gray-900 font-bold'
                  : isDark
                  ? 'text-[#b0b3b8] border-transparent hover:text-white'
                  : 'text-gray-500 border-transparent hover:text-gray-900'
              }`}
            >
              <QueueListIcon className="w-4 h-4" />
              <span>Active Tickets</span>
            </button>

            <button
              onClick={() => {
                setViewMode('archived');
                setFilterStatus('All');
                setCurrentPage(1);
              }}
              className={`px-4 py-2 font-semibold text-xs sm:text-sm transition-all relative border-b-2 -mb-0.5 focus:outline-none flex items-center gap-1.5 cursor-pointer ${
                viewMode === 'archived'
                  ? isDark
                    ? 'text-white border-white font-bold'
                    : 'text-gray-900 border-gray-900 font-bold'
                  : isDark
                  ? 'text-[#b0b3b8] border-transparent hover:text-white'
                  : 'text-gray-500 border-transparent hover:text-gray-900'
              }`}
            >
              <ArchiveBoxIcon className="w-4 h-4" />
              <span>Resolved & Closed</span>
            </button>
          </div>
        </div>

        {/* ---------------- CARDS (Staff Dashboard StatCard style) ---------------- */}
        {viewMode === 'archived' ? (
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4 mb-4">
            <StatCard title="Resolved Inquiries" count={statusCounts['Resolved']} color="green" />
            <StatCard title="Closed Inquiries" count={statusCounts['Closed']} color="blue" />
          </div>
        ) : (
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4 mb-4">
            <StatCard title="New Inquiries" count={statusCounts['Open']} color="yellow" />
            <StatCard title="In Progress" count={statusCounts['In Progress']} color="blue" />
            <StatCard title="Awaiting Student Response" count={statusCounts['Waiting for Student']} color="amber" />
          </div>
        )}

        {/* ---------------- TOOLBAR (Staff Dashboard Toolbar style) ---------------- */}
        <div className="mb-4 flex flex-col md:flex-row gap-2.5 justify-between items-center w-full">
          <div className="w-full sm:max-w-sm">
            <VoiceSearchInput
              value={searchTerm}
              onChange={(val) => {
                setSearchTerm(val);
                setCurrentPage(1);
              }}
              placeholder="Search ticket #, subject, student name..."
              language="en-US"
            />
          </div>

          <div className="flex flex-wrap items-center gap-2 w-full md:w-auto justify-end">
            {hasActiveFilters && (
              <button
                type="button"
                onClick={clearAllFilters}
                className={`px-3 sm:px-4 py-2 rounded-lg text-xs sm:text-sm font-semibold transition-colors border shadow-xs flex items-center justify-center shrink-0 whitespace-nowrap cursor-pointer ${
                  isDark
                    ? 'bg-[#1f1f1f] text-[#b0b3b8] border-[#3e4042] hover:bg-[#2a2a2f] hover:text-[#e4e6eb]'
                    : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50 hover:text-gray-900'
                }`}
              >
                Clear Filters
              </button>
            )}
          </div>
        </div>

        {/* ---------------- TABLE CONTAINER ---------------- */}
        <div
          className={`overflow-x-auto rounded-xl border relative shadow-xs ${
            isDark ? 'border-[#3e4042] bg-[#18191a]' : 'border-gray-200 bg-white'
          }`}
        >
          <table className="w-full text-left border-collapse">
            <thead className={isDark ? 'bg-[#1f1f1f] border-b border-[#3e4042]' : 'bg-gray-50 border-b border-gray-200'}>
              <tr>
                <Th center>Ticket ID</Th>
                <Th>Requestor & Info</Th>
                <Th center>
                  <DashboardDropdown
                    isOpen={categoryDropdownOpen}
                    setIsOpen={setCategoryDropdownOpen}
                    dropdownRef={categoryDropdownRef}
                    align="center"
                    width="w-56"
                    trigger={<span>Category</span>}
                    sections={[
                      {
                        title: 'Filter by Category',
                        items: categoryOptions.map((opt) => ({
                          label: opt,
                          isSelected: filterCategory === opt,
                          onClick: () => {
                            setFilterCategory(opt);
                            setCurrentPage(1);
                          },
                        })),
                      },
                    ]}
                  />
                </Th>
                <Th>Subject</Th>
                <Th center>
                  <DashboardDropdown
                    isOpen={priorityDropdownOpen}
                    setIsOpen={setPriorityDropdownOpen}
                    dropdownRef={priorityDropdownRef}
                    align="center"
                    width="w-48"
                    trigger={<span>Priority</span>}
                    sections={[
                      {
                        title: 'Filter by Priority',
                        items: priorityOptions.map((opt) => ({
                          label: opt,
                          isSelected: filterPriority === opt,
                          onClick: () => {
                            setFilterPriority(opt);
                            setCurrentPage(1);
                          },
                        })),
                      },
                    ]}
                  />
                </Th>
                <Th center>Date</Th>
                <Th center>
                  <DashboardDropdown
                    isOpen={statusDropdownOpen}
                    setIsOpen={setStatusDropdownOpen}
                    dropdownRef={statusDropdownRef}
                    align="center"
                    width="w-52"
                    trigger={<span>Status</span>}
                    sections={[
                      {
                        title: 'Filter by Status',
                        items: statusOptions.map((opt) => ({
                          label: opt,
                          isSelected: filterStatus === opt,
                          onClick: () => {
                            setFilterStatus(opt);
                            setCurrentPage(1);
                          },
                        })),
                      },
                    ]}
                  />
                </Th>
                <th
                  className={`px-3 py-2.5 text-[11px] uppercase font-bold tracking-wider ${
                    isDark ? 'text-[#b0b3b8]' : 'text-gray-500'
                  } text-center w-28 min-w-28`}
                >
                  Actions
                </th>
              </tr>
            </thead>
            <tbody className={isDark ? 'divide-y divide-[#3e4042]' : 'divide-y divide-gray-100'}>
              {currentItems.length === 0 ? (
                <tr>
                  <td colSpan="8" className="px-6 py-16 text-center">
                    <div className="flex flex-col items-center justify-center gap-3">
                      <LifebuoyIcon
                        className={`w-12 h-12 stroke-[1.2] ${isDark ? 'text-gray-600' : 'text-gray-300'}`}
                      />
                      <div>
                        <div className={`text-base font-semibold ${isDark ? 'text-gray-300' : 'text-gray-600'}`}>
                          No tickets found
                        </div>
                        <div className={`text-xs mt-1 ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>
                          Try adjusting your search terms or active filters.
                        </div>
                      </div>
                    </div>
                  </td>
                </tr>
              ) : (
                currentItems.map((ticket) => (
                  <tr
                    key={ticket.id}
                    onClick={() => setSelectedTicket(ticket)}
                    className={`transition-colors cursor-pointer ${
                      isDark ? 'hover:bg-[#3a3b3c]' : 'hover:bg-gray-50'
                    }`}
                  >
                    <Td center>
                      <span className="font-bold text-xs text-pup-dark-maroon dark:text-yellow-400">
                        {ticket.ticket_number}
                      </span>
                    </Td>

                    <Td>
                      <div className="font-semibold text-xs">{ticket.creator?.name || 'Student Requestor'}</div>
                      <div className={`text-[11px] ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>
                        {ticket.creator?.student_number || ticket.creator?.email || 'N/A'}
                      </div>
                    </Td>

                    <Td center>
                      <span className="text-xs font-medium">{ticket.category}</span>
                    </Td>

                    <Td>
                      <div className="font-medium text-xs max-w-xs truncate" title={ticket.subject}>
                        {ticket.subject}
                      </div>
                    </Td>

                    <Td center>{renderPriorityBadge(ticket.priority)}</Td>

                    <Td center>
                      <span className={`text-[11px] whitespace-nowrap ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>
                        {formatDateTime(ticket.created_at)}
                      </span>
                    </Td>

                    <Td center>{renderStatusBadge(ticket.status)}</Td>

                    <td className="px-3 py-2 text-center">
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation();
                          setSelectedTicket(ticket);
                        }}
                        className={`inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-lg border transition ${
                          isDark
                            ? 'border-[#3e4042] hover:bg-[#2d2e2f] text-yellow-400'
                            : 'border-gray-200 hover:bg-gray-100 text-[#800000]'
                        }`}
                      >
                        <EyeIcon className="w-3.5 h-3.5" />
                        <span>Manage</span>
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>

          {/* Pagination bar matching Staff Dashboard pagination */}
          <Pagination
            filteredCount={filteredTickets.length}
            indexOfFirstItem={indexOfFirstItem}
            indexOfLastItem={indexOfLastItem}
            currentPage={currentPage}
            totalPages={totalPages}
            handlePrevPage={handlePrevPage}
            handleNextPage={handleNextPage}
          />
        </div>
      </div>

      {/* Ticket Details & Action Modal */}
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

export default TicketingManagement;
