import React, { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import DropDown from "../components/DropDown";
import InputGroup from "../components/InputGroup.jsx";
import SuccessToast from "../components/SuccessToast.jsx";
import ErrorToast from "../components/ErrorToast.jsx";
import ConfirmationModal from "../components/ConfirmationModal.jsx";
import { useTheme } from "../context/ThemeContext";
import { useCertificateTemplates } from "../hooks/useCertificateTemplates";
import {
  UploadDropZone,
} from "../components/CertificateTemplateComponents";
import { CertHeader, CertFooter } from "../utils/helpers.jsx";
import { hasPreviewDataUrl } from "../utils/certificateTemplateUtils";
import {
  activateCertificationTemplateVersion,
  getCertificateMergeTags,
  getCertificationTemplate,
  getCertificationTemplates,
  getCertificationTemplateVersions,
  saveCertificationTemplate,
} from "../services/api";

const responseRows = (response) => response?.data?.data ?? [];
const responseData = (response) => response?.data?.data ?? null;

const previewTemplate = (content, tags) => {
  return String(content || "")
    .replace(/<script[\s\S]*?<\/script>/gi, "")
    .replace(/\son[a-z]+\s*=\s*(['"]).*?\1/gi, "")
    .replace(/\{\{\s*([a-z0-9_]+)(?:\|[a-z]+)?\s*\}\}/gi, (_, name) => {
      const tag = tags.find((item) => item.name === name);
      return `[${tag?.label ?? name.replace(/_/g, " ")}]`;
    });
};

const toTemplateMarkup = (content, tags) => {
  const knownTags = new Map(tags.map((tag) => [tag.label.trim().toLowerCase(), tag.name]));
  return String(content || "").replace(/\[([^\]]+)\]/g, (match, label) => {
    const name = knownTags.get(label.trim().toLowerCase());
    return name ? `{{${name}}}` : match;
  });
};

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

  const [templateSummaries, setTemplateSummaries] = useState([]);
  const [mergeTags, setMergeTags] = useState([]);
  const [template, setTemplate] = useState(null);
  const [templateVersions, setTemplateVersions] = useState([]);
  const [templateContent, setTemplateContent] = useState("");
  const [templateSettings, setTemplateSettings] = useState({
    paper_size: "A4",
    orientation: "portrait",
    margin_top: 25.4,
    margin_right: 25.4,
    margin_bottom: 25.4,
    margin_left: 25.4,
    required_fields: [],
    change_note: "",
  });
  const [templateLoading, setTemplateLoading] = useState(false);
  const [templateSaving, setTemplateSaving] = useState(false);
  const [templateFileName, setTemplateFileName] = useState("");
  const [templateMessage, setTemplateMessage] = useState("");
  const [templateError, setTemplateError] = useState("");
  const [showTemplateEditor, setShowTemplateEditor] = useState(true);
  const [showLayoutEditor, setShowLayoutEditor] = useState(true);
  const [layoutEditorHost, setLayoutEditorHost] = useState(null);
  const [mergeTagSearch, setMergeTagSearch] = useState("");
  const [openMergeTagGroups, setOpenMergeTagGroups] = useState({
    backend: true,
    manual: false,
    computed: false,
  });
  const [sameMargins, setSameMargins] = useState(false);
  const templateEditorRef = useRef(null);
  const savedTemplateSelection = useRef(null);

  useEffect(() => {
    let cancelled = false;
    Promise.all([getCertificationTemplates(), getCertificateMergeTags()])
      .then(([summaryResponse, tagResponse]) => {
        if (cancelled) return;
        setTemplateSummaries(responseRows(summaryResponse));
        setMergeTags(responseRows(tagResponse));
      })
      .catch(() => {
        if (!cancelled) setTemplateError("Unable to load certificate template information.");
      });
    return () => { cancelled = true; };
  }, []);

  useEffect(() => {
    if (!selectedCertId) return;
    let cancelled = false;
    setTemplateLoading(true);
    setTemplateError("");
    Promise.all([
      getCertificationTemplate(selectedCertId),
      getCertificationTemplateVersions(selectedCertId),
    ])
      .then(([templateResponse, versionsResponse]) => {
        if (cancelled) return;
        const data = responseData(templateResponse);
        const nextTemplate = data?.template ?? null;
        setTemplate(nextTemplate);
        setTemplateVersions(responseRows(versionsResponse));
        setTemplateContent(nextTemplate?.content ?? "");
        setTemplateFileName("");
        setTemplateSettings({
          paper_size: nextTemplate?.paper_size ?? "A4",
          orientation: nextTemplate?.orientation ?? "portrait",
          margin_top: nextTemplate?.margins?.top ?? 25.4,
          margin_right: nextTemplate?.margins?.right ?? 25.4,
          margin_bottom: nextTemplate?.margins?.bottom ?? 25.4,
          margin_left: nextTemplate?.margins?.left ?? 25.4,
          required_fields: nextTemplate?.required_fields ?? [],
          change_note: "",
        });
      })
      .catch(() => {
        if (!cancelled) setTemplateError("Unable to load the selected certificate template.");
      })
      .finally(() => {
        if (!cancelled) setTemplateLoading(false);
      });
    return () => { cancelled = true; };
  }, [selectedCertId]);

  const saveTemplateSelection = () => {
    const editor = templateEditorRef.current;
    const selection = window.getSelection();

    if (!editor || !selection?.rangeCount) return;

    const range = selection.getRangeAt(0);
    if (editor.contains(range.commonAncestorContainer)) {
      savedTemplateSelection.current = range.cloneRange();
    }
  };

  const insertMergeTag = (name) => {
    const tag = mergeTags.find((item) => item.name === name);
    const placeholder = `[${tag?.label ?? name.replace(/_/g, " ")}]`;
    const editor = templateEditorRef.current;
    if (!editor) return;

    const selection = window.getSelection();
    const range = savedTemplateSelection.current
      || (selection?.rangeCount ? selection.getRangeAt(0) : null);
    const insertionRange = range && editor.contains(range.commonAncestorContainer)
      ? range
      : (() => {
        const endRange = document.createRange();
        endRange.selectNodeContents(editor);
        endRange.collapse(false);
        return endRange;
      })();

    insertionRange.deleteContents();
    const textNode = document.createTextNode(placeholder);
    insertionRange.insertNode(textNode);
    insertionRange.setStartAfter(textNode);
    insertionRange.collapse(true);

    savedTemplateSelection.current = insertionRange.cloneRange();
    selection?.removeAllRanges();
    selection?.addRange(insertionRange);
    setTemplateContent(editor.innerHTML);
  };

  const handleTemplateFile = (event) => {
    const [file] = Array.from(event.target.files || []);
    event.target.value = "";
    if (!file) return;
    const extension = file.name.split(".").pop()?.toLowerCase();
    if (!["html", "htm", "txt"].includes(extension)) {
      setTemplateError("Template files must use .html, .htm, or .txt.");
      return;
    }
    if (file.size > 200000) {
      setTemplateError("Template files must be 200 KB or smaller.");
      return;
    }
    setTemplateLoading(true);
    setTemplateError("");
    setTemplateMessage("");
    setTemplateFileName(file.name);
    const reader = new FileReader();
    reader.onload = () => {
      setTemplateContent(String(reader.result || ""));
      setTemplateLoading(false);
      setTemplateMessage("Template loaded. Click “Save as New Version” to store it.");
    };
    reader.onerror = () => {
      setTemplateLoading(false);
      setTemplateError("Unable to read the selected template file.");
    };
    reader.readAsText(file);
  };

  const saveTemplate = async () => {
    if (!selectedCertId || !templateContent.trim()) {
      setTemplateError("Add template content before saving.");
      return;
    }
    setTemplateSaving(true);
    setTemplateError("");
    setTemplateMessage("");
    try {
      const response = await saveCertificationTemplate(selectedCertId, {
        content: toTemplateMarkup(templateContent, mergeTags),
        ...templateSettings,
      });
      const saved = responseData(response);
      const refreshed = await getCertificationTemplate(selectedCertId);
      const refreshedTemplate = responseData(refreshed)?.template ?? null;
      setTemplate(refreshedTemplate);
      setTemplateContent(refreshedTemplate?.content ?? "");
      setTemplateFileName("");
      const refreshedVersions = await getCertificationTemplateVersions(selectedCertId);
      setTemplateVersions(responseRows(refreshedVersions));
      setTemplateSummaries((current) => [
        ...current.filter((item) => String(item.certificate_type_id) !== String(selectedCertId)),
        saved,
      ]);
      setTemplateFileName("");
      setTemplateMessage(response?.data?.message ?? "Certificate template saved.");
    } catch (error) {
      const validationErrors = Object.values(error?.response?.data?.errors ?? {})
        .flat()
        .filter(Boolean);
      setTemplateError(
        validationErrors.length > 0
          ? validationErrors.join(" ")
          : error?.response?.data?.message ?? "Unable to save the certificate template."
      );
    } finally {
      setTemplateSaving(false);
    }
  };

  const activateVersion = async (version) => {
    setTemplateSaving(true);
    setTemplateError("");
    try {
      const response = await activateCertificationTemplateVersion(selectedCertId, version);
      const activated = responseData(response);
      setTemplate(activated);
      setTemplateContent("");
      setTemplateMessage(response?.data?.message ?? "Template version activated.");
      const refreshed = await getCertificationTemplate(selectedCertId);
      const refreshedTemplate = responseData(refreshed)?.template ?? null;
      setTemplate(refreshedTemplate);
      setTemplateContent(refreshedTemplate?.content ?? "");
    } catch (error) {
      setTemplateError(error?.response?.data?.message ?? "Unable to activate that template version.");
    } finally {
      setTemplateSaving(false);
    }
  };

  const mergeTagGroups = ["backend", "manual", "computed"].map((source) => ({
    source,
    label: source === "backend" ? "Student & academic data" : source === "manual" ? "Additional information" : "Computed values",
    tags: mergeTags.filter((tag) => {
      const query = mergeTagSearch.trim().toLowerCase();
      return tag.source === source && (!query || `${tag.label} ${tag.name}`.toLowerCase().includes(query));
    }),
  })).filter((group) => group.tags.length > 0);

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
            </div>
          </div>
        </header>

        {layoutEditorHost && createPortal(<section className={`overflow-hidden rounded-2xl shadow-sm ${isDark ? 'bg-[#242526]' : 'bg-white'}`}>
          <div className="flex min-h-[72px] items-center justify-between gap-3 px-4 py-3">
            <div className="min-w-0">
              <h2 className={`text-base font-bold ${isDark ? 'text-white' : 'text-[#10233f]'}`}>Certificate Template Settings</h2>
              <p className={`mt-1 truncate text-xs ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>
                {template ? `Version ${template.version ?? "—"} · ${template.is_active ? "Active template" : "Inactive template"}` : "No custom template saved yet"}
              </p>
            </div>
            <div className="flex shrink-0 items-center gap-2">
              {!template && (
                <span className={`hidden rounded-full border px-3 py-1 text-xs font-semibold sm:inline-flex sm:items-center sm:gap-1.5 ${isDark ? 'border-yellow-800 bg-yellow-950/30 text-yellow-300' : 'border-yellow-200 bg-yellow-50 text-yellow-700'}`}>
                  <span className="text-sm leading-none">●</span>
                  Built-in layout
                </span>
              )}
            <button
              type="button"
              onClick={() => setShowTemplateEditor((current) => !current)}
              aria-expanded={showTemplateEditor}
              className={`flex shrink-0 items-center gap-2 rounded-md px-2 py-2 text-sm font-semibold transition-colors focus:outline-none focus:ring-0 focus-visible:bg-gray-100 ${isDark ? 'text-[#e4e6eb] hover:bg-[#303136] focus-visible:bg-[#303136]' : 'text-[#243b5a] hover:bg-gray-50'}`}
            >
              {showTemplateEditor ? "Collapse" : "Expand"}
              <span aria-hidden="true" className={`h-2.5 w-2.5 -translate-y-0.5 rotate-45 border-b-2 border-r-2 transition-transform ${showTemplateEditor ? "rotate-[225deg]" : ""} ${isDark ? "border-[#e4e6eb]" : "border-[#243b5a]"}`} />
            </button>
            </div>
          </div>

          {showTemplateEditor && <div className="border-t px-4 pb-4 pt-3">
            {templateLoading && <p className="text-sm text-amber-600">Loading template...</p>}
            {templateError && <p role="alert" className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{templateError}</p>}
            {templateMessage && <p role="status" className="rounded-md bg-green-50 px-3 py-2 text-sm text-green-700">{templateMessage}</p>}

          <div className="mt-3 grid min-w-0 grid-cols-1 gap-2 sm:grid-cols-2">
            <div className={`min-w-0 rounded-md border px-3 py-2 ${isDark ? 'border-[#3e4042] bg-[#1f1f1f]' : 'border-gray-200 bg-gray-50'}`}><span className="text-[11px] font-semibold uppercase tracking-wide opacity-70">Status</span><p className="break-words text-sm font-medium">{template ? (template.is_active ? "Active" : "Inactive") : "Using built-in layout"}</p></div>
            <div className={`min-w-0 rounded-md border px-3 py-2 ${isDark ? 'border-[#3e4042] bg-[#1f1f1f]' : 'border-gray-200 bg-gray-50'}`}><span className="text-[11px] font-semibold uppercase tracking-wide opacity-70">Current version</span><p className="break-words text-sm font-medium">{template?.version ?? "—"}</p></div>
            <div className={`min-w-0 rounded-md border px-3 py-2 ${isDark ? 'border-[#3e4042] bg-[#1f1f1f]' : 'border-gray-200 bg-gray-50'}`}><span className="text-[11px] font-semibold uppercase tracking-wide opacity-70">Page setup</span><p className="break-words text-sm font-medium">{template ? `${template.paper_size} · ${template.orientation}` : "Default certificate layout"}</p></div>
            <div className={`min-w-0 rounded-md border px-3 py-2 ${isDark ? 'border-[#3e4042] bg-[#1f1f1f]' : 'border-gray-200 bg-gray-50'}`}><span className="text-[11px] font-semibold uppercase tracking-wide opacity-70">Storage</span><p className="break-words text-sm font-medium">{template ? "Private storage" : "Built-in configuration"} · {templateSummaries.length} active</p></div>
          </div>

            <div className="mt-4 min-w-0 space-y-4">
              <div className="min-w-0 space-y-3">
                <div className="flex min-w-0 flex-col items-stretch gap-2 sm:flex-row sm:items-center">
                  <label className={`w-full cursor-pointer rounded-md border px-3 py-2 text-center text-sm font-semibold sm:w-auto ${isDark ? 'border-[#3e4042]' : 'border-gray-300'}`}>
                    Upload HTML template
                    <input type="file" accept=".html,.htm,.txt,text/html,text/plain" className="hidden" onChange={handleTemplateFile} disabled={templateSaving || isLockedCertification} />
                  </label>
                  <span className="min-w-0 break-words text-center text-xs text-gray-500 sm:text-left">
                    {templateFileName ? `Ready to use: ${templateFileName}` : "Your template is checked automatically to keep it safe."}
                  </span>
                </div>
                <p className={`rounded-md border px-3 py-2 text-xs ${isDark ? 'border-[#3e4042] bg-[#1f1f1f] text-[#b0b3b8]' : 'border-gray-200 bg-gray-50 text-gray-600'}`}>
                  Edit your certificate directly in the preview, or upload an HTML file to get started. Click where you want to add information, then choose a merge field below, such as <strong>[Full name]</strong>. These placeholders are replaced with the student’s details when the certificate is generated.
                </p>
              </div>

              <div className={`min-w-0 space-y-3 rounded-lg border p-3 ${isDark ? 'border-[#3e4042] bg-[#1f1f1f]' : 'border-gray-200 bg-gray-50'}`}>
                <section className="min-w-0 overflow-hidden rounded-lg bg-white text-gray-900 shadow-sm">
                  <div className="p-3">
                    <h3 className="text-sm font-bold">Merge fields</h3>
                    <p className="mt-1 text-[11px] text-gray-500">Click a field to insert it into the template. Values are replaced when a certificate is generated.</p>
                    <input
                      value={mergeTagSearch}
                      onChange={(event) => setMergeTagSearch(event.target.value)}
                      placeholder="Search fields"
                      aria-label="Search merge fields"
                      className="mt-2 w-full rounded-md border-0 bg-gray-50 px-3 py-2 text-xs ring-1 ring-inset ring-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-300"
                    />
                  </div>
                  {mergeTagGroups.map((group) => (
                    <div key={group.source}>
                      <button type="button" onClick={() => setOpenMergeTagGroups((current) => ({ ...current, [group.source]: !current[group.source] }))} className="flex w-full items-center justify-between px-3 py-2 text-left text-xs font-semibold">
                        {group.label} ({group.tags.length})
                        <span>{openMergeTagGroups[group.source] ? "−" : "+"}</span>
                      </button>
                      {openMergeTagGroups[group.source] && (
                        <div className="flex min-w-0 flex-wrap gap-1 px-3 pb-3">
                          {group.tags.map((tag) => (
                            <button
                              key={tag.name}
                              type="button"
                              onMouseDown={(event) => event.preventDefault()}
                              onClick={() => insertMergeTag(tag.name)}
                              className="rounded-full border border-gray-300 px-2 py-1 text-[11px] hover:border-blue-400 hover:bg-blue-50"
                              title={`${tag.source}: ${tag.description}`}
                            >
                              {tag.label}
                            </button>
                          ))}
                        </div>
                      )}
                    </div>
                  ))}
                </section>

                <section className={`min-w-0 rounded border p-3 ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-gray-200 bg-white'}`}>
                  <h3 className="text-sm font-bold">Page setup</h3>
                  <div className="mt-2 grid gap-2 sm:grid-cols-2">
                    <DropDown
                      label="Paper size"
                      name="paper_size"
                      value={templateSettings.paper_size}
                      onChange={(event) => setTemplateSettings((current) => ({ ...current, paper_size: event.target.value }))}
                      options={["A4", "LETTER", "LEGAL", "FOLIO"]}
                      labelColor={isDark ? "text-[#b0b3b8]" : "text-gray-700"}
                    />
                    <DropDown
                      label="Orientation"
                      name="orientation"
                      value={templateSettings.orientation}
                      onChange={(event) => setTemplateSettings((current) => ({ ...current, orientation: event.target.value }))}
                      options={["portrait", "landscape"]}
                      labelColor={isDark ? "text-[#b0b3b8]" : "text-gray-700"}
                    />
                  </div>
                </section>

                <section className={`min-w-0 rounded border p-3 ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-gray-200 bg-white'}`}>
                  <div className="flex items-center justify-between">
                    <div><h3 className="text-sm font-bold">Margins (mm)</h3><p className="text-[11px] text-gray-500">Use the same value on all sides when enabled.</p></div>
                    <label className="flex items-center gap-1 text-[11px] font-medium">
                      <input type="checkbox" checked={sameMargins} onChange={(event) => {
                        const checked = event.target.checked;
                        setSameMargins(checked);
                        if (checked) setTemplateSettings((s) => ({ ...s, margin_right: s.margin_top, margin_bottom: s.margin_top, margin_left: s.margin_top }));
                      }} />
                      Same on all sides
                    </label>
                  </div>
                  <div className="mt-2 grid grid-cols-2 gap-2">
                    {["top", "right", "bottom", "left"].map((side) => (
                      <InputGroup
                        key={side}
                        label={side[0].toUpperCase() + side.slice(1)}
                        name={`margin_${side}`}
                        type="number"
                        min="5"
                        max="60"
                        value={templateSettings[`margin_${side}`]}
                        onChange={(event) => setTemplateSettings((current) => {
                          const value = event.target.value;
                          return sameMargins
                            ? { ...current, margin_top: value, margin_right: value, margin_bottom: value, margin_left: value }
                            : { ...current, [`margin_${side}`]: value };
                        })}
                        voiceEnabled={false}
                        isDark={isDark}
                        labelColor={isDark ? "text-[#b0b3b8]" : "text-gray-700"}
                      />
                    ))}
                  </div>
                </section>

                <section className={`min-w-0 rounded border p-3 ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-gray-200 bg-white'}`}>
                  <h3 className="text-sm font-bold">Required fields</h3>
                  <p className="mt-1 text-[11px] text-gray-500">The template cannot be saved unless it includes every checked field.</p>
                  <div className="mt-2 max-h-48 space-y-1 overflow-y-auto">
                    {mergeTags.filter((tag) => !mergeTagSearch || `${tag.label} ${tag.name}`.toLowerCase().includes(mergeTagSearch.toLowerCase())).map((tag) => (
                      <label key={tag.name} className="flex items-center justify-between gap-2 border-b py-1 text-xs last:border-b-0">
                        <span className="flex items-center gap-2"><input type="checkbox" checked={templateSettings.required_fields.includes(tag.name)} onChange={(e) => setTemplateSettings((s) => ({ ...s, required_fields: e.target.checked ? [...s.required_fields, tag.name] : s.required_fields.filter((name) => name !== tag.name) }))} />{tag.label}</span>
                        {templateSettings.required_fields.includes(tag.name) && <span className="rounded bg-green-50 px-1.5 py-0.5 text-[10px] text-green-700">Included</span>}
                      </label>
                    ))}
                  </div>
                </section>

                <input value={templateSettings.change_note} onChange={(e) => setTemplateSettings((s) => ({ ...s, change_note: e.target.value }))} placeholder="Version change note (optional)" aria-label="Version change note" className={`w-full rounded-md border-0 p-2 text-sm shadow-sm ring-1 ring-inset focus:outline-none focus:ring-2 ${isDark ? 'bg-[#1f1f1f] text-[#e4e6eb] ring-[#3e4042] placeholder:text-[#8f949d] focus:ring-[#5a5a60]' : 'bg-white text-gray-800 ring-gray-200 placeholder:text-gray-400 focus:ring-gray-300'}`} />
                <button type="button" onClick={saveTemplate} disabled={templateSaving || templateLoading || !selectedCertId || isLockedCertification || !templateContent.trim()} className="w-full rounded-md bg-[#800000] px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">
                  {templateSaving ? "Saving..." : "Save as New Version"}
                </button>
                {templateVersions.length > 0 && (
                  <div className="border-t pt-3">
                    <p className="mb-2 text-xs font-semibold">Template versions</p>
                    {templateVersions.map((version) => (
                      <div key={version.certificate_template_id ?? version.version} className="flex items-center justify-between gap-2 py-1 text-xs">
                        <span>Template v{version.version} {version.is_active ? "(active)" : ""}</span>
                        {!version.is_active && (
                          <span className="flex items-center gap-2">
                            <button type="button" onClick={() => activateVersion(version.version)} disabled={templateSaving} className="text-blue-700 hover:underline">Activate</button>
                          </span>
                        )}
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </div>
          </div>}
        </section>, layoutEditorHost)}

        {/* Workspace Layout Grid */}
        <div className="grid min-w-0 grid-cols-1 items-start gap-4 xl:grid-cols-[minmax(300px,390px)_minmax(0,1fr)]">
          {/* Layout Editor Sidebar */}
          <aside ref={setLayoutEditorHost} className={`min-w-0 w-full space-y-3 overflow-y-auto rounded-xl border p-3 shadow-sm max-h-[calc(100vh-12rem)] ${isDark ? 'border-[#3e4042] bg-[#242526]' : 'border-gray-200 bg-gray-50'}`}>
            <div className={`overflow-hidden rounded-2xl shadow-sm ${isDark ? 'bg-[#242526]' : 'bg-white'}`}>
            <div className="flex min-h-[76px] items-center justify-between gap-3 px-4 py-3">
              <div className="min-w-0">
                <h2 className={`text-base font-bold ${isDark ? 'text-white' : 'text-[#10233f]'}`}>Header and Footer Settings</h2>
                <p className={`mt-1 truncate text-xs ${isDark ? 'text-[#b0b3b8]' : 'text-gray-500'}`}>
                  Logos, header text, footer logos, and spacing
                </p>
              </div>
              <button
                type="button"
                onClick={() => setShowLayoutEditor((current) => !current)}
                aria-expanded={showLayoutEditor}
                className={`flex shrink-0 items-center gap-2 rounded-md px-2 py-2 text-sm font-semibold transition-colors focus:outline-none focus:ring-0 focus-visible:bg-gray-100 ${isDark ? 'text-[#e4e6eb] hover:bg-[#303136] focus-visible:bg-[#303136]' : 'text-[#243b5a] hover:bg-gray-50'}`}
              >
                {showLayoutEditor ? "Collapse" : "Expand"}
                <span aria-hidden="true" className={`h-2.5 w-2.5 -translate-y-0.5 rotate-45 border-b-2 border-r-2 transition-transform ${showLayoutEditor ? "rotate-[225deg]" : ""} ${isDark ? "border-[#e4e6eb]" : "border-[#243b5a]"}`} />
              </button>
            </div>
            {showLayoutEditor && <div className="space-y-3 border-t px-3 pb-3 pt-3">
            <div className="space-y-3">
              <div className="min-w-0">
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
              <label className="flex min-h-6 items-center gap-2 cursor-pointer select-none px-0.5">
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
              <label className="flex min-h-6 items-center gap-2 cursor-pointer select-none px-0.5">
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
            <button
              id="btn-save-layout"
              onClick={saveLayout}
              disabled={!selectedCertId || saving || saveSuccess || hasPreviewDataUrl(layout) || isLockedCertification}
              className={`w-full rounded-md px-4 py-2 text-sm font-semibold transition focus:outline-none focus:ring-2 disabled:cursor-not-allowed ${saveSuccess
                ? 'bg-green-500 text-white border-green-600'
                : isDark
                  ? 'bg-[#2a2a2f] text-[#e4e6eb] hover:bg-[#353539] focus:ring-[#4e4f50] disabled:bg-[#2a2a2f]/50 border border-[#3e4042]'
                  : 'bg-yellow-400 text-slate-900 hover:bg-yellow-500 focus:ring-yellow-200 disabled:bg-yellow-200'
                }`}
            >
              {isLockedCertification ? "Archived — Read Only" : saving ? "Saving..." : saveSuccess ? "Saved!" : hasPreviewDataUrl(layout) ? "Waiting for upload..." : "Save Layout"}
            </button>
            </div>}
            </div>
          </aside>

          {/* Certificate Preview Canvas */}
          <section className={`min-w-0 overflow-hidden rounded-2xl border p-3 sm:p-5 ${isDark ? 'border-[#3e4042] bg-[#353638]' : 'border-gray-200 bg-white'}`}>
            <div className="relative w-full overflow-auto">
              <div
                className="mx-auto min-h-180 bg-white p-8 text-black shadow-sm"
                style={{
                  maxWidth: templateSettings.orientation === "landscape" ? "1100px" : "820px",
                  minHeight: templateSettings.orientation === "landscape" ? "620px" : "1050px",
                  padding: `${templateSettings.margin_top}mm ${templateSettings.margin_right}mm ${templateSettings.margin_bottom}mm ${templateSettings.margin_left}mm`,
                }}
              >
                <CertHeader layout={layout} />
                <div
                  ref={templateEditorRef}
                  contentEditable={!isLockedCertification}
                  suppressContentEditableWarning
                  role="textbox"
                  aria-label="Editable certificate template preview"
                  data-placeholder="Click here to start editing your certificate template."
                  onFocus={saveTemplateSelection}
                  onMouseUp={saveTemplateSelection}
                  onKeyUp={saveTemplateSelection}
                  onInput={(event) => setTemplateContent(event.currentTarget.innerHTML)}
                  className={`min-h-96 focus:outline-none ${!templateContent ? "before:pointer-events-none before:text-gray-400 before:content-[attr(data-placeholder)]" : ""}`}
                  dangerouslySetInnerHTML={{
                    __html: previewTemplate(templateContent, mergeTags),
                  }}
                />
                <CertFooter layout={layout} />
              </div>
            </div>
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