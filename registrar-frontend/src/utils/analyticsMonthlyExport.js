import { Document, Packer, Paragraph, Table, TableRow, TableCell, VerticalAlign, TextRun, WidthType, AlignmentType, BorderStyle, ImageRun, Header, Footer, PageOrientation } from 'docx';
import { saveAs } from 'file-saver';
import puplogoimage from '../assets/puplogoimage.png';
import bagongPilipinasLogo from '../assets/Bagong_Pilipinas_logo.png';
import certificateFooterImg from '../assets/certificate_footer.png';
import { getDocumentTypes, getAllLogbookData, getCertifications } from '../services/api';
// FE-4 migration: replaced getDocumentRequests+getRequestHistory with getLogbookData();
// now uses getAllLogbookData() since the backend endpoint is paginated and this
// export groups records across the full completed-request history.

/**
 * Cumulative, calendar-aware processing minutes for a request, computed
 * from its full request_history array.
 *
 * request_history.business_minutes (see BusinessCalendarService on the
 * backend) is a PER-SEGMENT duration — time elapsed since the previous
 * status change only, counting office hours alone. Below, `historyRows` is
 * a request's entire history, and the "most recent" entry in it is always
 * whichever transition the caller is measuring through (e.g. the Completed
 * transition). Since nothing in the array is more recent than that entry,
 * summing business_minutes across the WHOLE array gives the cumulative
 * office-hours duration from the request being filed through that
 * transition — the calendar-aware equivalent of the cumulative
 * minutes_processed figure this file used to read directly.
 *
 * Returns null (rather than a partial total) when any segment predates the
 * business_minutes column, so callers can fall back to minutes_processed
 * for older records instead of mixing business-hours and wall-clock
 * minutes into one figure.
 */
const cumulativeBusinessMinutes = (historyRows) => {
  if (!Array.isArray(historyRows) || historyRows.length === 0) return null;

  let total = 0;
  for (const row of historyRows) {
    const minutes = row?.business_minutes;
    if (minutes === null || minutes === undefined) return null;
    total += Number(minutes) || 0;
  }
  return total;
};

const noBorder = {
  top: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' },
  bottom: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' },
  left: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' },
  right: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' },
};

const MONTH_NAMES = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];

const parseDateString = (dstr) => {
  if (!dstr) return null;
  const str = String(dstr).trim();
  const monthMatch = str.match(/^(\d{4})-(\d{2})$/);
  if (monthMatch) {
    const year = Number(monthMatch[1]);
    const month = Number(monthMatch[2]);
    const d = new Date(year, month - 1, 1, 0, 0, 0, 0);
    return { year, month, day: 1, raw: d };
  }

  const dateMatch = str.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (dateMatch) {
    const year = Number(dateMatch[1]);
    const month = Number(dateMatch[2]);
    const day = Number(dateMatch[3]);
    const d = new Date(year, month - 1, day, 0, 0, 0, 0);
    return { year, month, day, raw: d };
  }

  const d = new Date(str);
  if (Number.isNaN(d.getTime())) return null;
  return { year: d.getFullYear(), month: d.getMonth() + 1, day: d.getDate(), raw: d };
};

// Expand a month range into one row per calendar month.
const monthRowsBetween = (startDateStr, endDateStr) => {
  const s = parseDateString(startDateStr);
  const e = parseDateString(endDateStr);
  if (!s || !e) return [];
  const count = (e.year - s.year) * 12 + (e.month - s.month) + 1;
  const rows = [];

  for (let i = 0; i < count; i++) {
    const year = s.year + Math.floor((s.month - 1 + i) / 12);
    const month = ((s.month - 1 + i) % 12) + 1;
    const lastDay = new Date(year, month, 0).getDate();
    const startDate = new Date(year, month - 1, 1, 0, 0, 0, 0);
    const endDate = new Date(year, month - 1, lastDay, 23, 59, 59, 999);
    const startLabel = `${MONTH_NAMES[month - 1]} 1`;
    const endLabel = `${lastDay}`;
    const rangeText = `${startLabel} - ${endLabel}, ${year}`;
    rows.push({
      noOfRequests: '',
      estimatedProcess: '',
      dateRequested: rangeText,
      dateProcessed: rangeText,
      minutesProcessed: '',
      startDate,
      endDate,
    });
  }

  return rows;
};

const fetchImageData = async (src) => {
  const response = await fetch(src);
  return response.arrayBuffer();
};

const toRows = (raw) => {
  if (Array.isArray(raw)) return raw;
  if (Array.isArray(raw?.data)) return raw.data;
  return [];
};

