import { Document, Packer, Paragraph, Table, TableRow, TableCell, TextRun, WidthType, AlignmentType, BorderStyle, ImageRun, Header, Footer, PageOrientation, VerticalAlign } from 'docx';
import { saveAs } from 'file-saver';
import certificate_footer from '../assets/certificate_footer.png';

import {
  formatDateLong,
  formatMinutesDuration,
  getFullName,
  getCourse,
  getGender,
  getEmail,
  getHistoryRows,
  getProcessedAt,
  getProcessingDuration,
  getClaimedAt,
  findMatchingItem,
} from './logbookHelpers.js';
// FE-2 migration: helpers imported from logbookHelpers.js
const fetchImageData = async (src) => {
  if (!src) return null;
  try {
    const res = await fetch(src);
    return await res.arrayBuffer();
  } catch (e) {
    console.error('Failed to fetch image', src, e);
    return null;
  }
};

const makeFont = (size, extra = {}) => ({ size, font: 'Lucida Fax', ...extra });

const noBorder = {
  top: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' },
  bottom: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' },
  left: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' },
  right: { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' },
};

const DEFAULT_TEXT_SIZE = 18;
const HEADER_TEXT_SIZE = 18;
const DEFAULT_CELL_MARGINS = { top: 90, bottom: 90, left: 100, right: 100 };

const cell = (text, options = {}) => new TableCell({
  width: { size: options.width ?? 20, type: WidthType.PERCENTAGE },
  shading: options.shading,
  borders: {
    top: { style: BorderStyle.SINGLE, size: 1, color: 'D9D9D9' },
    bottom: { style: BorderStyle.SINGLE, size: 1, color: 'D9D9D9' },
    left: { style: BorderStyle.SINGLE, size: 1, color: 'D9D9D9' },
    right: { style: BorderStyle.SINGLE, size: 1, color: 'D9D9D9' },
  },
  margins: options.margins ?? DEFAULT_CELL_MARGINS,
  verticalAlign: options.verticalAlign ?? VerticalAlign.CENTER,
  children: [
    new Paragraph({
      alignment: options.align ?? AlignmentType.CENTER,
      spacing: { before: 0, after: 0 },
      children: [
        new TextRun({
          text: text === null || text === undefined ? '' : String(text),
          bold: options.bold ?? false,
          size: options.size ?? DEFAULT_TEXT_SIZE,
          color: options.color ?? '000000',
          font: options.font ?? 'Lucida Fax',
        }),
      ],
    }),
  ],
});

const buildHeader = async (pupLogoSrc, bpLogoSrc) => {
  const [leftLogo, rightLogo] = await Promise.all([
    fetchImageData(pupLogoSrc),
    fetchImageData(bpLogoSrc),
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
                width: { size: 8, type: WidthType.PERCENTAGE },
                borders: noBorder,
                children: [
                  new Paragraph({ alignment: AlignmentType.LEFT, children: leftLogo ? [new ImageRun({ data: leftLogo, transformation: { width: 72, height: 72 } })] : [] }),
                ],
              }),
              new TableCell({
                width: { size: 80, type: WidthType.PERCENTAGE },
                borders: noBorder,
                children: [
                  new Paragraph({ alignment: AlignmentType.LEFT, spacing: { after: 0 }, children: [new TextRun({ text: 'REPUBLIC OF THE PHILIPPINES', size: 16, font: 'Lucida Fax' })] }),
                  new Paragraph({ alignment: AlignmentType.LEFT, spacing: { after: 0 }, children: [new TextRun({ text: 'POLYTECHNIC UNIVERSITY OF THE PHILIPPINES', size: 22, color: '000000', bold: true, font: 'Lucida Fax' })] }),
                  new Paragraph({ alignment: AlignmentType.LEFT, spacing: { after: 0 }, children: [new TextRun({ text: 'OFFICE OF THE VICE PRESIDENT FOR CAMPUSES', size: 15, font: 'Lucida Fax' })] }),
                  new Paragraph({ alignment: AlignmentType.LEFT, spacing: { after: 0 }, children: [new TextRun({ text: 'TAGUIG CAMPUS', size: 22, bold: true, color: '000000', font: 'Lucida Fax' })] }),
                  new Paragraph({ alignment: AlignmentType.LEFT, spacing: { after: 0 }, children: [new TextRun({ text: 'Office of the Campus Registrar', size: 15, italics: true, color: '666666', font: 'Lucida Fax' })] }),
                ],
              }),
              new TableCell({
                width: { size: 12, type: WidthType.PERCENTAGE },
                borders: noBorder,
                children: [new Paragraph({ alignment: AlignmentType.RIGHT, children: rightLogo ? [new ImageRun({ data: rightLogo, transformation: { width: 72, height: 72 } })] : [] })],
              }),
            ],
          }),
        ],
      }),
      new Paragraph({ spacing: { after: 0 } }),
    ],
  });
};

