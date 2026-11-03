import React, { useEffect, useRef, useState, useCallback } from 'react';
import PropTypes from 'prop-types';
import {
  MicrophoneIcon,
  XMarkIcon,
  DocumentDuplicateIcon,
  CheckIcon,
  ExclamationCircleIcon,
} from '@heroicons/react/24/outline';
import useVoiceRecognition from '../utils/useVoiceRecognition.js';
import { useTheme } from '../context/ThemeContext';

const VoiceTextareaInput = ({
  id,
  label,
  value = '',
  onChange,
  placeholder = 'Type or dictate your message...',
  language = 'en-US',
  minHeightClass = 'min-h-32',
  required = false,
  labelColor,
  disabled = false,
  readOnly = false,
  maxLength,
  rows,
  name,
  helperText,
  error,
  className = '',
  isDark: isDarkProp,
}) => {
  const { isDark: themeIsDark } = useTheme();
  const isDark = typeof isDarkProp === 'boolean' ? isDarkProp : themeIsDark;
  const lastProcessedRef = useRef('');
  const [copied, setCopied] = useState(false);
  const [activeTooltip, setActiveTooltip] = useState(null);

  const {
    isListening,
    transcript,
    interimTranscript,
    isSupported,
    toggle,
    reset,
  } = useVoiceRecognition({
    language,
    continuous: true,
    interimResults: true,
    silenceTimeout: 4000,
  });

  // Synchronize voice transcript with parent state & handle voice commands
  useEffect(() => {
    if (!transcript) return;
    if (transcript === lastProcessedRef.current) return;

    lastProcessedRef.current = transcript;

    // Normalize transcript and clean punctuation for command checks
    const normalized = transcript
      .trim()
      .toLowerCase()
      .replace(/[.,/#!$%^&*;:{}=\-_`~()?]/g, '')
      .trim();

    if (normalized === 'clear' || normalized === 'clear text' || normalized === 'reset') {
      reset();
      lastProcessedRef.current = '';
      onChange('');
      return;
    }

    onChange(transcript.trim());
  }, [transcript, onChange, reset]);

  const displayValue = isListening
    ? (transcript + (interimTranscript ? ' ' + interimTranscript : '')).trim()
    : value;

  const handleReset = () => {
    reset();
    lastProcessedRef.current = '';
    onChange('');
  };

  const handleChange = (e) => {
    const nextValue = e.target.value;

    if (isListening || transcript) {
      reset();
    }
    lastProcessedRef.current = '';

    onChange(nextValue);
  };

  const handleCopy = useCallback(async () => {
    if (!value) return;
    try {
      await navigator.clipboard.writeText(value);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch (err) {
      console.error('Failed to copy text: ', err);
    }
  }, [value]);

  // Word and character counts
  const charCount = value ? value.length : 0;
  const wordCount = value && value.trim() ? value.trim().split(/\s+/).length : 0;

  // Header label style resolution
  const resolvedLabelClass = labelColor
    ? labelColor
    : isDark
    ? 'text-[#e4e6eb]'
    : 'text-gray-700';

  return (
    <div className={`space-y-1.5 w-full ${className}`}>
      {/* Visual audio wave keyframe styles */}
      <style>{`
        @keyframes soundWave1 { 0%, 100% { height: 6px; } 50% { height: 16px; } }
        @keyframes soundWave2 { 0%, 100% { height: 14px; } 50% { height: 6px; } }
        @keyframes soundWave3 { 0%, 100% { height: 8px; } 50% { height: 18px; } }
        @keyframes soundWave4 { 0%, 100% { height: 16px; } 50% { height: 8px; } }
        .animate-sound-wave-1 { animation: soundWave1 1.2s ease-in-out infinite; }
        .animate-sound-wave-2 { animation: soundWave2 0.9s ease-in-out infinite; }
        .animate-sound-wave-3 { animation: soundWave3 1.1s ease-in-out infinite; }
        .animate-sound-wave-4 { animation: soundWave4 1.3s ease-in-out infinite; }
      `}</style>

      {/* Label */}
      {label && (
        <label htmlFor={id} className={`block text-sm font-semibold tracking-wide ${resolvedLabelClass}`}>
          {label}
          {required && <span className="text-red-500 ml-1 font-bold">*</span>}
        </label>
      )}

      {/* Input Container Box */}
      <div
        className={`relative flex flex-col rounded-xl border shadow-sm transition-all duration-200 overflow-hidden ${
          error
            ? 'border-red-500 ring-1 ring-red-500'
            : isListening
            ? isDark
              ? 'border-[#FFC72C] ring-2 ring-[#FFC72C]/25 bg-[#1f1f1f]'
              : 'border-[#FFC72C] ring-2 ring-[#FFC72C]/25 bg-white'
            : isDark
            ? 'bg-[#1f1f1f] border-[#3e4042] focus-within:border-[#FFC72C] focus-within:ring-2 focus-within:ring-[#FFC72C]/30'
            : 'bg-white border-gray-200 focus-within:border-[#FFC72C] focus-within:ring-2 focus-within:ring-[#FFC72C]/20'
        } ${disabled ? 'opacity-60 cursor-not-allowed bg-gray-50 dark:bg-gray-800' : ''}`}
      >
        {/* Screen Reader Announcements */}
        <div className="sr-only" aria-live="polite">
          {isListening ? 'Voice input active. Speak now.' : 'Voice input inactive.'}
        </div>

        {/* Text Area */}
        <textarea
          id={id}
          name={name}
          rows={rows}
          maxLength={maxLength}
          disabled={disabled}
          readOnly={readOnly}
          required={required}
          value={displayValue}
          onChange={handleChange}
          placeholder={isListening ? 'Listening...' : placeholder}
          className={`${minHeightClass} w-full resize-y bg-transparent px-4 py-3 text-sm outline-none transition-colors ${
            isDark
              ? 'text-[#e4e6eb] placeholder:text-[#8f949d]'
              : 'text-gray-800 placeholder:text-gray-400'
          } ${disabled ? 'cursor-not-allowed' : ''}`}
        />

        {/* Bottom Integrated Control & Status Bar */}
        <div
          className={`flex items-center justify-between px-3 py-2 border-t text-xs select-none transition-colors ${
            isDark
              ? 'border-[#2d2e30] bg-[#181819]/60 text-[#9a9a9a]'
              : 'border-gray-100 bg-gray-50/80 text-gray-500'
          }`}
        >
          {/* Left Status: Audio Waves when listening OR Character/Word Counter when idle */}
          <div className="flex items-center gap-2">
            {isListening ? (
              <div className="flex items-center gap-2 text-[#b58700] dark:text-[#FFC72C] font-semibold">
                <div className="flex items-end gap-0.5 h-4.5">
                  <span className="w-1 bg-[#FFC72C] rounded-full animate-sound-wave-1"></span>
                  <span className="w-1 bg-[#FFC72C] rounded-full animate-sound-wave-2"></span>
                  <span className="w-1 bg-[#FFC72C] rounded-full animate-sound-wave-3"></span>
                  <span className="w-1 bg-[#FFC72C] rounded-full animate-sound-wave-4"></span>
                </div>
                <span className="text-xs">Listening... </span>
              </div>
            ) : (
              <div className="flex items-center gap-3">
                <span>
                  {charCount} {maxLength ? `/ ${maxLength}` : 'chars'}
                </span>
                <span className={`w-1 h-1 rounded-full ${isDark ? 'bg-gray-600' : 'bg-gray-300'}`} />
                <span>{wordCount} words</span>
              </div>
            )}
          </div>

          {/* Right Toolbar Action Buttons */}
          <div className="flex items-center gap-1">
            {/* Copy Button */}
            {value && !isListening && (
              <div className="relative">
                <button
                  type="button"
                  onClick={handleCopy}
                  onMouseEnter={() => setActiveTooltip('copy')}
                  onMouseLeave={() => setActiveTooltip(null)}
                  className={`p-1.5 rounded-lg transition-all ${
                    isDark
                      ? 'text-[#9a9a9a] hover:text-white hover:bg-[#2d2e30]'
                      : 'text-gray-400 hover:text-gray-700 hover:bg-gray-200/60'
                  }`}
                  aria-label="Copy to clipboard"
                >
                  {copied ? (
                    <CheckIcon className="w-4 h-4 text-green-500" />
                  ) : (
                    <DocumentDuplicateIcon className="w-4 h-4" />
                  )}
                </button>
                {activeTooltip === 'copy' && (
                  <div className="absolute bottom-full mb-1 right-0 px-2 py-1 bg-gray-900 text-white text-[10px] rounded shadow-md whitespace-nowrap z-10 pointer-events-none">
                    {copied ? 'Copied!' : 'Copy text'}
                  </div>
                )}
              </div>
            )}

            {/* Clear Button */}
            {value && !isListening && (
              <div className="relative">
                <button
                  type="button"
                  onClick={handleReset}
                  onMouseEnter={() => setActiveTooltip('clear')}
                  onMouseLeave={() => setActiveTooltip(null)}
                  className={`p-1.5 rounded-lg transition-all ${
                    isDark
                      ? 'text-[#9a9a9a] hover:text-red-400 hover:bg-[#2d2e30]'
                      : 'text-gray-400 hover:text-red-600 hover:bg-gray-200/60'
                  }`}
                  aria-label="Clear input"
                >
                  <XMarkIcon className="w-4 h-4" />
                </button>
                {activeTooltip === 'clear' && (
                  <div className="absolute bottom-full mb-1 right-0 px-2 py-1 bg-gray-900 text-white text-[10px] rounded shadow-md whitespace-nowrap z-10 pointer-events-none">
                    Clear text
                  </div>
                )}
              </div>
            )}

            {/* Divider */}
            {value && !isListening && isSupported && (
              <div className={`w-px h-4 mx-0.5 ${isDark ? 'bg-gray-700' : 'bg-gray-200'}`} />
            )}

            {/* Voice Dictation Button */}
            {isSupported ? (
              <div className="relative">
                <button
                  type="button"
                  disabled={disabled || readOnly}
                  onClick={() => toggle(value)}
                  onMouseEnter={() => setActiveTooltip('voice')}
                  onMouseLeave={() => setActiveTooltip(null)}
                  aria-label={isListening ? 'Stop listening' : 'Start voice input'}
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
                {activeTooltip === 'voice' && (
                  <div className="absolute bottom-full mb-1 right-0 px-2 py-1 bg-gray-900 text-white text-[10px] rounded shadow-md whitespace-nowrap z-10 pointer-events-none">
                    {isListening ? 'Stop listening' : 'Start voice input'}
                  </div>
                )}
              </div>
            ) : (
              <div className="relative">
                <button
                  type="button"
                  disabled
                  onMouseEnter={() => setActiveTooltip('unsupported')}
                  onMouseLeave={() => setActiveTooltip(null)}
                  className="p-1.5 rounded-lg text-gray-300 dark:text-gray-600 cursor-not-allowed"
                  aria-label="Voice input not supported"
                >
                  <MicrophoneIcon className="w-4 h-4 opacity-50" />
                </button>
                {activeTooltip === 'unsupported' && (
                  <div className="absolute bottom-full mb-1 right-0 px-2 py-1 bg-gray-900 text-white text-[10px] rounded shadow-md whitespace-nowrap z-10 pointer-events-none">
                    Voice input not supported in this browser
                  </div>
                )}
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Helper text or Error message */}
      {error ? (
        <div className="flex items-center gap-1 text-xs text-red-500 mt-1">
          <ExclamationCircleIcon className="w-3.5 h-3.5" />
          <span>{error}</span>
        </div>
      ) : helperText ? (
        <p className={`text-xs mt-1 ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
          {helperText}
        </p>
      ) : null}
    </div>
  );
};

VoiceTextareaInput.propTypes = {
  id: PropTypes.string.isRequired,
  label: PropTypes.string,
  value: PropTypes.string.isRequired,
  onChange: PropTypes.func.isRequired,
  placeholder: PropTypes.string,
  language: PropTypes.string,
  minHeightClass: PropTypes.string,
  required: PropTypes.bool,
  labelColor: PropTypes.string,
  disabled: PropTypes.bool,
  readOnly: PropTypes.bool,
  maxLength: PropTypes.number,
  rows: PropTypes.number,
  name: PropTypes.string,
  helperText: PropTypes.string,
  error: PropTypes.string,
  className: PropTypes.string,
  isDark: PropTypes.bool,
};

VoiceTextareaInput.defaultProps = {
  label: 'Message',
  value: '',
};

export default VoiceTextareaInput;