const toDate = (value) => {
  if (!value) return null;
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : date;
};

const getDocumentNames = (request) => {
  const documents = Array.isArray(request?.documents) ? request.documents : [];
  return documents
    .map((document) => document?.documentType?.document_name ?? document?.document_type?.document_name ?? document?.document_name ?? '')
    .filter(Boolean);
};

const fetchAllDocumentRequests = async () => {
  const allRequests = [];
  let page = 1;
  let lastPage = 1;

  do {
    const response = await getDocumentRequests({ page });
    const payload = response?.data ?? {};
    const rows = Array.isArray(payload?.data) ? payload.data : [];

    allRequests.push(...rows);
    lastPage = Number(payload?.last_page ?? 1) || 1;
    page += 1;
  } while (page <= lastPage);

  return allRequests;
};

const cell = (text, options = {}) => new TableCell({
  width: {
    size: options.width ?? 20,
    type: WidthType.PERCENTAGE,
  },
  shading: options.shading,
  borders: {
    top: { style: BorderStyle.SINGLE, size: 1, color: 'D9D9D9' },
    bottom: { style: BorderStyle.SINGLE, size: 1, color: 'D9D9D9' },
    left: { style: BorderStyle.SINGLE, size: 1, color: 'D9D9D9' },
    right: { style: BorderStyle.SINGLE, size: 1, color: 'D9D9D9' },
  },
  margins: { top: 120, bottom: 120, left: 120, right: 120 },
  children: [
    new Paragraph({
      alignment: options.align ?? AlignmentType.CENTER,
      children: [
        new TextRun({
          text: text === null || text === undefined ? '' : String(text),
          bold: options.bold ?? false,
          size: options.size ?? 20,
          color: options.color ?? '000000',
        }),
      ],
    }),
  ],
});

