import React, { useEffect, useState, useRef, useMemo, useCallback } from "react";
import InputGroup from "../components/InputGroup.jsx";
import { useTheme } from "../context/ThemeContext";
import { useReferenceData } from "../context/ReferenceDataContext";
import { PrinterIcon } from "@heroicons/react/24/solid";
import { getAcademicRecords, getCertifications, getCertificationLayouts, markCertificatesGenerated } from "../services/api";
import { CertHeader, CertFooter, getTodayDate } from "../utils/helpers.jsx";
import { CERT_CONFIG } from "../utils/Certification.jsx";
import DropDown from "../components/DropDown.jsx";
import {
  CERT_TEMPLATE_LAYOUT_CHANGED,
  DEFAULT_CERTIFICATE_LAYOUT,
  normalizeCertificateLayout,
} from "../utils/certificateTemplateSettings.js";

const toCertificateRows = (raw) => {
  if (Array.isArray(raw)) return raw;
  if (Array.isArray(raw?.data)) return raw.data;
  return [];
};

const useDebounce = (value, delay) => {
  const [debouncedValue, setDebouncedValue] = useState(value);
  const timeoutRef = useRef(null);

  useEffect(() => {
    timeoutRef.current = setTimeout(() => setDebouncedValue(value), delay);
    return () => clearTimeout(timeoutRef.current);
  }, [value, delay]);

  return debouncedValue;
};

const semesters   = ["1st Semester", "2nd Semester", "3rd Semester", "Summer"];
const latinHonors = ["(Cum Laude)", "(Magna Cum Laude)", "(Summa Cum Laude)"];
const eduLevels   = ["Undergraduate", "Graduate"];
const yearNum     = ["2", "3", "4", "5"];

const DEFAULT_FORM = {
  docType: null, // Will be set after fetching certifications
  fullName: "", 
  studentNum: "", 
  course: "", 
  syAdmitted: "", 
  eventTitle: "",
  dateGraduated: "", 
  educationLevel: "", 
  diplomaNum: "", 
  major: "", 
  date: "",
  semesters: "", 
  gwa: "", 
  latinHonors: "", 
  eligibilityType: "", 
  ladderizedDegree: "",
  studentStatus: "", 
  cavNum: "", 
  cavSeries: "", 
  amount: "", 
  nstpSerialNum: "",
  lastSemesters: "", 
  lastSy: "", 
  units: "", 
  semestersNum: "", 
  officialReceiptNum: "",
  yearNum: "",
  signee: "", // Set once signatories load — see the effect below.
};

// ─── Field Config ─────
// ComponentType: "input" | "dropdown"

const buildFieldConfig = (courseOptions) => [
  ["fullName",          "input",    "Full Name",               { placeholder: "Juan Santos Dela Cruz Jr." }],
  ["studentNum",        "input",    "Student Number",          { placeholder: "e.g. 2023-00101-TG-0" }],
  ["course",            "dropdown", "Course",                  { options: courseOptions }],
  ["latinHonors",       "dropdown", "Latin Honors",            { options: latinHonors }],
  ["major",             "input",    "Major",                   { placeholder: "e.g. Human Resource Management" }],
  ["educationLevel",    "dropdown", "Education Level",         { options: eduLevels }],
  ["semesters",         "dropdown", "Semester",                { options: semesters }],
  ["yearNum",           "dropdown", "Year",                    { options: yearNum }],
  ["syAdmitted",        "input",    "S.Y. Admitted",           { type: "date", placeholder: "XXXX", max: getTodayDate() }],
  ["eventTitle",        "input",    "Event/Seminar Title",     { placeholder: "e.g. 1st ICT Congress" }],
  ["dateGraduated",     "input",    "Date of Graduation",      { type: "date", max: getTodayDate() }],
  ["diplomaNum",        "input",    "Diploma Number",          { placeholder: "e.g. 2026-XXXX" }],
  ["officialReceiptNum","input",    "Official Receipt Number", { placeholder: "e.g. XXXXXXX" }],
  ["gwa",               "input",    "General Weighted Average",{ placeholder: "e.g. 1.25" }],
  ["eligibilityType",   "input",    "Eligibility Type",        { placeholder: "e.g. Professional License" }],
  ["lastSemesters",     "dropdown", "Last Semester",           { options: semesters }],
  ["lastSy",            "input",    "Last S.Y. Admitted",      { type: "date", placeholder: "XXXX", max: getTodayDate() }],
  ["units",             "input",    "Number of Units",         { placeholder: "e.g. 120" }],
  ["semestersNum",      "input",    "Number of Semesters",     { placeholder: "e.g. 8" }],
  ["ladderizedDegree",  "dropdown", "Ladderized Degree",       { options: courseOptions }],
  ["studentStatus",     "input",    "Student Status",          { placeholder: "e.g. Graduated - BSBAMM" }],
  ["cavNum",            "input",    "CAV Number",              { placeholder: "e.g. TG-008" }],
  ["cavSeries",         "input",    "Series Year",             { placeholder: "e.g. 2026" }],
  ["amount",            "input",    "Amount (PHP)",            { placeholder: "e.g. 620.00" }],
  ["nstpSerialNum",     "input",    "NSTP Serial Number",      { placeholder: "e.g. C-13-113719-16" }],
];

