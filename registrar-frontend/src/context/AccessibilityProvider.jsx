import { useCallback, useEffect, useState } from "react";
import { AccessibilityContext } from "./AccessibilityContext";

const STORAGE_KEY = "accessibility-preferences";
const DEFAULT_PREFERENCES = {
  fontScale: "default",
  dyslexiaFont: false,
  highContrast: false,
  highlightLinks: false,
  reducedMotion: false,
  lineSpacing: false,
  letterSpacing: false,
};

const validFontScales = new Set(["default", "large", "extraLarge"]);

const loadPreferences = () => {
  try {
    const stored = JSON.parse(localStorage.getItem(STORAGE_KEY));
    if (!stored || typeof stored !== "object") return DEFAULT_PREFERENCES;
    return {
      ...DEFAULT_PREFERENCES,
      ...stored,
      fontScale: validFontScales.has(stored.fontScale) ? stored.fontScale : "default",
    };
  } catch (error) {
    console.error("Failed to load accessibility preferences:", error);
    return DEFAULT_PREFERENCES;
  }
};

export const AccessibilityProvider = ({ children }) => {
  const [preferences, setPreferences] = useState(DEFAULT_PREFERENCES);
  const [isOpen, setIsOpen] = useState(false);

  useEffect(() => {
    setPreferences(loadPreferences());
  }, []);

  useEffect(() => {
    const root = document.documentElement;
    const fontScales = { default: "1", large: "1.15", extraLarge: "1.3" };
    root.style.setProperty("--a11y-font-scale", fontScales[preferences.fontScale]);
    root.classList.toggle("a11y-dyslexia-font", preferences.dyslexiaFont);
    root.classList.toggle("a11y-high-contrast", preferences.highContrast);
    root.classList.toggle("a11y-highlight-links", preferences.highlightLinks);
    root.classList.toggle("a11y-reduced-motion", preferences.reducedMotion);
    root.classList.toggle("a11y-line-spacing", preferences.lineSpacing);
    root.classList.toggle("a11y-letter-spacing", preferences.letterSpacing);
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(preferences));
    } catch (error) {
      console.error("Failed to save accessibility preferences:", error);
    }
  }, [preferences]);

  const updatePreference = useCallback((key, value) => {
    setPreferences((current) => ({ ...current, [key]: value }));
  }, []);

  const resetPreferences = useCallback(() => {
    setPreferences(DEFAULT_PREFERENCES);
  }, []);

  const toggleAccessibility = useCallback(() => setIsOpen((open) => !open), []);
  const closeAccessibility = useCallback(() => setIsOpen(false), []);

  const value = {
    preferences,
    isOpen,
    toggleAccessibility,
    closeAccessibility,
    updatePreference,
    resetPreferences,
  };

  return (
    <AccessibilityContext.Provider value={value}>
      {children}
    </AccessibilityContext.Provider>
  );
};
