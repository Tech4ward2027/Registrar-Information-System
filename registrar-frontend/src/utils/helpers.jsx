import puplogoimage from "../assets/puplogoimage.png";
import certificate_footer from "../assets/certificate_footer.png";
import { formatDateFormal, formatDateOrdinal } from "./formatters.js";
import { CURRENT_YEAR } from "./formatters.js";
import { useState, useEffect } from "react";
import { useReferenceData } from "../context/ReferenceDataContext";

export const TextBlock = ({ children, className = "" }) => (
  <div
    className={`mb-4 ${className} cert-editable-block`}
    contentEditable
    suppressContentEditableWarning
    spellCheck
  >
    {children}
  </div>
);
export const bold = (text) => <strong>{text}</strong>;

export const fillOrLine = (value, lineLength = 10) => {
  if (value && String(value).trim()) {
    return <strong>{value}</strong>;
  }
  return <span className="border-b border-gray-800" style={{ display: 'inline-block', minWidth: `${lineLength * 0.15}in` }}>&nbsp;</span>;
};

export const CertificateTitle = ({ title }) => (
  <div className="text-center mb-6 print:mb-7">
    <TextBlock className="cert-title font-lucida text-[12px] sm:text-[20px] md:text-[25px] font-bold uppercase tracking-normal leading-tight text-black">
      {title}
    </TextBlock>
  </div>
);

export const SignatureBlock = ({ name, position, className = "", salutation = "" }) => (
  <div className={className || "mb-4 mt-12 sm:mt-16 print:mt-20 flex justify-end"}>
    <TextBlock className="inline-block text-center mb-0">
      {salutation && (
        <p className="font-lucida text-xs sm:text-sm print:text-[10pt] text-gray-900 mb-6">
          {salutation}
        </p>
      )}
      <p className="font-bold px-10 text-xs sm:text-sm uppercase font-lucida text-gray-900 print:text-[10pt]">
        {name}
      </p>
      <p className="text-[8px] lg:text-[10px] print:text-[8pt] font-lucida">
        {position}
      </p>
    </TextBlock>
  </div>
);

// Fallback used only if the signatories API is completely unreachable
// (e.g. network failure) AND the reference data hasn't loaded yet. A
// certificate's signature block must never silently render blank, since
// these are printed legal documents — this keeps the pre-DB-migration
// default names as a last resort so generation never hard-fails.
const SAFE_FALLBACK = {
  registrar: { name: "Mhel P. Garcia", position: "Campus Registrar/Head of Registration Office" },
  director:  { name: "Marissa B. Ferrer, DEM, RPsy", position: "Director" },
};

/**
 * Resolve { name, position } for a signature block.
 *
 * @param signee       The exact name string selected in the "Signee"
 *                      dropdown (GenerateCertificate.jsx) — this is always
 *                      one of the fetched `signatories[].name` values, so
 *                      an exact match is sufficient (no more substring
 *                      guessing against a hardcoded name list).
 * @param signatories   The array from useReferenceData(), already ordered
 *                      by sort_order server-side.
 * @param fallbackIndex Which signatories[] entry to fall back to when
 *                      `signee` doesn't match anything (empty selection,
 *                      or the selected signatory was since deleted).
 *                      RegistrarSignature passes 0, DirectorSignature
 *                      passes 1 — preserving the seeded convention that
 *                      sort_order 0 is the registrar-type signatory and
 *                      sort_order 1 is the director-type one. If your
 *                      org's data no longer matches that convention,
 *                      pass whichever index makes sense for the block.
 */
export const getSigneeInfo = (signee, signatories = [], fallbackIndex = 0) => {
  const match = signatories.find((s) => s.name === signee);
  if (match) return { name: match.name, position: match.position };

  const fallback = signatories[fallbackIndex];
  if (fallback) return { name: fallback.name, position: fallback.position };

  return fallbackIndex === 0 ? SAFE_FALLBACK.registrar : SAFE_FALLBACK.director;
};

export const FooterInfo = ({ diplomaNum, date }) => (
  <TextBlock className="mt-12 sm:mt-16 print:mt-20 pt-2 font-lucida space-y-0.5">
    <p className="text-[7px] sm:text-[9px] print:text-[8pt] font-normal leading-tight text-black mb-1.5">
      Not Valid Without<br />
      University Dry Seal
    </p>
    <p className="text-[9px] sm:text-[11px] print:text-[9pt] font-bold text-black">
      Diploma No.: {" "}
      <span className="text-[#e53935] font-bold">
        {diplomaNum || "________________"}
      </span>
    </p>
    <p className="text-[9px] sm:text-[11px] print:text-[9pt] font-bold text-black mb-1.5">
      Date: <span className="font-bold text-black">{date}</span>
    </p>
    <p className="text-[7px] sm:text-[9px] print:text-[8pt] text-black font-normal mt-1.5">/shgsese{CURRENT_YEAR}</p>
  </TextBlock>
);