// ─── Memoized Certificate Preview ───
  const CertificatePreview = React.memo(({ certConfig, activeLayout, debouncedFormData, isDark, pageDimensions, marginValue, margins }) => {
    const pageSizeSpec = `${pageDimensions.widthInches}in ${pageDimensions.heightInches}in`;

    const fontScale = useMemo(() => 1.0, []);

    return (
      <div 
        id="print-area" 
        style={{
          "--print-margin": `${marginValue}in`,
          "--header-logo-size": `${activeLayout?.headerLogoSize ?? 120}px`,
          "--footer-logo-size": `${activeLayout?.footerLogoSize ?? 45}px`,
          padding: "var(--print-margin)",
          backgroundColor: "white",
          color: "black",
          width: `${pageDimensions.width}px`,
          height: `${pageDimensions.height}px`,
          display: "flex",
          flexDirection: "column",
          boxSizing: "border-box",
        }}
        className="bg-white text-black print:!w-full print:!h-full print:!min-h-0 print:!max-h-full"
      >
        <style dangerouslySetInnerHTML={{__html: `
          @page {
            size: ${pageSizeSpec};
            margin: 0;
          }
        `}} />
        {!certConfig?.hideHeaderFooter && <CertHeader layout={activeLayout} />}
        
        <div 
          className="flex-1 min-h-0 overflow-visible transition-all"
          style={{ fontSize: `${fontScale * 100}%` }}
        >
          {certConfig?.renderBody(debouncedFormData, activeLayout)}
        </div>
        
        {!certConfig?.hideHeaderFooter && <CertFooter layout={activeLayout} />}
      </div>
    );
  });

CertificatePreview.displayName = 'CertificatePreview';

// ─── Component ───────
const normalizeCertName = (name) => (typeof name === "string" ? name.replace(/[\r\n]+/g, " ").replace(/\s+/g, " ").trim() : "");

