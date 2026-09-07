import React from 'react';
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/react/24/solid';
import { useTheme } from '../context/ThemeContext';

export const StatCard = ({ title, count, color }) => {
  const { isDark } = useTheme();
  const colors = {
    yellow: isDark ? 'border-yellow-400 text-yellow-400' : 'border-yellow-400 text-yellow-500',
    blue: isDark ? 'border-blue-400 text-blue-400' : 'border-blue-500 text-blue-500',
    green: isDark ? 'border-green-400 text-green-400' : 'border-green-500 text-green-500',
    orange: isDark ? 'border-orange-400 text-orange-400' : 'border-orange-500 text-orange-500',
    amber: isDark ? 'border-amber-400 text-amber-400' : 'border-amber-500 text-amber-600',
  };
  return (
    <div className={`p-6 rounded-xl shadow border-l-4 ${isDark ? 'bg-[#242526] border-[#3e4042]' : 'bg-white'} ${colors[color]}`}>
      <div className={`text-xs uppercase font-bold ${isDark ? 'text-[#b0b3b8]' : 'text-gray-400'}`}>{title}</div>
      <div className={`text-3xl font-extrabold mt-1 ${isDark ? 'text-[#e4e6eb]' : 'text-inherit'}`}>{count}</div>
    </div>
  );
};

export const Th = ({ children, center }) => {
  const { isDark } = useTheme();
  return (
    <th className={`px-6 py-4 text-xs uppercase font-bold ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'} ${center ? 'text-center' : 'text-left'}`}>
      {children}
    </th>
  );
};

export const Td = ({ children, center }) => {
  const { isDark } = useTheme();
  return (
    <td className={`px-6 py-4 text-sm ${isDark ? 'text-[#e4e6eb]' : 'text-inherit'} ${center ? 'text-center' : 'text-left'}`}>
      {children}
    </td>
  );
};

export const StatusBadge = ({ status }) => {
  const { isDark } = useTheme();
  const normalizedStatus = String(status ?? '').trim().toLowerCase();
  const styles = isDark
    ? {
        pending: 'bg-yellow-900/20 text-yellow-400 border-yellow-600',
        processing: 'bg-blue-900/20 text-blue-400 border-blue-600',
        'awaiting submission': 'bg-purple-900/20 text-purple-400 border-purple-600',
        'pending signature': 'bg-amber-900/20 text-amber-400 border-amber-600',
        'ready to claim': 'bg-green-900/20 text-green-400 border-green-600',
        completed: 'bg-gray-700/20 text-gray-300 border-gray-400',
        forfeited: 'bg-gray-700/20 text-gray-300 border-gray-400',
        cancelled: 'bg-gray-700/20 text-gray-300 border-gray-400',
        withdrawn: 'bg-red-900/20 text-red-300 border-red-500',
      }
    : {
        pending: 'bg-yellow-100 text-yellow-700 border-yellow-200',
        processing: 'bg-blue-100 text-blue-700 border-blue-200',
        'awaiting submission': 'bg-purple-100 text-purple-700 border-purple-200',
        'pending signature': 'bg-amber-100 text-amber-700 border-amber-200',
        'ready to claim': 'bg-green-100 text-green-700 border-green-200',
        completed: 'bg-gray-100 text-gray-700 border-gray-200',
        forfeited: 'bg-gray-100 text-gray-700 border-gray-200',
        cancelled: 'bg-gray-100 text-gray-700 border-gray-200',
        withdrawn: 'bg-red-100 text-red-700 border-red-200',
      };
  return (
    <span className={`px-3 py-1 rounded-full text-xs font-bold border whitespace-nowrap ${styles[normalizedStatus] ?? (isDark ? 'bg-gray-700/20 text-gray-300 border-gray-400' : 'bg-gray-100 text-gray-600')}`}>
      {status ?? 'Unknown'}
    </span>
  );
};

export const Pagination = ({
  filteredCount,
  indexOfFirstItem,
  indexOfLastItem,
  currentPage,
  totalPages,
  handlePrevPage,
  handleNextPage,
}) => {
  const { isDark } = useTheme();
  return (
    <div className={`sticky left-0 bottom-0 w-full px-4 sm:px-8 py-4 text-[11px] sm:text-sm flex flex-col sm:flex-row justify-between items-center gap-4 print:hidden border-t z-10 ${isDark ? 'bg-[#18191a] text-[#b0b3b8] border-[#3e4042]' : 'bg-gray-50 text-gray-500 border-gray-200'}`}>
      <span className="text-center sm:text-left whitespace-nowrap">
        Showing {filteredCount > 0 ? indexOfFirstItem + 1 : 0} to {Math.min(indexOfLastItem, filteredCount)} of {filteredCount} results
      </span>

      <div className="flex gap-4 items-center">
        <button
          onClick={handlePrevPage}
          disabled={currentPage === 1}
          className={`p-1 rounded transition-colors ${
            currentPage === 1
              ? (isDark ? 'text-[#4e4f50] cursor-not-allowed' : 'text-gray-300 cursor-not-allowed')
              : (isDark ? 'text-[#b0b3b8] hover:bg-[#3a3b3c]' : 'text-gray-600 hover:bg-gray-200')
          }`}
        >
          <ChevronLeftIcon className="w-4 sm:w-5 h-4 sm:h-5" />
        </button>

        <span className={`text-xs font-semibold whitespace-nowrap ${isDark ? 'text-[#e4e6eb]' : 'text-gray-700'}`}>
          Page {currentPage} of {totalPages}
        </span>

        <button
          onClick={handleNextPage}
          disabled={currentPage === totalPages || totalPages === 0}
          className={`p-1 rounded transition-colors ${
            currentPage === totalPages || totalPages === 0
              ? (isDark ? 'text-[#4e4f50] cursor-not-allowed' : 'text-gray-300 cursor-not-allowed')
              : (isDark ? 'text-[#b0b3b8] hover:bg-[#3a3b3c]' : 'text-gray-600 hover:bg-gray-200')
          }`}
        >
          <ChevronRightIcon className="w-4 sm:w-5 h-4 sm:h-5" />
        </button>
      </div>
    </div>
  );
};