export const ReceiptInfo = ({ officialReceiptNum, date, className = "" }) => (
  <TextBlock className={className || "mt-12 sm:mt-16 print:mt-20 pt-2 font-lucida space-y-0.5"}>
    <p className="text-[7px] sm:text-[9px] print:text-[8pt] font-normal leading-tight text-black mb-1.5">
      Not Valid Without<br />
      University Dry Seal
    </p>
    <p className="text-[9px] sm:text-[11px] print:text-[9pt] font-bold text-black">
      O.R. No.: {" "}
      <span className="text-[#e53935] font-bold">
        {officialReceiptNum || "________________"}
      </span>
    </p>
    <p className="text-[9px] sm:text-[11px] print:text-[9pt] font-bold text-black mb-1.5">
      Date: <span className="font-bold text-black">{date}</span>
    </p>
    <p className="text-[7px] sm:text-[9px] print:text-[8pt] text-black font-normal mt-1.5">/shgsese{CURRENT_YEAR}</p>
  </TextBlock>
);

export const CertHeader = ({ layout }) => {
  const logoSize = layout?.headerLogoSize ?? 56;
  const baseFontSize = layout?.headerFontSize ?? 14;
  const headerLines = Array.isArray(layout?.headerLines) && layout.headerLines.length > 0
    ? layout.headerLines
    : [
        "REPUBLIC OF THE PHILIPPINES",
        "Polytechnic University of the Philippines",
        "OFFICE OF THE VICE PRESIDENT FOR CAMPUSES",
        "TAGUIG CAMPUS",
      ];

  return (
    <div className="cert-header border-b-2 border-gray-200 pb-4 mb-5">
      <div className="flex flex-wrap sm:flex-nowrap items-center justify-between gap-3 sm:gap-4">
        <div className="flex items-center gap-3 sm:gap-4 min-w-0">
          <img
            src={layout?.headerLeftUrl || puplogoimage}
            alt="Main Logo"
            className="object-contain shrink-0 transition-all"
            style={{ width: `${logoSize}px`, height: `${logoSize}px` }}
          />
          <div className="cert-header-text pt-0.5 leading-tight text-black uppercase font-lucida min-w-0">
            {headerLines.map((line, idx) => {
              const isMain = idx % 2 !== 0; // lines 1, 3, etc. are main lines (bold)
              const fontSize = isMain ? baseFontSize : Math.max(7, Math.round(baseFontSize * 0.68));
              return (
                <p
                  key={`header-line-${idx}`}
                  className={`tracking-tight truncate ${isMain ? "font-bold" : "font-normal"}`}
                  style={{ fontSize: `${fontSize}px`, lineHeight: "1.2" }}
                >
                  {line}
                </p>
              );
            })}
          </div>
        </div>
        {layout?.headerRightUrl && (
          <img
            src={layout.headerRightUrl}
            alt="Header Right Logo"
            className="object-contain shrink-0 transition-all ml-auto sm:ml-0"
            style={{ width: `${logoSize}px`, height: `${logoSize}px` }}
          />
        )}
      </div>
    </div>
  );
};

export const RegistrarDateTitle = ({ date }) => (
  <>
    <TextBlock className="font-lucida text-left cert-meta-line -mt-1 print:mt-0">
      Office of the Campus Registrar
    </TextBlock>

    <TextBlock className="font-lucida text-right cert-meta-line mt-6 print:mt-7">
      {formatDateFormal(date)}
    </TextBlock>
  </>
);

export const CertFooter = ({ layout }) => (
  <div className="mt-auto pt-2 border-t border-gray-200 flex flex-col sm:flex-row justify-between items-start sm:items-start print:items-start gap-4 shrink-0">
    <div className="space-y-1 text-left cert-footer-contact pt-0.5">
      <div className="text-[9px] sm:text-[10px] leading-tight">
        General Santos Avenue, Lower Bicutan, Taguig City, Philippines 1632<br />
        Direct Line: (02) 8837 5858 to 60 | Email: taguig@pup.edu.ph<br />
        Website: www.pup.edu.ph | Inquiries: https://bit.ly/PUPSINTA
      </div>
      <div className="cert-footer-tagline text-[10px] sm:text-[15px] font-bold tracking-tight leading-tight uppercase text-black font-lucida">
        THE COUNTRY'S 1st POLYTECHNIC
      </div>
    </div>
    <div className="shrink-0 items-start flex flex-wrap justify-end gap-2 sm:pt-0.5 print:pt-0 print:self-start">
      {(layout?.footerUrls?.length ? layout.footerUrls : [certificate_footer]).map((footerLogoUrl, index) => (
        <img
          key={`${footerLogoUrl}-${index}`}
          src={footerLogoUrl}
          alt={`Certificate Footer ${index + 1}`}
          className="footer-accreditation-img w-auto object-contain"
          style={{ height: `${layout?.footerLogoSize ?? 45}px` }}
        />
      ))}
    </div>
  </div>
);

