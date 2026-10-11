import { useState, useEffect, useRef, useCallback } from "react";
import { useNavigate } from "react-router-dom";
import { useAlertToast } from "../context/AlertToastContext";
import { useAuth } from "../context/AuthProvider";
import { useTheme } from "../context/ThemeContext";
import { useAccessibility } from "../context/AccessibilityContext";
import useVoiceRecognition from "../utils/useVoiceRecognition";
import { matchCommand, resolveVoiceRoute } from "../utils/voiceCommands";
import ConfirmationModal from "./ConfirmationModal";

const FloatingActionMenu = () => {
  const [isOpen, setIsOpen] = useState(false);
  const [isLogoutConfirmOpen, setIsLogoutConfirmOpen] = useState(false);
  const { showError } = useAlertToast();
  const { user, logout } = useAuth();
  const { setTheme, toggleTheme } = useTheme();
  const { toggleAccessibility } = useAccessibility();
  const navigate = useNavigate();
  const menuRef = useRef(null);

  const toggleMenu = () => setIsOpen((prev) => !prev);

  useEffect(() => {
    const handleClickOutside = (event) => {
      if (menuRef.current && !menuRef.current.contains(event.target)) {
        setIsOpen(false);
      }
    };
    document.addEventListener("mousedown", handleClickOutside);
    return () => {
      document.removeEventListener("mousedown", handleClickOutside);
    };
  }, []);

  useEffect(() => {
    const handleKeyDown = (event) => {
      if (event.key === "Escape") {
        setIsOpen(false);
      }
    };
    document.addEventListener("keydown", handleKeyDown);
    return () => {
      document.removeEventListener("keydown", handleKeyDown);
    };
  }, []);

  const handleAccessibilityClick = () => {
    toggleAccessibility();
  };

  // -------------------------------------------------------
  // Voice navigation
  //
  // onResult fires once per finished utterance (continuous: false stops
  // listening automatically after the first final transcript, so we don't
  // need a manual timeout). We match it against the command grammar and
  // either navigate to a role-resolved route or trigger an auth/theme action.
  // -------------------------------------------------------
  const handleVoiceResult = useCallback((transcript) => {
    const command = matchCommand(transcript);

    if (!command) {
      showError(`"${transcript}" isn't a supported voice command. Please try again or use one of the available commands.`);
      return;
    }

    if (command.type === "action") {
      if (command.action === "logout") {
        setIsOpen(false);
        setIsLogoutConfirmOpen(true);
        return;
      }
      if (command.action === "dark-mode") {
        setIsOpen(false);
        setTheme("dark");
        return;
      }
      if (command.action === "light-mode") {
        setIsOpen(false);
        setTheme("light");
        return;
      }
      if (command.action === "toggle-theme") {
        setIsOpen(false);
        toggleTheme();
        return;
      }
      if (command.action === "export-docx") {
        setIsOpen(false);
        const btn = document.querySelector('[data-voice-action="export"]');
        if (btn) {
          if (btn.disabled) {
            showError("Document export is currently unavailable or loading.");
          } else {
            btn.click();
          }
        } else {
          showError("Exporting documents is only available on the Logbook and Analytics pages.");
        }
        return;
      }
      if (command.action === "compose-email") {
        setIsOpen(false);
        const link = document.querySelector('[data-voice-action="compose-email"]');
        if (link) {
          link.click();
        } else {
          window.open(
            "https://mail.google.com/mail/u/0/?view=cm&fs=1&to=taguig@pup.edu.ph%2Ctaguig.registrar@pup.edu.ph&su=Inquiry%3A%20PUP%20Registrar%20Office%20Concern",
            "_blank"
          );
        }
        return;
      }
    }

    if (command.type === "navigate") {
      const path = resolveVoiceRoute(command.target, user?.role_name);
      if (!path) {
        showError("That section isn't available for your account.");
        return;
      }
      setIsOpen(false);
      navigate(path);
    }
  }, [navigate, user, setTheme, toggleTheme, showError]);

  const handleVoiceError = useCallback(() => {
    showError("We couldn't access the microphone. Please check your browser permissions.");
  }, [showError]);

  const {
    isListening: isVoiceAnimating,
    isSupported: isVoiceSupported,
    toggle: toggleVoiceRecognition,
  } = useVoiceRecognition({
    onResult: handleVoiceResult,
    onError: handleVoiceError,
    continuous: false,
  });

  const handleVoiceSpeechClick = () => {
    if (!isVoiceSupported) {
      showError("Voice commands aren't supported in this browser. Try Chrome or Edge.");
      return;
    }
    toggleVoiceRecognition();
  };

  return (
    <div
      ref={menuRef}
      className="fixed bottom-6 right-6 z-50 flex flex-col items-end gap-3"
      role="region"
      aria-label="Quick Access Menu"
    >
      {/* Submenu items */}
      <div
        className={`flex flex-col gap-3 mb-1 transition-all duration-300 transform origin-bottom ${isOpen
            ? "opacity-100 translate-y-0 scale-100 pointer-events-auto"
            : "opacity-0 translate-y-4 scale-75 pointer-events-none"
          }`}
      >
        {/* Accessibility Submenu Option */}
        <div className="flex items-center justify-end relative group">
          <span className="absolute right-16 bg-gray-900/90 text-white text-xs font-semibold px-2.5 py-1.5 rounded-lg shadow-md opacity-0 group-hover:opacity-100 transition-opacity duration-200 pointer-events-none whitespace-nowrap">
            Accessibility Options
          </span>
          <button
            onClick={() => {
              handleAccessibilityClick();
              setIsOpen(false);
            }}
            className="w-14 h-14 rounded-full bg-pup-dark-maroon hover:bg-[#500000] text-white flex items-center justify-center shadow-lg hover:scale-105 active:scale-95 transition-all focus:outline-none focus:ring-2 focus:ring-yellow-400"
            aria-label="Open Accessibility Menu"
            title="Accessibility Options"
          >
            <svg
              className="w-7 h-7"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2.5"
              strokeLinecap="round"
              strokeLinejoin="round"
              xmlns="http://www.w3.org/2000/svg"
            >
              <circle cx="12" cy="5.5" r="2" fill="currentColor" stroke="none" />
              <path d="M5 11h14" />
              <path d="M12 7.5v8" />
              <path d="M8.5 21.5L12 15.5L15.5 21.5" />
            </svg>
          </button>
        </div>

        {/* Voice Speech Submenu Option */}
        <div className="flex items-center justify-end relative group">
          <span className="absolute right-16 bg-gray-900/90 text-white text-xs font-semibold px-2.5 py-1.5 rounded-lg shadow-md opacity-0 group-hover:opacity-100 transition-opacity duration-200 pointer-events-none whitespace-nowrap">
            Voice Speech
          </span>
          <button
            onClick={handleVoiceSpeechClick}
            className={`w-14 h-14 rounded-full flex items-center justify-center shadow-lg hover:scale-105 active:scale-95 transition-all focus:outline-none focus:ring-2 focus:ring-yellow-400 ${isVoiceAnimating
                ? "bg-pup-yellow text-[#800000] scale-110"
                : "bg-pup-dark-maroon text-white hover:bg-[#500000]"
              }`}
            aria-label={isVoiceAnimating ? "Stop Voice Speech" : "Start Voice Speech"}
            title={isVoiceAnimating ? "Listening… tap to stop" : "Voice Speech"}
          >
            <svg
              className="w-7 h-7"
              fill="none"
              stroke="currentColor"
              strokeWidth="2.5"
              strokeLinecap="round"
              strokeLinejoin="round"
              viewBox="0 0 24 24"
              xmlns="http://www.w3.org/2000/svg"
            >
              <path d="M3 11v2" className={isVoiceAnimating ? "animate-wave-1" : ""} />
              <path d="M6 9v6" className={isVoiceAnimating ? "animate-wave-2" : ""} />
              <path d="M9 6v12" className={isVoiceAnimating ? "animate-wave-3" : ""} />
              <path d="M12 3v18" className={isVoiceAnimating ? "animate-wave-4" : ""} />
              <path d="M15 6v12" className={isVoiceAnimating ? "animate-wave-3" : ""} />
              <path d="M18 9v6" className={isVoiceAnimating ? "animate-wave-2" : ""} />
              <path d="M21 11v2" className={isVoiceAnimating ? "animate-wave-1" : ""} />
            </svg>
          </button>
        </div>
      </div>

      <button
        onClick={toggleMenu}
        aria-expanded={isOpen}
        aria-haspopup="true"
        aria-label={isOpen ? "Close Quick Access Menu" : "Open Quick Access Menu"}
        className="w-14 h-14 rounded-full bg-pup-dark-maroon hover:bg-[#500000] text-white flex items-center justify-center shadow-xl hover:shadow-2xl transition-all duration-300 transform hover:scale-105 active:scale-95 focus:outline-none focus:ring-4 focus:ring-yellow-400/50"
      >
        <span
          className={`transform transition-transform duration-300 ${isOpen ? "rotate-45" : "rotate-0"
            }`}
        >
          <svg
            className="w-7 h-7"
            fill="none"
            stroke="currentColor"
            strokeWidth="3.5"
            strokeLinecap="round"
            strokeLinejoin="round"
            viewBox="0 0 24 24"
            xmlns="http://www.w3.org/2000/svg"
          >
            <line x1="12" y1="5" x2="12" y2="19" />
            <line x1="5" y1="12" x2="19" y2="12" />
          </svg>
        </span>
      </button>

      <ConfirmationModal
        isOpen={isLogoutConfirmOpen}
        onClose={() => setIsLogoutConfirmOpen(false)}
        onConfirm={logout}
        title="Logout Session"
        message="Are you sure you want to log out? Any unsaved changes in the registrar system may be lost."
        type="default"
      />
    </div>
  );
};

export default FloatingActionMenu;