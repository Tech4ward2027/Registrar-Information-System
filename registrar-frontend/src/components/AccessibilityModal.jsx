import { useEffect, useRef } from "react";
import { useAccessibility } from "../context/AccessibilityContext";

const options = [
  ["dyslexiaFont", "Dyslexia-friendly font", "Uses a more readable, spaced typeface."],
  ["highContrast", "High contrast", "Strengthens text, borders, and control contrast."],
  ["reducedMotion", "Reduce motion", "Stops animations and transitions."],
  ["highlightLinks", "Highlight links", "Underlines all links for easier identification."],
  ["lineSpacing", "Increase line spacing", "Adds space between lines of text."],
  ["letterSpacing", "Increase letter spacing", "Adds space between individual letters."],
];

const AccessibilityModal = () => {
  const { preferences, isOpen, closeAccessibility, updatePreference, resetPreferences } = useAccessibility();
  const closeButtonRef = useRef(null);

  useEffect(() => {
    if (!isOpen) return undefined;
    closeButtonRef.current?.focus();
    const handleKeyDown = (event) => {
      if (event.key === "Escape") closeAccessibility();
    };
    document.addEventListener("keydown", handleKeyDown);
    return () => document.removeEventListener("keydown", handleKeyDown);
  }, [isOpen, closeAccessibility]);

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-[9999] bg-black/40" onMouseDown={closeAccessibility}>
      <section
        role="dialog"
        aria-modal="true"
        aria-labelledby="accessibility-title"
        className="ml-auto flex h-full w-full max-w-md flex-col overflow-y-auto bg-gray-50 text-gray-900 shadow-2xl dark:bg-gray-950 dark:text-white"
        onMouseDown={(event) => event.stopPropagation()}
      >
        <div className="bg-pup-maroon-header px-6 py-3 text-white">
          <div className="flex items-start justify-between gap-4">
            <div>
              <p className="text-xs font-bold uppercase tracking-[0.18em] text-pup-yellow">PUP Registrar</p>
              <h2 id="accessibility-title" className="mt-0.5 text-xl font-bold">Accessibility</h2>
              <p className="text-xs text-white/80">Personalize your registrar experience.</p>
            </div>
            <button ref={closeButtonRef} type="button" onClick={closeAccessibility} className="rounded-lg p-2 text-2xl leading-none text-white hover:bg-white/15 focus:outline-none focus:ring-2 focus:ring-pup-yellow" aria-label="Close accessibility options">×</button>
          </div>
        </div>

        <div className="flex-1 space-y-6 p-6">
        <fieldset>
          <legend className="mb-3 text-sm font-bold uppercase tracking-wide text-pup-maroon dark:text-pup-yellow">Text size</legend>
          <div className="grid grid-cols-3 gap-2">
            {[
              ["default", "Default"],
              ["large", "Large"],
              ["extraLarge", "Extra large"],
            ].map(([value, label]) => (
              <button key={value} type="button" onClick={() => updatePreference("fontScale", value)} aria-pressed={preferences.fontScale === value} className={`rounded-lg border px-3 py-2 text-sm font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-pup-yellow ${preferences.fontScale === value ? "border-pup-maroon bg-pup-maroon text-white" : "border-gray-300 bg-white hover:border-pup-maroon hover:text-pup-maroon dark:border-gray-700 dark:bg-gray-900 dark:hover:border-pup-yellow dark:hover:text-pup-yellow"}`}>
                {label}
              </button>
            ))}
          </div>
        </fieldset>

        <div className="space-y-3">
          {options.map(([key, label, description]) => (
            <label key={key} className="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 border-l-4 border-l-pup-maroon bg-white p-4 shadow-sm transition-colors hover:border-pup-yellow hover:bg-yellow-50 dark:border-gray-700 dark:border-l-pup-yellow dark:bg-gray-900 dark:hover:bg-gray-800">
              <input type="checkbox" checked={preferences[key]} onChange={(event) => updatePreference(key, event.target.checked)} className="checkbox-pup mt-1" />
              <span><span className="block font-semibold">{label}</span><span className="mt-1 block text-sm text-gray-600 dark:text-gray-300">{description}</span></span>
            </label>
          ))}
        </div>

        <button type="button" onClick={resetPreferences} className="w-full rounded-lg border-2 border-pup-maroon px-4 py-2.5 font-bold text-pup-maroon transition-colors hover:bg-pup-maroon hover:text-white focus:outline-none focus:ring-2 focus:ring-pup-yellow dark:border-pup-yellow dark:text-pup-yellow dark:hover:bg-pup-yellow dark:hover:text-pup-dark-maroon">Reset to default</button>
        </div>
      </section>
    </div>
  );
};

export default AccessibilityModal;
