import React, { useState, useRef } from 'react';
import { QRCodeSVG } from 'qrcode.react';
import { ClipboardDocumentIcon, CheckIcon, ArrowDownTrayIcon } from '@heroicons/react/24/outline';
import { toPng } from 'html-to-image';

/**
 * ClaimTicket — the student-facing "claim ticket" for a document request.
 *
 * Renders the QR code (encodes the request's uuid — scanned by staff at
 * the Registrar counter) together with the short claim_code as plain
 * text underneath it, per QR Code Claiming Policy v1.0 §3.2/3.7.
 *
 * Always rendered in standard white printable format (white background,
 * maroon header, dark text, clean QR code) regardless of the application's
 * active theme (Dark Mode / Light Mode) so that viewing on screen, browser
 * printing, and image exports are clear, uniform, and easy to print.
 */
const ClaimTicket = ({ uuid, claimCode, size = 144, downloadOnly = false, small = false }) => {
  const [copied, setCopied] = useState(false);
  const contentRef = useRef(null);

  if (!uuid || !claimCode) {
    return null;
  }

  const handleCopyCode = async () => {
    try {
      await navigator.clipboard.writeText(claimCode);
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    } catch {
      // Clipboard API fallback
    }
  };

  const handleDownloadPNG = () => {
    if (!contentRef.current) return;

    toPng(contentRef.current, {
      backgroundColor: '#ffffff',
      cacheBust: true,
      pixelRatio: 2,
      style: {
        borderRadius: '16px',
      },
      filter: (node) => {
        return !(node.classList && node.classList.contains('download-btn-hide'));
      }
    })
      .then((dataUrl) => {
        const link = document.createElement('a');
        link.download = `claim-ticket-${claimCode}.png`;
        link.href = dataUrl;
        link.click();
      })
      .catch((err) => {
        console.error('Failed to export Claim Ticket to PNG', err);
      });
  };

  const qrSize = small ? 108 : (size || 144);

  return (
    <div className="flex flex-col items-center gap-3 w-full max-w-full">
      {/* Printable/Downloadable Ticket Wrapper — Always Standard Clean White Format */}
      <div
        ref={contentRef}
        className={`${
          downloadOnly 
            ? 'absolute -left-2499.75 -top-2499.75' 
            : `w-full flex flex-col sm:flex-row items-center sm:items-stretch transition-all duration-300 relative overflow-hidden ${
                small 
                  ? 'gap-3 sm:gap-4 p-3.5 sm:p-5 rounded-xl border max-w-105' 
                  : 'gap-4 sm:gap-6 p-4 sm:p-6 rounded-2xl border max-w-130'
              }`
        } bg-white text-gray-900 border-gray-200 shadow-[0_8px_30px_rgb(0,0,0,0.06)]`}
      >
        {/* Decorative top gold/maroon accent bar */}
        <div className="absolute top-0 left-0 right-0 h-1.5 bg-linear-to-r from-[#800000] via-[#FFC72C] to-[#800000]" />

        {/* Left Column (or Top section on mobile): Details & Code */}
        <div className="flex-1 flex flex-col justify-between gap-3 sm:gap-4 w-full min-w-0">
          <div className="flex flex-col gap-1.5 sm:gap-2 mt-0.5 sm:mt-1">
            <div className="flex flex-col gap-1 text-center sm:text-left">
              <h4
                className={`font-extrabold uppercase tracking-[0.15em] sm:tracking-[0.2em] text-[#800000] ${
                  small ? 'text-xs sm:text-sm' : 'text-xs sm:text-sm md:text-base'
                }`}
              >
                Registrar Claim Ticket
              </h4>
            </div>
            <p className={`leading-relaxed text-center sm:text-left text-gray-600 ${small ? 'text-[10px] sm:text-[11px]' : 'text-[10.5px] sm:text-xs'}`}>
              Present this QR code at the Registrar counter to instantly retrieve and claim your documents.
            </p>
          </div>

          <div className="flex flex-col gap-1 sm:gap-1.5 w-full">
            <span className={`font-bold uppercase tracking-wider text-center sm:text-left text-gray-500 ${small ? 'text-[9px] sm:text-[10px]' : 'text-[9.5px] sm:text-xs'}`}>
              Manual Claim Code:
            </span>
            <button
              type="button"
              onClick={handleCopyCode}
              title="Copy claim code"
              className={`relative group flex items-center justify-center w-full rounded-xl font-mono font-bold tracking-[0.15em] sm:tracking-[0.25em] border transition-all active:scale-98 cursor-pointer bg-gray-50 text-gray-900 border-gray-200 hover:bg-gray-100 hover:border-gray-300 ${
                small ? 'py-2 px-8 text-xs sm:text-sm md:text-base' : 'py-2.5 px-10 text-xs sm:text-base md:text-lg'
              }`}
            >
              <span className="truncate text-center">{claimCode}</span>
              <span className="absolute right-2.5 sm:right-3.5 top-1/2 -translate-y-1/2 text-gray-400 group-hover:text-gray-600 transition-colors shrink-0 print:hidden">
                {copied ? (
                  <CheckIcon className="w-3.5 h-3.5 sm:w-4 sm:h-4 text-green-500" />
                ) : (
                  <ClipboardDocumentIcon className="w-3.5 h-3.5 sm:w-4 sm:h-4" />
                )}
              </span>
            </button>
          </div>
        </div>

        {/* Responsive Divider: horizontal on mobile, vertical on sm+ */}
        <div className="w-full sm:w-auto self-stretch border-t sm:border-t-0 sm:border-l border-dashed border-gray-200 my-1 sm:my-0 shrink-0" />

        {/* Right Column (or Bottom section on mobile): QR Code Container & Download Action */}
        <div className="flex flex-col items-center justify-center gap-2 sm:gap-2.5 shrink-0 w-full sm:w-auto">
          <div className={`rounded-xl bg-white border border-gray-200 ${small ? 'p-1.5 sm:p-2' : 'p-2 sm:p-3'}`}>
            <div className={`border border-dashed border-gray-250 rounded-lg flex items-center justify-center ${small ? 'p-1' : 'p-1 sm:p-1.5'}`}>
              <QRCodeSVG id={`qr-code-${claimCode}`} value={uuid} size={qrSize} level="M" fgColor="#000000" bgColor="#ffffff" />
            </div>
          </div>

          <button
            type="button"
            onClick={handleDownloadPNG}
            title="Download ticket as image"
            className="download-btn-hide flex items-center justify-center gap-1.5 w-full sm:w-auto px-3 py-1.5 rounded-lg border font-bold text-[8px] sm:text-[9px] uppercase tracking-wider transition-all active:scale-95 cursor-pointer mt-0.5 sm:mt-1 print:hidden bg-gray-50 border-gray-200 text-gray-700 hover:bg-gray-100 hover:text-gray-900"
          >
            <ArrowDownTrayIcon className="w-3 h-3 sm:w-3.5 sm:h-3.5" />
            Download Ticket
          </button>
        </div>
      </div>
    </div>
  );
};

export default ClaimTicket;
