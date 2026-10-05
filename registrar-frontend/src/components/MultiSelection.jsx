import React, { useState, useRef, useEffect } from "react";
import PropTypes from 'prop-types';
import { ChevronDownIcon, CheckIcon } from "@heroicons/react/24/outline";
import { XMarkIcon } from "@heroicons/react/24/solid";
import { useTheme } from "../context/ThemeContext";

const isDesktopPointer = () =>
  typeof window !== 'undefined' &&
  window.matchMedia('(pointer: fine) and (hover: hover)').matches;

const MultiSelectDropdown = ({
  name,
  label,
  options = [],
  selectedValues = [],
  onChange,
  required = false,
  labelColor = 'text-white',
  placeholder = "Please Select",
  direction = 'auto',
}) => {
  const [isOpen, setIsOpen] = useState(false);
  const [searchTerm, setSearchTerm] = useState("");
  const [openUpward, setOpenUpward] = useState(false);
  const dropdownRef = useRef(null);
  const searchInputRef = useRef(null);
  const { isDark } = useTheme();

  const handleClose = () => {
    setIsOpen(false);
    setSearchTerm("");
    setOpenUpward(false);
  };

  const handleToggle = () => {
    if (isOpen) {
      handleClose();
    } else {
      setIsOpen(true);
    }
  };

  /* Close dropdown when clicking outside */
  useEffect(() => {
    const handleClickOutside = (event) => {
      if (
        dropdownRef.current &&
        !dropdownRef.current.contains(event.target)
      ) {
        handleClose();
      }
    };

    document.addEventListener("mousedown", handleClickOutside);
    return () =>
      document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  // Auto-detect vertical direction and direct to search on desktop when dropdown opens
  useEffect(() => {
    if (!isOpen) return;

    if (dropdownRef.current) {
      const rect = dropdownRef.current.getBoundingClientRect();
      const scrollParent = dropdownRef.current.closest('.overflow-y-auto, .overflow-auto, modal');
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

    // Direct to search input on desktop devices (precision pointer with hover)
    if (isDesktopPointer()) {
      const frameId = requestAnimationFrame(() => {
        searchInputRef.current?.focus({ preventScroll: true });
      });
      return () => cancelAnimationFrame(frameId);
    }
  }, [isOpen]);

  const toggleOption = (option) => {
    let newValues;

    if (selectedValues.includes(option)) {
      newValues = selectedValues.filter((item) => item !== option);
    } else {
      newValues = [...selectedValues, option];
    }

    onChange({
      target: {
        name,
        value: newValues,
      },
    });
  };

  const filteredOptions = searchTerm === ""
    ? options
    : options.filter((option) =>
        option.toLowerCase().includes(searchTerm.toLowerCase())
      );

  const isUp = direction === 'up' || (direction === 'auto' && openUpward);

  return (
    <div className="w-full group relative" ref={dropdownRef}>
      {/* Label */}
      {label && (
        <label className={`block text-sm font-medium mb-1.5 ${isDark ? 'text-[#e4e6eb]' : labelColor}`}>
          {label}
          {required && <span className={isDark ? 'text-[#FFC72C]' : 'text-red-500'} title="Required"> *</span>}
        </label>
      )}

      {/* Trigger Box / Input */}
      <div className="relative">
        <div
          onClick={handleToggle}
          className={`
            w-full flex items-center justify-between gap-2 pl-3 pr-10 py-2.5 rounded-lg text-sm font-medium shadow-sm border transition-colors text-left cursor-pointer min-h-[46px]
            ${isOpen ? 'ring-2 ring-[#FFC72C] border-transparent' : 'border-gray-200 hover:border-gray-300'}
            ${isDark ? 'bg-[#1f1f1f] text-[#e4e6eb] border-[#3e4042]' : 'bg-white text-gray-700 border-gray-200'}
          `}
        >
          <div className="flex flex-wrap gap-1.5 items-center flex-1 pr-1">
            {selectedValues.length === 0 ? (
              <span className={isDark ? 'text-gray-500' : 'text-gray-400'}>{placeholder}</span>
            ) : (
              selectedValues.map((val) => (
                <span
                  key={val}
                  className={`text-xs px-2.5 py-1 rounded-md flex items-center gap-1 shadow-xs ${
                    isDark ? 'bg-pup-yellow text-pup-maroon font-bold' : 'bg-[#800000] text-white font-medium'
                  }`}
                >
                  <span className="truncate max-w-[200px]">{val}</span>
                  <button
                    type="button"
                    aria-label={`Remove ${val}`}
                    onClick={(e) => {
                      e.stopPropagation();
                      toggleOption(val);
                    }}
                    className="hover:opacity-80 cursor-pointer flex items-center focus:outline-none ml-0.5"
                  >
                    <XMarkIcon className="w-3.5 h-3.5" />
                  </button>
                </span>
              ))
            )}
          </div>
        </div>

        {/* Toggle Arrow (Clickable) */}
        <span
          onClick={(e) => {
            e.stopPropagation();
            handleToggle();
          }}
          className="absolute inset-y-0 right-0 flex items-center pr-3 cursor-pointer z-10"
        >
          <ChevronDownIcon
            className={`w-4 h-4 transition-transform duration-200 
              ${isDark ? 'text-[#b0b3b8]' : 'text-gray-400'} 
              ${isOpen ? 'rotate-180 text-[#FFC72C]' : ''}
            `}
          />
        </span>

        {/* Dropdown Menu */}
        {isOpen && (
          <div
            className={`absolute z-[9999] ${isUp ? 'bottom-full mb-1.5' : 'top-full mt-1.5'} w-full rounded-xl overflow-hidden ${isDark ? 'bg-[#1f1f1f]' : 'bg-white'}`}
            style={{ boxShadow: '0 8px 32px -4px rgba(0,0,0,0.18), 0 2px 8px -2px rgba(0,0,0,0.10)', border: '1px solid #FFC72C' }}
          >
            {/* Search Input inside the dropdown popover when options are many */}
            {options.length > 5 && (
              <div className={`p-2 border-b ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-gray-200 bg-gray-50'}`}>
                <input
                  ref={searchInputRef}
                  type="text"
                  placeholder="Search options..."
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === 'Escape') {
                      e.preventDefault();
                      handleClose();
                    } else if (e.key === 'Enter') {
                      e.preventDefault();
                      if (filteredOptions.length > 0) {
                        toggleOption(filteredOptions[0]);
                      }
                    }
                  }}
                  className={`w-full px-3 py-1.5 rounded-lg text-xs font-medium focus:outline-none focus:ring-1 focus:ring-[#FFC72C] ${
                    isDark ? 'bg-[#1f1f1f] text-[#e4e6eb] placeholder-gray-500 border border-[#3e4042]' : 'bg-white text-gray-800 placeholder-gray-400 border border-gray-200'
                  }`}
                />
              </div>
            )}
            <ul className="max-h-56 overflow-y-auto py-1 dropdown-scroll">
              {filteredOptions.length > 0 ? (
                filteredOptions.map((option) => {
                  const isSelected = selectedValues.includes(option);
                  return (
                    <li key={option}>
                      <button
                        type="button"
                        onClick={() => toggleOption(option)}
                        className={`
                          w-full flex items-center px-3 py-2.5 text-left text-sm transition-colors duration-100 cursor-pointer
                          ${isSelected
                            ? (isDark ? 'bg-[#3a3b3c] text-pup-yellow' : 'bg-amber-50 text-pup-maroon font-semibold')
                            : isDark ? 'text-[#e4e6eb] hover:bg-[#3a3b3c]' : 'text-gray-700 hover:bg-gray-100'
                          }
                        `}
                      >
                        {/* Checkbox preserved on inside */}
                        <div
                          className={`w-4 h-4 rounded border flex items-center justify-center mr-3 shrink-0 transition-colors ${
                            isSelected
                              ? isDark
                                ? "bg-pup-yellow border-pup-yellow"
                                : "bg-pup-maroon border-pup-maroon"
                              : isDark
                              ? "border-gray-600 bg-[#242526]"
                              : "border-gray-300 bg-white"
                          }`}
                        >
                          {isSelected && (
                            <CheckIcon className={`w-3 h-3 stroke-[2.5] ${isDark ? 'text-pup-maroon' : 'text-white'}`} />
                          )}
                        </div>

                        <span className={`text-sm truncate ${isSelected ? 'font-semibold' : ''}`}>
                          {option}
                        </span>
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

MultiSelectDropdown.propTypes = {
  label: PropTypes.string,
  name: PropTypes.string.isRequired,
  options: PropTypes.arrayOf(PropTypes.string).isRequired,
  selectedValues: PropTypes.arrayOf(PropTypes.string),
  onChange: PropTypes.func.isRequired,
  required: PropTypes.bool,
  labelColor: PropTypes.string,
  placeholder: PropTypes.string,
  direction: PropTypes.string,
};

export default MultiSelectDropdown;
