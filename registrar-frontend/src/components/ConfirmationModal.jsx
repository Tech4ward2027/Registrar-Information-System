import React from 'react';
import { ExclamationTriangleIcon, ArrowRightStartOnRectangleIcon, QuestionMarkCircleIcon } from '@heroicons/react/24/outline';
import { useTheme } from "../context/ThemeContext";

const ConfirmationModal = ({
  isOpen,
  onClose,
  onConfirm,
  title,
  message,
  type = 'default',
  confirmText,
  confirmLabel,
  buttonLabel,
  isDark: isDarkProp,
}) => {
  const { isDark: themeIsDark } = useTheme();
  const isDark = typeof isDarkProp === 'boolean' ? isDarkProp : themeIsDark;

  if (!isOpen) return null;

  const isDanger   = type === 'danger';
  const isConfirm  = type === 'confirm';

  const iconClass = isDanger
    ? (isDark ? 'bg-red-900/20 text-red-300' : 'bg-red-100 text-red-600')
    : isConfirm
      ? (isDark ? 'bg-yellow-900/20 text-yellow-300' : 'bg-yellow-100 text-yellow-600')
      : (isDark ? 'bg-blue-900/20 text-blue-300' : 'bg-blue-100 text-blue-600');
  const btnClass  = isDanger ? 'bg-red-600 hover:bg-red-700' : isConfirm ? 'bg-[#800000] hover:bg-[#3a0303]' : 'bg-blue-600 hover:bg-blue-700';
  const btnLabel  = confirmText || confirmLabel || buttonLabel || (isDanger ? 'Delete' : isConfirm ? 'Confirm' : 'Logout');

  return (
    <div className="fixed inset-0 z-9999 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
      <div className={`w-100 h-80 rounded-2xl shadow-2xl flex flex-col items-center justify-between p-7 animate-in fade-in zoom-in duration-200 ${isDark ? 'bg-[#242526] border border-[#3e4042] text-[#e4e6eb]' : 'bg-white'}`}>

        <div className={`p-4 rounded-full ${iconClass}`}>
          {isDanger ? (
            <ExclamationTriangleIcon className="w-8 h-8" />
          ) : isConfirm ? (
            <QuestionMarkCircleIcon className="w-8 h-8" />
          ) : (
            <ArrowRightStartOnRectangleIcon className="w-8 h-8" />
          )}
        </div>

        <div className="text-center space-y-2">
          <h3 className={`text-lg font-bold leading-tight ${isDark ? 'text-[#e4e6eb]' : 'text-gray-900'}`}>{title}</h3>
          <p className={`text-sm font-medium ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>{message}</p>
        </div>

        <div className="flex gap-3 w-full">
          <button
            onClick={onClose}
            className={`flex-1 px-4 py-2 text-sm font-semibold rounded-lg transition-colors ${isDark ? 'text-[#e4e6eb] bg-[#3a3b3c] hover:bg-[#4e4f50] border border-[#4e4f50]' : 'text-gray-700 bg-gray-100 hover:bg-gray-200'}`}
          >
            Cancel
          </button>
          <button
            onClick={() => { onConfirm(); onClose(); }}
            className={`flex-1 px-4 py-2 text-sm font-semibold text-white rounded-lg shadow-md transition-colors ${btnClass}`}
          >
            {btnLabel}
          </button>
        </div>
      </div>
    </div>
  );
};

export default ConfirmationModal;
