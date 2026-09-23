import React from "react";
import DropDown from "../components/DropDown";
import SuccessToast from "../components/SuccessToast.jsx";
import ErrorToast from "../components/ErrorToast.jsx";
import ConfirmationModal from "../components/ConfirmationModal.jsx";
import { useTheme } from "../context/ThemeContext";
import { useCertificateTemplates } from "../hooks/useCertificateTemplates";
import {
  UploadDropZone,
  CertificatePreviewCanvas,
} from "../components/CertificateTemplateComponents";
import { hasPreviewDataUrl } from "../utils/certificateTemplateUtils";

const CertificateTemplateManagement = () => {
  const { isDark } = useTheme();

  const {
    selectedCertId,
    layout,
    setLayout,
    loading,
    saving,
    saveSuccess,
    successMessage,
    setSuccessMessage,
    errorMessage,
    setErrorMessage,
    past,
    future,
    applyMainLogoToAll,
    setApplyMainLogoToAll,
    applyRightLogoToAll,
    setApplyRightLogoToAll,
    applyFooterLogosToAll,
    setApplyFooterLogosToAll,
    isResetConfirmOpen,
    setIsResetConfirmOpen,
    isLockedCertification,
    certificateOptions,
    selectedCertificateName,
    handleCertChange,
    updateMainLogo,
    updateRightLogo,
    addFooterLogos,
    saveLayout,
    resetLayout,
    undo,
    redo,
    resetAllLayouts,
    removeFooterLogo,
  } = useCertificateTemplates();

  return (
    <div className={`min-h-screen p-4 sm:p-6 rounded-2xl ${isDark ? 'bg-[#18191a] text-[#e4e6eb]' : 'bg-white'}`}>
      <div className="mx-auto max-w-400 space-y-4">
        {/* Top Header Actions */}
        <header className={`rounded-xl border p-4 shadow-sm ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-gray-200 bg-white'}`}>
          <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
              <h1 className={`text-xl font-bold sm:text-2xl ${isDark ? 'text-white' : 'text-[#4f2018]'}`}>Certificate Template Editor</h1>
              <div className={`mt-1 text-xs  ${isDark ? 'text-[#b0b3b8]' : 'text-[#4f2018]'}`}>
                <span className="font-bold">Reminder:</span>
                <ul className="list-disc ml-5 mt-1 space-y-0.5">
                  <li>Logos, header titles, and font sizes are editable.</li>
                  <li>Check the corresponding checkbox before uploading a logo to apply it to all certificates.</li>
                </ul>
              </div>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <button
                id="btn-undo-logo"
                onClick={undo}
                disabled={past.length === 0}
                className={`flex-1 sm:flex-initial rounded-md border px-3 py-2 text-sm font-semibold transition disabled:opacity-50 disabled:cursor-not-allowed ${isDark ? 'border-[#3e4042] bg-[#2a2a2f] text-[#e4e6eb] hover:bg-[#353539]' :
                  'border-gray-300 bg-gray-100 hover:bg-gray-200'}`}
                title="Undo last layout change"
              >
                Undo
              </button>
              <button
                id="btn-redo-logo"
                onClick={redo}
                disabled={future.length === 0}
                className={`flex-1 sm:flex-initial rounded-md border px-3 py-2 text-sm font-semibold transition disabled:opacity-50 disabled:cursor-not-allowed ${isDark ? 'border-[#3e4042] bg-[#2a2a2f] text-[#e4e6eb] hover:bg-[#353539]' :
                  'border-gray-300 bg-gray-100 hover:bg-gray-200'}`}
                title="Redo layout change"
              >
                Redo
              </button>
              <button
                id="btn-reset-logos"
                onClick={resetLayout}
                disabled={isLockedCertification}
                className={`flex-1 sm:flex-initial rounded-md border px-3 py-2 text-sm font-semibold disabled:opacity-50 disabled:cursor-not-allowed ${isDark ? 'border-[#3e4042] bg-[#2a2a2f] text-[#e4e6eb] hover:bg-[#353539]' :
                  'border-gray-300 bg-gray-100 hover:bg-gray-200'}`}
              >
                Reset Layout
              </button>
              <button
                id="btn-reset-all-logos"
                onClick={() => setIsResetConfirmOpen(true)}
                disabled={saving || loading || isLockedCertification}
                className={`flex-1 sm:flex-initial rounded-md border px-3 py-2 text-sm font-semibold transition disabled:opacity-50 disabled:cursor-not-allowed ${isDark
                    ? 'border-red-950 bg-red-950/20 text-red-400 hover:bg-red-950/30'
                    : 'border-red-200 bg-red-50 text-red-700 hover:bg-red-100'
                  }`}
              >
                Reset All Layouts
              </button>
              <button
                id="btn-save-layout"
                onClick={saveLayout}
                disabled={!selectedCertId || saving || saveSuccess || hasPreviewDataUrl(layout) || isLockedCertification}
                className={`w-full sm:w-auto rounded-md px-4 py-2 text-sm font-semibold transition focus:outline-none focus:ring-2 disabled:cursor-not-allowed ${saveSuccess
                  ? 'bg-green-500 text-white border-green-600'
                  : isDark
                    ? 'bg-[#2a2a2f] text-[#e4e6eb] hover:bg-[#353539] focus:ring-[#4e4f50] disabled:bg-[#2a2a2f]/50 border border-[#3e4042]'
                    : 'bg-yellow-400 text-slate-900 hover:bg-yellow-500 focus:ring-yellow-200 disabled:bg-yellow-200'
                  }`}
              >
                {isLockedCertification ? "Archived — Read Only" : saving ? "Saving..." : saveSuccess ? "Saved!" : hasPreviewDataUrl(layout) ? "Waiting for upload..." : "Save Layout"}
              </button>
            </div>
          </div>
        </header>

        {/* Workspace Layout Grid */}
        <div className="grid grid-cols-1 gap-4 xl:grid-cols-[350px_1fr]">
          {/* Layout Editor Sidebar */}
          <aside className={`rounded-xl border p-4 shadow-sm ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-gray-200 bg-white'}`}>
            <h2 className={`mb-3 text-lg font-bold ${isDark ? 'text-white' : 'text-[#4f2018]'}`}>Layout & Header Editor</h2>
            <div className="space-y-3">
              <div>
                <DropDown
                  label="Certificate Type"
                  name="certificateType"
                  value={selectedCertificateName}
                  onChange={handleCertChange}
                  options={certificateOptions}
                  labelColor={isDark ? 'text-[#b0b3b8]' : 'text-gray-700'}
                />
              </div>

              {isLockedCertification && (
                <div className={`rounded-lg border px-3 py-2 text-xs font-medium ${isDark ? 'border-yellow-900/50 bg-yellow-900/10 text-yellow-400' : 'border-yellow-300 bg-yellow-50 text-yellow-800'}`}>
                  This certificate is archived — the template is read-only. Restore it from the Archived Documents tab to make changes.
                </div>
              )}

              {/* Main Logo Uploader */}
              <UploadDropZone label="Main Logo" onFiles={updateMainLogo} disabled={!selectedCertId || isLockedCertification} />
              <label className="mt-1.5 mb-3 flex items-center gap-2 cursor-pointer select-none">
                <input
                  id="checkbox-apply-main-logo"
                  type="checkbox"
                  checked={applyMainLogoToAll}
                  onChange={(e) => setApplyMainLogoToAll(e.target.checked)}
                  disabled={!selectedCertId || isLockedCertification}
                  className={`w-3.5 h-3.5 rounded border focus:ring-0 cursor-pointer disabled:cursor-not-allowed ${isDark
                    ? 'border-[#3e4042] bg-[#242526] text-yellow-500'
                    : 'border-gray-300 text-yellow-500'
                    }`}
                />
                <span className={`text-[11px] font-semibold ${isDark ? 'text-[#b0b3b8]' : 'text-gray-600'}`}>
                  Apply main logo to all templates
                </span>
              </label>

              {/* Header Right Logo Uploader */}
              <UploadDropZone label="Header Right Logo" onFiles={updateRightLogo} disabled={!selectedCertId || isLockedCertification} />
              <label className="mt-1.5 mb-3 flex items-center gap-2 cursor-pointer select-none">
                <input
                  id="checkbox-apply-right-logo"
                  type="checkbox"
                  checked={applyRightLogoToAll}
                  onChange={(e) => setApplyRightLogoToAll(e.target.checked)}
                  disabled={!selectedCertId || isLockedCertification}
                  className={`w-3.5 h-3.5 rounded border focus:ring-0 cursor-pointer disabled:cursor-not-allowed ${isDark
                    ? 'border-[#3e4042] bg-[#242526] text-yellow-500'
                    : 'border-gray-300 text-yellow-500'
                    }`}
                />
                <span className={`text-[11px] font-semibold ${isDark ? 'text-[#b0b3b8]' : 'text-gray-600'}`}>
                  Apply right logo to all templates
                </span>
              </label>

              {/* Header Logo & Title Font Size Sliders */}
              <div className={`rounded-lg border p-3 space-y-3 ${isDark ? 'border-[#3e4042] bg-[#1f1f1f]' : 'border-gray-200 bg-gray-50'}`}>
                <div>
                  <p className={`text-xs font-semibold uppercase ${isDark ? 'text-[#b0b3b8]' : 'text-gray-700'}`}>Header Logo Size</p>
                  <label className="mt-1 block text-sm">
                    <span className={`block text-xs ${isDark ? 'text-[#e4e6eb]' : 'text-gray-700'}`}>Size: {layout.headerLogoSize}px</span>
                    <input
                      type="range"
                      min="40"
                      max="140"
                      value={layout.headerLogoSize}
                      onChange={(event) => setLayout((prev) => ({ ...prev, headerLogoSize: Number(event.target.value) }))}
                      className="w-full cursor-pointer"
                    />
                  </label>
                </div>
                <div>
                  <p className={`text-xs font-semibold uppercase ${isDark ? 'text-[#b0b3b8]' : 'text-gray-700'}`}>Header Title Size</p>
                  <label className="mt-1 block text-sm">
                    <span className={`block text-xs ${isDark ? 'text-[#e4e6eb]' : 'text-gray-700'}`}>Title Font: {layout.headerFontSize ?? 14}px</span>
                    <input
                      type="range"
                      min="10"
                      max="28"
                      value={layout.headerFontSize ?? 14}
                      onChange={(event) => setLayout((prev) => ({ ...prev, headerFontSize: Number(event.target.value) }))}
                      className="w-full cursor-pointer"
                    />
                  </label>
                </div>
              </div>

              {/* Header Text Lines Editor */}
              <div className={`rounded-lg border p-3 space-y-2 ${isDark ? 'border-[#3e4042] bg-[#1f1f1f]' : 'border-gray-200 bg-gray-50'}`}>
                <div className="flex items-center justify-between">
                  <p className={`text-xs font-semibold uppercase ${isDark ? 'text-[#b0b3b8]' : 'text-gray-700'}`}>Header Text Lines</p>
                  <button
                    type="button"
                    onClick={() => setLayout((prev) => ({ ...prev, headerLines: [...(prev.headerLines || []), "NEW HEADER LINE"] }))}
                    disabled={isLockedCertification}
                    className="text-[11px] font-semibold text-blue-500 hover:underline disabled:opacity-50"
                  >
                    + Add Line
                  </button>
                </div>
                {(layout.headerLines || []).map((line, idx) => (
                  <div key={`edit-line-${idx}`} className="flex items-center gap-1.5">
                    <input
                      type="text"
                      value={line}
                      onChange={(e) => {
                        const val = e.target.value;
                        setLayout((prev) => {
                          const next = [...(prev.headerLines || [])];
                          next[idx] = val;
                          return { ...prev, headerLines: next };
                        });
                      }}
                      disabled={isLockedCertification}
                      className={`w-full text-xs rounded border px-2 py-1 focus:outline-none ${isDark ? 'border-[#3e4042] bg-[#242526] text-white' : 'border-gray-300 bg-white text-gray-900'}`}
                      placeholder={`Line ${idx + 1}`}
                    />
                    {(layout.headerLines || []).length > 1 && (
                      <button
                        type="button"
                        onClick={() => {
                          setLayout((prev) => ({
                            ...prev,
                            headerLines: (prev.headerLines || []).filter((_, i) => i !== idx),
                          }));
                        }}
                        disabled={isLockedCertification}
                        className="text-red-500 hover:text-red-700 text-xs px-1.5 font-bold"
                        title="Remove line"
                      >
                        ×
                      </button>
                    )}
                  </div>
                ))}
              </div>

              {/* Footer Logos Uploader */}
              <UploadDropZone label="Footer Logos" multiple onFiles={addFooterLogos} disabled={!selectedCertId || isLockedCertification} />
              <label className="mt-1.5 mb-3 flex items-center gap-2 cursor-pointer select-none">
                <input
                  id="checkbox-apply-footer-logos"
                  type="checkbox"
                  checked={applyFooterLogosToAll}
                  onChange={(e) => setApplyFooterLogosToAll(e.target.checked)}
                  disabled={!selectedCertId || isLockedCertification}
                  className={`w-3.5 h-3.5 rounded border focus:ring-0 cursor-pointer disabled:cursor-not-allowed ${isDark
                    ? 'border-[#3e4042] bg-[#242526] text-yellow-500'
                    : 'border-gray-300 text-yellow-500'
                    }`}
                />
                <span className={`text-[11px] font-semibold ${isDark ? 'text-[#b0b3b8]' : 'text-gray-600'}`}>
                  Apply footer logos to all templates
                </span>
              </label>

              {/* Footer Logo Slider */}
              <div className={`rounded-lg border p-3 ${isDark ? 'border-[#3e4042] bg-[#1f1f1f]' : 'border-gray-200 bg-gray-50'}`}>
                <p className={`text-xs font-semibold uppercase ${isDark ? 'text-[#b0b3b8]' : 'text-gray-700'}`}>Footer Logo Size</p>
                <label className="mt-2 block text-sm">
                  <span className={`block ${isDark ? 'text-[#e4e6eb]' : 'text-gray-700'}`}>Size: {layout.footerLogoSize}px</span>
                  <input
                    type="range"
                    min="40"
                    max="100"
                    value={layout.footerLogoSize}
                    onChange={(event) => setLayout((prev) => ({ ...prev, footerLogoSize: Number(event.target.value) }))}
                    className="w-full"
                  />
                </label>
              </div>

              {/* Footer Logo List */}
              <div className="space-y-2">
                {layout.footerUrls.map((logoUrl, index) => (
                  <div key={`${logoUrl}-${index}`} className={`rounded-lg border p-3 ${isDark ? 'border-[#3e4042] bg-[#1f1f1f]' : 'border-gray-200 bg-gray-50'}`}>
                    <div className="mb-2 flex items-center justify-between">
                      <p className={`text-xs font-semibold ${isDark ? 'text-[#b0b3b8]' : 'text-gray-700'}`}>Footer Logo {index + 1}</p>
                      <button
                        onClick={() => removeFooterLogo(index)}
                        className={`rounded border px-2 py-1 text-xs font-semibold ${isDark ? 'border-red-950 bg-red-950/20 text-red-400 hover:bg-red-950/30' : 'border-red-300 text-red-700 hover:bg-red-50'}`}
                      >
                        Remove
                      </button>
                    </div>
                    <img src={logoUrl} alt={`Footer Logo ${index + 1}`} className="h-12 w-auto object-contain" />
                  </div>
                ))}
              </div>
            </div>
          </aside>

          {/* Certificate Preview Canvas */}
          <section className={`rounded-2xl border p-4 sm:p-8 ${isDark ? 'border-[#3e4042] bg-[#353638]' : 'border-gray-200 bg-white'}`}>
            <CertificatePreviewCanvas layout={layout} certId={Number(selectedCertId)} />
          </section>
        </div>
      </div>

      {/* Modals & Alerts */}
      <ConfirmationModal
        isOpen={isResetConfirmOpen}
        type="danger"
        title="Reset All Logo Layouts"
        message="Are you sure you want to reset ALL certificate logos and layouts to defaults? This action will overwrite all custom configurations for all types and cannot be undone."
        onConfirm={async () => {
          setIsResetConfirmOpen(false);
          await resetAllLayouts();
        }}
        onClose={() => setIsResetConfirmOpen(false)}
        onCancel={() => setIsResetConfirmOpen(false)}
        confirmText="Reset All"
        cancelText="Cancel"
      />

      <SuccessToast message={successMessage} onClose={() => setSuccessMessage("")} />
      <ErrorToast message={errorMessage} onClose={() => setErrorMessage("")} />
    </div>
  );
};

export default CertificateTemplateManagement;