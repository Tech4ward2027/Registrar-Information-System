import React, { useState } from 'react';
import StaffDashboard from '../layouts/StaffDashboard.jsx';
import ClaimScannerModal from '../components/ClaimScannerModal.jsx';
import { useTheme } from '../context/ThemeContext';
import { QueueListIcon, ArchiveBoxIcon, QrCodeIcon } from '@heroicons/react/24/outline';

const StaffDashboardPage = () => {
  const [activeTab, setActiveTab] = useState('active'); // 'active' | 'archived'
  const [scannerOpen, setScannerOpen] = useState(false);
  const { isDark } = useTheme();

  return (
    <div className="max-w-7xl mx-auto px-3 sm:px-5 mb-6">
      <div className={`rounded-2xl p-4 sm:p-5 shadow-sm border ${
        isDark ? 'bg-[#242526] border-[#3e4042] text-[#e4e6eb]' : 'bg-white border-gray-200 text-gray-900'
      }`}>
        {/* Tab Navigation */}
        <div className={`flex justify-between items-center border-b mb-4 ${isDark ? 'border-[#3e4042]' : 'border-gray-200'}`}>
          <div className="flex">
            <button
              onClick={() => setActiveTab('active')}
              className={`px-4 py-2 font-semibold text-xs sm:text-sm transition-all relative border-b-2 -mb-0.5 focus:outline-none flex items-center gap-1.5 ${
                activeTab === 'active'
                  ? isDark
                    ? 'text-white border-white font-bold'
                    : 'text-gray-950 border-gray-955 font-bold'
                  : isDark
                  ? 'text-[#b0b3b8] border-transparent hover:text-white'
                  : 'text-gray-500 border-transparent hover:text-gray-900'
              }`}
            >
              <QueueListIcon className="w-4 h-4" />
              <span>Active requests</span>
            </button>
            <button
              onClick={() => setActiveTab('archived')}
              className={`px-4 py-2 font-semibold text-xs sm:text-sm transition-all relative border-b-2 -mb-0.5 focus:outline-none flex items-center gap-1.5 ${
                activeTab === 'archived'
                  ? isDark
                    ? 'text-white border-white font-bold'
                    : 'text-gray-950 border-gray-955 font-bold'
                  : isDark
                  ? 'text-[#b0b3b8] border-transparent hover:text-white'
                  : 'text-gray-500 border-transparent hover:text-gray-900'
              }`}
            >
              <ArchiveBoxIcon className="w-4 h-4" />
              <span>Archived records</span>
            </button>
          </div>
        </div>

        {/* Dashboard View */}
        <StaffDashboard 
          viewMode={activeTab} 
          isEmbedded={true} 
          onScanToClaim={() => setScannerOpen(true)} 
        />
      </div>

      <ClaimScannerModal open={scannerOpen} onClose={() => setScannerOpen(false)} />
    </div>
  );
};

export default StaffDashboardPage;