const buildFooter = async () => {
  const footerLogo = await fetchImageData(certificate_footer);

  return new Footer({
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
                borders: noBorder,
                children: [
                  new Paragraph({ children: [ new TextRun({ text: 'General Santos Avenue, Lower Bicutan, Taguig City, Philippines 1632', size: 18, font: 'Lucida Fax' }) ] }),
                  new Paragraph({ children: [ new TextRun({ text: 'Direct Line: (02) 8837 5858 to 60 | Email: taguig@pup.edu.ph', size: 18, font: 'Lucida Fax' }) ] }),
                  new Paragraph({ children: [ new TextRun({ text: 'Website: www.pup.edu.ph | Inquiries: https://bit.ly/PUPSINTA', size: 18, font: 'Lucida Fax' }) ] }),
                  new Paragraph({ spacing: { before: 120 }, children: [ new TextRun({ text: "THE COUNTRY'S 1st POLYTECHNIC", bold: true, size: 18, font: 'Lucida Fax' }) ] }),
                ],
              }),
              new TableCell({
                width: { size: 30, type: WidthType.PERCENTAGE },
                borders: noBorder,
                children: [ new Paragraph({ alignment: AlignmentType.RIGHT, children: [ new ImageRun({ data: new Uint8Array(footerLogo || []), transformation: { width: 240, height: 80 } }) ] }) ],
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
};

export const logbookDocx = async (sectionsOrRows, pupLogoSrc = null, bpLogoSrc = null, historyByRequestId = {}, dateRangeLabel = null, logbookCategoryNameById = null) => {
  const header = await buildHeader(pupLogoSrc, bpLogoSrc);
  const footer = await buildFooter();

  const headerCellBorders = {
    top: { style: BorderStyle.SINGLE, size: 1, color: 'D9D9D9' },
    bottom: { style: BorderStyle.SINGLE, size: 1, color: 'D9D9D9' },
    left: { style: BorderStyle.SINGLE, size: 1, color: 'D9D9D9' },
    right: { style: BorderStyle.SINGLE, size: 1, color: 'D9D9D9' },
  };

  const buildHeaderRowCell = (text, width) => cell(String(text).toUpperCase(), {
    bold: true,
    shading: { fill: '7F0000' },
    color: 'FFFFFF',
    width,
    size: HEADER_TEXT_SIZE,
    align: AlignmentType.CENTER,
    verticalAlign: VerticalAlign.CENTER,
    margins: { top: 50, bottom: 50, left: 110, right: 110 },
    font: 'Lucida Fax',
  });

  // Column widths (percent, must sum to 100)
  // Date Requested | Client Name | Course | Gender | Email | Date Processed | Minutes | Date Claimed
  const colWidths = [12, 14, 13, 8, 16, 12, 12, 13];

  const headerRow = new TableRow({
    tableHeader: true,
    height: { value: 720, rule: 'exact' },
    children: [
      buildHeaderRowCell('Date Requested', colWidths[0]),
      buildHeaderRowCell('Client Name', colWidths[1]),
      buildHeaderRowCell('Course/Year & Section', colWidths[2]),
      buildHeaderRowCell('Gender', colWidths[3]),
      buildHeaderRowCell('Email Address/Contact', colWidths[4]),
      buildHeaderRowCell('Date/Time Processed', colWidths[5]),
      buildHeaderRowCell('No. of Minutes Processed (Business Hours)', colWidths[6]),
      buildHeaderRowCell('Date Claimed', colWidths[7]),
    ],
  });

  const normalizeSections = () => {
    if (Array.isArray(sectionsOrRows) && sectionsOrRows.length > 0 && Array.isArray(sectionsOrRows[0]?.rows)) {
      return sectionsOrRows.map((section) => {
        const rawTitle = section?.title ?? 'All Document';
        const title = String(rawTitle).trim() || 'Unspecified';
        return {
          title,
          rows: Array.isArray(section?.rows) ? section.rows : [],
        };
      });
    }

    return [{ title: 'All Document', rows: Array.isArray(sectionsOrRows) ? sectionsOrRows : [] }];
  };

  const sections = normalizeSections();

  const buildRows = (rows, sectionTitle = '') => {
    if (!Array.isArray(rows) || rows.length === 0) {
      return [new TableRow({
        children: [
          new TableCell({
            columnSpan: colWidths.length,
            borders: {
              top: { style: BorderStyle.SINGLE, size: 1, color: 'D9D9D9' },
              bottom: { style: BorderStyle.SINGLE, size: 1, color: 'D9D9D9' },
              left: { style: BorderStyle.SINGLE, size: 1, color: 'D9D9D9' },
              right: { style: BorderStyle.SINGLE, size: 1, color: 'D9D9D9' },
            },
            margins: { top: 120, bottom: 120, left: 120, right: 120 },
            children: [
              new Paragraph({
                alignment: AlignmentType.CENTER,
                children: [new TextRun({ text: 'No data available for this document and period', italics: true, size: DEFAULT_TEXT_SIZE, font: 'Lucida Fax' })],
              }),
            ],
          }),
        ],
      })];
    }

    return rows.map((row) => {
      const targetItem = findMatchingItem(row, sectionTitle, logbookCategoryNameById);
      return new TableRow({
        children: [
          cell(formatDateLong(row.requested_at) || 'N/A', { width: colWidths[0], align: AlignmentType.CENTER, size: DEFAULT_TEXT_SIZE }),
          cell(getFullName(row), { width: colWidths[1], align: AlignmentType.LEFT, size: DEFAULT_TEXT_SIZE }),
          cell(getCourse(row), { width: colWidths[2], align: AlignmentType.LEFT, size: DEFAULT_TEXT_SIZE }),
          cell(getGender(row), { width: colWidths[3], align: AlignmentType.CENTER, size: DEFAULT_TEXT_SIZE }),
          cell(getEmail(row), { width: colWidths[4], align: AlignmentType.LEFT, size: DEFAULT_TEXT_SIZE }),
          cell(formatDateLong(getProcessedAt(row, historyByRequestId, targetItem), true) || '---', { width: colWidths[5], align: AlignmentType.CENTER, size: DEFAULT_TEXT_SIZE }),
          cell(formatMinutesDuration(getProcessingDuration(row, historyByRequestId, targetItem)), { width: colWidths[6], align: AlignmentType.CENTER, size: DEFAULT_TEXT_SIZE }),
          cell(formatDateLong(getClaimedAt(row, historyByRequestId, targetItem)) || 'Pending', { width: colWidths[7], align: AlignmentType.CENTER, size: DEFAULT_TEXT_SIZE }),
        ],
      });
    });
  };

  const contentChildren = [];

  sections.forEach((section, index) => {
    if (index > 0) {
      contentChildren.push(new Paragraph({ pageBreakBefore: true, spacing: { after: 0 } }));
    }

    // ensure title is always a visible string
    const safeTitle = String(section.title || 'Unspecified').trim() || 'Unspecified';

    // decide whether to show a title for this section; skip umbrella titles
    const lower = safeTitle.toLowerCase();
    // Only suppress the title for the generic "All Document" fallback (single-section flat export).
    // Every named document type gets its own visible section title, even in multi-section exports.
    const isUmbrella = lower === 'all document';

    if (isUmbrella) {
      // push table without the section title
      contentChildren.push(
        new Table({ width: { size: 100, type: WidthType.PERCENTAGE }, rows: [headerRow, ...buildRows(section.rows, section.title)] })
      );
    } else {
      // create a title row that spans all columns to ensure visibility and prevent overlap with headers
      const titleRow = new TableRow({
        children: [
          new TableCell({
            columnSpan: colWidths.length,
            borders: noBorder,
            margins: { top: 120, bottom: 120, left: 120, right: 120 },
            children: [
              new Paragraph({
                alignment: AlignmentType.CENTER,
                spacing: { after: 80, before: 0 },
                children: [new TextRun({ text: `Processing of Application for ${safeTitle}`, ...makeFont(24, { bold: true, font: 'Lucida Fax' }) })],
              }),
            ],
          }),
        ],
      });

      contentChildren.push(
        new Table({ width: { size: 100, type: WidthType.PERCENTAGE }, rows: [titleRow, headerRow, ...buildRows(section.rows, section.title)] })
      );
    }
  });

  const doc = new Document({
    sections: [
      {
        properties: {
          page: {
            size: { orientation: PageOrientation.LANDSCAPE },
            margin: { top: 1080, bottom: 1080, left: 480, right: 480, header: 480, footer: 480 },
          },
        },
        headers: { default: header },
        footers: { default: footer },
        children: contentChildren,
      },
    ],
  });

  const blob = await Packer.toBlob(doc);
  const year = new Date().getFullYear();
  const fileName = dateRangeLabel ? `Logbook_Records_${dateRangeLabel.replace(/\s/g, '_')}` : `Logbook_Records_${year}`;
  saveAs(blob, `${fileName}.docx`);
};

export default logbookDocx;