import React, { useState, useEffect, useRef, useCallback } from 'react';
import { createPortal } from 'react-dom';
import loading1 from "../assets/Loading 1.png";
import loading2 from "../assets/Loading 2.png";
import loading3 from "../assets/Loading 3.png";
import useNetworkStatus from "../hooks/useNetworkStatus";
import ConnectionErrorCard from "./ConnectionErrorCard";

const FOLDER_IMGS = [loading1, loading2, loading3];
const FOLDER_TRANSFORMS = [
  "translate(56px, 0px)",
  "translate(28px, 28px)",
  "translate(0px, 56px)",
];

export const DelayedSkeleton = ({ children }) => {
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    const timer = setTimeout(() => {
      setVisible(true);
    }, 200);
    return () => clearTimeout(timer);
  }, []);

  if (!visible) return null;
  return children;
};

export const StatCardSkeleton = ({ isDark }) => {
  const bg = isDark ? 'bg-[#3a3b3c]' : 'bg-slate-100';
  const bgDim = isDark ? 'bg-[#2f3133]' : 'bg-slate-50';

  return (
    <DelayedSkeleton>
      <div className={`relative p-6 rounded-4xl border shadow-sm animate-pulse ${isDark ? 'bg-[#242526] border-[#3e4042]' : 'bg-white border-slate-200'}`}>
        <div className="flex justify-between items-start">
          <div className="space-y-3 w-full">
            <div className={`h-3 w-24 rounded-full ${bg}`} />
            <div className={`h-10 w-32 rounded-xl ${bg}`} />
          </div>
          <div className={`w-12 h-12 rounded-2xl ${bgDim}`} />
        </div>

        <div className="mt-6 flex items-center gap-2">
          <div className={`h-7 w-36 rounded-full ${bg}`} />
        </div>
      </div>
    </DelayedSkeleton>
  );
};

export const ChartCardSkeleton = ({ isDark }) => {
  const bg = isDark ? 'bg-[#3a3b3c]' : 'bg-slate-100';
  const bgDim = isDark ? 'bg-[#2f3133]' : 'bg-slate-50';

  return (
    <DelayedSkeleton>
      <div className={`border p-6 rounded-4xl shadow-sm min-w-0 animate-pulse ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-slate-200 bg-white'}`}>
        <div className="mb-4 space-y-2">
          <div className={`h-5 w-40 rounded-full ${bg}`} />
          <div className={`h-3 w-28 rounded-full ${bgDim}`} />
        </div>

        <div className="h-64 flex items-end gap-2">
          {Array.from({ length: 10 }).map((_, i) => (
            <div
              key={i}
              className={`flex-1 rounded-t-xl ${bg}`}
              style={{ height: `${25 + ((i * 9) % 55)}%` }}
            />
          ))}
        </div>
      </div>
    </DelayedSkeleton>
  );
};