const buildReportTable = (rows) => {
  const totalRequests = rows.reduce((s, r) => s + (Number(r.noOfRequests || 0) || 0), 0);
  const totalMinutes = rows.reduce((s, r) => s + (Number(r.minutesNumeric || 0) || 0), 0);

  // Compute average minutes per processed request and format as hours/minutes
  const avgMinutesPerRequest = totalRequests > 0 ? (totalMinutes / totalRequests) : 0;
  const hours = Math.floor(avgMinutesPerRequest / 60);
  const minutes = Math.round(avgMinutesPerRequest - (hours * 60));

  // SLA-Aware OPC Rating: Parses process period SLA (1 working day = 480 business minutes).
  // Supports formats like "2 working day/s, 23 minute/s", "3-5 business days", etc.
  // Automatically returns 1.0 if average processing time exceeds allowed SLA days/time.
  const parseSlaMinutes = (estimatedProcessStr) => {
    if (!estimatedProcessStr) return 480;
    const str = String(estimatedProcessStr).trim().toLowerCase();
    let totalMinutes = 0;

    // Range days: e.g. "3-5 business days" -> 5 days
    const rangeDayMatch = str.match(/(\d+)\s*-\s*(\d+)\s*(?:working|business)?\s*day/i);
    if (rangeDayMatch) {
      const maxDays = Math.max(Number(rangeDayMatch[1]), Number(rangeDayMatch[2]));
      totalMinutes += maxDays * 8 * 60;
    } else {
      // Single days: e.g. "2 working day/s", "1 day"
      const singleDayMatch = str.match(/(\d+)\s*(?:working|business)?\s*day/i);
      if (singleDayMatch) {
        totalMinutes += Number(singleDayMatch[1]) * 8 * 60;
      }
    }

    // Hours: e.g. "2 hour/s", "5 hours"
    const hourMatch = str.match(/(\d+)\s*hour/i);
    if (hourMatch) {
      totalMinutes += Number(hourMatch[1]) * 60;
    }

    // Minutes: e.g. "23 minute/s", "15 minutes"
    const minuteMatch = str.match(/(\d+)\s*minute/i);
    if (minuteMatch) {
      totalMinutes += Number(minuteMatch[1]);
    }

    // Fallback for raw numbers without unit labels (assume working days)
    if (totalMinutes === 0) {
      const rawMatch = str.match(/\d+/);
      if (rawMatch) {
        totalMinutes = Number(rawMatch[0]) * 8 * 60;
      }
    }

    return totalMinutes > 0 ? totalMinutes : 480;
  };

  const computeOpcRating = (avgMins, estimatedProcessStr) => {
    if (avgMins <= 0) return 5.0;
    const slaMins = parseSlaMinutes(estimatedProcessStr);

    // Automatic 1.0 if average time exceeds allowed document SLA target
    if (avgMins > slaMins) {
      return 1.0;
    }

    const ratio = avgMins / slaMins;
    if (ratio <= 0.25) return 5.0;
    if (ratio <= 0.50) return 4.0;
    if (ratio <= 0.75) return 3.0;
    return 2.0;
  };

  const sampleEstimatedProcess = rows.find((r) => r.estimatedProcess)?.estimatedProcess || '';
  const numericValue = computeOpcRating(avgMinutesPerRequest, sampleEstimatedProcess).toFixed(1);
  let timeText;
  if (hours <= 0) {
    timeText = `${minutes} MINUTE${minutes !== 1 ? 'S' : ''}`;
  } else {
    timeText = `${hours} HOUR${hours !== 1 ? 'S' : ''} AND ${minutes} MINUTE${minutes !== 1 ? 'S' : ''}`;
  }

  const totalText = `${totalMinutes}/${totalRequests} = PROCESSED REQUEST WITHIN ${timeText} = ${numericValue}`;

  return new Table({
    width: { size: 100, type: WidthType.PERCENTAGE },
    rows: [
      new TableRow({
        children: [
          cell('NO. OF REQUEST', { bold: true, shading: { fill: '7F0000' }, color: 'FFFFFF', width: 18 }),
          cell('ESTIMATED DAY/TIME TO PROCESS', { bold: true, shading: { fill: '7F0000' }, color: 'FFFFFF', width: 24 }),
          cell('DATE REQUESTED', { bold: true, shading: { fill: '7F0000' }, color: 'FFFFFF', width: 19 }),
          cell('DATE PROCESSED', { bold: true, shading: { fill: '7F0000' }, color: 'FFFFFF', width: 19 }),
          cell('NUMBER OF MINUTES PROCESSED (BUSINESS HOURS)', { bold: true, shading: { fill: '7F0000' }, color: 'FFFFFF', width: 20 }),
        ],
      }),
      ...rows.map((row) => new TableRow({
        children: [
          cell(row.noOfRequests, { width: 18, align: AlignmentType.CENTER }),
          cell(row.estimatedProcess, { width: 24, align: AlignmentType.LEFT }),
          cell(row.dateRequested, { width: 19, align: AlignmentType.CENTER }),
          cell(row.dateProcessed, { width: 19, align: AlignmentType.CENTER }),
          cell(row.minutesProcessed, { width: 20, align: AlignmentType.CENTER }),
        ],
      })),
      new TableRow({
        children: [
          cell('TOTAL', { bold: true, align: AlignmentType.CENTER, width: 18 }),
          new TableCell({
            columnSpan: 4,
            width: { size: 82, type: WidthType.PERCENTAGE },
            borders: {
              top: { style: BorderStyle.SINGLE, size: 1, color: 'D9D9D9' },
              bottom: { style: BorderStyle.SINGLE, size: 1, color: 'D9D9D9' },
              left: { style: BorderStyle.SINGLE, size: 1, color: 'D9D9D9' },
              right: { style: BorderStyle.SINGLE, size: 1, color: 'D9D9D9' },
            },
            margins: { top: 120, bottom: 120, left: 120, right: 120 },
            children: [
              new Paragraph({ alignment: AlignmentType.CENTER, children: [ new TextRun({ text: totalText, bold: true, size: 20 }) ] }),
            ],
          }),
        ],
      }),
    ],
  });
};

const buildEmptyRow = (text) => new TableRow({
  children: [
    new TableCell({
      columnSpan: 5,
      width: { size: 100, type: WidthType.PERCENTAGE },
      borders: {
        top: { style: BorderStyle.SINGLE, size: 1, color: 'D9D9D9' },
        bottom: { style: BorderStyle.SINGLE, size: 1, color: 'D9D9D9' },
        left: { style: BorderStyle.SINGLE, size: 1, color: 'D9D9D9' },
        right: { style: BorderStyle.SINGLE, size: 1, color: 'D9D9D9' },
      },
      margins: { top: 120, bottom: 120, left: 120, right: 120 },
      children: [
        new Paragraph({ alignment: AlignmentType.CENTER, children: [ new TextRun({ text: text, italics: true, size: 18 }) ] }),
      ],
    })
  ],
});

