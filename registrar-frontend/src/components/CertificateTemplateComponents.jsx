import React, { useState } from "react";
import { useTheme } from "../context/ThemeContext";
import { CERT_CONFIG } from "../utils/Certification.jsx";
import { CertFooter, CertHeader } from "../utils/helpers.jsx";
import { SAMPLE_FORM_DATA } from "../utils/certificateTemplateUtils.js";

export const UploadDropZone = ({ label, multiple = false, onFiles, disabled = false }) => {
  const [dragOver, setDragOver] = useState(false);
  const { isDark } = useTheme();

  return (
    <div
      className={`rounded-lg border-2 border-dashed p-3 text-center transition ${dragOver
        ? isDark
          ? "border-[#b08a57] bg-[#2a2a2f]"
          : "border-[#7f3f33] bg-[#fff7f4]"
        : isDark
          ? "border-[#3e4042] bg-[#242526]"
          : "border-gray-300 bg-white"
        }`}
      onDragOver={(event) => {
        if (disabled) return;
        event.preventDefault();
        setDragOver(true);
      }}
      onDragLeave={() => setDragOver(false)}
      onDrop={(event) => {
        if (disabled) return;
        event.preventDefault();
        setDragOver(false);
        const files = Array.from(event.dataTransfer.files || []);
        if (files.length) onFiles(files);
      }}
    >
      <p className={`text-xs font-semibold ${isDark ? 'text-[#e4e6eb]' : 'text-gray-700'}`}>{label}</p>
      <p className={`mt-0.5 text-xs ${isDark ? 'text-[#9a9a9a]' : 'text-gray-500'}`}>Drag and drop image file{multiple ? "s" : ""} here</p>
      <p className={`text-[10px] ${isDark ? 'text-[#808080]' : 'text-gray-400'}`}>PNG, JPG, JPEG, SVG (MAX. 2MB)</p>
      <label
        className={`mt-2 inline-block rounded-md px-3 py-1.5 text-xs font-semibold text-white ${disabled
          ? "cursor-not-allowed bg-gray-400"
          : isDark
            ? "cursor-pointer bg-[#2a2a2f] hover:bg-[#353539] border border-[#3e4042]"
            : "cursor-pointer bg-[#5c2a21] hover:bg-[#492119]"
          }`}
      >
        Upload
        <input
          type="file"
          accept=".png,.jpg,.jpeg,.svg,image/png,image/jpeg,image/svg+xml"
          multiple={multiple}
          className="hidden"
          disabled={disabled}
          onChange={(event) => {
            if (disabled) return;
            const files = Array.from(event.target.files || []);
            if (files.length) onFiles(files);
            event.target.value = "";
          }}
        />
      </label>
    </div>
  );
};

export const CertificatePreviewCanvas = ({ layout, certId }) => {
  const certConfig = CERT_CONFIG[certId] || CERT_CONFIG[1];

  return (
    <div className="relative w-full overflow-auto">
      <div className="flex min-h-180 flex-col">
        {!certConfig?.hideHeaderFooter && <CertHeader layout={layout} />}
        <div className="flex-1 min-h-0">{certConfig?.renderBody(SAMPLE_FORM_DATA, layout)}</div>

        {!certConfig?.hideHeaderFooter && (
          <div className="mt-auto shrink-0 pt-4">
            <CertFooter layout={layout} />
          </div>
        )}
      </div>
    </div>
  );
};
