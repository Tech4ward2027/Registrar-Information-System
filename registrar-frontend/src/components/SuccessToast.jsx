import React, { useEffect, useRef, useState } from 'react';
import { CheckCircleIcon, XMarkIcon } from '@heroicons/react/24/outline';
import { useHeaderResponsiveState } from '../utils/helpers';

const SuccessToast = ({ message, onClose }) => {
  const [duration, setDuration] = useState(5000);
  const toastRef = useRef(null);
  const { headerHeight } = useHeaderResponsiveState(!!message);

  useEffect(() => {
    if (message) {
      const displayTime = message.length > 60 ? 5000 : 3000;
      setDuration(displayTime);

      const timer = setTimeout(() => {
        onClose();
      }, displayTime);

      return () => clearTimeout(timer);
    }
  }, [message, onClose]);

  // Click outside to close
  useEffect(() => {
    function handleClickOutside(event) {
      if (toastRef.current && !toastRef.current.contains(event.target)) {
        onClose();
      }
    }
    if (message) {
      document.addEventListener('mousedown', handleClickOutside);
    }
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
    };
  }, [message, onClose]);

  if (!message) return null;

  return (
    <div
      ref={toastRef}
      style={{
        top: `${headerHeight > 0 ? headerHeight + 16 : 20}px`,
      }}
      className="fixed left-1/2 -translate-x-1/2 md:left-auto md:right-5 md:translate-x-0 z-[999999] flex items-center w-[calc(100vw-24px)] md:w-[380px] px-4 py-3.5 rounded-xl shadow-2xl animate-in slide-in-from-top-4 md:slide-in-from-right-4 fade-in duration-300 transition-all text-white bg-green-600 border border-white/20"
    >
      {/* Icon Badge */}
      <div className="flex items-center justify-center w-10 h-10 rounded-lg shrink-0 text-green-600 bg-white shadow-sm">
        <CheckCircleIcon className="w-6 h-6" strokeWidth={2.5} />
      </div>

      {/* Message */}
      <div className="ml-3 text-sm font-semibold leading-snug flex-1 text-white drop-shadow-xs">
        {message}
      </div>

      {/* Close Button */}
      <button 
        type="button"
        onClick={onClose}
        aria-label="Close success alert"
        className="ml-3 p-1.5 rounded-lg text-white/80 hover:text-white hover:bg-white/20 transition-colors cursor-pointer"
      >
        <XMarkIcon className="w-5 h-5" strokeWidth={2.5} />
      </button>

      {/* Progress Bar */}
      <div className="absolute bottom-0 left-0 h-1 rounded-b-xl overflow-hidden w-full bg-white/30">
        <div
          className="h-full bg-white"
          style={{
            width: '100%',
            animation: `shrink ${duration}ms linear forwards`
          }}
        />
      </div>
    </div>
  );
};

export default SuccessToast;