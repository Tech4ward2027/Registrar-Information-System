import React, { useState, useEffect, useMemo, useRef } from "react";
import { useNavigate } from "react-router-dom";
import { createDocumentRequest, verifyOfficialReceipt } from "../services/api";
import InputGroup from "../components/InputGroup.jsx";
import DropdownGroup from "../components/DropDown.jsx";
import MultiSelectDropdown from "../components/MultiSelection.jsx";
import LoadingOverlay from "../components/LoadingOverlay.jsx";
import ErrorToast from "../components/ErrorToast.jsx";
import StepProgress from "../components/StepProgress.jsx";
import TermsAndConditionsStep from "../components/TermsAndConditionsStep.jsx";
import OrValidationErrorModal from "../components/OrValidationErrorModal.jsx";
import { getTodayDate, useHeaderResponsiveState } from "../utils/helpers";
import qrCode from "../assets/qrcode.png";
import SubmitConfirmationModal from '../components/SubmitConfirmationModal.jsx';
import ClaimTicket from '../components/ClaimTicket.jsx';
import OfficeHoursNotice from '../components/OfficeHoursNotice.jsx';
import { useFormDraft } from '../hooks/useFormDraft';
import { useScrollToTop } from '../hooks/useScrollToTop';
import { useTheme } from '../context/ThemeContext';
import { useReferenceData } from '../context/ReferenceDataContext';
import { useMutation } from '@tanstack/react-query';
import {
  InformationCircleIcon,
  ExclamationTriangleIcon,
  ArrowLeftIcon,
  ArrowRightIcon,
  CheckIcon
} from "@heroicons/react/24/outline";
import { STUDENT_ACCESS_IDS } from "../constants/accessTypes";

// parseRequirements is pure — no hooks needed here
const parseRequirements = (value) => {
  if (Array.isArray(value)) {
    return value.map((item) => String(item).trim()).filter(Boolean);
  }
  if (typeof value === 'string') {
    return value
      .split('\n')
      .map((item) => item.trim().replace(/,$/, ''))
      .filter(Boolean);
  }
  return [];
};

const wizardSteps = [
  { id: 1, label: "Terms" },
  { id: 2, label: "Receipt" },
  { id: 3, label: "Documents" },
  { id: 4, label: "Review" },
];

const stepTitles = {
  1: { title: "Terms & Conditions", subtitle: "Please review and accept our data privacy and release guidelines." },
  2: { title: "Official Receipt", subtitle: "Verify your receipt from the Cashier's Office." },
  3: { title: "Select documents", subtitle: "Choose what you're requesting based on your receipt." },
  4: { title: "Review & Copies", subtitle: "Specify number of copies and check required documents." },
};

const finalStep = 4;
const orStep = 2;
const docStep = 3;