export const LogbookSkeleton = ({ isDark }) => {
  const bg = isDark ? 'bg-[#3a3b3c]' : 'bg-slate-100';
  const bgDim = isDark ? 'bg-[#2f3133]' : 'bg-slate-50';
  const border = isDark ? 'border-[#3e4042]' : 'border-gray-200';

  const Cell = ({ w = 'w-20' }) => (
    <td className="p-3 sm:p-4 text-center whitespace-nowrap">
      <div className={`h-3 ${w} mx-auto rounded-full ${bg}`} />
    </td>
  );

  return (
    <DelayedSkeleton>
      <div className={`relative min-h-full font-sans text-left z-20 animate-pulse ${isDark ? 'bg-[#18191a] text-[#e4e6eb]' : 'bg-white text-gray-900'}`}>
        <div className={`max-w-350 mx-auto shadow-md rounded-sm flex flex-col min-h-150 print:p-0 print:shadow-none ${isDark ? 'bg-[#242526]' : 'bg-white'}`}>

          <div className="p-3 sm:p-4 md:p-4 pb-0">
            <div className="mb-4 grid grid-cols-1 gap-3 print:hidden lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-2">
                <div className={`h-10 w-full rounded-lg ${bg}`} />
                <div className={`h-10 w-full rounded-lg ${bgDim}`} />
              </div>
              <div className={`h-9 w-36 rounded-md ${bg}`} />
            </div>

            <div className={`w-full border-b pb-3 mb-0 ${border}`}>
              <div className={`h-6 w-64 max-w-full mx-auto rounded-full ${bg}`} />
              <div className={`h-3 w-56 max-w-full mx-auto rounded-full mt-2 ${bgDim}`} />
            </div>
          </div>

          <div className="flex-1 overflow-x-auto px-4 sm:px-6 md:px-6">
            <table className="w-full min-w-225 border-collapse md:min-w-full">
              <thead>
                <tr className={`border-b-2 ${border}`}>
                  {[14, 16, 12, 20, 14, 10, 14].map((w, i) => (
                    <th key={i} className="py-4 px-2">
                      <div className={`h-3 min-w-10 mx-auto rounded-full ${bgDim}`} style={{ width: `${w}%` }} />
                    </th>
                  ))}
                </tr>
              </thead>

              <tbody>
                {Array.from({ length: 8 }).map((_, i) => (
                  <tr key={i} className={`border-b ${border}`}>
                    <Cell w="w-28" />
                    <Cell w="w-36" />
                    <Cell w="w-24" />
                    <Cell w="w-40" />
                    <Cell w="w-28" />
                    <Cell w="w-20" />
                    <Cell w="w-24" />
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className={`px-4 sm:px-8 py-4 flex flex-col sm:flex-row justify-between items-center gap-4 border-t ${border}`}>
            <div className={`h-4 w-64 rounded-full ${bgDim}`} />
            <div className="flex gap-4 items-center">
              <div className={`w-6 h-6 rounded ${bg}`} />
              <div className={`h-4 w-24 rounded-full ${bgDim}`} />
              <div className={`w-6 h-6 rounded ${bg}`} />
            </div>
          </div>

        </div>
      </div>
    </DelayedSkeleton>
  );
};

export const InboxListSkeleton = ({ isDark, count = 6 }) => {
  const bg = isDark ? 'bg-[#3a3b3c]' : 'bg-slate-200';
  const bgDim = isDark ? 'bg-[#2f3133]' : 'bg-slate-100';

  return (
    <DelayedSkeleton>
      <>
        {Array.from({ length: count }).map((_, i) => (
          <div key={i} className={`w-full text-left px-4 py-3 border-b ${isDark ? 'border-[#3e4042]' : 'border-gray-200'} animate-pulse`}>
            <div className="flex items-center justify-between gap-2 mb-1">
              <div className={`h-4 w-1/2 rounded-full ${bg}`} />
              <div className={`h-3 w-12 rounded-full ${bgDim}`} />
            </div>
            <div className={`h-3 w-3/4 rounded-full mt-2.5 ${bgDim}`} />
            <div className={`h-3 w-full rounded-full mt-2 ${bg}`} />
            <div className={`h-3 w-5/6 rounded-full mt-1 ${bgDim}`} />
          </div>
        ))}
      </>
    </DelayedSkeleton>
  );
};

export const InboxPreviewSkeleton = ({ isDark }) => {
  const bg = isDark ? 'bg-[#3a3b3c]' : 'bg-slate-200';
  const bgDim = isDark ? 'bg-[#2f3133]' : 'bg-slate-100';

  return (
    <DelayedSkeleton>
      <div className={`flex flex-col h-full animate-pulse ${isDark ? 'bg-[#242526]' : 'bg-white'}`}>
        <header className={`px-4 md:px-6 py-4 border-b ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-gray-200 bg-white'}`}>
          <div className={`h-3 w-32 rounded-full mb-3 ${bgDim}`} />
          <div className={`h-6 w-3/4 rounded-lg mb-3 ${bg}`} />
          <div className={`h-4 w-40 rounded-full ${bgDim}`} />
        </header>

        <div className={`flex-1 p-4 md:p-6 space-y-4 ${isDark ? 'bg-[#1a1b1e]' : 'bg-gray-50'}`}>
          <div className={`rounded-lg border px-4 py-4 ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-gray-200 bg-white'}`}>
            <div className={`h-3 w-32 rounded-full mb-5 ${bgDim}`} />
            <div className="space-y-4">
              <div className={`h-4 w-1/3 rounded-full ${bg}`} />
              <div className={`h-4 w-1/4 rounded-full ${bg}`} />
              <div className={`h-4 w-full rounded-full ${bgDim}`} />
              <div className={`h-4 w-5/6 rounded-full ${bgDim}`} />
              <div className={`h-4 w-4/6 rounded-full ${bgDim}`} />
            </div>
          </div>
        </div>
      </div>
    </DelayedSkeleton>
  );
};

// Add this export to your existing LoadingSkeleton.jsx file

export const DocumentListSkeleton = ({ isDark, count = 8 }) => {
  const bg = isDark ? 'bg-[#3a3b3c]' : 'bg-slate-200';

  return (
    <DelayedSkeleton>
      <>
        {Array.from({ length: count }).map((_, i) => (
          <div
            key={i}
            className={`h-21 w-full border rounded-4xl flex items-center justify-between p-7 animate-pulse shadow-sm ${
              isDark ? 'bg-[#242526] border-[#3e4042]' : 'bg-white border-gray-200'
            }`}
          >
            {/* Title Placeholder */}
            <div className={`h-5 w-2/3 md:w-1/2 rounded-md ${bg}`} />
            {/* Chevron Placeholder */}
            <div className={`w-8 h-8 rounded-full shrink-0 ${bg}`} />
          </div>
        ))}
      </>
    </DelayedSkeleton>
  );
};

export const UserTableSkeleton = ({ isDark, count = 7 }) => {
  const bg = isDark ? 'bg-[#3a3b3c]' : 'bg-slate-200';
  const bgDim = isDark ? 'bg-[#2f3133]' : 'bg-slate-100';
  const borderColor = isDark ? 'border-[#3e4042]' : 'border-gray-50';

  return (
    <DelayedSkeleton>
      <>
        {Array.from({ length: count }).map((_, i) => (
          <tr key={i} className={`border-b text-center animate-pulse ${borderColor}`}>
            {/* Checkbox */}
            <td className="px-4 py-3">
              <div className={`w-4 h-4 mx-auto rounded ${bg}`} />
            </td>
            
            {/* Name */}
            <td className="px-4 py-3">
              <div className={`h-4 w-24 mx-auto rounded-md ${bg}`} />
            </td>
            
            {/* Email */}
            <td className="px-4 py-3">
              <div className={`h-4 w-32 mx-auto rounded-md ${bgDim}`} />
            </td>
            
            {/* Role Badge */}
            <td className="px-4 py-3">
              <div className={`h-6 w-20 mx-auto rounded-full ${bg}`} />
            </td>
            
            {/* Policy attached */}
            <td className="px-6 py-4">
              <div className={`h-6 w-24 mx-auto rounded-full ${bgDim}`} />
            </td>
            
            {/* Joined Date */}
            <td className="px-4 py-3">
              <div className={`h-4 w-20 mx-auto rounded-md ${bgDim}`} />
            </td>
            
            {/* Status Badge */}
            <td className="px-4 py-3">
              <div className={`h-6 w-20 mx-auto rounded-full ${bg}`} />
            </td>
            
            {/* Access */}
            <td className="px-6 py-4">
              <div className={`h-7 w-28 mx-auto rounded-full ${bg}`} />
            </td>
            
            {/* Actions */}
            <td className="px-4 py-3">
              <div className="flex items-center justify-center">
                <div className={`w-4 h-4 rounded ${bgDim}`} />
              </div>
            </td>
          </tr>
        ))}
      </>
    </DelayedSkeleton>
  );
};

export const ReportTableSkeleton = ({ isDark, count = 10 }) => {
  const bg = isDark ? 'bg-[#3a3b3c]' : 'bg-slate-200';
  const bgDim = isDark ? 'bg-[#2f3133]' : 'bg-slate-100';
  const borderColor = isDark ? 'border-[#3e4042]' : 'border-gray-50';

  return (
    <DelayedSkeleton>
      <>
        {Array.from({ length: count }).map((_, i) => (
          <tr key={i} className={`border-b text-center animate-pulse ${borderColor}`}>
            {/* Timestamp */}
            <td className="px-4 py-3">
              <div className={`h-3 w-28 mx-auto rounded-md ${bgDim}`} />
            </td>
            
            {/* User Email */}
            <td className="px-4 py-3">
              <div className={`h-4 w-40 mx-auto rounded-md ${bg}`} />
            </td>
            
            {/* Role Badge */}
            <td className="px-4 py-3">
              <div className={`h-6 w-20 mx-auto rounded-full ${bg}`} />
            </td>
            
            {/* Action Badge */}
            <td className="px-4 py-3">
              <div className={`h-6 w-24 mx-auto rounded-full ${bgDim}`} />
            </td>
            
            {/* Browser Info */}
            <td className="px-4 py-3">
              <div className={`h-3 w-32 mx-auto rounded-md ${bgDim}`} />
            </td>
          </tr>
        ))}
      </>
    </DelayedSkeleton>
  );
};

export const AnnouncementSkeleton = ({ isDark, count = 4 }) => {
  const bg = isDark ? 'bg-[#3a3b3c]' : 'bg-slate-200';
  const bgDim = isDark ? 'bg-[#2f3133]' : 'bg-slate-100';

  return (
    <DelayedSkeleton>
      <>
        {Array.from({ length: count }).map((_, i) => (
          <div key={i} className={`rounded-xl mt-3 px-4 py-3.5 shadow-sm animate-pulse ${isDark ? 'bg-[#1f1f1f] border border-[#3e4042]' : 'bg-white'}`}>
            <div className="flex items-center justify-between mb-2">
              <div className={`h-4 w-1/3 rounded-full ${bg}`} />
              <div className="flex items-center gap-2">
                <div className={`h-3 w-8 rounded-full ${bgDim}`} />
                <div className={`h-5 w-9 rounded-full ${bgDim}`} />
              </div>
            </div>
            <div className={`h-3 w-3/4 rounded-full mt-2 ${bgDim}`} />
            <div className={`h-3 w-1/2 rounded-full mt-1.5 ${bgDim}`} />
          </div>
        ))}
      </>
    </DelayedSkeleton>
  );
};

export const DocumentSkeleton = ({ isDark, count = 5 }) => {
  const baseBg = isDark ? 'bg-[#3a3b3c]' : 'bg-gray-300';
  const iconBg = isDark ? 'bg-[#3a3b3c]]' : 'bg-gray-300';

  return (
    <DelayedSkeleton>
      <>
        {Array.from({ length: count }).map((_, i) => (
          <div 
            key={i} 
            className="flex items-center justify-between px-3 py-4 mb-1 animate-pulse"
          >
            {/* Document Name Placeholder */}
            <div className={`h-4 w-40 rounded-md ${baseBg}`} />
            
            {/* Action Icons Placeholder */}
            <div className="flex gap-2">
              <div className={`w-4 h-4 rounded ${iconBg}`} />
              <div className={`w-4 h-4 rounded ${iconBg}`} />
            </div>
          </div>
        ))}
      </>
    </DelayedSkeleton>
  );
};

export const PolicyTableSkeleton = ({ isDark, count = 5 }) => {
  const bg = isDark ? 'bg-[#3a3b3c]' : 'bg-slate-200';
  const bgDim = isDark ? 'bg-[#2f3133]' : 'bg-slate-100';
  const borderColor = isDark ? 'border-[#3e4042]' : 'border-gray-50';

  return (
    <DelayedSkeleton>
      <>
        {Array.from({ length: count }).map((_, i) => (
          <tr key={i} className={`border-b text-left animate-pulse ${borderColor}`}>
            {/* Checkbox Select cell */}
            <td className="px-4 py-3 text-center">
              <div className={`w-4 h-4 mx-auto rounded ${bg}`} />
            </td>

            {/* Cube Block symbol */}
            <td className="px-2 py-3 text-center">
              <div className={`w-5 h-5 mx-auto rounded ${bg}`} />
            </td>

            {/* Policy name */}
            <td className="px-4 py-3">
              <div className={`h-4 w-40 rounded-md ${bg}`} />
            </td>

            {/* Type */}
            <td className="px-4 py-3">
              <div className={`h-4 w-16 rounded-md ${bgDim}`} />
            </td>

            {/* Assigned admins count link */}
            <td className="px-4 py-3">
              <div className={`h-4 w-32 rounded-md ${bg}`} />
            </td>

            {/* Description */}
            <td className="px-4 py-3">
              <div className={`h-4 w-60 rounded-md ${bgDim}`} />
            </td>
          </tr>
        ))}
      </>
    </DelayedSkeleton>
  );
};

export const AccessRequestsSkeleton = ({ isDark }) => {
  return (
    <DelayedSkeleton>
      <>
        {[1, 2, 3].map((n) => (
          <tr key={`skeleton-${n}`} className={`border-b last:border-0 ${isDark ? 'border-[#3e4042]' : 'border-gray-100'}`}>
            {/* # */}
            <td className="px-5 py-4 align-middle text-center">
              <div className={`h-4 w-4 mx-auto rounded-md animate-pulse ${isDark ? 'bg-[#3a3b3c]' : 'bg-gray-200'}`} />
            </td>
            {/* Target User */}
            <td className="px-5 py-4 align-top">
              <div className="space-y-2">
                <div className={`h-4 w-28 rounded-md animate-pulse ${isDark ? 'bg-[#3a3b3c]' : 'bg-gray-200'}`} />
                <div className={`h-3 w-36 rounded-md animate-pulse ${isDark ? 'bg-[#3a3b3c]' : 'bg-gray-200'}`} />
              </div>
            </td>
            {/* Requested Access */}
            <td className="px-5 py-4 align-top">
              <div className="space-y-2">
                <div className={`h-4 w-20 rounded-md animate-pulse ${isDark ? 'bg-[#3a3b3c]' : 'bg-gray-200'}`} />
                <div className={`h-3 w-24 rounded-md animate-pulse ${isDark ? 'bg-[#3a3b3c]' : 'bg-gray-200'}`} />
              </div>
            </td>
            {/* Justification & Requester */}
            <td className="px-5 py-4 align-top">
              <div className="space-y-2">
                <div className={`h-3.5 w-full max-w-[200px] rounded-md animate-pulse ${isDark ? 'bg-[#3a3b3c]' : 'bg-gray-200'}`} />
                <div className={`h-3 w-32 rounded-md animate-pulse ${isDark ? 'bg-[#3a3b3c]' : 'bg-gray-200'}`} />
              </div>
            </td>
            {/* Expiration Date */}
            <td className="px-5 py-4 align-middle text-center">
              <div className={`h-4 w-16 mx-auto rounded-md animate-pulse ${isDark ? 'bg-[#3a3b3c]' : 'bg-gray-200'}`} />
            </td>
            {/* Status */}
            <td className="px-5 py-4 align-middle text-center">
              <div className={`h-6 w-16 mx-auto rounded-full animate-pulse ${isDark ? 'bg-[#3a3b3c]' : 'bg-gray-200'}`} />
            </td>
            {/* Actions */}
            <td className="px-5 py-4 align-middle text-center">
              <div className="flex items-center justify-center gap-2">
                <div className={`h-8 w-20 rounded-lg animate-pulse ${isDark ? 'bg-[#3a3b3c]' : 'bg-gray-200'}`} />
                <div className={`h-8 w-20 rounded-lg animate-pulse ${isDark ? 'bg-[#3a3b3c]' : 'bg-gray-200'}`} />
              </div>
            </td>
          </tr>
        ))}
      </>
    </DelayedSkeleton>
  );
};

export const PageSkeleton = ({ isDark }) => {
  const bg = isDark ? 'bg-[#3a3b3c]/60' : 'bg-slate-200/60';
  const bgDim = isDark ? 'bg-[#2f3133]/40' : 'bg-slate-100/40';
  const border = isDark ? 'border-[#3e4042]/50' : 'border-slate-200/50';

  return (
    <div className="min-h-[calc(100vh-120px)] w-full p-4 sm:p-6 md:p-8 animate-pulse bg-transparent">
      <div className="max-w-7xl mx-auto space-y-6">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-dashed border-slate-300/40 dark:border-[#3e4042]/40">
          <div className="space-y-2">
            <div className={`h-8 w-48 sm:w-64 rounded-xl ${bg}`} />
            <div className={`h-4 w-72 sm:w-96 rounded-lg ${bgDim}`} />
          </div>
          <div className="flex gap-3">
            <div className={`h-10 w-28 rounded-xl ${bgDim}`} />
            <div className={`h-10 w-32 rounded-xl ${bg}`} />
          </div>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div className={`p-6 rounded-3xl border ${border} bg-transparent space-y-4`}>
            <div className={`h-5 w-32 rounded-lg ${bg}`} />
            <div className={`h-10 w-24 rounded-xl ${bgDim}`} />
            <div className={`h-3 w-full rounded-full ${bgDim}`} />
          </div>
          <div className={`p-6 rounded-3xl border ${border} bg-transparent space-y-4`}>
            <div className={`h-5 w-32 rounded-lg ${bg}`} />
            <div className={`h-10 w-24 rounded-xl ${bgDim}`} />
            <div className={`h-3 w-full rounded-full ${bgDim}`} />
          </div>
          <div className={`p-6 rounded-3xl border ${border} bg-transparent space-y-4`}>
            <div className={`h-5 w-32 rounded-lg ${bg}`} />
            <div className={`h-10 w-24 rounded-xl ${bgDim}`} />
            <div className={`h-3 w-full rounded-full ${bgDim}`} />
          </div>
        </div>

        <div className={`p-6 sm:p-8 rounded-3xl border ${border} bg-transparent space-y-5`}>
          <div className={`h-6 w-40 rounded-lg ${bg}`} />
          <div className="space-y-3">
            <div className={`h-4 w-full rounded-lg ${bgDim}`} />
            <div className={`h-4 w-5/6 rounded-lg ${bgDim}`} />
            <div className={`h-4 w-4/6 rounded-lg ${bgDim}`} />
          </div>
          <div className="pt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div className={`h-24 rounded-2xl ${bgDim}`} />
            <div className={`h-24 rounded-2xl ${bgDim}`} />
          </div>
        </div>
      </div>
    </div>
  );
};

export const FolderLoadingOverlay = ({ isDark, message = "Loading...", fullScreen = true }) => {
  const { isOnline, retry } = useNetworkStatus();
  const r0 = useRef(null);
  const r1 = useRef(null);
  const r2 = useRef(null);
  const refs = useRef([r0, r1, r2]);
  const timers = useRef([]);
  const chars = message.split("");

  const clearAll = useCallback(() => {
    timers.current.forEach(clearTimeout);
    timers.current = [];
  }, []);

  const after = useCallback((ms, fn) => {
    const id = setTimeout(fn, ms);
    timers.current.push(id);
  }, []);

  const runCycle = useCallback(() => {
    const els = refs.current.map(r => r.current);
    if (els.some(el => !el)) return;

    const FADE  = 400; // fade transition duration
    const GAP   = 250; // stagger between each folder
    const HOLD  = 500; // pause while all invisible
    const CYCLE = (FADE + GAP) * 3 + HOLD + (FADE + GAP) * 3 + 600;

    // FADE OUT: front (index 2) → mid (index 1) → back (index 0)
    [2, 1, 0].forEach((elIdx, order) => {
      after((FADE + GAP) * order, () => {
        const el = els[elIdx];
        if (!el) return;
        el.style.transition = `opacity ${FADE}ms ease`;
        el.style.opacity = "0";
      });
    });

    // FADE IN: back (index 0) → mid (index 1) → front (index 2)
    const inStart = (FADE + GAP) * 3 + HOLD;
    [0, 1, 2].forEach((elIdx, order) => {
      after(inStart + (FADE + GAP) * order, () => {
        const el = els[elIdx];
        if (!el) return;
        el.style.transition = `opacity ${FADE}ms ease`;
        el.style.opacity = "1";
      });
    });

    // Loop
    after(CYCLE, runCycle);
  }, [after]);

  useEffect(() => {
    if (!isOnline) return;

    const rs = refs.current;
    rs.forEach((r, i) => {
      if (!r.current) return;
      r.current.style.transition = "none";
      r.current.style.transform  = FOLDER_TRANSFORMS[i];
      r.current.style.zIndex     = String(i + 1);
      r.current.style.opacity    = "1";
    });

    runCycle();
    return () => clearAll();
  }, [isOnline, runCycle, clearAll]);

  const overlay = (
    <div
      className={`${
        fullScreen ? 'fixed inset-0 z-[2147483647]' : 'min-h-[60vh] w-full'
      } flex flex-col items-center justify-center px-4 transition-all duration-300 bg-transparent`}
    >
      <div className="flex flex-col items-center justify-center gap-3 sm:gap-4 text-center max-w-[90vw]">
        {isOnline ? (
          <div className="relative h-44 w-44 sm:h-52 sm:w-52">
            {FOLDER_IMGS.map((src, i) => (
              <img
                key={i}
                ref={refs.current[i]}
                src={src}
                alt={`Loading folder ${i + 1}`}
                className="absolute top-0 left-0 w-32 sm:w-36 drop-shadow-[0_14px_22px_rgba(0,0,0,0.18)]"
                style={{
                  willChange: "opacity",
                  backfaceVisibility: "hidden",
                  WebkitBackfaceVisibility: "hidden",
                  transform: FOLDER_TRANSFORMS[i],
                  zIndex: i + 1,
                }}
              />
            ))}
          </div>
        ) : (
          <ConnectionErrorCard isDark={isDark} onRetry={retry} />
        )}

        {isOnline && <div className="flex max-w-full flex-wrap justify-center gap-0.5">
          {chars.map((char, i) => (
            <span
              key={i}
              className={`font-bold text-[10px] sm:text-xs uppercase tracking-widest inline-block ${
                isDark ? 'text-[#e4e6eb]' : 'text-[#800000]'
              }`}
            >
              {char === " " ? "\u00A0" : char}
            </span>
          ))}
        </div>}
      </div>
    </div>
  );

  return fullScreen ? createPortal(overlay, document.body) : overlay;
};

export default function LoadingSkeleton({ isDark, variant = 'chart', message = "Loading..." }) {
  if (variant === 'stat') return <StatCardSkeleton isDark={isDark} />;
  if (variant === 'logbook') return <LogbookSkeleton isDark={isDark} />;
  if (variant === 'policy') return <PolicyTableSkeleton isDark={isDark} />;
  if (variant === 'access-request') return <AccessRequestsSkeleton isDark={isDark} />;
  if (variant === 'page') return <PageSkeleton isDark={isDark} />;
  if (variant === 'folder' || variant === 'overlay') return <FolderLoadingOverlay isDark={isDark} message={message} />;
  return <ChartCardSkeleton isDark={isDark} />;
}