const buildHeader = async () => {
  const [leftLogo, rightLogo] = await Promise.all([
    fetchImageData(puplogoimage),
    fetchImageData(bagongPilipinasLogo),
  ]);

  return new Header({
    children: [
      new Table({
        width: { size: 100, type: WidthType.PERCENTAGE },
        borders: {
          top: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' },
          bottom: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' },
          left: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' },
          right: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' },
          insideHorizontal: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' },
          insideVertical: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' },
        },
        rows: [
          new TableRow({
            children: [
              new TableCell({
                width: { size: 12, type: WidthType.PERCENTAGE },
                borders: { top: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' }, bottom: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' }, left: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' }, right: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' } },
                children: [
                  new Paragraph({
                    alignment: AlignmentType.RIGHT,
                    children: [
                      new ImageRun({
                        data: leftLogo,
                        transformation: { width: 72, height: 72 },
                      }),
                    ],
                  }),
                ],
              }),
              new TableCell({
                width: { size: 68, type: WidthType.PERCENTAGE },
                borders: { top: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' }, bottom: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' }, left: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' }, right: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' } },
                children: [
                  new Paragraph({
                    alignment: AlignmentType.LEFT,
                    spacing: { after: 0 },
                    children: [new TextRun({ text: 'REPUBLIC OF THE PHILIPPINES', size: 16 })],
                  }),
                  new Paragraph({
                    alignment: AlignmentType.LEFT,
                    spacing: { after: 0 },
                    children: [
                      new TextRun({ text: 'POLYTECHNIC UNIVERSITY OF THE PHILIPPINES', size: 22, color: '000000', bold: true }),
                    ],
                  }),
                  new Paragraph({
                    alignment: AlignmentType.LEFT,
                    spacing: { after: 0 },
                    children: [new TextRun({ text: 'OFFICE OF THE VICE PRESIDENT FOR CAMPUSES', size: 15 })],
                  }),
                  new Paragraph({
                    alignment: AlignmentType.LEFT,
                    spacing: { after: 0 },
                    children: [new TextRun({ text: 'TAGUIG CAMPUS', size: 22, bold: true, color: '000000' })],
                  }),
                  new Paragraph({
                    alignment: AlignmentType.LEFT,
                    spacing: { after: 0 },
                    children: [new TextRun({ text: 'Office of the Campus Registrar', size: 15, italics: true, color: '666666' })],
                  }),
                ],
              }),
              new TableCell({
                width: { size: 20, type: WidthType.PERCENTAGE },
                borders: { top: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' }, bottom: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' }, left: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' }, right: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' } },
                children: [
                  new Paragraph({
                    alignment: AlignmentType.CENTER,
                    children: [
                      new ImageRun({
                        data: rightLogo,
                        transformation: { width: 72, height: 72 },
                      }),
                    ],
                  }),
                ],
              }),
            ],
          }),
        ],
      }),
      new Paragraph({ spacing: { after: 0 } }),
    ],
  });
};

