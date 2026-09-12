import React, { useState, useEffect, useMemo } from 'react';
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/react/24/solid';
import { useTheme } from '../context/ThemeContext';
import { useAuth } from '../context/AuthProvider';
import { hasModuleAction } from '../utils/policy';
import { getAllLogbookData, getDocumentTypes, getCertifications, getLogbookCategories } from '../services/api'; // FE-3 migration: uses getLogbookData() from API; now pages through via getAllLogbookData() since the backend endpoint is paginated
import {
  formatMinutesDuration,
  getProcessedAt,
  getProcessingDuration,
  getFullName,
  getCourse,
  getGender,
  getEmail,
  getDocumentLogbookLabels,
  getCertificationLogbookLabels,
  resolveLogbookLabel,
  formatDateLong,
  getClaimedAt,
} from '../utils/logbookHelpers.js';

import DropDown from '../components/DropDown';
import MultiSelectDropdown from '../components/MultiSelection.jsx';
import LogbookDateRangeModal from '../components/LogbookDateRangeModal';
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
  const [data, setData] = useState([]);
  const [dbDocTypes, setDbDocTypes] = useState([]);
  const [availableCertifications, setAvailableCertifications] = useState([]);
  const [logbookCategories, setLogbookCategories] = useState([]);
  const [historyByRequestId, setHistoryByRequestId] = useState({});
  const [loading, setLoading] = useState(true);
  const [currentPage, setCurrentPage] = useState(1);
  const [selectedDocCategories, setSelectedDocCategories] = useState([]);
  const [selectedCertificationLabel, setSelectedCertificationLabel] = useState('');
  const [exporting, setExporting] = useState(false);
  const [toastSuccess, setToastSuccess] = useState('');
  const [toastError, setToastError] = useState('');
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

  // Load logbook data on mount
  useEffect(() => {
    const fetchLogbookData = async () => {
      setLoading(true);
      try {
        const [logbookRows, typesRes, certRes, categoriesRes] = await Promise.all([
          getAllLogbookData(),
          getDocumentTypes(),
          getCertifications(),
          getLogbookCategories(),
        ]);

        const requests = toRows(logbookRows);
        const types = toRows(typesRes.data);
        const certifications = toRows(certRes.data);
        const categories = toRows(categoriesRes.data);

        setData(requests);
        setDbDocTypes(types);
        setAvailableCertifications(certifications);
        setLogbookCategories(categories);
        setHistoryByRequestId({});
        setCurrentPage(1);
      } catch (error) {
        console.error('Error loading logbook records:', error);
        setData([]);
        setDbDocTypes([]);
        setAvailableCertifications([]);
        setLogbookCategories([]);
        setHistoryByRequestId({});
        setToastError((error && (error.message || error.toString())) || 'Error loading logbook records.');
      } finally {
        setLoading(false);
      }
    };
    fetchLogbookData();
  }, []);

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
  // Build document filter dropdown options — one entry per distinct
  // logbook label (post-rollup), plus mandatory logbook category options
  // ("Completion Fee", "Transcript of Records", "Certified True Copy of Records").
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

    const requiredCategories = [
      'Completion Fee',
      'Transcript of Records',
      'Certified True Copy of Records',
    ];

    requiredCategories.forEach((cat) => {
      const key = cat.toLowerCase();
      if (!seen.has(key)) {
        seen.add(key);
        options.push(cat);
      }
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

  // Filter data
  const filteredData = useMemo(() => {
    const from = dateFrom ? new Date(dateFrom + 'T00:00:00') : null;
    const to = dateTo ? new Date(dateTo + 'T23:59:59') : null;

    const completedOnly = data.filter(item => {
      if (from || to) {
        const req = item.requested_at ? new Date(item.requested_at) : null;
        if (!req) return false;
        if (from && req < from) return false;
        if (to && req > to) return false;
      }
      return true;
    });

    if (isCertificationMode) {
      const targetCertification = String(selectedCertificationLabel || 'All Certification').trim().toLowerCase();
      return completedOnly.filter((item) => {
        const certLabels = getCertificationLogbookLabels(item, logbookCategoryNameById)
          .map((label) => label.trim().toLowerCase());
        if (targetCertification === 'all certification') return certLabels.length > 0;
        return certLabels.includes(targetCertification);
      });
    }

    if (!selectedDocCategories || selectedDocCategories.length === 0 || selectedDocCategories.includes('All Document')) {
      return completedOnly;
    }

    const selectedKeys = selectedDocCategories.map((c) => c.trim().toLowerCase());

    return completedOnly.filter((item) => {
      const labels = getDocumentLogbookLabels(item, logbookCategoryNameById).map((l) => l.trim().toLowerCase());
      if (labels.some((label) => selectedKeys.includes(label))) {
        return true;
      }
      const docs = Array.isArray(item.documents) ? item.documents : [];
      return docs.some((doc) => {
        const name = String(doc?.documentType?.document_name || doc?.document_name || doc?.name || '').toLowerCase();
        return selectedKeys.some((targetLabel) => {
          if (targetLabel === 'completion fee') {
            return name.includes('completion') || name.includes('incomplete') || name.includes('correction of entry');
          }
          if (targetLabel === 'transcript of records') {
            return name.includes('transcript') || name.includes('tor');
          }
          if (targetLabel === 'certified true copy of records') {
            return name.includes('certified true copy') || name.includes('ctc');
          }
          return name === targetLabel;
        });
      });
    });
  }, [selectedDocCategories, data, isCertificationMode, selectedCertificationLabel, logbookCategoryNameById, dateFrom, dateTo]);

  // Sort filtered data by request timestamp (most recent first)
  const sortedData = useMemo(() => {
    return [...filteredData].sort((a, b) => {
      const aRequestedAt = new Date(a.requested_at || 0).getTime();
      const bRequestedAt = new Date(b.requested_at || 0).getTime();
      return bRequestedAt - aRequestedAt;
    });
  }, [filteredData]);

  const totalPages = Math.ceil(sortedData.length / rowsPerPage) || 1;
  const indexOfFirstItem = (currentPage - 1) * rowsPerPage;
  const indexOfLastItem = currentPage * rowsPerPage;

  const currentData = useMemo(() => {
    return sortedData.slice(indexOfFirstItem, indexOfLastItem);
  }, [indexOfFirstItem, indexOfLastItem, sortedData]);

  // Build sections for DOCX export. Section titles/grouping are the
  // resolved logbook label (post-rollup) so the exported document groups
  // requests exactly the way the on-screen filter/dropdown does — never
  // the raw, pre-rollup document/certificate type name.
  const getExportSections = () => {
    if (isCertificationMode) {
      if (selectedCertificationLabel && selectedCertificationLabel !== 'All Certification') {
        return [{ title: selectedCertificationLabel, rows: sortedData }];
      }
      const certLabels = certificationOptions.filter(n => String(n || '').trim().toLowerCase() !== 'all certification');
      return certLabels.map((label) => ({
        title: label,
        rows: sortedData.filter((row) =>
          getCertificationLogbookLabels(row, logbookCategoryNameById)
            .map(x => x.toLowerCase())
            .includes(String(label).trim().toLowerCase())
        ),
      }));
    }

    if (selectedDocCategories && selectedDocCategories.length > 0 && !selectedDocCategories.includes('All Document')) {
      return selectedDocCategories.map((catName) => {
        const targetLabel = catName.trim().toLowerCase();
        return {
          title: catName,
          rows: sortedData.filter((item) => {
            const labels = getDocumentLogbookLabels(item, logbookCategoryNameById).map((l) => l.trim().toLowerCase());
            if (labels.includes(targetLabel)) return true;
            const docs = Array.isArray(item.documents) ? item.documents : [];
            return docs.some((doc) => {
              const name = String(doc?.documentType?.document_name || doc?.document_name || doc?.name || '').toLowerCase();
              if (targetLabel === 'completion fee') return name.includes('completion') || name.includes('incomplete') || name.includes('correction of entry');
              if (targetLabel === 'transcript of records') return name.includes('transcript') || name.includes('tor');
              if (targetLabel === 'certified true copy of records') return name.includes('certified true copy') || name.includes('ctc');
              return name === targetLabel;
            });
          }),
        };
      }).filter((sec) => sec.rows.length > 0);
    }

    const docLabels = Object.values(docLogbookLabelById).map(n => String(n || '').trim()).filter(Boolean);
    const uniqueDocLabels = Array.from(new Set(docLabels));

    return uniqueDocLabels.map((label) => ({
      title: label,
      rows: sortedData.filter((row) =>
        getDocumentLogbookLabels(row, logbookCategoryNameById)
          .map(x => x.toLowerCase())
          .includes(String(label).trim().toLowerCase())
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
      await logbookDocx(getExportSections(), pupLogoSrc, bpLogoSrc, historyByRequestId, rangeLabel);
      setToastSuccess('Exporting Report completed.');
    } catch (e) {
      console.error('Export to DOCX failed', e);
      setToastError((e && (e.message || e.toString())) || 'Exporting Report failed.');
    } finally {
      setExporting(false);
    }
  };

  // Listen for voice command to export documents
  useEffect(() => {
    const handleVoiceExport = () => {
      // Work Item #1: the voice-command path bypasses the Export
      // button entirely, so it needs its own canExport check — without
      // this, a Student Staff account could still trigger an export
      // by voice even with the button hidden.
      if (!loading && !exporting && canExport) {
        handleExportDocx();
      }
    };
    window.addEventListener('voice-command-export', handleVoiceExport);
    return () => window.removeEventListener('voice-command-export', handleVoiceExport);
  }, [loading, exporting, canExport]);

  // Convert text to Proper Case while preserving roman numerals and hyphenated parts
  const toProperCase = (value = '') => {
    return value
      .toString()
      .trim()
      .split(/\s+/)
      .map((token) => {
        if (/^[IVXLCDM]+$/i.test(token)) return token.toUpperCase();
        return token
          .toLowerCase()
          .replace(/(^|[-'])([a-z])/g, (_, separator, letter) => `${separator}${letter.toUpperCase()}`);
      })
      .join(' ');
  };

  // Format an ISO datetime value into long date + 24-hour time
  const formatDateTimeLong = (value) => {
    const datePart = formatDateLong(value);
    if (!datePart) return null;

    const timePart = new Date(value).toLocaleTimeString('en-US', {
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
    });

    return `${datePart} ${timePart}`;
  };

  if (loading) return <LogbookSkeleton isDark={isDark} />;

  return (
    <div className={`relative min-h-full font-sans text-left ${isDark ? 'bg-[#18191a] text-[#e4e6eb]' : 'bg-white text-gray-900'}`}>
      <div className={`max-w-350 mx-auto shadow-md rounded-sm flex flex-col min-h-150 print:p-0 print:shadow-none ${isDark ? 'bg-[#242526]' : 'bg-white'}`}>

        <div className="px-8 lg:mt-10">

          {/* ── Controls Panel ── */}
          <div className={`mb-6 print:hidden rounded-xl border p-4 sm:p-5 ${isDark ? 'bg-[#1e1f20] border-[#3e4042]' : 'bg-gray-50 border-gray-200'}`}>

            {/* Controls Row */}
            <div className="flex flex-wrap items-end gap-3 w-full">

              {/* Document Type multi-select checkbox dropdown */}
              <div className="w-full md:w-85 shrink-0">
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
                <div className="w-full md:w-75 shrink-0">
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

              {/* Vertical divider */}
              <div className={`hidden md:block self-stretch w-px mx-1 ${isDark ? 'bg-[#3e4042]' : 'bg-gray-200'}`} />
              
              {/* Date Filter Button */}
              <div className="w-full md:w-65 shrink-0 flex flex-col">
                <label className={`block text-sm font-medium mb-1.5 ${isDark ? 'text-[#b0b3b8]' : 'text-gray-600'}`}>
                  Date Range
                </label>
                <button
                  type="button"
                  onClick={() => setIsDateModalOpen(true)}
                  className={`
                    w-full flex items-center justify-between gap-2 pl-3 pr-3 py-3 rounded-lg text-sm font-medium 
                    shadow-sm focus:outline-none border transition-colors text-left cursor-pointer
                    ${isDark
                      ? 'bg-[#1f1f1f] text-[#e4e6eb] border-[#3e4042] hover:border-gray-200'
                      : 'bg-white text-gray-700 border-gray-200 hover:border-gray-200'
                    }
                  `}
                >
                  <span className="truncate">
                    {dateFrom && dateTo
                      ? `${dateFrom} to ${dateTo}`
                      : (dateFrom ? `From ${dateFrom}` : (dateTo ? `To ${dateTo}` : 'All Time'))}
                  </span>
                  <svg className="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                  </svg>
                </button>
              </div>

              {/* Export button — Work Item #1: hidden (not just disabled) when
                  the policy lacks Export, since this is documented as a soft/
                  UI-only gate rather than a hard boundary. */}
              {canExport && (
                <div className="w-full md:w-60 md:ml-auto shrink-0">
                  <button
                    data-voice-action="export"
                    onClick={handleExportDocx}
                    disabled={loading || exporting || sortedData.length === 0}
                    className={`w-full flex items-center justify-center px-3 py-3 
                      rounded-lg text-sm font-black uppercase tracking-wide shadow 
                      transition-colors bg-[#800000] text-white hover:bg-[#6b0000]
                      ${isDark ? 'bg-[#3a3b3c] text-[#e4e6eb] hover:bg-[#4e4f50]' : 
                      'bg-[#800000] text-white hover:bg-[#6b0000]'}`}
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
              {sortedData.length} record{sortedData.length !== 1 ? 's' : ''}
              {(dateFrom || dateTo) && (
                <span> &mdash; {dateFrom && `from ${dateFrom}`}{dateFrom && dateTo && ' '}{dateTo && `to ${dateTo}`}</span>
              )}
            </p>
          </div>
        </div>

        {/* ── Original Table ── */}
        <div className="flex-1 overflow-x-auto px-4 sm:px-6 md:px-8">
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
              {currentData.map((row) => (
                (() => {
                  const processedAt = getProcessedAt(row);
                  const claimedAt = getClaimedAt(row);

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
                        {formatMinutesDuration(getProcessingDuration(row))}
                      </td>

                      <td className="p-3 sm:p-4 text-center italic text-gray-400 whitespace-nowrap">
                        {formatDateLong(claimedAt) || 'Pending'}
                      </td>

                    </tr>
                  );
                })()
              ))}

              {!loading && Array.from({ length: Math.max(0, rowsPerPage - currentData.length) }).map((_, i) => (
                <tr key={`empty-${i}`} className="h-11.25 sm:h-13.25 border-b border-gray-100">
                  <td colSpan="8"></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* ── Original Pagination Footer ── */}
        <div className={`px-4 sm:px-8 py-4 text-[11px] sm:text-sm flex flex-col sm:flex-row justify-between 
          items-center gap-4 print:hidden border-t ${isDark ? 'bg-[#242526] text-[#9a9a9a] border-[#3e4042]' 
          : 'bg-gray-50 text-gray-500 border-gray-200'}`}>
          <span className="text-center sm:text-left">
            Showing {sortedData.length > 0 ? indexOfFirstItem + 1 : 0} to{" "}
            {Math.min(indexOfLastItem, sortedData.length)} of {sortedData.length} results
          </span>

          <div className="flex gap-4 items-center">
            <button
              onClick={() => setCurrentPage((prev) => Math.max(prev - 1, 1))}
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