export const RegistrarSignature = ({ signee, className = "", salutation = "" }) => {
  const { signatories } = useReferenceData();
  const signer = getSigneeInfo(signee, signatories, 0);
  return (
  <SignatureBlock
    name={signer.name}
    position={signer.position}
    className={className}
    salutation={salutation}
  />
  );
};

export const DirectorSignature = ({ signee, className = "", salutation = "" }) => {
  const { signatories } = useReferenceData();
  const signer = getSigneeInfo(signee, signatories, 1);
  return (
  <SignatureBlock
    name={signer.name}
    position={signer.position}
    className={className}
    salutation={salutation}
  />
  );
};

export const StandardCertLayout = ({ date, children }) => (
  <>
    <RegistrarDateTitle date={date} />
    <CertificateTitle title="C E R T I F I C A T I O N" />
    <div className="cert-body px-2 sm:px-4 text-[13px] sm:text-[14px] print:text-[11pt] leading-relaxed">
      <TextBlock className="mb-5 cert-salutation font-lucida text-[13px] sm:text-[14px] print:text-[11pt]">To Whom It May Concern:</TextBlock>
      {children}
    </div>
  </>
);

export const CertParagraph = ({ children, className = "" }) => (
  <p
    className={`cert-paragraph indent-8 font-lucida text-[13px] sm:text-[14px] print:text-[11pt] leading-relaxed ${className} cert-editable-block`}
    contentEditable
    suppressContentEditableWarning
    spellCheck
  >
    {children}
  </p>
);

export const IssuedLine = ({ date }) => (
  <CertParagraph className="mb-10">
    This certification is issued this {formatDateOrdinal(date)} upon request 
    the aforementioned name for whatever legal purpose it may serve.
  </CertParagraph>
);

export const IssuedLineAforementioned = ({ date }) => (
  <CertParagraph className="mb-10">
    This certification is issued this {formatDateOrdinal(date)} upon request of the aforementioned
    name for whatever legal purpose it may serve.
  </CertParagraph>
);

export const PupLetterhead = ({ date, layout }) => (
  <>
    <div className="flex items-center gap-3 mb-2">
      <img src={layout?.headerLeftUrl || puplogoimage} alt="PUP Logo" className="w-10 h-10 object-contain print:w-12 print:h-12" />      
      <div className="text-[10px] leading-tight">
        <p>Republic of the Philippines</p>
        <p className="font-bold uppercase text-[11px]">Polytechnic University of the Philippines</p>
        <p className="uppercase">Taguig Campus</p>
      </div>
    </div>
    {date && (
      <div className="mb-4 text-[11px]">
        <p>{formatDateFormal(date)}</p>
      </div>
    )}
  </>
);

export const EndorsementNoteBlock = ({ items, className = "", twoCol = false }) => (
  <TextBlock className={`text-[11px] sm:text-[12px] mb-2 ${className}`}>
    <p className="mb-1">Note: The following documents are attached:</p>
    <ol className={`list-decimal list-inside ml-6 space-y-1 ${twoCol ? "grid grid-cols-2 gap-x-4 gap-y-0.5 space-y-0" : ""}`}>
      {items.map((item, i) => (
        <li key={i} className="text-left">{item}</li>
      ))}
    </ol>
  </TextBlock>
);

export const getTodayDate = () => {
  const today = new Date();
  const localDate = new Date(
    today.getTime() - today.getTimezoneOffset() * 60000
  );
  return localDate.toISOString().split("T")[0];
};

export const formatLabel = (str) => {
  if (!str || str === "All") return str;
  return str
    .split("_")
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(" ");
};

export const useHeaderResponsiveState = (enabled = true) => {
  const [headerHeight, setHeaderHeight] = useState(101);
  const [isMobile, setIsMobile] = useState(window.innerWidth < 1024);

  useEffect(() => {
    if (!enabled) return;

    const handleResize = () => {
      setIsMobile(window.innerWidth < 1024);
    };
    window.addEventListener("resize", handleResize);

    const headerElement = document.querySelector("header");
    if (!headerElement) return () => window.removeEventListener("resize", handleResize);

    const resizeObserver = new ResizeObserver((entries) => {
      for (let entry of entries) {
        setHeaderHeight(entry.target.offsetHeight);
      }
    });
    resizeObserver.observe(headerElement);

    return () => {
      window.removeEventListener("resize", handleResize);
      resizeObserver.disconnect();
    };
  }, [enabled]);

  return { headerHeight, isMobile };
};