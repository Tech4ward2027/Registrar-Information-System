import React, { useEffect, useRef } from "react";
import PropTypes from "prop-types";
import { MicrophoneIcon, XMarkIcon } from "@heroicons/react/24/outline";
import useVoiceRecognition from "../utils/useVoiceRecognition.js";
import { useTheme } from "../context/ThemeContext";

const InputGroup = ({
  label,
  name,
  value,
  onChange,
  type = "text",
  placeholder = "",
  pattern,
  title,
  required = false,
  min,
  max,
  labelColor = "text-white",
  voiceEnabled = true,
  language = "en-US",
  isDark: isDarkProp,
}) => {
  const { isDark: themeIsDark } = useTheme();
  const isDark = isDarkProp !== undefined ? isDarkProp : themeIsDark;
  const lastProcessedRef = useRef('');

  const { isListening, transcript, interimTranscript, isSupported, toggle, reset } = useVoiceRecognition({
    language,
    continuous: true,
    interimResults: true,
    silenceTimeout: 3000,
  });

  useEffect(() => {
    if (!voiceEnabled || !transcript) return;

    if (transcript === lastProcessedRef.current) return;
    lastProcessedRef.current = transcript;

    // Normalize transcript and clean punctuation
    const normalized = transcript.trim().toLowerCase().replace(/[.,/#!$%^&*;:{}=\-_`~()?]/g, "").trim();
    if (normalized === "clear" || normalized === "clear search" || normalized === "reset") {
      reset();
      lastProcessedRef.current = "";
      onChange({ target: { name, value: "" } });
      return;
    }

    const cleaned = type === 'number' || pattern?.includes('\\d')
      ? transcript.replace(/\s+/g, '')
      : transcript;

    onChange({ target: { name, value: cleaned } });
  }, [voiceEnabled, transcript, type, pattern, onChange, name, reset]);

  const displayValue = voiceEnabled && isListening
    ? (transcript + (interimTranscript ? ' ' + interimTranscript : '')).trim()
    : value;

  const handleChange = (e) => {
    // If user types while voice is active, stop it immediately and reset speech buffer.
    if (isListening) {
      reset();
    } else if (transcript) {
      reset();
    }
    lastProcessedRef.current = '';
    onChange(e);
  };

  const handleReset = () => {
    reset();
    lastProcessedRef.current = '';
    onChange({ target: { name, value: "" } });
  };

  return (
    <div className="w-full">
      <label className={`block text-sm font-medium mb-1.5 ${isDark ? 'text-[#e4e6eb]' : labelColor}`}>
        {label}
        {required && <span className={isDark ? 'text-[#FFC72C] ml-1' : 'text-red-400 ml-1'}>*</span>}
      </label>

      <div className="relative flex items-center">
        {/* Screen Reader Announcements */}
        {voiceEnabled && (
          <div className="sr-only" aria-live="polite">
            {isListening ? `${label} voice input active. Speak now.` : `${label} voice input inactive.`}
          </div>
        )}

        <input
          type={type}
          name={name}
          value={displayValue}
          onChange={handleChange}
          placeholder={voiceEnabled && isListening ? "Listening..." : placeholder}
          required={required}
          pattern={pattern}
          title={title}
          min={min}
          max={max}
          className={`w-full px-3 py-3 rounded-lg text-sm font-medium shadow-sm transition-all duration-200 focus:outline-none ${
            isListening
              ? 'border-[#FFC72C] ring-2 ring-[#FFC72C]/25'
              : 'focus:border-[#FFC72C] focus:ring-2 focus:ring-[#FFC72C]/25'
          } ${
            isDark
              ? 'bg-[#1f1f1f] text-[#e4e6eb] border-[#3e4042] placeholder:text-[#8f949d]'
              : 'bg-white text-gray-700 border-gray-200 placeholder:text-gray-400'
          } ${voiceEnabled ? "pr-20" : ""}`}
        />

        <div className="absolute right-2 flex items-center gap-1">
          {String(value ?? "").trim().length > 0 && !isListening && (
            <button
              type="button"
              onClick={handleReset}
              className={`p-1.5 rounded-md transition-all ${isDark ? 'text-[#b0b3b8] hover:text-[#e4e6eb] hover:bg-[#3a3b3c]' : 'text-gray-400 hover:text-gray-600 hover:bg-gray-100'}`}
              aria-label={`Clear ${label}`}
            >
              <XMarkIcon className="w-4 h-4" />
            </button>
          )}

          {voiceEnabled && isSupported && (
            <button
              type="button"
              onClick={() => toggle(value)}
              aria-label={isListening ? "Stop listening" : `Start voice input for ${label}`}
              className={`p-1.5 rounded-md transition-all duration-200 shrink-0 ${
                isListening
                  ? 'text-[#b58700] dark:text-[#FFC72C] bg-[#FFC72C]/20 border border-[#FFC72C]/40 animate-pulse'
                  : isDark
                    ? 'text-[#b0b3b8] hover:text-[#FFC72C] hover:bg-[#3a3b3c]'
                    : 'text-gray-400 hover:text-[#b58700] hover:bg-amber-50'
              }`}
            >
              <MicrophoneIcon className="w-4 h-4" />
            </button>
          )}
        </div>
      </div>
    </div>
  );
};

InputGroup.propTypes = {
  label: PropTypes.string.isRequired,
  name: PropTypes.string.isRequired,
  value: PropTypes.oneOfType([PropTypes.string, PropTypes.number]).isRequired,
  onChange: PropTypes.func.isRequired,
  type: PropTypes.string,
  placeholder: PropTypes.string,
  required: PropTypes.bool,
  pattern: PropTypes.string,
  title: PropTypes.string,
  labelColor: PropTypes.string,
  min: PropTypes.string,
  max: PropTypes.string,
  voiceEnabled: PropTypes.bool,
  language: PropTypes.string,
};

export default InputGroup;