const RequestForm = () => {
  const { isDark } = useTheme();
  const { headerHeight } = useHeaderResponsiveState();
  const navigate = useNavigate();
  const {
    documentTypes,
    certifications,
    purposes,
    docTypeName,
    purposeName,
    certName,
    refreshDocumentTypes,
    refreshCertifications
  } = useReferenceData();

  const [currentStep, setCurrentStep] = useState(1);
  const [isSubmitted, setIsSubmitted] = useState(false);
  const { targetRef: formRef } = useScrollToTop([currentStep, isSubmitted]);
  const [errorMessage, setErrorMessage] = useState("");
  const [showConfirmModal, setShowConfirmModal] = useState(false);
  const [showOrModal, setShowOrModal] = useState(false);
  const [orModalMessage, setOrModalMessage] = useState("");
  // Populated from the create response on success — holds just the two
  // fields ClaimTicket needs. Not the whole DocumentRequest object: this
  // screen has nothing else to do with the rest of it, and keeping only
  // what's displayed avoids this state going stale/wrong if the request
  // is later updated elsewhere while this tab is still open.
  const [claimTicket, setClaimTicket] = useState(null);

  // OR-first wizard: populated once verifyOfficialReceipt() succeeds.
  // unresolvedItems holds receipt lines the suggester couldn't match to
  // any document/certificate type (see CashierDocumentSuggester) —
  // surfaced so they're never silently dropped. autoFilledNames tags
  // which currently-selected documents/certifications came from the
  // suggestion (vs. the student picking them manually) purely for the
  // "Auto-filled from OR #..." badge; it does not gate anything
  // server-side — final submit re-verifies from scratch regardless of
  // how an item got onto the form.
  const [unresolvedItems, setUnresolvedItems] = useState([]);
  const [autoFilledNames, setAutoFilledNames] = useState([]);
  const [suggestedDocIds, setSuggestedDocIds] = useState({});
  const [suggestedCertIds, setSuggestedCertIds] = useState({});

  // documentTypes comes exclusively from GET /document-types (the
  // document_type table) — every row here is already a genuine document
  // by construction. A previous version additionally excluded any row
  // whose name started with "certif", on the assumption that "Certificate"
  // in the name meant it truly belonged in the certifications API instead.
  // That assumption broke once the Cashier's Office master document list
  // added real, Type=Document rows named "Certified True Copy - X" (CTC)
  // and "Certification Fee - X" (Completion Fee correction) — those are
  // legitimate documents, not certificates, and the name-based guess was
  // silently hiding all of them from the selectable list, the requirements
  // lookup, and the final submit's ID resolution. Access control alone
  // (STUDENT_ACCESS_IDS) is the correct and sufficient filter here.
  const availableDocs = useMemo(() => {
    return documentTypes
      .filter(doc => STUDENT_ACCESS_IDS.includes(Number(doc.access_id)));
  }, [documentTypes]);

  const availableCertifications = useMemo(() => {
    return certifications.filter(cert => STUDENT_ACCESS_IDS.includes(Number(cert.access_id)));
  }, [certifications]);

  const availablePurposes = purposes;

  const [formData, setFormData] = useState({
    termsAgreed: false,
    documentsRequested: [],
    purposeOfRequest: "",
    certification: [],
    receiptNumber: "",
    dateOfPayment: getTodayDate(),
    documentCopies: {},
    certCopies: {},
  });

  const { clearDraft } = useFormDraft({
    storageKey: 'student_request_draft',
    formData,
    setFormData,
    currentStep,
    setCurrentStep,
    isSubmitted,
  });

  const handleInputChange = (e) => {
    const { name, value } = e.target;
    setFormData((prev) => ({ ...prev, [name]: value }));
  };

  const handleCombinedItemsChange = (e) => {
    const selectedList = e.target.value || [];

    const newDocs = selectedList.filter((name) =>
      documentOptions.includes(name) ||
      availableDocs.some((d) => d.document_name === name) ||
      documentTypes.some((d) => d.document_name === name)
    );
    const newCerts = selectedList.filter((name) =>
      certificationOptions.includes(name) ||
      availableCertifications.some((c) => c.certificate_name === name) ||
      certifications.some((c) => c.certificate_name === name)
    );

    setFormData((prev) => {
      const newDocCopies = { ...prev.documentCopies };
      newDocs.forEach((doc) => {
        if (!newDocCopies[doc]) newDocCopies[doc] = 1;
      });

      const newCertCopies = { ...prev.certCopies };
      newCerts.forEach((cert) => {
        if (!newCertCopies[cert]) newCertCopies[cert] = 1;
      });

      return {
        ...prev,
        documentsRequested: newDocs,
        certification: newCerts,
        documentCopies: newDocCopies,
        certCopies: newCertCopies,
      };
    });
  };

  const handlePreSubmit = (e) => {
    e.preventDefault();

    // OR Number / Date of Payment are validated and verified against the
    // cashier API earlier now (see handleVerifyOr, triggered when leaving
    // the OR-verification step) — this final step only has copies left
    // to check before confirming.
    // Note: formData.documentsRequested only ever contains document_type
    // names — never certificate_type names. It's populated exclusively by
    // (a) CashierDocumentSuggester's `documents` array on OR verification,
    // and (b) handleCombinedItemsChange's structural membership check
    // against availableDocs/documentOptions when the student edits the
    // selection manually. Neither path can put a certificate name in here.
    // A previous version filtered this array by `!name.includes("certif")`
    // to guess which entries were "really" certificates — that heuristic
    // broke the moment a legitimate DOCUMENT type was named with the word
    // "Certificate" in it (e.g. the "Certified True Copy - X" family,
    // which the Cashier's master list explicitly classifies as
    // Type=Document, not Certificate) — those items would silently vanish
    // from copy validation, the requirements panel, and the final submit
    // payload. Trust the array's actual contents instead of re-guessing
    // from the string.
    const hasInvalidDocCopy = (formData.documentsRequested || [])
      .some((doc) => {
        const copies = Number(formData.documentCopies?.[doc] || 1);
        return !Number.isInteger(copies) || copies < 1 || copies > 10;
      });

    const hasInvalidCertCopy = (formData.certification || []).some((cert) => {
      const copies = Number(formData.certCopies?.[cert] || 1);
      return !Number.isInteger(copies) || copies < 1 || copies > 10;
    });

    if (hasInvalidDocCopy || hasInvalidCertCopy) {
      setErrorMessage("Number of copies must be between 1 and 10.");
      return;
    }

    setShowConfirmModal(true);
  };

  const handleCertCopyChange = (certName, value) => {
    setFormData((prev) => ({
      ...prev,
      certCopies: {
        ...prev.certCopies,
        [certName]: value,
      },
    }));
  };

  const handleDocCopyChange = (docName, value) => {
    setFormData((prev) => ({
      ...prev,
      documentCopies: {
        ...prev.documentCopies,
        [docName]: value
      }
    }));
  };

  const handleCheckboxChange = (e) => {
    const { name, checked } = e.target;
    setFormData((prev) => ({ ...prev, [name]: checked }));
  };

  const verifyOrMutation = useMutation({
    mutationFn: verifyOfficialReceipt,
    onSuccess: (response) => {
      const suggestions = response?.data?.suggestions ?? { documents: [], certificates: [], unresolved: [] };

      const suggestedDocNames = [];
      const suggestedCertNames = [];
      const newDocCopies = {};
      const newCertCopies = {};
      const docIdMap = {};
      const certIdMap = {};

      (suggestions.documents || []).forEach((doc) => {
        const name = doc.document_name;
        if (!name) return;
        suggestedDocNames.push(name);
        newDocCopies[name] = doc.number_of_copies || 1;
        if (doc.document_type_id) {
          docIdMap[name] = doc.document_type_id;
        }
      });

      (suggestions.certificates || []).forEach((cert) => {
        const name = cert.certificate_name;
        if (!name) return;
        suggestedCertNames.push(name);
        newCertCopies[name] = cert.number_of_copies || 1;
        if (cert.certificate_type_id) {
          certIdMap[name] = cert.certificate_type_id;
        }
      });

      setSuggestedDocIds(docIdMap);
      setSuggestedCertIds(certIdMap);

      setFormData((prev) => ({
        ...prev,
        documentsRequested: suggestedDocNames,
        certification: suggestedCertNames,
        documentCopies: newDocCopies,
        certCopies: newCertCopies,
      }));

      setAutoFilledNames([...suggestedDocNames, ...suggestedCertNames]);
      setUnresolvedItems(suggestions.unresolved || []);
      setErrorMessage("");
      setCurrentStep((s) => s + 1);
    },
    onError: (error) => {
      console.error("OR verification error:", error.response?.data || error);
      const msg =
        error.response?.data?.message
        || "The Cashier's Office couldn't match this receipt to your record. This usually happens when the name on the receipt doesn't exactly match your name in the system.";
      setOrModalMessage(msg);
      setShowOrModal(true);
    },
  });

  const handleVerifyOr = async () => {
    if (!(formData.receiptNumber || '').trim()) {
      setErrorMessage("Please enter the Official Receipt Number.");
      return;
    }

    if (!/^\d{7}$/.test((formData.receiptNumber || '').trim())) {
      setErrorMessage("Official Receipt Number must be exactly 7 digits.");
      return;
    }

    if (!formData.dateOfPayment) {
      setErrorMessage("Please select the date of payment.");
      return;
    }

    if (formData.dateOfPayment > getTodayDate()) {
      setErrorMessage("Date of payment cannot be in the future.");
      return;
    }

    setErrorMessage("");

    await Promise.all([refreshDocumentTypes(), refreshCertifications()]);

    verifyOrMutation.mutate({
      or_number: formData.receiptNumber.trim(),
      receipt_date: formData.dateOfPayment,
    });
  };

  const nextStep = (e) => {
    e.preventDefault();

    if (currentStep === 1 && !formData.termsAgreed) {
      setErrorMessage("You must read and agree to the Terms & Conditions to proceed.");
      return;
    }

    // OR-verification step: don't just advance — verify against the
    // cashier API first (see handleVerifyOr). Advancing on success/failure
    // is handled entirely inside that function via the mutation's
    // callbacks, so we return here rather than falling through to the
    // plain setCurrentStep increment below.
    if (currentStep === orStep) {
      handleVerifyOr();
      return;
    }

    if (currentStep === docStep) {
      if (
        (!formData.documentsRequested || formData.documentsRequested.length === 0) &&
        (!formData.certification || formData.certification.length === 0)
      ) {
        setErrorMessage("Please select at least one document or certification to proceed.");
        return;
      }

      if (!formData.purposeOfRequest || formData.purposeOfRequest.length === 0) {
        setErrorMessage("Please select a purpose for your request.");
        return;
      }
    }

    if (currentStep < finalStep) setCurrentStep((s) => s + 1);
  };

  const prevStep = (e) => {
    e.preventDefault();
    if (currentStep > 1) setCurrentStep((s) => s - 1);
  };

  const mutation = useMutation({
    mutationFn: createDocumentRequest,
    onSuccess: (response) => {
      // response.data is the full created DocumentRequest (see
      // DocumentRequestController::store) — uuid/claim_code are always
      // present since DocumentRequest::booted() generates both for
      // every new row, and neither is in $hidden.
      setClaimTicket({
        uuid: response?.data?.uuid ?? null,
        claimCode: response?.data?.claim_code ?? null,
      });
      clearDraft();
      setIsSubmitted(true);
    },
    onError: (error) => {
      console.error("Submission error:", error.response?.data || error);
      setErrorMessage(error.response?.data?.message || "Submission failed. Please check your data.");
    }
  });

  const handleSubmit = (e) => {
    if (e && e.preventDefault) e.preventDefault();

    const selectedPurpose = availablePurposes.find(
      p => p.purpose_name === formData.purposeOfRequest || Number(p.request_purpose_id) === Number(formData.purposeOfRequest)
    ) ?? purposes.find(
      p => p.purpose_name === formData.purposeOfRequest || Number(p.request_purpose_id) === Number(formData.purposeOfRequest)
    );

    const purposeId = selectedPurpose?.request_purpose_id;

    const certificates = (formData.certification || [])
      .map(name => {
        const certObj = availableCertifications.find(c => c.certificate_name === name)
          ?? certifications.find(c => c.certificate_name === name);
        const id = suggestedCertIds[name] ?? certObj?.certificate_type_id;

        return {
          certificate_type_id: id,
          number_of_copies: parseInt(formData.certCopies[name]) || 1,
        };
      })
      .filter(c => c.certificate_type_id);

    const documents = (formData.documentsRequested || [])
      .map(name => {
        const docObj = availableDocs.find(d => d.document_name === name)
          ?? documentTypes.find(d => d.document_name === name);
        const id = suggestedDocIds[name] ?? docObj?.document_type_id;

        return {
          document_type_id: id,
          number_of_copies: parseInt(formData.documentCopies[name]) || 1,
        };
      })
      .filter(doc => doc.document_type_id);

    const payload = {
      request_purpose_id: purposeId,
      or_number: formData.receiptNumber,
      receipt_date: formData.dateOfPayment,
      documents: documents,
      certificates: certificates,
    };

    mutation.mutate(payload);
  };

  const isLoading = mutation.isPending;
  const isVerifyingOr = verifyOrMutation.isPending;

  const handleConfirm = () => {
    clearDraft();
    setIsSubmitted(false);
    setCurrentStep(1);
    setFormData({
      termsAgreed: false,
      documentsRequested: [],
      purposeOfRequest: "",
      certification: [],
      receiptNumber: "",
      dateOfPayment: getTodayDate(),
      documentCopies: {},
      certCopies: {},
    });
    setErrorMessage("");
    mutation.reset();
    verifyOrMutation.reset();
    setShowOrModal(false);
    setOrModalMessage("");
    setUnresolvedItems([]);
    setAutoFilledNames([]);
    setSuggestedDocIds({});
    setSuggestedCertIds({});
  };

  const handleGoToDashboard = () => {
    if (window.location.pathname.startsWith('/staff')) {
      navigate('/staff/dashboard');
    } else if (window.location.pathname.startsWith('/alumni')) {
      navigate('/alumni/home');
    } else {
      navigate('/student/home');
    }
  };

  const purposeOptions = availablePurposes.length > 0
    ? availablePurposes.map(p => p.purpose_name)
    : [];

  const certificationOptions = availableCertifications.length > 0
    ? availableCertifications.map((c) => c.certificate_name)
    : [];

  const documentOptions = availableDocs.length > 0
    ? availableDocs.map(d => d.document_name)
    : [];

  const combinedOptions = useMemo(() => {
    return [...documentOptions, ...certificationOptions];
  }, [documentOptions, certificationOptions]);

  const docByName = useMemo(() => {
    return availableDocs.reduce((acc, doc) => {
      acc[doc.document_name] = {
        ...doc,
        requirementsParsed: parseRequirements(doc.document_requirements),
      };
      return acc;
    }, {});
  }, [availableDocs]);

  const certByName = useMemo(() => {
    return availableCertifications.reduce((acc, cert) => {
      acc[cert.certificate_name] = {
        ...cert,
        requirementsParsed: parseRequirements(cert.certificate_requirements),
      };
      return acc;
    }, {});
  }, [availableCertifications]);

  return (
    <>
      <div className="relative min-h-screen pb-20 z-20">
        <LoadingOverlay isVisible={isLoading} message="Submitting Request..." />
        <LoadingOverlay isVisible={isVerifyingOr} message="Verifying Official Receipt..." />
        {isSubmitted ? (
          <div className="max-w-4xl mx-auto animate-fadeIn">
            <div className={`shadow-2xl rounded-3xl border flex flex-col items-center text-center px-6 py-12 md:px-10 lg:px-16 transition-all duration-300 ${
              isDark
                ? 'bg-[#18191a] border-[#3e4042]/70 text-[#e4e6eb]'
                : 'bg-pup-dark-maroon border-pup-yellow/30 text-white'
            }`}>
              {/* Green Check Icon */}
              <div className="flex items-center justify-center w-16 h-16 rounded-full bg-green-500/10 border border-green-500/20 text-green-400 mb-6 shrink-0 shadow-lg shadow-green-500/10">
                <svg className="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                </svg>
              </div>

              {/* Title & Subtitle */}
              <h2 className="text-2xl sm:text-3xl font-extrabold text-white mb-3 tracking-wide">
                Request Submitted Successfully
              </h2>
              <p className={`text-xs sm:text-base max-w-xl mx-auto mb-6 font-medium ${
                isDark ? 'text-gray-300' : 'text-white/85'
              }`}>
                Please be patient as we process your requested document. Thank you and keep safe always!
              </p>

              {/* Top Divider */}
              <div className={`w-full max-w-4xl mx-auto border-t border-dashed my-6 ${
                isDark ? 'border-[#3e4042]' : 'border-white/15'
              }`} />

              {/* Side-by-Side Grid Container */}
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 lg:gap-8 w-full max-w-4xl mx-auto my-4 items-start justify-items-center">
                {/* Left Column: Office Hours Notice */}
                <div className="flex flex-col items-center gap-3 sm:gap-4 w-full max-w-105 mx-auto">
                  <h3 className="text-xs font-bold uppercase tracking-widest text-[#FFC72C] text-center w-full">
                    Processing Schedule & Hours
                  </h3>
                  <OfficeHoursNotice isDark={isDark} small={true} />
                  <p className={`text-[11px] text-center leading-relaxed max-w-sm mx-auto ${
                    isDark ? 'text-gray-400' : 'text-white/50'
                  }`}>
                    Note: View/download your claim ticket QR code in your inbox or present the manual claim code when claiming.
                  </p>
                </div>

                {/* Right Column: Claim Details & QR */}
                <div className="flex flex-col items-center gap-3 sm:gap-4 w-full max-w-105 mx-auto">
                  <h3 className="text-xs font-bold uppercase tracking-widest text-[#FFC72C] text-center w-full">
                    Claim Ticket & Code
                  </h3>

                  {/* Claim Ticket Component */}
                  <ClaimTicket uuid={claimTicket?.uuid} claimCode={claimTicket?.claimCode} small={true} />
                </div>
              </div>

              {/* Bottom Divider */}
              <div className={`w-full max-w-4xl mx-auto border-t border-dashed my-6 ${
                isDark ? 'border-[#3e4042]' : 'border-white/15'
              }`} />

              {/* Bottom Navigation Actions */}
              <div className="flex flex-col sm:flex-row items-center justify-center gap-4 w-full max-w-xl mx-auto mt-6">
                <button
                  type="button"
                  onClick={handleConfirm}
                  className={`w-full sm:w-1/2 py-3 px-6 rounded-xl font-bold text-sm transition-all shadow-md active:scale-95 text-center cursor-pointer ${
                    isDark
                      ? 'bg-[#2b2c2f] hover:bg-[#383a3e] text-gray-200 border border-[#3e4042]'
                      : 'border border-white/15 bg-[#3d0c0c] hover:bg-[#4c1212] text-white'
                  }`}
                >
                  Create Another Request
                </button>
                <button
                  type="button"
                  onClick={handleGoToDashboard}
                  className="w-full sm:w-1/2 py-3 px-8 rounded-xl font-bold text-sm bg-[#F8BF1E] hover:bg-[#e6b01b] text-pup-maroon transition-all shadow-md shadow-yellow-500/20 active:scale-95 text-center cursor-pointer"
                >
                  Go to Dashboard
                </button>
              </div>
            </div>
          </div>
        ) : (
          <div
            ref={formRef}
            style={{
              scrollMarginTop: `${headerHeight + 20}px`,
            }}
            className="max-w-auto mx-auto space-y-6 pt-2 sm:pt-4 pb-12 animate-fadeIn"
          >
            {/* Top Stepper Progress */}
            <StepProgress
              steps={wizardSteps}
              currentStep={currentStep}
              isDark={isDark}
              onStepClick={(stepId) => {
                if (stepId < currentStep) {
                  setErrorMessage("");
                  setCurrentStep(stepId);
                }
              }}
            />

            {/* Main Form Card */}
            <form
              className={`shadow-2xl rounded-2xl sm:rounded-3xl border flex flex-col relative transition-all duration-300 ${
                isDark
                  ? 'bg-[#18191a] border-[#3e4042]/70 text-[#e4e6eb]'
                  : 'bg-pup-dark-maroon border-pup-yellow/30 text-white'
              }`}
              onSubmit={(e) => {
                e.preventDefault();
                if (currentStep < finalStep) {
                  nextStep(e);
                } else {
                  handlePreSubmit(e);
                }
              }}
              noValidate
            >
              {/* Card Header */}
              <div className="px-6 sm:px-10 pt-8 pb-4">
                <h2 className="text-2xl sm:text-3xl font-bold tracking-tight text-white mb-1.5">
                  {stepTitles[currentStep]?.title}
                </h2>
                <p className="text-sm sm:text-base text-gray-300">
                  {stepTitles[currentStep]?.subtitle}
                </p>
              </div>

              {/* Step Content Container */}
              <div className="px-6 sm:px-10 py-4 flex-1">
                {/* STEP 1: Terms & Conditions (Modular Component) */}
                {currentStep === 1 && (
                  <TermsAndConditionsStep
                    termsAgreed={formData.termsAgreed}
                    onCheckboxChange={handleCheckboxChange}
                    isDark={isDark}
                  />
                )}
                  {/* STEP: Official Receipt Verification (new — moved ahead of
                    Documents so the receipt can drive what gets suggested).
                    Clicking Next here calls handleVerifyOr(), which hits
                    verify-or and only advances on a successful match. */}
                {currentStep === orStep && (
                  <div className="space-y-6 animate-fadeIn">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                      <InputGroup
                        name="receiptNumber"
                        label="Official Receipt Number"
                        value={formData.receiptNumber}
                        onChange={handleInputChange}
                        placeholder='XXXXXXX'
                        required
                        voiceEnabled
                      />
                      <InputGroup
                        name="dateOfPayment"
                        label="Date of Payment"
                        type="date"
                        value={formData.dateOfPayment}
                        onChange={handleInputChange}
                        max={getTodayDate()}
                        required
                        voiceEnabled={false}
                      />
                    </div>
                    <div className={`p-4 rounded-xl border text-xs sm:text-sm flex items-start gap-3 ${
                      isDark ? 'bg-[#242526] border-[#3e4042] text-gray-300' : 'bg-white/10 border-white/15 text-white/90'
                    }`}>
                      <InformationCircleIcon className="w-5 h-5 shrink-0 text-[#FFC72C] mt-0.5" />
                      <p className="leading-relaxed">
                        We'll verify this against the Cashier's Office and use it to automatically suggest which
                        documents to request on the next step — you'll still be able to review and
                        change your selection before submitting.
                      </p>
                    </div>
                  </div>
                )}

                {/* STEP 3: Documents Requested */}
                {currentStep === docStep && (
                  <div className="space-y-6 animate-fadeIn">
                    {/* Auto-filled notice */}
                    {autoFilledNames.length > 0 && (
                      <div className={`flex items-start gap-3 p-4 rounded-xl border text-sm transition-all ${
                        isDark
                          ? 'bg-[#242526] border-[#FFC72C]/40 text-[#e4e6eb]'
                          : 'bg-white/10 border-white/20 text-white'
                      }`}>
                        <InformationCircleIcon className="w-5 h-5 shrink-0 text-[#FFC72C] mt-0.5" />
                        <div className="leading-relaxed">
                          Auto-filled from <span className="text-[#FFC72C] font-semibold">OR #{formData.receiptNumber}</span> — we pre-selected the documents that match your receipt. Uncheck anything wrong, or add more below.
                        </div>
                      </div>
                    )}

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                      <MultiSelectDropdown
                        name="selectedItems"
                        label="Documents & Certifications"
                        options={combinedOptions}
                        selectedValues={[
                          ...(formData.documentsRequested || []),
                          ...(formData.certification || []),
                        ]}
                        onChange={handleCombinedItemsChange}
                        Required
                      />

                      <DropdownGroup
                        name="purposeOfRequest"
                        label="Purpose of Request"
                        value={formData.purposeOfRequest}
                        onChange={handleInputChange}
                        required
                        options={purposeOptions}
                      />
                    </div>

                    {/* Unresolved Receipt Items Callout Card */}
                    {unresolvedItems.length > 0 && (
                      <div className={`p-4 sm:p-5 rounded-2xl border transition-all ${
                        isDark
                          ? 'bg-[#241a0e]/80 border-amber-600/40'
                          : 'bg-amber-950/40 border-amber-500/30'
                      }`}>
                        <div className="flex items-center justify-between pb-3 border-b border-amber-500/20">
                          <div className="flex items-center gap-2">
                            <ExclamationTriangleIcon className="w-5 h-5 shrink-0 text-amber-400" />
                            <span className="font-semibold text-amber-300 text-sm sm:text-base">
                              Couldn't match automatically
                            </span>
                          </div>
                          <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-[#1a1309] text-amber-300 border border-amber-500/30">
                            {unresolvedItems.length} {unresolvedItems.length === 1 ? 'item' : 'items'}
                          </span>
                        </div>

                        <div className="max-h-48 overflow-y-auto custom-scrollbar pr-1 space-y-2 mt-3">
                          {unresolvedItems.map((item, i) => (
                            <div
                              key={i}
                              className={`flex justify-between items-center px-4 py-3 rounded-xl border transition-all ${
                                isDark
                                  ? 'bg-[#18120a] border-amber-500/20 text-gray-200'
                                  : 'bg-black/20 border-amber-500/20 text-white'
                              }`}
                            >
                              <span className="font-medium text-sm text-amber-200">{item.label}</span>
                              <span className="text-xs font-medium text-amber-400/90 shrink-0 ml-2">
                                {item.amount ? `₱${parseFloat(item.amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` : ''}
                                {item.amount ? ' • ' : ''}
                                qty {item.quantity}
                              </span>
                            </div>
                          ))}
                        </div>

                        <p className="text-amber-200/70 text-xs mt-3">
                          Select the matching document below, or contact the registrar's office if unsure.
                        </p>
                      </div>
                    )}
                  </div>
                )}

                {/* STEP 4: Number of Copies & Review */}
                {currentStep === finalStep && (
                  <div className="space-y-6 animate-fadeIn">
                    <div className={`p-5 rounded-2xl border ${
                      isDark ? 'bg-[#242526] border-[#3e4042]' : 'bg-white/10 border-white/20'
                    }`}>
                      <h3 className="text-[#FFC72C] font-bold mb-4 uppercase text-xs sm:text-sm tracking-wider">
                        Number of copies per document / certificate
                      </h3>
                      <div className="space-y-3 max-h-44 overflow-y-auto pr-2 custom-scrollbar">
                        {/* formData.documentsRequested contains only document
                            names by construction — see handlePreSubmit's
                            comment for why we no longer re-filter it by a
                            "certif" substring guess. */}
                        {formData.documentsRequested.map((doc, index) => (
                          <div key={`doc-copy-${index}`} className="flex items-center justify-between gap-4 py-1">
                            <label className="text-white text-sm font-medium flex-1">
                              {doc}
                            </label>
                            <div className="w-24">
                              <input
                                type="number"
                                min="1"
                                max="10"
                                inputMode="numeric"
                                pattern="[0-9]*"
                                className={`w-full p-2 text-center text-sm font-bold rounded-xl outline-none transition-all duration-200 focus:ring-2 focus:ring-[#FFC72C] ${
                                  isDark ? 'bg-[#18191a] border border-[#3e4042] text-white' : 'bg-white text-black'
                                }`}
                                value={formData.documentCopies[doc] === undefined ? 1 : formData.documentCopies[doc]}
                                onChange={e => {
                                  const val = e.target.value;
                                  handleDocCopyChange(doc, val === '' ? '' : Math.max(1, Math.min(10, Number(val))));
                                }}
                                onBlur={e => {
                                  if (e.target.value === '') handleDocCopyChange(doc, 1);
                                }}
                              />
                            </div>
                          </div>
                        ))}
                        {formData.certification.length > 0 &&
                          formData.certification.map((certName, index) => (
                            <div key={`cert-copy-${index}`} className="flex items-center justify-between gap-4 py-1">
                              <label className="text-white text-sm font-medium flex-1">
                                {certName} <span className="text-[#FFC72C] text-xs font-semibold">(Certificate)</span>
                              </label>
                              <div className="w-24">
                                <input
                                  type="number"
                                  min="1"
                                  max="10"
                                  className={`w-full p-2 text-center text-sm font-bold rounded-xl outline-none transition-all duration-200 focus:ring-2 focus:ring-[#FFC72C] ${
                                    isDark ? 'bg-[#18191a] border border-[#3e4042] text-white' : 'bg-white text-black'
                                  }`}
                                  value={formData.certCopies[certName] === undefined ? 1 : formData.certCopies[certName]}
                                  onChange={e => {
                                    const val = e.target.value;
                                    handleCertCopyChange(certName, val === '' ? '' : Math.max(1, Math.min(10, Number(val))));
                                  }}
                                  onBlur={e => {
                                    if (e.target.value === '') handleCertCopyChange(certName, 1);
                                  }}
                                />
                              </div>
                            </div>
                          ))
                        }
                      </div>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                      <div className={`flex flex-col gap-3 max-h-64 overflow-y-auto pr-1 custom-scrollbar p-3 rounded-2xl border ${
                        isDark ? 'bg-[#242526] border-[#3e4042]' : 'bg-white/10 border-white/20'
                      }`}>
                        <span className="text-xs font-bold uppercase tracking-wider text-[#FFC72C] px-1">
                          Document Requirements
                        </span>
                        {formData.documentsRequested.map((doc, index) => {
                          const docData = docByName[doc];
                          const requirements = docData?.requirementsParsed ?? [];

                          return (
                            <div
                              key={`doc-req-${index}`}
                              className={`p-3 rounded-xl border ${isDark ? 'bg-[#18191a] border-[#3e4042]' : 'bg-white/10 border-white/15'}`}
                            >
                              <div className="flex items-center gap-2 mb-2">
                                <div className="w-1 h-3.5 bg-[#FFC72C] rounded-full shrink-0" />
                                <h4 className="text-[#FFC72C] font-bold text-xs uppercase tracking-wide">
                                  {doc}
                                </h4>
                              </div>

                              <ul className="flex flex-col gap-1 pl-1">
                                {requirements.length > 0 ? (
                                  requirements.map((req, i) => (
                                    <li key={i} className="flex items-start gap-2 text-xs text-white/80 leading-relaxed">
                                      <span className="w-1 h-1 bg-[#FFC72C] rounded-full shrink-0 mt-1.5" />
                                      <span>{req}</span>
                                    </li>
                                  ))
                                ) : (
                                  <li className="text-xs text-white/40 italic">No special requirements</li>
                                )}
                              </ul>
                            </div>
                          );
                        })}

                        {formData.certification.map((certName, index) => {
                          const certData = certByName[certName];
                          const requirements = certData?.requirementsParsed ?? [];

                          return (
                            <div
                              key={`cert-req-${index}`}
                              className={`p-3 rounded-xl border ${isDark ? 'bg-[#18191a] border-[#3e4042]' : 'bg-white/10 border-white/15'}`}
                            >
                              <div className="flex items-center gap-2 mb-2">
                                <div className="w-1 h-3.5 bg-[#FFC72C] rounded-full shrink-0" />
                                <h4 className="text-[#FFC72C] font-bold text-xs uppercase tracking-wide">
                                  {certName} <span className="text-white/60 font-normal normal-case">(Certificate)</span>
                                </h4>
                              </div>

                              <ul className="flex flex-col gap-1 pl-1">
                                {requirements.length > 0 ? (
                                  requirements.map((req, i) => (
                                    <li key={i} className="flex items-start gap-2 text-xs text-white/80 leading-relaxed">
                                      <span className="w-1 h-1 bg-[#FFC72C] rounded-full shrink-0 mt-1.5" />
                                      <span>{req}</span>
                                    </li>
                                  ))
                                ) : (
                                  <li className="text-xs text-white/40 italic">No special requirements</li>
                                )}
                              </ul>
                            </div>
                          );
                        })}
                      </div>

                      <div className={`p-4 rounded-2xl border flex flex-col items-center justify-center text-center ${
                        isDark ? 'bg-[#242526] border-[#3e4042]' : 'bg-white/10 border-white/20'
                      }`}>
                        <p className="text-xs text-white/80 leading-relaxed mb-2">
                          <strong>REMINDER</strong>: Your feedback is important to us. Kindly take a moment to share your experience.
                        </p>

                        <h4 className="text-[#FFC72C] font-bold text-xs uppercase tracking-wide mb-2">
                          Scan QR Code
                        </h4>

                        <img
                          src={qrCode}
                          alt="QR Code"
                          className="w-24 h-24 sm:w-28 sm:h-28 object-contain my-1 bg-white p-1 rounded-lg"
                        />

                        <a
                          href="https://pupsinta.freshservice.com/support/home"
                          target="_blank"
                          rel="noopener noreferrer"
                          className="mt-2 text-xs text-[#FFC72C] underline hover:text-yellow-400 transition break-all"
                        >
                          https://pupsinta.freshservice.com/support/home
                        </a>
                      </div>
                    </div>
                  </div>
                )}
              </div>

              {/* Navigation Action Buttons */}
              <div className={`mt-8 px-6 sm:px-10 py-5 flex items-center justify-between border-t ${
                isDark ? 'border-[#3e4042]/50 bg-[#141517]/50' : 'border-white/10 bg-black/10'
              } rounded-b-2xl sm:rounded-b-3xl`}>
                {currentStep > 1 ? (
                  <button
                    type="button"
                    onClick={prevStep}
                    className={`inline-flex items-center gap-2 font-semibold text-sm py-2.5 px-6 rounded-xl transition-all duration-200 cursor-pointer active:scale-95 ${
                      isDark
                        ? 'bg-[#2b2c2f] hover:bg-[#383a3e] text-gray-200 border border-[#444649]'
                        : 'bg-white/10 hover:bg-white/20 text-white border border-white/20'
                    }`}
                  >
                    <ArrowLeftIcon className="w-4 h-4" />
                    <span>Back</span>
                  </button>
                ) : <div />}

                <button
                  type="button"
                  onClick={currentStep < finalStep ? nextStep : handlePreSubmit}
                  disabled={isVerifyingOr}
                  className="inline-flex items-center justify-center gap-2 font-bold text-sm py-2.5 px-7 rounded-xl bg-pup-yellow hover:bg-[#e6b01b] text-pup-maroon active:scale-95 transition-all duration-200 shadow-md shadow-yellow-500/20 disabled:opacity-60 disabled:cursor-not-allowed ml-auto cursor-pointer"
                >
                  <span>
                    {currentStep === orStep && isVerifyingOr
                      ? "Verifying..."
                      : currentStep < finalStep
                      ? "Next"
                      : "Submit"}
                  </span>
                  {currentStep < finalStep ? (
                    <ArrowRightIcon className="w-4 h-4 stroke-[2.5]" />
                  ) : (
                    <CheckIcon className="w-4 h-4 stroke-[2.5]" />
                  )}
                </button>
              </div>
            </form>
          </div>
        )}
        <SubmitConfirmationModal
          isOpen={showConfirmModal}
          onClose={() => setShowConfirmModal(false)}
          onConfirm={() => {
            setShowConfirmModal(false);
            handleSubmit({ preventDefault: () => { } });
          }}
          title="Submit Confirmation"
          message="Are you sure you want to submit your request?"
        />
      </div>
      <OrValidationErrorModal
        isOpen={showOrModal}
        onClose={() => setShowOrModal(false)}
        message={orModalMessage}
      />
      <ErrorToast
        message={errorMessage}
        onClose={() => setErrorMessage("")}
      />
    </>
  );
};

export default RequestForm;