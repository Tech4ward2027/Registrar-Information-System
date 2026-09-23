import React, { useEffect, useState } from 'react';
import GenerateCertification from '../layouts/GenerateCertificate.jsx';
import { CERT_CONFIG } from '../utils/Certification.jsx';
import LoadingOverlay from './LoadingOverlay.jsx';
import ConfirmationModal from './ConfirmationModal.jsx';
import { createPortal } from 'react-dom';

const CertificateModal = ({ request, onClose, onCertificatePrinted }) => {
  const normalizeCertName = (value) =>
    typeof value === 'string' ? value.replace(/[\r\n]+/g, ' ').replace(/\s+/g, ' ').trim() : '';

  const configNames = Object.values(CERT_CONFIG)
    .map((cfg) => normalizeCertName(cfg?.name))
    .filter(Boolean);

  const requestedNamesRaw = Array.isArray(request?.certificateNames)
    ? request.certificateNames
    : (typeof request?.certificateNames === 'string' ? [request.certificateNames] : []);

  const requestedNames = requestedNamesRaw
    .map((item) => {
      if (typeof item === 'string') return item;
      if (item && typeof item === 'object') return item.certificate_name ?? item.name ?? '';
      return '';
    })
    .map(normalizeCertName)
    .filter(Boolean);

  const matchedConfigs = Object.values(CERT_CONFIG).filter((cfg) => {
    const primaryNorm = normalizeCertName(cfg?.name);
    const otherNorms = (cfg?.otherNames || []).map(normalizeCertName);
    const allNorms = [primaryNorm, ...otherNorms];
    return requestedNames.some((reqName) => allNorms.includes(reqName));
  });

  const certNames = matchedConfigs.map((cfg) => cfg.name);
  const fallbackCertName = configNames[0] ?? '';

  // Name -> request_certificate_id map, built from the real backend rows
  // (request.rawRequest.certificates — see staffDashboardUtils.js's
  // mapDocumentRequest, which keeps the original raw payload alongside the
  // flattened dashboard fields). Lets GenerateCertificate.jsx resolve which
  // specific certificate line item is currently selected in its dropdown
  // and mark just that one as generated on print, instead of the older
  // whole-request fallback. A request with two certificates of the same
  // name (unusual, but not impossible) collapses to one id here — the last
  // one wins — which only means the fallback (mark-all) behavior is used
  // for that edge case rather than a wrong id; see
  // DocumentRequestService::markCertificatesGenerated()'s docblock.
  const certificateIdsByName = (Array.isArray(request?.rawRequest?.certificates)
    ? request.rawRequest.certificates
    : []
  ).reduce((acc, c) => {
    const certName = normalizeCertName(c?.certification_type?.certificate_name);
    if (certName && c?.request_certificate_id != null) {
      acc[certName] = c.request_certificate_id;
    }
    return acc;
  }, {});

  const [visible, setVisible] = useState(false);
  const [opening, setOpening] = useState(true);
  const [editLoading, setEditLoading] = useState(false);
  const [headerHeight, setHeaderHeight] = useState(101);
  const [proceedUnmatched, setProceedUnmatched] = useState(false);

  useEffect(() => {
    const headerElement = document.querySelector('header');
    if (!headerElement) return;

    const resizeObserver = new ResizeObserver((entries) => {
      for (let entry of entries) {
        setHeaderHeight(entry.target.offsetHeight);
      }
    });
    resizeObserver.observe(headerElement);
    return () => resizeObserver.disconnect();
  }, []);

  useEffect(() => {
    const t = setTimeout(() => setVisible(true), 10);
    const openingTimer = setTimeout(() => setOpening(false), 600);
    return () => {
      clearTimeout(t);
      clearTimeout(openingTimer);
    };
  }, []);

  useEffect(() => {
    document.body.style.overflow = 'hidden';
    // Automatically collapse sidebar when Certificate Modal opens
    window.dispatchEvent(new CustomEvent('collapse-sidebar', { detail: true }));
    return () => { document.body.style.overflow = ''; };
  }, []);

  const handleClose = () => {
    setVisible(false);
    setTimeout(onClose, 300);
  };

  const hasMatchingCert = certNames.length > 0;
  const selectedCert = hasMatchingCert ? certNames[0] : (proceedUnmatched ? fallbackCertName : null);

  if (!visible) return null;

  const initialData = {
    requestId: request.id,
    docType: selectedCert,
    certificateNames: hasMatchingCert ? certNames : [],
    fullName: request.studentName ?? '',
    studentNum: request.studentNum?? '',
    course: request.course ?? '',
    syAdmitted: request.syAdmitted ?? '',
    eventTitle: request.eventTitle ?? '',
    dateGraduated: request.graduation_date?? '',
    educationLevel: request.educationLevel ?? '',
    diplomaNum: request.diplomaNum ?? '',
    major: request.major ?? '',
    date: new Date().toISOString().split('T')[0],
    officialReceiptNum: request.or_number ?? '',
    certificateIdsByName,
  };

  return createPortal(
    <div id="cert-modal-root" role="dialog" aria-modal="true" className="fixed inset-0 z-20 mb-2">
      {/* Dim Overlay - Native div with keyboard support */}
      <div
        id="cert-modal-overlay"
        className={`fixed inset-0 bg-black/40 transition-opacity duration-300 ${
          visible ? "opacity-100" : "opacity-0"
        }`}
        onClick={onClose}
        aria-hidden="true"
      />

      {/* Confirmation Modal when no matching certification requested */}
      {!hasMatchingCert && !proceedUnmatched && (
        <ConfirmationModal
          isOpen={true}
          type="confirm"
          title="No Matching Certification Found"
          message="This request does not specify a matched certification template (e.g. Certificate of GWA, Non-Issuance of SO)."
          confirmLabel="Continue"
          onClose={handleClose}
          onConfirm={() => setProceedUnmatched(true)}
        />
      )}

      {/* Slide-in Panel */}
      {(hasMatchingCert || proceedUnmatched) && (
        <div
          id="cert-modal-panel"
          className={`fixed bottom-0 left-0 right-0 lg:left-72 bg-white dark:bg-[#18191a] flex flex-col shadow-2xl transition-all duration-300 ease-in-out ${
            visible ? "translate-x-0" : "translate-x-full"
          }`}
          style={{ zIndex: 9999, top: `${headerHeight}px` }}
        >
          <div id="cert-modal-content" className="flex-1 overflow-auto h-full bg-white dark:bg-[#18191a]">
            <GenerateCertification
              key={selectedCert}
              initialData={initialData}
              onClose={handleClose}
              onCertificatePrinted={onCertificatePrinted}
              onLoadingChange={setEditLoading}
            />
          </div>
        </div>
      )}

      <LoadingOverlay
        isVisible={opening || editLoading}
        message="Loading Certificate..." 
      />
    </div>,
    document.body
  );
};

export default CertificateModal;