const GenerateCertification = ({ initialData, onClose, onCertificatePrinted, onLoadingChange }) => {
  const resolveCertConfigId = useCallback(
    (docType) => {
      const numericDocType = Number(docType);
      if (Number.isInteger(numericDocType) && CERT_CONFIG[numericDocType]) {
        return numericDocType;
      }

      const normalizedDocType = normalizeCertName(docType);
      if (!normalizedDocType) return null;

      const matchedEntry = Object.entries(CERT_CONFIG).find(([, config]) => normalizeCertName(config.name) === normalizedDocType);
      return matchedEntry ? Number(matchedEntry[0]) : null;
    },
    []
  );

  // Create a mapping from CERT_CONFIG ID to certificate name for display
  const certIdToName = Object.entries(CERT_CONFIG).reduce((acc, [id, config]) => {
    acc[id] = config.name;
    return acc;
  }, {});

  // Create a mapping from database certificate_type_id to CERT_CONFIG ID
  // For now, assuming database IDs match CERT_CONFIG IDs
  const certTypeIdToCertConfigId = (dbId) => dbId;

  const [loading, setLoading] = useState(false);
  const [printLoading, setPrintLoading] = useState(false);
  const [certifications, setCertifications] = useState([]);
  const [layoutsByCertId, setLayoutsByCertId] = useState({});
  const [docTypeOptions, setDocTypeOptions] = useState(Object.keys(CERT_CONFIG).map(Number));
  const [certNameById, setCertNameById] = useState(certIdToName);
  const [formData, setFormData] = useState({ ...DEFAULT_FORM, ...(initialData ?? {}) });
  const [savedData, setSavedData] = useState({ ...DEFAULT_FORM, ...(initialData ?? {}) });

  const [paperSize, setPaperSize] = useState("Letter");
  const [orientation, setOrientation] = useState("Portrait");
  const [margins, setMargins] = useState("Narrow (0.25\")");

  const requestedDocTypeId = useMemo(() => Number(initialData?.docType), [initialData?.docType]);

const requestedCertNames = useMemo(() => Array.from(
  new Set(
    [
      ...(Array.isArray(initialData?.certificateNames) ? initialData.certificateNames : []),
      ...(typeof initialData?.certificateNames === "string" ? [initialData.certificateNames] : []),
      ...(typeof initialData?.docType === "string" ? [initialData.docType] : []),
    ]
      .map(normalizeCertName)
      .filter((name) => name.length > 0)
  )
), [initialData?.certificateNames, initialData?.docType]);

const lockDocTypeToRequest = useMemo(() => 
  Boolean(initialData?.requestId && (requestedCertNames.length > 0 || Number.isInteger(requestedDocTypeId)))
, [initialData?.requestId, requestedCertNames.length, requestedDocTypeId]);

useEffect(() => {
    const fetchLayoutData = async () => {
      try {
        const [certRes, layoutRes] = await Promise.all([
          getCertifications(),
          getCertificationLayouts(),
        ]);
        
        const certs = toCertificateRows(certRes?.data) || [];
        const layouts = toCertificateRows(layoutRes?.data) || [];

        // 1. Build mapping from database certificate_type_id to CERT_CONFIG ID
        const dbCertIdToCertConfigId = {};
        const fetchedIds = [];
        
        certs.forEach((cert) => {
          const normalizedName = normalizeCertName(cert?.certificate_name);
          const certConfigId = Object.entries(CERT_CONFIG).find(
            ([_, config]) => normalizeCertName(config.name) === normalizedName
          )?.[0];
          
          if (certConfigId) {
            dbCertIdToCertConfigId[cert.certificate_type_id] = Number(certConfigId);
            fetchedIds.push(Number(certConfigId));
          }
        });

        // 2. Map the layouts using BOTH IDs so both systems can find them
        const nextLayouts = {};
        layouts.forEach((layoutRow) => {
          const dbId = layoutRow.certificate_type_id;
          const normalizedLayout = normalizeCertificateLayout(layoutRow);
          
          // Save under database ID
          nextLayouts[String(dbId)] = normalizedLayout;
          
          // Also save under the CERT_CONFIG ID
          const configId = dbCertIdToCertConfigId[dbId];
          if (configId) {
            nextLayouts[String(configId)] = normalizedLayout;
          }
        });

        let finalDocTypeOptions = fetchedIds; // Default: show all

        // If this request came from a specific student application, lock the dropdown
        if (lockDocTypeToRequest) {
          const requestedIdsFromNames = requestedCertNames
            .map((name) => {
              return Object.entries(CERT_CONFIG).find(
                ([_, config]) => normalizeCertName(config.name) === name
              )?.[0];
            })
            .filter(Boolean)
            .map(Number);

          const requestedIds = Array.from(
            new Set([
              ...requestedIdsFromNames,
              ...(Number.isInteger(requestedDocTypeId) && requestedDocTypeId in CERT_CONFIG ? [requestedDocTypeId] : []),
            ])
          );
          
          // Apply the restriction if we successfully mapped the requested IDs
          if (requestedIds.length > 0) {
            finalDocTypeOptions = requestedIds;
          }
        }

        // 4. Update the States
        setCertifications(certs);
        setLayoutsByCertId(nextLayouts);
        setDocTypeOptions(finalDocTypeOptions); // Now it uses the locked options!

        // Ensure the current selection is valid for the new restricted list
        setFormData((prev) => {
          const prevDocType = Number(prev.docType);
          const defaultId = finalDocTypeOptions.includes(prevDocType) ? prevDocType : (finalDocTypeOptions[0] ?? prevDocType ?? null);
          return { ...prev, docType: defaultId };
        });
        setSavedData((prev) => {
          const prevDocType = Number(prev.docType);
          const defaultId = finalDocTypeOptions.includes(prevDocType) ? prevDocType : (finalDocTypeOptions[0] ?? prevDocType ?? null);
          return { ...prev, docType: defaultId };
        });

      } catch (err) {
        console.error("Error fetching certification layouts:", err);
      }
    };

    fetchLayoutData();
  }, [lockDocTypeToRequest, requestedCertNames, requestedDocTypeId]); // ensure dependencies are correct // Add your dependencies here

  useEffect(() => {
    const handleTemplateLayoutChanged = (event) => {
      const certTypeId = Number(event?.detail?.certTypeId);
      if (!Number.isFinite(certTypeId)) return;

      const updatedLayout = normalizeCertificateLayout(event?.detail?.layout);

      setLayoutsByCertId((prev) => ({
        ...prev,
        [certTypeId]: updatedLayout,
        [String(certTypeId)]: updatedLayout,
      }));
    };

    window.addEventListener(CERT_TEMPLATE_LAYOUT_CHANGED, handleTemplateLayoutChanged);

    return () => {
      window.removeEventListener(CERT_TEMPLATE_LAYOUT_CHANGED, handleTemplateLayoutChanged);
    };
  }, []);

  useEffect(() => {
    // Automatically collapse navigation sidebar to maximize workspace for certificate generation
    window.dispatchEvent(new CustomEvent('collapse-sidebar', { detail: true }));
  }, []);

  useEffect(() => {
    if (typeof onLoadingChange === "function") {
      onLoadingChange(loading);
    }
  }, [loading, onLoadingChange]);

  useEffect(() => {
    if (initialData?.or_number) {
      setFormData((prev) => ({ ...prev, officialReceiptNum: initialData.or_number }));
    }
  }, [initialData?.or_number]);

  useEffect(() => {
    if (formData.course && formData.studentNum) return;
    const studentNum = initialData?.studentNum;
    const studentId  = initialData?.studentId;
    const fetch = async () => {
      try {
        setLoading(true);
        const res = await getAcademicRecords();
        const records = Array.isArray(res?.data?.data) ? res.data.data : (Array.isArray(res?.data) ? res.data : []);
        const record = records.find(
          (r) => r.student_number === studentNum || r.student_id === studentId
        );
        if (record) {
          const educationLevel = ["Alumni", "Graduated"].includes(record.status) ? "Graduate" : "Undergraduate";
          setFormData((prev) => ({
            ...prev,
            course: record.course || prev.course,
            studentNum: record.student_number || prev.studentNum,
            educationLevel,
          }));
        }
      } catch (err) {
        console.error("Error fetching course data:", err);
      } finally {
        setLoading(false)
      }
    };
    fetch();
  }, [initialData?.studentNum, initialData?.studentId]);

  // Create display options (names) in the order of docTypeOptions
  const docTypeDisplayOptions = useMemo(() =>
    docTypeOptions.map((id) => certIdToName[id])
  , [docTypeOptions, certIdToName]);

  const handleChange = (e) => {
    const { name, value } = e.target;
    
    // If this is the docType field, convert display name back to numeric ID
    if (name === "docType") {
      const selectedId = docTypeOptions.find((id) => certIdToName[id] === value);
      const nextDocType = selectedId || value;
      setFormData((prev) => ({ ...prev, [name]: nextDocType }));
      // Always reflect doc type changes in preview so layout switches immediately.
      setSavedData((prev) => ({ ...prev, [name]: nextDocType }));
    } else {
      setFormData((prev) => ({ ...prev, [name]: value }));
    }
  };

  const handlePrint = async () => {
    setPrintLoading(true);

    await new Promise(resolve => setTimeout(resolve, 0));

    window.print();

    // Clear loading state after a brief delay (covers most print scenarios)
    setTimeout(() => setPrintLoading(false), 1000);

    // Also clear when print dialog closes (if user cancels)
    const handleAfterPrint = () => {
      setPrintLoading(false);
      window.removeEventListener("afterprint", handleAfterPrint);
    };
    window.addEventListener("afterprint", handleAfterPrint, { once: true });

    if (initialData?.requestId) {
      const requestId = initialData.requestId;

      // Resolve which specific request_certificate row is currently
      // selected, via the name -> id map CertificateModal.jsx built from
      // the real backend data. formData.docType is a CERT_CONFIG numeric
      // id here, not a name, so it's translated back through certIdToName
      // and normalized the same way the map's keys were built. If it
      // can't be resolved (no map entry, e.g. an older caller that never
      // passed certificateIdsByName, or the ambiguous same-name-twice
      // edge case noted in CertificateModal.jsx), certificateId stays
      // undefined and markCertificatesGenerated falls back to its
      // request-wide behavior — never a hard failure either way.
      const selectedCertName = normalizeCertName(certIdToName[formData.docType]);
      const certificateId = initialData?.certificateIdsByName?.[selectedCertName];

      // Real, server-side "generated" signal — see
      // DocumentRequestService::markCertificatesGenerated(). This is what
      // the ReadyToClaim guards actually check now; the onCertificatePrinted
      // callback below just invalidates the dashboard's cached request list
      // so it reflects the change sooner than its 30s poll — it no longer
      // gates anything by itself. Fire-and-forget: a failure here shouldn't
      // block the print the staff member already completed, but is
      // surfaced so it isn't silently lost.
      markCertificatesGenerated(requestId, certificateId).catch((err) => {
        console.error("Failed to record certificate as generated:", err);
      });

      if (onCertificatePrinted) {
        onCertificatePrinted(requestId);
      }
    }
  };

  const resolvedFormDocTypeId = resolveCertConfigId(formData.docType);
  const resolvedPreviewDocTypeId = resolveCertConfigId(savedData.docType);

  useEffect(() => {
    if (!resolvedPreviewDocTypeId) return;
    const config = CERT_CONFIG[resolvedPreviewDocTypeId];
    if (config) {
      setPaperSize(config.defaultPaperSize || "Letter");
      setOrientation(config.defaultOrientation || "Portrait");
      setMargins(config.defaultMargins || "Narrow (0.25\")");
    }
  }, [resolvedPreviewDocTypeId]);

  const formCertConfig = resolvedFormDocTypeId ? CERT_CONFIG[resolvedFormDocTypeId] : undefined;
  const previewCertConfig = resolvedPreviewDocTypeId ? CERT_CONFIG[resolvedPreviewDocTypeId] : undefined;
  const shouldShow = useCallback((fieldName) => formCertConfig?.fields.includes(fieldName), [formCertConfig]);
  
  // Get the certificate name for display
  const certDisplayName = previewCertConfig?.name || certIdToName[resolvedPreviewDocTypeId] || "Certificate";

  const activeLayout = useMemo(() => {
    // Look up the layout using the exact ID selected in the dropdown
    if (!resolvedPreviewDocTypeId) return DEFAULT_CERTIFICATE_LAYOUT;
    
    const id = String(resolvedPreviewDocTypeId);
    
    // If we have a layout in the database for this ID, use it. Otherwise, use default.
    return layoutsByCertId[id] ?? DEFAULT_CERTIFICATE_LAYOUT;
  }, [resolvedPreviewDocTypeId, layoutsByCertId]);

  const pageDimensions = useMemo(() => {
    let w = 8.5;
    let h = 11;
    if (paperSize === "A4") {
      w = 8.27;
      h = 11.69;
    } else if (paperSize === "Legal") {
      w = 8.5;
      h = 14;
    }
    return orientation === "Portrait"
      ? { width: Math.round(w * 96), height: Math.round(h * 96), widthInches: w, heightInches: h }
      : { width: Math.round(h * 96), height: Math.round(w * 96), widthInches: h, heightInches: w };
  }, [paperSize, orientation]);

  const marginValue = useMemo(() => {
    if (margins.startsWith("Narrow")) return 0.25;
    if (margins.startsWith("Wide")) return 1.0;
    if (margins.startsWith("None")) return 0;
    return 0.75; // Default: Normal (0.75")
  }, [margins]);

  const [scale, setScale] = useState(1);
  const previewContainerRef = useRef(null);

  const updateScale = useCallback(() => {
    if (!previewContainerRef.current) return;
    const rect = previewContainerRef.current.getBoundingClientRect();
    const availableWidth = Math.max(200, rect.width - 32);
    const availableHeight = Math.max(200, rect.height - 32);
    const scaleX = availableWidth / pageDimensions.width;
    const scaleY = availableHeight / pageDimensions.height;
    setScale(Math.min(1, scaleX, scaleY));
  }, [pageDimensions.width, pageDimensions.height]);

  useEffect(() => {
    updateScale();
    const observer = new ResizeObserver(updateScale);
    if (previewContainerRef.current) {
      observer.observe(previewContainerRef.current);
    }
    return () => observer.disconnect();
  }, [updateScale]);

  const { isDark } = useTheme();
  const { programs, signatories } = useReferenceData();

  // Build course options from the live programs table.
  // Falls back to an empty array while programs are loading —
  // the DropDown will show "Please Select" with no options until ready.
  const courseOptions = programs.map((p) => p.name);

  // Signee options now come from the signatories table (see
  // SignatoryController) instead of the old hardcoded signeeOptions
  // array — an admin can add/rename/reorder signatories without a
  // frontend deploy. Falls back to an empty array while loading, same
  // as courseOptions above.
  const signeeOptions = signatories.map((s) => s.name);

  // Once signatories load, default the signee to the first one
  // (sort_order 0) if nothing has been explicitly selected yet — mirrors
  // the docType-defaulting effect above, and replaces the old hardcoded
  // `signee: "Mhel P. Garcia"` in DEFAULT_FORM.
  useEffect(() => {
    if (signatories.length === 0) return;
    setFormData((prev) => (prev.signee ? prev : { ...prev, signee: signatories[0].name }));
    setSavedData((prev) => (prev.signee ? prev : { ...prev, signee: signatories[0].name }));
  }, [signatories]);

  const debouncedFormData = useDebounce(formData, 200);

  const FIELD_CONFIG = buildFieldConfig(courseOptions);

  return (
    <div className={`flex flex-col min-h-screen p-4 md:p-6 transition-colors ${isDark ? 'bg-[#18191a] text-[#e4e6eb]' : 'bg-gray-50 text-gray-900'}`}>

      {/* Header Toolbar */}
      <div className="relative z-10 w-full max-w-7xl mx-auto mb-4 print:hidden">
        <div className={`flex flex-col gap-4 rounded-2xl p-4 sm:p-5 shadow-sm border transition-colors md:flex-row md:items-center md:justify-between ${
          isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-gray-200 bg-white'
        }`}>
          <div className="flex flex-wrap items-center gap-3 relative z-10 w-full md:flex-1">
            <div className="w-full sm:w-64 md:max-w-xs shrink-0">
              <DropDown
                label="Certification Type"
                name="docType"
                value={certIdToName[formData.docType] || "Certificate"}
                onChange={handleChange}
                options={docTypeDisplayOptions}
                labelColor={isDark ? 'text-[#b0b3b8]' : 'text-gray-700'}
              />
            </div>
            <div className="w-28 sm:w-32 shrink-0">
              <DropDown
                label="Paper Size"
                name="paperSize"
                value={paperSize}
                onChange={(e) => setPaperSize(e.target.value)}
                options={["Letter", "A4", "Legal"]}
                labelColor={isDark ? 'text-[#b0b3b8]' : 'text-gray-700'}
              />
            </div>
            <div className="w-36 sm:w-40 shrink-0">
              <DropDown
                label="Margins"
                name="margins"
                value={margins}
                onChange={(e) => setMargins(e.target.value)}
                options={["Normal (0.75\")", "Narrow (0.25\")", "Wide (1.0\")", "None"]}
                labelColor={isDark ? 'text-[#b0b3b8]' : 'text-gray-700'}
              />
            </div>
          </div>
          <div className="flex items-center gap-3 w-full md:w-auto shrink-0">
            {onClose && (
              <button
                onClick={onClose}
                className={`flex-1 sm:flex-none flex items-center justify-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-semibold shadow-xs transition-all active:scale-95 ${
                  isDark
                    ? 'border-[#3e4042] bg-[#2a2a2f] text-[#e4e6eb] hover:bg-[#353539]'
                    : 'border-gray-300 bg-gray-100 text-gray-700 hover:bg-gray-200'
                }`}
              >
                ← Back
              </button>
            )}
            <button
              onClick={handlePrint}
              disabled={printLoading}
              className={`flex-1 sm:flex-none flex items-center justify-center gap-2 rounded-xl px-5 py-2.5 text-sm font-bold shadow-md transition-all active:scale-95 ${
                printLoading
                  ? 'bg-gray-400 cursor-not-allowed text-white'
                  : isDark
                    ? 'bg-[#2a2a2f] hover:bg-[#353539] text-[#e4e6eb] border border-[#3e4042]'
                    : 'bg-[#800000] hover:bg-[#600000] text-white shadow-red-950/20'
              }`}
            >
              <PrinterIcon className="w-4 h-4" />
              {printLoading ? 'Preparing...' : 'Print Certificate'}
            </button>
          </div>
        </div>
      </div>

      <div className="flex-1 flex flex-col lg:flex-row gap-6 items-start w-full max-w-7xl mx-auto print:block print:max-w-none print:p-0">

        {/* Form Controls Sidebar */}
        <div className="w-full lg:w-96 shrink-0 order-1 print:hidden">
          <div className={`rounded-2xl border p-5 shadow-sm transition-colors ${
            isDark ? 'border-[#3e4042] bg-[#242526] text-[#e4e6eb]' : 'border-gray-200 bg-white text-gray-900'
          }`}>
            <div className="flex items-center justify-between mb-4">
              <h3 className={`text-base font-extrabold uppercase tracking-tight ${isDark ? 'text-white' : 'text-[#800000]'}`}>
                Edit Information
              </h3>
              <span className={`text-[11px] font-semibold px-2 py-0.5 rounded-full ${
                isDark ? 'bg-[#3a3b3c] text-[#b0b3b8]' : 'bg-gray-100 text-gray-600'
              }`}>
                Live Preview
              </span>
            </div>

            <form className={`space-y-4 ${loading ? "opacity-50 pointer-events-none" : ""}`}>
              {loading && <p className="text-xs text-amber-500 animate-pulse">Loading academic record data...</p>}

              {FIELD_CONFIG.map(([name, type, label, props]) => {
                if (!shouldShow(name)) return null;
                if (type === "dropdown") {
                  return (
                    <DropDown
                      key={name}
                      label={label}
                      name={name}
                      value={formData[name]}
                      onChange={handleChange}
                      options={props.options}
                      labelColor={isDark ? 'text-[#b0b3b8]' : 'text-gray-700'}
                    />
                  );
                }
                return (
                  <InputGroup
                    key={name}
                    label={label}
                    name={name}
                    value={formData[name]}
                    onChange={handleChange}
                    {...props}
                    voiceEnabled={props.type !== "date"}
                    labelColor={isDark ? 'text-[#b0b3b8]' : 'text-gray-700'} 
                  />
                );
              })}

              <InputGroup
                label="Date Issued"
                type="date"
                name="date"
                value={formData.date}
                onChange={handleChange}
                voiceEnabled={false}
                labelColor={isDark ? 'text-[#b0b3b8]' : 'text-gray-700'}
                max={getTodayDate()}
              />

              <DropDown
                label="Signee"
                name="signee"
                value={formData.signee}
                onChange={handleChange}
                options={signeeOptions}
                labelColor={isDark ? 'text-[#b0b3b8]' : 'text-gray-700'}
              />

              <button
                type="button"
                onClick={() => setSavedData({ ...formData })}
                className={`w-full mt-5 rounded-xl font-bold py-2.5 text-sm transition-all active:scale-95 ${
                  isDark
                    ? 'bg-[#2a2a2f] hover:bg-[#353539] text-[#e4e6eb] border border-[#3e4042]'
                    : 'bg-[#800000] hover:bg-[#600000] text-white shadow-md'
                }`}
              >
                Apply Changes
              </button>
            </form>
          </div>
        </div>

        {/* Certificate Preview Card */}
        <div className={`relative order-2 flex flex-1 flex-col rounded-2xl border min-h-[500px] max-h-[82vh] overflow-hidden transition-colors print:bg-white print:text-black print:rounded-none print:border-0 print:min-h-0 print:max-h-none ${
          isDark ? 'border-[#3e4042] bg-[#353638] text-[#e4e6eb]' : 'border-gray-200 bg-gray-100 text-gray-900'
        }`}>
          <div className={`flex items-center justify-between px-5 py-3 border-b shrink-0 print:hidden ${
            isDark ? 'bg-[#242526] border-[#3e4042]' : 'bg-white border-gray-200'
          }`}>
            <h2 className={`text-sm font-extrabold uppercase tracking-tight ${isDark ? 'text-white' : 'text-gray-800'}`}>
              Certificate Preview
            </h2>
            <span className={`text-xs font-semibold px-2.5 py-1 rounded-md border ${
              isDark ? 'border-[#3e4042] bg-[#1f1f1f] text-[#b0b3b8]' : 'border-gray-200 bg-gray-50 text-gray-600'
            }`}>
              Scale: {Math.round(scale * 100)}%
            </span>
          </div>
          
          <div 
            ref={previewContainerRef}
            className="flex-1 flex justify-center items-start p-4 sm:p-6 overflow-auto print:p-0 print:overflow-visible"
          >
            <div 
              style={{
                width: `${pageDimensions.width * scale}px`,
                height: `${pageDimensions.height * scale}px`,
                overflow: "hidden",
                position: "relative",
              }}
              className="rounded-lg shadow-2xl transition-all border border-gray-300 dark:border-zinc-700 shrink-0 print:shadow-none print:border-0 print:!w-full print:!h-full print:!transform-none print:!static print:!p-0 print:!m-0"
            >
              <div
                style={{
                  width: `${pageDimensions.width}px`,
                  height: `${pageDimensions.height}px`,
                  transform: `scale(${scale})`,
                  transformOrigin: "top left",
                  position: "absolute",
                  top: 0,
                  left: 0,
                }}
                className="print:!static print:!transform-none print:!w-full print:!h-full print:!p-0 print:!m-0"
              >
                <CertificatePreview 
                  certConfig={previewCertConfig} 
                  activeLayout={activeLayout} 
                  debouncedFormData={debouncedFormData} 
                  isDark={isDark} 
                  pageDimensions={pageDimensions}
                  marginValue={marginValue}
                  margins={margins}
                />
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default GenerateCertification;