import React, { useState, useEffect, useMemo, useRef } from 'react';
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/react/24/solid';
import { useTheme } from '../context/ThemeContext';
import { useAuth } from '../context/AuthProvider';
import { hasModuleAction } from '../utils/policy';
import { getLogbookData, getAllLogbookData, getDocumentTypes, getCertifications, getLogbookCategories, recordLogbookExport } from '../services/api'; // The table loads one server-filtered page at a time; getAllLogbookData() is used only for the DOCX export.
import {
  formatMinutesDuration,
  getProcessedAt,
  getProcessingMinutes,
  getFullName,
  getCourse,
  getGender,
  getEmail,
  getDocumentLogbookLabels,
  getCertificationLogbookLabels,
  resolveLogbookLabel,
  formatDateLong,
  formatDateTimeLong,
  getClaimedAt,
  findMatchingItem,
  rowHasLabel,
} from '../utils/logbookHelpers.js';

import DropDown from '../components/DropDown';
import MultiSelectDropdown from '../components/MultiSelection.jsx';
import LogbookDateRangeModal from '../components/LogbookDateRangeModal';
import VoiceSearchInput from '../components/VoiceSearchInput.jsx';
import { LogbookSkeleton } from '../components/LoadingSkeleton';
import SuccessToast from '../components/SuccessToast.jsx';
import ErrorToast from '../components/ErrorToast.jsx';
import { logbookDocx } from '../utils/logbookDocx.js';
import pupLogoSrc from '../assets/puplogoimage.png';
import bpLogoSrc from '../assets/Bagong_Pilipinas_logo.png';

const toRows = (raw) => {
  if (Array.isArray(raw)) return raw;
  if (Array.isArray(raw?.data)) return raw.data;
  return [];
};

// Wait this long after the last keystroke before asking the server to search.
const SEARCH_DEBOUNCE_MS = 350;

