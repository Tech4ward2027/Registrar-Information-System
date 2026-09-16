import React, { useState, useRef, useEffect } from 'react';
import PropTypes from 'prop-types';
import { ChevronDownIcon, CheckIcon } from '@heroicons/react/24/outline';
import { useTheme } from '../context/ThemeContext';

const DropdownGroup = ({ label, name, value, onChange, options, required = false, labelColor = 'text-white', direction = 'auto', isDark: isDarkProp }) => {
  const [open, setOpen] = useState(false);
  const [searchTerm, setSearchTerm] = useState("");
  const [openUpward, setOpenUpward] = useState(false);
  const ref = useRef(null);
  const inputRef = useRef(null);
  const { isDark: themeIsDark } = useTheme();
  const isDark = isDarkProp !== undefined ? isDarkProp : themeIsDark;

  // Clear search term & auto-detect vertical direction when dropdown opens
  useEffect(() => {
    if (!open) {
      setSearchTerm("");
      setOpenUpward(false);
    } else {
      if (ref.current) {
        const rect = ref.current.getBoundingClientRect();
        const scrollParent = ref.current.closest('.overflow-y-auto, .overflow-auto, modal');
        let bottomLimit = window.innerHeight;
        if (scrollParent) {
          const parentRect = scrollParent.getBoundingClientRect();
          bottomLimit = parentRect.bottom;
        }
        const spaceBelow = bottomLimit - rect.bottom;
        const dropdownMenuHeight = 220;
        if (spaceBelow < dropdownMenuHeight && rect.top > dropdownMenuHeight) {
          setOpenUpward(true);
        } else {
          setOpenUpward(false);
        }
      }
      const timer = setTimeout(() => {
        inputRef.current?.focus();
      }, 50);
      return () => clearTimeout(timer);
    }
  }, [open]);

  // Handle clicking outside to close
  useEffect(() => {
    const handler = (e) => {
      if (ref.current && !ref.current.contains(e.target)) {
        setOpen(false);
      }
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, []);

  const handleSelect = (option) => {
    onChange({ target: { name, value: option } });
    setOpen(false);
  };

  const safeOptions = Array.isArray(options) ? options : [];
  const filteredOptions = searchTerm === ''
    ? safeOptions
    : safeOptions.filter((option) => String(option ?? '').toLowerCase().includes(searchTerm.toLowerCase()));

  const isUp = direction === 'up' || (direction === 'auto' && openUpward);

  return (
    <div className="w-full group relative" ref={ref}>
      {/* Label */}
      {label && (
        <label className={`block text-sm font-medium mb-1.5 ${isDark ? 'text-[#e4e6eb]' : labelColor}`}>
          {label}
          {required && <span className={isDark ? 'text-[#FFC72C]' : 'text-red-500'} title="Required"> *</span>}
        </label>
      )}

      {/* Trigger Button / Input */}
      <div className="relative">
        {open ? (
          <input
            ref={inputRef}
            type="text"
            placeholder={value || "Search..."}
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            className={`
              w-full flex items-center justify-between gap-2 pl-3 pr-10 py-3 rounded-lg text-sm font-medium shadow-sm focus:outline-none border transition-colors text-left
              ring-2 ring-[#FFC72C] border-transparent
              ${isDark ? 'bg-[#1f1f1f] text-[#e4e6eb] border-[#3e4042]' : 'bg-white text-gray-700 border-gray-200'}
            `}
          />
        ) : (
          <button
            type="button"
            onClick={() => setOpen(true)}
            className={`
              w-full flex items-center justify-between gap-2 pl-3 pr-10 py-3 rounded-lg text-sm font-medium shadow-sm focus:outline-none border transition-colors text-left cursor-pointer
              border-transparent hover:border-gray-200
              ${isDark ? 'bg-[#1f1f1f] text-[#e4e6eb] border-[#3e4042]' : 'bg-white text-gray-700 border-gray-200'}
            `}
          >
            <span className={value ? '' : (isDark ? 'text-gray-500' : 'text-gray-400')}>
              {value || "Please Select"}
            </span>
          </button>
        )}

        {/* Toggle Arrow (Clickable) */}
        <span 
          onClick={() => setOpen((prev) => !prev)}
          className="absolute inset-y-0 right-0 flex items-center pr-3 cursor-pointer z-10"
        >
          <ChevronDownIcon
            className={`w-4 h-4 transition-transform duration-200 
              ${isDark ? 'text-[#b0b3b8]' : 'text-gray-400'} 
              ${open ? 'rotate-180 text-[#FFC72C]' : ''}
            `}
          />
        </span>

        {/* Dropdown Menu */}
        {open && (
          <div
            className={`absolute z-[9999] ${isUp ? 'bottom-full mb-1.5' : 'top-full mt-1.5'} w-full rounded-xl overflow-hidden ${isDark ? 'bg-[#1f1f1f]' : 'bg-white'}`}
            style={{ boxShadow: '0 8px 32px -4px rgba(0,0,0,0.18), 0 2px 8px -2px rgba(0,0,0,0.10)', border: '1px solid #FFC72C' }}
          >
            <ul className="max-h-56 overflow-y-auto py-1 dropdown-scroll">
              {filteredOptions.length > 0 ? (
                filteredOptions.map((option) => {
                  const isSelected = value === option;
                  return (
                    <li key={option}>
                      <button
                        type="button"
                        onClick={() => handleSelect(option)}
                        className={`
                          w-full flex items-center px-1 py-2.5 text-left text-sm transition-colors duration-100
                          ${isSelected
                            ? 'bg-[#800000] text-white font-bold'
                            : isDark ? 'text-[#b0b3b8] hover:bg-[#3a3b3c] hover:text-[#e4e6eb]' : 'text-gray-700 hover:bg-amber-50 hover:text-[#800000]'
                          }
                        `}
                      >
                        <span className="truncate px-2 flex-1">{option}</span>
                        {isSelected && <CheckIcon className="w-4 h-4 mr-2" />}
                      </button>
                    </li>
                  );
                })
              ) : (
                <li className={`px-4 py-3 text-sm text-center italic ${isDark ? 'text-gray-500' : 'text-gray-400'}`}>
                  No options found
                </li>
              )}
            </ul>

            {/* Gold bottom accent */}
            <div className="h-1 w-full bg-linear-to-r from-[#FFD700] via-[#FFC72C] to-[#FFD700]" />
          </div>
        )}
      </div>
    </div>
  );
};

DropdownGroup.propTypes = {
  label: PropTypes.string,
  name: PropTypes.string.isRequired,
  value: PropTypes.string,
  onChange: PropTypes.func.isRequired,
  options: PropTypes.arrayOf(PropTypes.string),
  required: PropTypes.bool,
  labelColor: PropTypes.string,
  direction: PropTypes.string,
};

export default DropdownGroup;