export const exportMonthlyDocx = async (startYM, endYM, docType = 'ALL', certType = '', options = {}) => {
  const preparedByName = options.preparedByName || 'MHEL P. GARCIA';
  const preparedByTitle = options.preparedByTitle || 'Head of Registration Office';
  const notedByName = options.notedByName || 'DR. MARISSA B. FERRER';
  const notedByTitle = options.notedByTitle || 'Campus Director';

  const header = await buildHeader();
  const footerLogo = await fetchImageData(certificateFooterImg);
  const getSectionTitle = (docName) => {
    const normalizedDocType = String(docType || '').trim().toLowerCase();
    const normalizedCertType = String(certType || '').trim().toLowerCase();

    const isAllCertificationMode =
      normalizedDocType === 'all certification' ||
      (normalizedDocType === 'certification' && normalizedCertType === 'all certification');

    if (docType === 'ALL' || isAllCertificationMode) {
      return String(docName || '').toUpperCase();
    }

    if (normalizedDocType === 'certification' && certType) {
      return `CERTIFICATION ${String(certType).toUpperCase()}`;
    }

    return `${String(docType).toUpperCase()}${certType ? ` ${String(certType).toUpperCase()}` : ''}`;
  };

  const footer = new Footer({
    children: [
      new Table({
        width: { size: 100, type: WidthType.PERCENTAGE },
        borders: {
          top: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' },
          bottom: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' },
          left: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' },
          right: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' },
          insideHorizontal: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' },
          insideVertical: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' },
        },
        rows: [
          new TableRow({
            children: [
              new TableCell({
                width: { size: 70, type: WidthType.PERCENTAGE },
                borders: { top: { style: BorderStyle.NONE, size: 0 }, bottom: { style: BorderStyle.NONE, size: 0 }, left: { style: BorderStyle.NONE, size: 0 }, right: { style: BorderStyle.NONE, size: 0 } },
                children: [
                  new Paragraph({ children: [ new TextRun({ text: 'General Santos Avenue, Lower Bicutan, Taguig City, Philippines 1632', size: 18 }) ] }),
                  new Paragraph({ children: [ new TextRun({ text: 'Direct Line: (02) 8837 5858 to 60 | Email: taguig@pup.edu.ph', size: 18 }) ] }),
                  new Paragraph({ children: [ new TextRun({ text: 'Website: www.pup.edu.ph | Inquiries: https://bit.ly/PUPSINTA', size: 18 }) ] }),
                  new Paragraph({ spacing: { before: 120 }, children: [ new TextRun({ text: "THE COUNTRY'S 1st POLYTECHNIC", bold: true, size: 18 }) ] }),
                ],
              }),
              new TableCell({
                width: { size: 30, type: WidthType.PERCENTAGE },
                borders: { top: { style: BorderStyle.NONE, size: 0 }, bottom: { style: BorderStyle.NONE, size: 0 }, left: { style: BorderStyle.NONE, size: 0 }, right: { style: BorderStyle.NONE, size: 0 } },
                children: [
                  new Paragraph({ alignment: AlignmentType.RIGHT, children: [ new ImageRun({ data: new Uint8Array(footerLogo), transformation: { width: 240, height: 80 } }) ] }),
                ],
              }),
            ],
          }),
          new TableRow({
            children: [
              new TableCell({
                width: { size: 60, type: WidthType.PERCENTAGE },
                borders: noBorder,
                children: [
                  new Paragraph({
                    spacing: { before: 50 },
                    children: [
                      new TextRun({
                        text: 'This document contains personal-identifiable information that is subject to Data Privacy.',
                        size: 13,
                        color: 'FF0000',
                        bold: true,
                        font: 'Lucida Fax',
                      }),
                    ],
                  }),
                  new Paragraph({
                    children: [
                      new TextRun({
                        text: 'Please keep this document protected and in a safe place.',
                        size: 13,
                        color: 'FF0000',
                        bold: true,
                        font: 'Lucida Fax',
                      }),
                    ],
                  }),
                ],
              }),
              new TableCell({
                width: { size: 20, type: WidthType.PERCENTAGE },
                borders: noBorder,
                verticalAlign: VerticalAlign.BOTTOM,
                children: [
                  new Paragraph({
                    alignment: AlignmentType.RIGHT,
                    spacing: { before: 50 },
                    children: [
                      new TextRun({
                        text: 'This is system-generated, signature is not required.',
                        size: 13,
                        color: '555555',
                        font: 'Lucida Fax',
                      }),
                    ],
                  }),
                ],
              }),
            ],
          }),
        ],
      }),
    ],
  });

  let docsToExport = [];
  if (!docType) throw new Error('Please select a document type.');
  if (docType === 'ALL') {
    try {
      const dtRes = await getDocumentTypes();
      docsToExport = dtRes.data.map(d => d.document_name);
    } catch (_) {
      docsToExport = ['All Documents'];
    }
  } else if (String(docType).toLowerCase() === 'all certification') {
    // Certification exports can target all certificate types at once.
    try {
      const certRes = await getCertifications();
      docsToExport = Array.isArray(certRes?.data) ? certRes.data.map(c => c.certificate_name).filter(Boolean) : [];
    } catch (_) {
      docsToExport = [];
    }
  } else if (docType === 'CERTIFICATION' && certType) {
    if (String(certType).toLowerCase() === 'all certification') {
      try {
        const certRes = await getCertifications();
        docsToExport = Array.isArray(certRes?.data) ? certRes.data.map(c => c.certificate_name).filter(Boolean) : [];
      } catch (_) {
        docsToExport = [];
      }
    } else {
      // only export that certification
      docsToExport = [certType];
    }
  } else {
    docsToExport = [docType];
  }
  if (!Array.isArray(docsToExport) || docsToExport.length === 0) {
    throw new Error('No document types found to export for the selected options.');
  }
  const [logbookRes, allDocumentTypesRes, allCertificationsRes] = await Promise.all([
    getAllLogbookData(), // FE-4: returns completed requests with embedded history, paged through in full
    (async () => { try { return await getDocumentTypes(); } catch (_) { return { data: [] }; } })(),
    (async () => { try { return await getCertifications(); } catch (_) { return { data: [] }; } })(),
  ]);

  const requestsRaw = toRows(logbookRes);
  const latestHistoryByRequestId = {};
  requestsRaw.forEach((req) => {
    if (req?.request_id) {
      latestHistoryByRequestId[req.request_id] = Array.isArray(req.history) ? req.history : [];
    }
  });

  const seenRequestIds = new Set();
  let logbookRequests  = requestsRaw.filter((req) => {
    const id = req?.request_id;
    if (seenRequestIds.has(id)) return false;
    seenRequestIds.add(id);
    return true;
  });

  let activeStart = startYM;
  let activeEnd = endYM;

  if (!activeStart || !activeEnd) {
    const dates = logbookRequests
      .map((r) => toDate(r.requested_at ?? r.date_requested ?? r.requestedOn ?? r.processed_at ?? r.date_processed))
      .filter(Boolean)
      .map((d) => d.getTime());

    if (dates.length > 0) {
      const minDate = new Date(Math.min(...dates));
      const maxDate = new Date(Math.max(...dates));
      const pad2 = (val) => String(val).padStart(2, '0');
      
      if (!activeStart) {
        activeStart = `${minDate.getFullYear()}-${pad2(minDate.getMonth() + 1)}`;
      }
      if (!activeEnd) {
        activeEnd = `${maxDate.getFullYear()}-${pad2(maxDate.getMonth() + 1)}`;
      }
    } else {
      const today = new Date();
      const pad2 = (val) => String(val).padStart(2, '0');
      const yStr = today.getFullYear();
      const mStr = pad2(today.getMonth() + 1);
      if (!activeStart) activeStart = `${yStr}-${mStr}`;
      if (!activeEnd) activeEnd = `${yStr}-${mStr}`;
    }
  }

  const docTypesArray = Array.isArray(allDocumentTypesRes?.data) ? allDocumentTypesRes.data : [];
  const docProcessMapByName = {};
  const docProcessMapById = {};
  docTypesArray.forEach((dt) => {
    const name = String(dt.document_name || '').trim().toLowerCase();
    const id = dt.document_type_id;
    const period = dt.document_process_period ?? dt.document_process_periods ?? dt.process_period ?? dt.estimated_process_time ?? '';
    if (name) docProcessMapByName[name] = String(period || '');
    if (id != null) docProcessMapById[String(id)] = String(period || '');
  });

  const certArray = Array.isArray(allCertificationsRes?.data) ? allCertificationsRes.data : [];
  const certProcessMapByName = {};
  certArray.forEach((c) => {
    const name = String(c.certificate_name || c.name || '').trim().toLowerCase();
    const period = c.certificate_process_period ?? c.process_period ?? c.estimated_process_time ?? '';
    if (name) certProcessMapByName[name] = String(period || '');
  });

  const sectionArrays = await Promise.all(docsToExport.map(async (docName) => {
    const monthRows = monthRowsBetween(activeStart, activeEnd);
    const getCertificateNames = (request) => {
      const certs = Array.isArray(request?.certificates) ? request.certificates : [];
      return certs.map((c) => (c.certification_type?.certificate_name ?? c.certificate_name ?? c.name ?? '')).filter(Boolean).map(s => String(s).trim().toLowerCase());
    };

    const matchingRequests = logbookRequests.filter((request) => {
      const docNames = getDocumentNames(request).map(s => String(s).trim().toLowerCase());
      const certNames = getCertificateNames(request);
      const target = String(docName).trim().toLowerCase();
      return docNames.includes(target) || certNames.includes(target);
    });

    const aggregatedRows = monthRows.map((mRow) => {
      const start = mRow.startDate;
      const end = mRow.endDate;

      const parseRequestDates = (request) => {
        const requestedDate = toDate(request?.requested_at ?? request?.date_requested ?? request?.requestedOn);
        const historyRows = Array.isArray(latestHistoryByRequestId[request?.request_id])
          ? [...latestHistoryByRequestId[request?.request_id]].sort((a, b) => {
              const aTime = new Date(a?.changed_at || 0).getTime();
              const bTime = new Date(b?.changed_at || 0).getTime();
              return bTime - aTime;
            })
          : [];
        const history = historyRows[0] || null;
        const processedDate = toDate(history?.changed_at ?? request?.processed_at ?? request?.date_processed);
        // Prefer the calendar-aware (business-hours-only) cumulative total;
        // fall back to the raw wall-clock figure for pre-business_minutes
        // records so older requests still report something.
        const minutesProcessed = cumulativeBusinessMinutes(historyRows)
          ?? Number(history?.minutes_processed ?? request?.minutes_processed ?? request?.minutes ?? request?.number_of_minutes_processed);
        return { requestedDate, processedDate, minutesProcessed, historyRows, history };
      };

      const isCompleted = (request) => {
        const histRows = Array.isArray(latestHistoryByRequestId[request?.request_id]) ? latestHistoryByRequestId[request?.request_id] : [];
        const latestHist = [...histRows].sort((a, b) => new Date(b?.changed_at || 0).getTime() - new Date(a?.changed_at || 0).getTime())[0] || null;
        const statusName = String(request?.status?.status_name || request?.status_name || latestHist?.status_name || '').toLowerCase();
        return statusName === 'completed' || statusName === 'complete' || statusName === 'completed.';
      };

      const processedInRange = matchingRequests.filter((request) => {
        const { processedDate } = parseRequestDates(request);
        return processedDate && processedDate >= start && processedDate <= end;
      });

      const completedProcessedInRange = processedInRange.filter(isCompleted);

      const requestHasTarget = (request) => {
        const target = String(docName).trim().toLowerCase();
        const docs = Array.isArray(request?.documents) ? request.documents : [];
        const certs = Array.isArray(request?.certificates) ? request.certificates : [];

        const docMatch = docs.some((doc) => {
          const name = (doc?.documentType?.document_name ?? doc?.document_type?.document_name ?? doc?.document_name ?? '').toString().trim().toLowerCase();
          return name === target;
        });

        const certMatch = certs.some((c) => {
          const name = (c.certification_type?.certificate_name ?? c.certificate_name ?? c.name ?? '').toString().trim().toLowerCase();
          return name === target;
        });

        return docMatch || certMatch;
      };

      const totalRequests = completedProcessedInRange.filter(requestHasTarget).length;
      let totalMinutes = 0;
      const debugRequestIds = [];

      completedProcessedInRange.forEach((request) => {
        if (!requestHasTarget(request)) return;

        const historyRows = Array.isArray(latestHistoryByRequestId[request?.request_id]) ? latestHistoryByRequestId[request?.request_id] : [];
        const history = [...historyRows].sort((a, b) => {
          const aTime = new Date(a?.changed_at || 0).getTime();
          const bTime = new Date(b?.changed_at || 0).getTime();
          return bTime - aTime;
        })[0] || null;
        // Same business-hours-first, wall-clock-fallback preference as
        // parseRequestDates() above — see cumulativeBusinessMinutes() doc
        // block for why this can't be read off a single history row.
        const businessMinutes = cumulativeBusinessMinutes(historyRows);
        const minutesFromHistory = businessMinutes ?? Number(history?.minutes_processed ?? NaN);
        if (Number.isFinite(minutesFromHistory) && minutesFromHistory >= 0) {
          totalMinutes += Number(minutesFromHistory);
          debugRequestIds.push(request?.request_id);
        }
      });

      const lowerName = String(docName).trim().toLowerCase();
      const estimatedProcess = docProcessMapByName[lowerName] || certProcessMapByName[lowerName] || '';
      const finalNoOfRequests = Number(totalRequests) || 0;
      const finalMinutesNumeric = Number(totalMinutes) || 0;
      const minutesCell = finalMinutesNumeric > 0 ? String(finalMinutesNumeric) : '0';

      return {
        noOfRequests: finalNoOfRequests,
        estimatedProcess,
        dateRequested: mRow.dateRequested,
        dateProcessed: mRow.dateProcessed,
        minutesProcessed: minutesCell,
        minutesNumeric: finalMinutesNumeric,
      };
    });
    const hasAnyData = aggregatedRows.some((row) => (Number(row.noOfRequests) || 0) > 0 || (Number(row.minutesNumeric) || 0) > 0);

    const sectionChildren = [];
    sectionChildren.push(new Paragraph({
      alignment: AlignmentType.CENTER,
      spacing: { before: 150, after: 150 },
      children: [ new TextRun({ text: 'SUMMARY MATRIX OF PROCESSED REQUEST FOR', bold: true, size: 24, color: '000000' }) ],
    }));
    sectionChildren.push(new Paragraph({
      alignment: AlignmentType.CENTER,
      spacing: { before: 150, after: 150 },
      children: [ new TextRun({ text: getSectionTitle(docName), bold: true, size: 22, color: '000000', highlight: 'yellow' }) ],
    }));
    if (!hasAnyData) {
      sectionChildren.push(new Table({ width: { size: 100, type: WidthType.PERCENTAGE }, rows: [
        new TableRow({ children: [
          cell('NO. OF REQUEST', { bold: true, shading: { fill: '7F0000' }, color: 'FFFFFF', width: 18 }),
          cell('ESTIMATED DAY/TIME TO PROCESS', { bold: true, shading: { fill: '7F0000' }, color: 'FFFFFF', width: 24 }),
          cell('DATE REQUESTED', { bold: true, shading: { fill: '7F0000' }, color: 'FFFFFF', width: 19 }),
          cell('DATE PROCESSED', { bold: true, shading: { fill: '7F0000' }, color: 'FFFFFF', width: 19 }),
          cell('NUMBER OF MINUTES PROCESSED (BUSINESS HOURS)', { bold: true, shading: { fill: '7F0000' }, color: 'FFFFFF', width: 20 }),
        ]}),
        buildEmptyRow('No data available for this document and period'),
      ]}));
    } else {
      sectionChildren.push(buildReportTable(aggregatedRows));
    }

    sectionChildren.push(new Paragraph({ spacing: { before: 720 }, children: [ new TextRun({ text: '', size: 20 }) ] }));
    sectionChildren.push(new Table({
      width: { size: 100, type: WidthType.PERCENTAGE },
      borders: {
        top: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' },
        bottom: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' },
        left: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' },
        right: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' },
        insideHorizontal: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' },
        insideVertical: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' },
      },
      rows: [
        new TableRow({
          children: [
            new TableCell({
              width: { size: 45, type: WidthType.PERCENTAGE },
              children: [
                new Paragraph({ children: [ new TextRun({ text: 'Prepared by:', bold: true, size: 20 }) ] }),
                new Paragraph({ spacing: { before: 720 }, children: [ new TextRun({ text: preparedByName, bold: true, size: 20 }) ] }),
                new Paragraph({ children: [ new TextRun({ text: preparedByTitle, size: 18 }) ] }),
              ],
              borders: { top: { style: BorderStyle.NONE, size: 0 }, bottom: { style: BorderStyle.NONE, size: 0 }, left: { style: BorderStyle.NONE, size: 0 }, right: { style: BorderStyle.NONE, size: 0 } },
            }),
            new TableCell({
              width: { size: 10, type: WidthType.PERCENTAGE },
              children: [ new Paragraph({ children: [ new TextRun({ text: '', size: 20 }) ] }) ],
              borders: { top: { style: BorderStyle.NONE, size: 0 }, bottom: { style: BorderStyle.NONE, size: 0 }, left: { style: BorderStyle.NONE, size: 0 }, right: { style: BorderStyle.NONE, size: 0 } },
            }),
            new TableCell({
              width: { size: 45, type: WidthType.PERCENTAGE },
              children: [
                new Paragraph({ children: [ new TextRun({ text: 'Noted by:', bold: true, size: 20 }) ] }),
                new Paragraph({ spacing: { before: 720 }, children: [ new TextRun({ text: notedByName, bold: true, size: 20 }) ] }),
                new Paragraph({ children: [ new TextRun({ text: notedByTitle, size: 18 }) ] }),
              ],
              borders: { top: { style: BorderStyle.NONE, size: 0 }, bottom: { style: BorderStyle.NONE, size: 0 }, left: { style: BorderStyle.NONE, size: 0 }, right: { style: BorderStyle.NONE, size: 0 } },
            }),
          ],
        }),
      ],
    }));

    return sectionChildren;
  }));

  // Build one Document section per document to ensure each document starts on its own page
  const docSections = sectionArrays.map((children) => ({
    headers: { default: header },
    footers: { default: footer },
    properties: {
      page: {
        size: {
          orientation: PageOrientation.LANDSCAPE,
          width: 15840,
          height: 12240,
        },
        margin: {
          top: 720,
          right: 720,
          bottom: 720,
          left: 720,
          header: 360,
          footer: 360,
        },
      },
    },
    children: [
      ...children,
    ],
  }));

  const doc = new Document({
    styles: {
      default: {
        document: {
          run: {
            font: 'Lucida Fax',
            size: 20,
          },
        },
      },
    },
    sections: docSections,
  });

          
          const blob = await Packer.toBlob(doc);
          const filename = `MATRIX OF PROCESSED REQUEST - OPCR ${new Date().getFullYear()}.docx`;
          try { saveAs(blob, filename); } catch (e) { /* ignore save errors in non-browser contexts */ }
          return { blob, filename };
        };

export default exportMonthlyDocx;