const LogbookRecords = () => {
  const { isDark } = useTheme();
  const { user } = useAuth();

  // Work Item #1 — Granular Per-Action Permissions: documented as a
  // soft/UI-only gate, not a hard security boundary — the underlying
  // logbook data is already in the browser once the page is viewable
  // at all, so hiding the Export button doesn't protect anything the
  // View permission hasn't already exposed. The backend's
  // module:logbook,Export check on the export code path exists for
  // consistency/defense-in-depth, not because export itself discloses
  // anything View didn't.
  const canExport = hasModuleAction(user, 'logbook', 'Export');
  // One server-filtered page of logbook rows, plus the total across all pages.
  const [rows, setRows] = useState([]);
  const [total, setTotal] = useState(0);
  const [dbDocTypes, setDbDocTypes] = useState([]);
  const [availableCertifications, setAvailableCertifications] = useState([]);
  const [logbookCategories, setLogbookCategories] = useState([]);
  const [loading, setLoading] = useState(true); // first load of the lookup lists
  const requestSeq = useRef(0); // discards responses that arrive after a newer request
  const [currentPage, setCurrentPage] = useState(1);
  const [selectedDocCategories, setSelectedDocCategories] = useState([]);
  const [selectedCertificationLabel, setSelectedCertificationLabel] = useState('');
  const [exporting, setExporting] = useState(false);
  const [tableLoading, setTableLoading] = useState(false);
  const [toastSuccess, setToastSuccess] = useState('');
  const [toastError, setToastError] = useState('');
  const [searchTerm, setSearchTerm] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [activePreset, setActivePreset] = useState('');
  const [isDateModalOpen, setIsDateModalOpen] = useState(false);
  const rowsPerPage = 8;

  const handleApplyDateFilter = (start, end, preset) => {
    setDateFrom(start);
    setDateTo(end);
    setActivePreset(preset);
    setCurrentPage(1);
    setIsDateModalOpen(false);
  };

  // Load the lookup lists on mount. The rows themselves are fetched below,
  // one page at a time, with the active filters applied by the server.
  useEffect(() => {
    const fetchLookups = async () => {
      setLoading(true);
      try {
        const [typesRes, certRes, categoriesRes] = await Promise.all([
          getDocumentTypes(),
          getCertifications(),
          getLogbookCategories(),
        ]);

        setDbDocTypes(toRows(typesRes.data));
        setAvailableCertifications(toRows(certRes.data));
        setLogbookCategories(toRows(categoriesRes.data));
      } catch (error) {
        console.error('Error loading logbook records:', error);
        setDbDocTypes([]);
        setAvailableCertifications([]);
        setLogbookCategories([]);
        setToastError((error && (error.message || error.toString())) || 'Error loading logbook records.');
      } finally {
        setLoading(false);
      }
    };
    fetchLookups();
  }, []);

  // Debounce the search box; a new search always returns to page 1.
  useEffect(() => {
    const handle = setTimeout(() => {
      setDebouncedSearch(searchTerm.trim());
      setCurrentPage(1);
    }, SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(handle);
  }, [searchTerm]);

  // Map of document type id -> document's own name (pre-rollup), used only
  // for the "is this catalog's magic 'Certification' row present" check
  // below — unrelated to logbook_category grouping.
  const activeDocMap = useMemo(() => {
    if (dbDocTypes.length > 0) {
      return Object.fromEntries(dbDocTypes.map(t => [t.document_type_id, t.document_name]));
    }
    return {};
  }, [dbDocTypes]);

  // logbook_category_id -> category name, from GET /logbook-categories.
  // Single source of truth for the rollup: several document/certificate
  // types can share one category so they log/export as one umbrella line
  // (see logbookHelpers.js's "Logbook rollup helpers" section for why).
  const logbookCategoryNameById = useMemo(() => {
    const map = new Map();
    logbookCategories.forEach((c) => {
      const id = Number(c?.logbook_category_id);
      if (!Number.isNaN(id) && c?.name) {
        map.set(id, c.name);
      }
    });
    return map;
  }, [logbookCategories]);

  // Map of document_type_id -> resolved logbook label (category name if
  // assigned, otherwise the type's own name). Drives the Document Type
  // dropdown so types that roll up under the same category collapse into
  // a single selectable option, matching what the backend's
  // DocumentType::logbookLabel() considers "the same logbook line".
  const docLogbookLabelById = useMemo(() => {
    const map = {};
    dbDocTypes.forEach((t) => {
      map[t.document_type_id] = resolveLogbookLabel(
        t?.logbook_category_id,
        t?.document_name,
        logbookCategoryNameById
      );
    });
    return map;
  }, [dbDocTypes, logbookCategoryNameById]);

  // Map of certificate_type_id -> resolved logbook label. Same rollup
  // convention as docLogbookLabelById above.
  const certLogbookLabelById = useMemo(() => {
    const map = {};
    availableCertifications.forEach((c) => {
      map[c.certificate_type_id] = resolveLogbookLabel(
        c?.logbook_category_id,
        c?.certificate_name,
        logbookCategoryNameById
      );
    });
    return map;
  }, [availableCertifications, logbookCategoryNameById]);

  // Build document filter dropdown options — one entry per distinct
  // logbook label (post-rollup): every logbook category, then every type that
  // has no category under its own name.
  const docOptions = useMemo(() => {
    const options = ['All Document'];
    const seen = new Set(['all document']);

    (logbookCategories || []).forEach((c) => {
      const name = String(c?.name || '').trim();
      if (!name) return;
      const key = name.toLowerCase();
      if (!seen.has(key)) {
        seen.add(key);
        options.push(name);
      }
    });

    Object.values(docLogbookLabelById).forEach((label) => {
      const normalized = String(label || '').trim();
      if (!normalized) return;
      const key = normalized.toLowerCase();
      if (seen.has(key)) return;
      seen.add(key);
      options.push(normalized);
    });

    // Structural marker, unrelated to logbook_category rollup: a document
    // type literally named "Certification" signals that certification
    // filtering should be offered. Checked against the type's own raw
    // name (activeDocMap), not its rolled-up label, since collapsing it
    // under a category would never change what this marker means.
    if (Object.values(activeDocMap).some((name) => String(name || '').trim().toLowerCase() === 'certification')) {
      options.splice(1, 0, 'All Certification');
    }

    return options;
  }, [docLogbookLabelById, activeDocMap, logbookCategories]);

  // List of distinct certification logbook labels (post-rollup)
  const certificationOptions = useMemo(() => {
    const options = [];
    const seen = new Set();

    Object.values(certLogbookLabelById).forEach((label) => {
      const normalized = String(label || '').trim();
      if (!normalized) return;
      const key = normalized.toLowerCase();
      if (seen.has(key)) return;
      seen.add(key);
      options.push(normalized);
    });

    return options;
  }, [certLogbookLabelById]);

  // Whether the UI is currently focused on certification-specific filters/exports
  const isCertificationMode = useMemo(() => {
    return (selectedDocCategories || []).includes('All Certification');
  }, [selectedDocCategories]);

  // Label displayed for the currently selected document/export option.
  const selectedDocLabel = useMemo(() => {
    if (isCertificationMode) {
      return selectedCertificationLabel || 'All Certification';
    }
    if (!selectedDocCategories || selectedDocCategories.length === 0 || selectedDocCategories.includes('All Document')) {
      return 'All Document';
    }
    if (selectedDocCategories.length === 1) {
      return selectedDocCategories[0];
    }
    return selectedDocCategories.join(', ');
  }, [selectedDocCategories, isCertificationMode, selectedCertificationLabel]);


  useEffect(() => {
    if (isCertificationMode) {
      if (!selectedCertificationLabel) {
        setSelectedCertificationLabel(certificationOptions[0] || '');
      }
    } else {
      setSelectedCertificationLabel('');
    }
  }, [isCertificationMode, certificationOptions]);

  // The one category/certification the screen is narrowed to, if any. With
  // exactly one selected, each row shows the figures for that line item only.
  const targetCategory = useMemo(() => {
    if (selectedDocCategories && selectedDocCategories.length === 1 && selectedDocCategories[0] !== 'All Document') {
      return selectedDocCategories[0];
    }
    if (isCertificationMode && selectedCertificationLabel && selectedCertificationLabel !== 'All Certification') {
      return selectedCertificationLabel;
    }
    return null;
  }, [selectedDocCategories, isCertificationMode, selectedCertificationLabel]);

  // Filters sent to the server (search, category, certification, dates).
  const filterParams = useMemo(() => {
    const params = {};
    if (dateFrom) params.from = dateFrom;
    if (dateTo) params.to = dateTo;
    if (debouncedSearch) params.search = debouncedSearch;

    if (isCertificationMode) {
      params.mode = 'certifications';
      if (selectedCertificationLabel && selectedCertificationLabel !== 'All Certification') {
        params.certification = selectedCertificationLabel;
      }
    } else if (selectedDocCategories && selectedDocCategories.length > 0 && !selectedDocCategories.includes('All Document')) {
      params.categories = selectedDocCategories;
    }

    return params;
  }, [dateFrom, dateTo, debouncedSearch, isCertificationMode, selectedCertificationLabel, selectedDocCategories]);

  // Fetch the current page whenever the filters or the page change.
  useEffect(() => {
    if (loading) return undefined;

    const seq = ++requestSeq.current;

    const fetchPage = async () => {
      setTableLoading(true);
      try {
        const res = await getLogbookData({ ...filterParams, page: currentPage, per_page: rowsPerPage });
        if (seq !== requestSeq.current) return; // a newer request superseded this one

        const body = res.data;
        const pageRows = toRows(body);
        const lastPage = Number(body?.last_page ?? 1);

        setRows(pageRows);
        setTotal(Number(body?.total ?? pageRows.length));

        // The filtered result shrank below the page we were on.
        if (lastPage >= 1 && currentPage > lastPage) setCurrentPage(lastPage);
      } catch (error) {
        if (seq !== requestSeq.current) return;
        console.error('Error loading logbook records:', error);
        setRows([]);
        setTotal(0);
        setToastError((error && (error.message || error.toString())) || 'Error loading logbook records.');
      } finally {
        if (seq === requestSeq.current) setTableLoading(false);
      }
    };

    fetchPage();
    return undefined;
  }, [loading, filterParams, currentPage]);

  const totalPages = Math.max(1, Math.ceil(total / rowsPerPage));
  const indexOfFirstItem = (currentPage - 1) * rowsPerPage;
  const indexOfLastItem = currentPage * rowsPerPage;

  // Build sections for DOCX export from the FULL filtered result (every page,
  // not just the one on screen). Section titles/grouping are the resolved
  // logbook label (post-rollup) so the exported document groups requests
  // exactly the way the on-screen filter/dropdown does, never the raw,
  // pre-rollup document/certificate type name.
  const getExportSections = (exportRows) => {
    if (isCertificationMode) {
      if (selectedCertificationLabel && selectedCertificationLabel !== 'All Certification') {
        return [{ title: selectedCertificationLabel, rows: exportRows }];
      }
      return certificationOptions
        .filter((label) => String(label || '').trim().toLowerCase() !== 'all certification')
        .map((label) => ({
          title: label,
          rows: exportRows.filter((row) =>
            rowHasLabel(getCertificationLogbookLabels(row, logbookCategoryNameById), label)
          ),
        }));
    }

    if (selectedDocCategories && selectedDocCategories.length > 0 && !selectedDocCategories.includes('All Document')) {
      return selectedDocCategories
        .map((catName) => ({
          title: catName,
          rows: exportRows.filter((row) =>
            rowHasLabel(getDocumentLogbookLabels(row, logbookCategoryNameById), catName)
          ),
        }))
        .filter((sec) => sec.rows.length > 0);
    }

    const uniqueDocLabels = Array.from(
      new Set(Object.values(docLogbookLabelById).map((n) => String(n || '').trim()).filter(Boolean))
    );

    return uniqueDocLabels.map((label) => ({
      title: label,
      rows: exportRows.filter((row) =>
        rowHasLabel(getDocumentLogbookLabels(row, logbookCategoryNameById), label)
      ),
    }));
  };

  // Trigger DOCX export
  const handleExportDocx = async () => {
    if (exporting) return;
    try {
      setExporting(true);
      const rangeLabel = (dateFrom && dateTo)
        ? `${dateFrom}_to_${dateTo}`
        : (dateFrom ? `from_${dateFrom}` : (dateTo ? `to_${dateTo}` : null));

      // Export what the search box shows right now, even if the debounce has
      // not fired yet.
      const exportParams = { ...filterParams };
      const liveSearch = searchTerm.trim();
      if (liveSearch) exportParams.search = liveSearch;
      else delete exportParams.search;

      const exportRows = await getAllLogbookData(exportParams);
      const sections = getExportSections(exportRows);

      // Audit FIRST (fail closed): the file is built in the browser, so this
      // call is the only server-side record of the export. If it fails, the
      // export does not run. Metadata only: no row contents.
      await recordLogbookExport({
        format: 'docx',
        mode: isCertificationMode ? 'certifications' : 'documents',
        date_from: dateFrom || null,
        date_to: dateTo || null,
        row_count: sections.reduce((n, sec) => n + sec.rows.length, 0),
        section_count: sections.length,
      });
      await logbookDocx(sections, pupLogoSrc, bpLogoSrc, rangeLabel, logbookCategoryNameById);
      setToastSuccess('Exporting Report completed.');
    } catch (e) {
      console.error('Export to DOCX failed', e);
      setToastError((e && (e.message || e.toString())) || 'Exporting Report failed.');
    } finally {
      setExporting(false);
    }
  };

  // Always call the latest handler (it closes over the current filters).
  const exportHandlerRef = useRef(handleExportDocx);
  exportHandlerRef.current = handleExportDocx;

  // Listen for voice command to export documents
  useEffect(() => {
    const handleVoiceExport = () => {
      // Work Item #1: the voice-command path bypasses the Export
      // button entirely, so it needs its own canExport check — without
      // this, a Student Staff account could still trigger an export
      // by voice even with the button hidden.
      if (!loading && !exporting && canExport) {
        exportHandlerRef.current();
      }
    };
    window.addEventListener('voice-command-export', handleVoiceExport);
    return () => window.removeEventListener('voice-command-export', handleVoiceExport);
  }, [loading, exporting, canExport]);

  if (loading) return <LogbookSkeleton isDark={isDark} />;

  return (
    <div className={`relative min-h-full font-sans text-left ${isDark ? 'bg-[#18191a] text-[#e4e6eb]' : 'bg-white text-gray-900'}`}>
      <div className={`w-full max-w-7xl mx-auto shadow-xs sm:shadow-md rounded-lg flex flex-col min-h-150 print:p-0 print:shadow-none ${isDark ? 'bg-[#242526]' : 'bg-white'}`}>

        <div className="p-3.5 sm:p-6 md:p-8 lg:mt-4">

          {/* ── Controls Panel ── */}
          <div className={`mb-6 print:hidden rounded-xl border p-3.5 sm:p-5 ${isDark ? 'bg-[#1e1f20] border-[#3e4042]' : 'bg-gray-50 border-gray-200'}`}>

            {/* Controls Row */}
            <div className="flex flex-col sm:flex-row flex-wrap items-end gap-3.5 w-full">

            {/* Search Input for Client Name / Keywords */}
              <div className="w-full sm:w-64 shrink-0 flex flex-col">
                <label className={`block text-sm font-medium mb-1.5 ${isDark ? 'text-[#b0b3b8]' : 'text-gray-600'}`}>
                  Search 
                </label>
                <VoiceSearchInput
                  value={searchTerm}
                  onChange={setSearchTerm}
                  placeholder="Search name, email..."
                />
              </div>

              {/* Document Type multi-select checkbox dropdown */}
              <div className="w-full sm:w-72 shrink-0">
                <MultiSelectDropdown
                  label="Document Category"
                  name="docCategory"
                  placeholder="All Categories"
                  labelColor={isDark ? 'text-[#b0b3b8]' : 'text-gray-600'}
                  selectedValues={selectedDocCategories}
                  onChange={(e) => {
                    setSelectedDocCategories(e.target.value);
                    if (!e.target.value.includes('All Certification')) {
                      setSelectedCertificationLabel('');
                    }
                    setCurrentPage(1);
                  }}
                  options={docOptions}
                />
              </div>

              {/* Certification Type dropdown (conditional) */}
              {isCertificationMode && (
                <div className="w-full sm:w-64 shrink-0">
                  <DropDown
                    label="Certification Type"
                    name="certificationType"
                    value={selectedCertificationLabel}
                    labelColor={isDark ? 'text-[#b0b3b8]' : 'text-gray-600'}
                    onChange={(e) => {
                      setSelectedCertificationLabel(e.target.value);
                      setCurrentPage(1);
                    }}
                    options={certificationOptions}
                  />
                </div>
              )}

              {/* Date Filter Button */}
              <div className="w-full sm:w-60 shrink-0 flex flex-col">
                <label className={`block text-sm font-medium mb-1.5 ${isDark ? 'text-[#b0b3b8]' : 'text-gray-600'}`}>
                  Date Range
                </label>
                <button
                  type="button"
                  onClick={() => setIsDateModalOpen(true)}
                  className={`
                    w-full flex items-center justify-between gap-2 px-3 py-2.5 rounded-lg text-sm font-medium 
                    shadow-xs focus:outline-none border transition-colors text-left cursor-pointer min-h-[46px]
                    ${isDark
                      ? 'bg-[#1f1f1f] text-[#e4e6eb] border-[#3e4042] hover:border-gray-200'
                      : 'bg-white text-gray-700 border-gray-200 hover:border-gray-300'
                    }
                  `}
                >
                  <span className="truncate">
                    {dateFrom && dateTo
                      ? `${dateFrom} to ${dateTo}`
                      : (dateFrom ? `From ${dateFrom}` : (dateTo ? `To ${dateTo}` : 'All Time'))}
                  </span>
                  <svg className="w-4 h-4 shrink-0 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                  </svg>
                </button>
              </div>

              {/* Export button — Work Item #1: hidden (not just disabled) when
                  the policy lacks Export, since this is documented as a soft/
                  UI-only gate rather than a hard boundary. */}
              {canExport && (
                <div className="w-full sm:w-48 sm:ml-auto shrink-0">
                  <button
                    data-voice-action="export"
                    onClick={handleExportDocx}
                    disabled={loading || exporting || total === 0}
                    className={`w-full flex items-center justify-center px-4 py-3 min-h-11.5
                      rounded-lg text-xs font-black uppercase tracking-wider shadow-xs 
                      transition-all cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed
                      ${isDark 
                        ? 'bg-[#3a3b3c] text-[#e4e6eb] hover:bg-[#4e4f50]' 
                        : 'bg-[#800000] text-white hover:bg-[#6b0000]'}`}
                  >
                    <span>{exporting ? 'Exporting…' : 'Export DOCX'}</span>
                  </button>
                </div>
              )}
            </div>
          </div>

          {/* ── Original section heading ── */}
          <div className={`w-full text-center border-b pb-4 mb-0 ${isDark ? 'border-[#3e4042]' : 'border-gray-300'}`}>
            <h2 className={`text-lg sm:text-xl md:text-2xl font-black uppercase tracking-widest leading-tight ${isDark ? 'text-[#f5c542]' : 'text-[#4a0000]'}`}>
              Processing of Application for <br className="sm:hidden" /> {selectedDocLabel}
            </h2>
            <p className={`text-xs mt-1 font-medium ${isDark ? 'text-[#9a9a9a]' : 'text-gray-400'}`}>
              {total} record{total !== 1 ? 's' : ''}
              {(dateFrom || dateTo) && (
                <span> &mdash; {dateFrom && `from ${dateFrom}`}{dateFrom && dateTo && ' '}{dateTo && `to ${dateTo}`}</span>
              )}
            </p>
          </div>
        </div>

        {/* ── Table Container (Desktop: Table, Mobile: Card List) ── */}
        {/* Desktop Table View (hidden on mobile, visible md and up) */}
        <div className="hidden md:block flex-1 overflow-x-auto px-4 sm:px-6 md:px-8">
          <table className="w-full min-w-225 border-collapse md:min-w-full">
            <thead>
              <tr className={`border-b-2 uppercase text-center ${isDark ? 'border-[#3e4042] text-[#9a9a9a]' : 'border-gray-300 text-gray-400'}`}>
                <th className="py-4 px-2 text-[10px] font-black w-[11%] whitespace-nowrap">Date/Time Requested</th>
                <th className="py-4 px-2 text-[10px] font-black w-[14%] whitespace-nowrap">Client Name</th>
                <th className="py-4 px-2 text-[10px] font-black w-[11%] whitespace-nowrap">Course</th>
                <th className="py-4 px-2 text-[10px] font-black w-[8%] whitespace-nowrap">Gender</th>
                <th className="py-4 px-2 text-[10px] font-black w-[15%] whitespace-nowrap">Email</th>
                <th className="py-4 px-2 text-[10px] font-black w-[11%] whitespace-nowrap">Date/Time Processed</th>
                <th className="py-4 px-2 text-[10px] font-black w-[10%] whitespace-nowrap" title="Office-hours time only — weekends, holidays, and after-hours are excluded">No. of Minutes Processed (Business Hours)</th>
                <th className="py-4 px-2 text-[10px] font-black w-[12%] whitespace-nowrap">Date Claimed</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                (() => {
                  const targetItem = targetCategory ? findMatchingItem(row, targetCategory, logbookCategoryNameById) : null;
                  const processedAt = getProcessedAt(row, targetItem);
                  const claimedAt = getClaimedAt(row, targetItem);

                  return (
                    <tr key={row.request_id || row.id} className={`border-b text-[11px] sm:text-[12px] transition-colors 
                    ${isDark ? 'border-[#3e4042] hover:bg-[#3a3b3c] text-[#b0b3b8]' : 
                    'border-gray-200 hover:bg-gray-50 text-gray-700'}`}>

                      <td className="p-3 sm:p-4 text-center whitespace-nowrap">
                        {formatDateTimeLong(row.requested_at) || 'N/A'}
                      </td>

                      <td className="p-3 sm:p-4 text-center font-bold whitespace-nowrap">
                        {getFullName(row)}
                      </td>

                      <td className="p-3 sm:p-4 text-center whitespace-nowrap">
                        {getCourse(row)}
                      </td>

                      <td className="p-3 sm:p-4 text-center whitespace-nowrap">
                        {getGender(row)}
                      </td>

                      <td className="p-3 sm:p-4 text-center truncate max-w-55 whitespace-nowrap">
                        {getEmail(row)}
                      </td>

                      <td className="p-3 sm:p-4 text-center whitespace-nowrap">
                        {formatDateTimeLong(processedAt) || '---'}
                      </td>

                      <td className="p-3 sm:p-4 text-center whitespace-nowrap">
                        {formatMinutesDuration(getProcessingMinutes(row, targetItem))}
                      </td>

                      <td className="p-3 sm:p-4 text-center italic text-gray-400 whitespace-nowrap">
                        {formatDateLong(claimedAt) || 'Pending'}
                      </td>

                    </tr>
                  );
                })()
              ))}

              {!loading && Array.from({ length: Math.max(0, rowsPerPage - rows.length) }).map((_, i) => (
                <tr key={`empty-${i}`} className="h-11.25 sm:h-13.25 border-b border-gray-100">
                  <td colSpan="8"></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Mobile Card View (visible on mobile, hidden md and up) */}
        <div className="block md:hidden flex-1 px-4 py-2 space-y-3">
          {rows.length === 0 && !loading && !tableLoading && (
            <div className={`text-center py-8 text-sm ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
              No logbook records found for this period.
            </div>
          )}

          {rows.map((row) => {
            const targetItem = targetCategory ? findMatchingItem(row, targetCategory, logbookCategoryNameById) : null;
            const processedAt = getProcessedAt(row, targetItem);
            const claimedAt = getClaimedAt(row, targetItem);

            return (
              <div
                key={row.request_id || row.id}
                className={`p-4 rounded-xl border transition-all shadow-sm ${
                  isDark
                    ? 'bg-[#1e1f20] border-[#3e4042] text-[#e4e6eb]'
                    : 'bg-white border-gray-200 text-gray-800'
                }`}
              >
                {/* Header: Name & Gender Badge */}
                <div className="flex items-center justify-between border-b pb-2.5 mb-3 border-gray-100 dark:border-[#3e4042]">
                  <span className={`font-bold text-sm ${isDark ? 'text-[#f5c542]' : 'text-[#800000]'}`}>
                    {getFullName(row)}
                  </span>
                  <span className={`text-[10px] px-2 py-0.5 rounded-full font-bold uppercase ${
                    isDark ? 'bg-[#3a3b3c] text-[#b0b3b8]' : 'bg-gray-100 text-gray-600'
                  }`}>
                    {getGender(row) || 'N/A'}
                  </span>
                </div>

                {/* Details Grid */}
                <div className="grid grid-cols-2 gap-x-3 gap-y-2.5 text-xs">
                  <div>
                    <span className="block text-[10px] uppercase font-bold tracking-wider text-gray-400 mb-0.5">
                      Course
                    </span>
                    <span className="font-semibold">{getCourse(row) || 'N/A'}</span>
                  </div>

                  <div>
                    <span className="block text-[10px] uppercase font-bold tracking-wider text-gray-400 mb-0.5">
                      Gender
                    </span>
                    <span className="font-semibold">{getGender(row) || '---'}</span>
                  </div>

                  <div>
                    <span className="block text-[10px] uppercase font-bold tracking-wider text-gray-400 mb-0.5">
                      Email
                    </span>
                    <span className="font-semibold truncate block" title={getEmail(row)}>
                      {getEmail(row) || 'N/A'}
                    </span>
                  </div>

                  <div>
                    <span className="block text-[10px] uppercase font-bold tracking-wider text-gray-400 mb-0.5">
                      Requested
                    </span>
                    <span>{formatDateTimeLong(row.requested_at) || 'N/A'}</span>
                  </div>

                  <div>
                    <span className="block text-[10px] uppercase font-bold tracking-wider text-gray-400 mb-0.5">
                      Processed
                    </span>
                    <span>{formatDateTimeLong(processedAt) || '---'}</span>
                  </div>

                  <div>
                    <span className="block text-[10px] uppercase font-bold tracking-wider text-gray-400 mb-0.5">
                      Business Minutes
                    </span>
                    <span className={`font-bold ${isDark ? 'text-[#f5c542]' : 'text-[#800000]'}`}>
                      {formatMinutesDuration(getProcessingMinutes(row, targetItem))}
                    </span>
                  </div>

                  <div>
                    <span className="block text-[10px] uppercase font-bold tracking-wider text-gray-400 mb-0.5">
                      Date Claimed
                    </span>
                    <span className="italic text-gray-400">{formatDateLong(claimedAt) || 'Pending'}</span>
                  </div>
                </div>
              </div>
            );
          })}
        </div>

        {/* ── Original Pagination Footer ── */}
        <div className={`px-4 sm:px-8 py-4 text-[11px] sm:text-sm flex flex-col sm:flex-row justify-between 
          items-center gap-4 print:hidden border-t ${isDark ? 'bg-[#242526] text-[#9a9a9a] border-[#3e4042]' 
          : 'bg-gray-50 text-gray-500 border-gray-200'}`}>
          <span className="text-center sm:text-left">
            Showing {total > 0 ? indexOfFirstItem + 1 : 0} to{" "}
            {Math.min(indexOfLastItem, total)} of {total} results
          </span>

          <div className="flex gap-4 items-center">
            <button
              onClick={() => setCurrentPage((prev) => Math.max(prev - 1, 1))}
              aria-label="Previous page"
              disabled={currentPage === 1}
              className={`p-1 rounded transition-colors ${currentPage === 1 ? 
                (isDark ? 'text-[#4e4f50] cursor-not-allowed' : 'text-gray-300 cursor-not-allowed') 
                : (isDark ? 'text-[#b0b3b8] hover:bg-[#3a3b3c]' : 'text-gray-600 hover:bg-gray-200')}`}
            >
              <ChevronLeftIcon className="w-5 h-5" />
            </button>

            <span className={`text-xs font-semibold whitespace-nowrap ${isDark ? 'text-[#b0b3b8]' : 'text-gray-700'}`}>
              Page {currentPage} of {totalPages}
            </span>

            <button
              onClick={() => setCurrentPage((prev) => Math.min(prev + 1, totalPages))}
              aria-label="Next page"
              disabled={currentPage === totalPages || totalPages === 0}
              className={`p-1 rounded transition-colors ${(currentPage === totalPages || totalPages === 0) 
                ? (isDark ? 'text-[#4e4f50] cursor-not-allowed' : 'text-gray-300 cursor-not-allowed') 
                : (isDark ? 'text-[#b0b3b8] hover:bg-[#3a3b3c]' : 'text-gray-600 hover:bg-gray-200')}`}
            >
              <ChevronRightIcon className="w-5 h-5" />
            </button>
          </div>
        </div>

        <SuccessToast message={toastSuccess} onClose={() => setToastSuccess('')} />
        <ErrorToast message={toastError} onClose={() => setToastError('')} />

        <LogbookDateRangeModal
          isOpen={isDateModalOpen}
          onClose={() => setIsDateModalOpen(false)}
          onConfirm={handleApplyDateFilter}
          initialDateFrom={dateFrom}
          initialDateTo={dateTo}
          initialActivePreset={activePreset}
          isDark={isDark}
        />
      </div>
    </div>
  );
};

export default LogbookRecords;