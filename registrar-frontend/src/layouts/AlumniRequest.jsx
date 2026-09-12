import React, { useEffect, useRef } from "react";
import { useNavigate } from "react-router-dom";
import InputGroup from "../components/InputGroup.jsx";
import CheckboxItem from "../components/Checkbox.jsx";
import DropdownGroup from "../components/DropDown.jsx";
import MultiSelectDropdown from "../components/MultiSelection.jsx";
import ErrorToast from "../components/ErrorToast.jsx";
import LoadingOverlay from "../components/LoadingOverlay.jsx";
import SubmitConfirmationModal from "../components/SubmitConfirmationModal.jsx";
import ClaimTicket from "../components/ClaimTicket.jsx";
import OfficeHoursNotice from "../components/OfficeHoursNotice.jsx";
import StepProgress from "../components/StepProgress.jsx";
import TermsAndConditionsStep from "../components/TermsAndConditionsStep.jsx";
import OrValidationErrorModal from "../components/OrValidationErrorModal.jsx";
import qrCode from "../assets/qrcode.png";
import { useTheme } from "../context/ThemeContext";
import { useAlumniRequest } from "../hooks/useAlumniRequest";
import { useScrollToTop } from "../hooks/useScrollToTop";
import { getTodayDate, useHeaderResponsiveState } from "../utils/helpers";
import {
  InformationCircleIcon,
  ExclamationTriangleIcon,
  ArrowLeftIcon,
  ArrowRightIcon,
  CheckIcon
} from "@heroicons/react/24/outline";

const AlumniRequestForm = () => {
  const { isDark } = useTheme();
  const { headerHeight } = useHeaderResponsiveState();
  const navigate = useNavigate();

  const {
    currentStep,
    isSubmitted,
    claimTicket,
    errorMessage,
    setErrorMessage,
    showConfirmModal,
    setShowConfirmModal,
    showOrModal,
    setShowOrModal,
    orModalMessage,
    formData,
    handleInputChange,
    handleCheckboxChange,
    handleCertCopyChange,
    handleDocCopyChange,
    nextStep,
    prevStep,
    handlePreSubmit,
    handleSubmit,
    handleConfirm,
    isLoading,
    isVerifyingOr,
    availableDocs,
    availableCertifications,
    certificationOptions,
    purposeOptions,
    documentOptions,
    combinedOptions,
    handleCombinedItemsChange,
    finalStep,
    orStep,
    docStep,
    unresolvedItems,
    autoFilledNames,
  } = useAlumniRequest({ showProfileStep: false });

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

  const handleGoToDashboard = () => {
    if (window.location.pathname.startsWith('/staff')) {
      navigate('/staff/dashboard');
    } else if (window.location.pathname.startsWith('/alumni')) {
      navigate('/alumni/home');
    } else {
      navigate('/student/home');
    }
  };

  const handleGoToInbox = () => {
    if (window.location.pathname.startsWith('/staff')) {
      navigate('/staff/inbox');
    } else if (window.location.pathname.startsWith('/alumni')) {
      navigate('/alumni/inbox');
    } else {
      navigate('/student/inbox');
    }
  };

  const { targetRef: formRef } = useScrollToTop([currentStep, isSubmitted]);

  return (
    <div className="relative min-h-screen pb-20 z-10">
      <LoadingOverlay isVisible={isLoading} message="Submitting Request..." />
      <LoadingOverlay isVisible={isVerifyingOr} message="Verifying Official Receipt..." />
      {isSubmitted ? (
        <div className="max-w-4xl mx-auto animate-fadeIn">
          <div className={`shadow-2xl rounded-3xl border flex flex-col items-center text-center px-6 py-12 md:px-10 lg:px-16 transition-all duration-300 ${
            isDark
              ? "bg-[#18191a] border-[#3e4042]/70 text-[#e4e6eb]"
              : "bg-pup-dark-maroon border-pup-yellow/30 text-white"
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
              isDark ? "text-gray-300" : "text-white/85"
            }`}>
              Please be patient as we process your requested document. Thank you and keep safe always!
            </p>

            {/* Top Divider */}
            <div className={`w-full max-w-4xl mx-auto border-t border-dashed my-6 ${
              isDark ? "border-[#3e4042]" : "border-white/15"
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
                  isDark ? "text-gray-400" : "text-white/50"
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
              isDark ? "border-[#3e4042]" : "border-white/15"
            }`} />

            {/* Bottom Navigation Actions */}
            <div className="flex flex-col sm:flex-row items-center justify-center gap-4 w-full max-w-xl mx-auto mt-6">
              <button
                type="button"
                onClick={handleConfirm}
                className={`w-full sm:w-1/2 py-3 px-6 rounded-xl font-bold text-sm transition-all shadow-md active:scale-95 text-center cursor-pointer ${
                  isDark
                    ? "bg-[#2b2c2f] hover:bg-[#383a3e] text-gray-200 border border-[#3e4042]"
                    : "border border-white/15 bg-[#3d0c0c] hover:bg-[#4c1212] text-white"
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
                ? "bg-[#18191a] border-[#3e4042]/70 text-[#e4e6eb]"
                : "bg-pup-dark-maroon border-pup-yellow/30 text-white"
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
              {/* STEP 1: TERMS & CONDITIONS */}
              {currentStep === 1 && (
                <TermsAndConditionsStep
                  termsAgreed={formData.termsAgreed}
                  onCheckboxChange={handleCheckboxChange}
                  isDark={isDark}
                />
              )}

              {/* STEP 2: Official Receipt Verification */}
              {currentStep === orStep && (
                <div className="space-y-6 animate-fadeIn">
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <InputGroup
                      name="receiptNumber"
                      label="Official Receipt Number"
                      value={formData.receiptNumber}
                      onChange={handleInputChange}
                      placeholder="XXXXXXX"
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

              {/* STEP 3: Alumni Request (Document & Purpose Selection) */}
              {currentStep === docStep && (
                <div className="space-y-6 animate-fadeIn">
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
                        required
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


              {/* STEP: Number of Copies & Claim Ticket (final step) */}
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
                          names by construction — populated exclusively by
                          CashierDocumentSuggester's `documents` array and
                          handleCombinedItemsChange's structural membership
                          check against availableDocs. A previous version
                          filtered this by `!name.includes("certif")` to
                          guess which entries were "really" certificates;
                          that broke once legitimate Type=Document rows
                          named "Certified True Copy - X" existed, which
                          would silently vanish from this list. */}
                      {formData.documentsRequested.length > 0 &&
                        formData.documentsRequested
                          .map((doc, index) => (
                            <div key={`doc-copy-${index}`} className="flex items-center justify-between gap-4 py-1">
                              <label className="text-white text-sm font-medium flex-1">{doc}</label>
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
                                  value={
                                    formData.documentCopies[doc] === undefined
                                      ? 1
                                      : formData.documentCopies[doc]
                                  }
                                  onChange={(e) => {
                                    const val = e.target.value;
                                    handleDocCopyChange(
                                      doc,
                                      val === "" ? "" : Math.max(1, Math.min(10, Number(val)))
                                    );
                                  }}
                                  onBlur={(e) => {
                                    if (e.target.value === "") handleDocCopyChange(doc, 1);
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
                                value={
                                  formData.certCopies[certName] === undefined
                                    ? 1
                                    : formData.certCopies[certName]
                                }
                                onChange={(e) => {
                                  const val = e.target.value;
                                  handleCertCopyChange(
                                    certName,
                                    val === "" ? "" : Math.max(1, Math.min(10, Number(val)))
                                  );
                                }}
                                onBlur={(e) => {
                                  if (e.target.value === "") handleCertCopyChange(certName, 1);
                                }}
                              />
                            </div>
                          </div>
                        ))}
                    </div>
                  </div>

                  <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div
                      className={`flex flex-col gap-3 max-h-64 overflow-y-auto pr-1 custom-scrollbar p-3 rounded-2xl border ${
                        isDark ? 'bg-[#242526] border-[#3e4042]' : 'bg-white/10 border-white/20'
                      }`}
                    >
                      <span className="text-xs font-bold uppercase tracking-wider text-[#FFC72C] px-1">
                        Document Requirements
                      </span>
                      {formData.documentsRequested.map((doc, index) => {
                        const docData = availableDocs.find((d) => d.document_name === doc);
                        const requirements = docData?.document_requirements
                          ? Array.isArray(docData.document_requirements)
                            ? docData.document_requirements
                            : docData.document_requirements
                              .split("\n")
                              .map((r) => r.trim().replace(/,$/, ""))
                              .filter(Boolean)
                          : [];

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
                                  <li
                                    key={i}
                                    className="flex items-start gap-2 text-xs text-white/80 leading-relaxed"
                                  >
                                    <span className="w-1 h-1 bg-[#FFC72C] rounded-full shrink-0 mt-1.5" />
                                    <span>{req}</span>
                                  </li>
                                ))
                              ) : (
                                <li className="text-xs text-white/40 italic">
                                  No requirements available
                                </li>
                              )}
                            </ul>
                          </div>
                        );
                      })}

                      {formData.certification.map((certName, index) => {
                        const certData = availableCertifications?.find((c) => c.certificate_name === certName);
                        const requirements = certData?.certificate_requirements
                          ? Array.isArray(certData.certificate_requirements)
                            ? certData.certificate_requirements
                            : certData.certificate_requirements
                              .split("\n")
                              .map((r) => r.trim().replace(/,$/, ""))
                              .filter(Boolean)
                          : [];

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
                                  <li
                                    key={i}
                                    className="flex items-start gap-2 text-xs text-white/80 leading-relaxed"
                                  >
                                    <span className="w-1 h-1 bg-[#FFC72C] rounded-full shrink-0 mt-1.5" />
                                    <span>{req}</span>
                                  </li>
                                ))
                              ) : (
                                <li className="text-xs text-white/40 italic">
                                  No requirements available
                                </li>
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
                        <strong>REMINDER</strong>: Your feedback is important to us. Kindly take a moment
                        to share your experience.
                      </p>

                      <h4 className="text-[#FFC72C] font-bold text-xs uppercase tracking-wide mb-2">
                        Scan QR Code
                      </h4>

                      <img src={qrCode} alt="QR Code" className="w-24 h-24 sm:w-28 sm:h-28 object-contain my-1 bg-white p-1 rounded-lg" />

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
                    : currentStep < finalStep ? "Next" : "Submit"}
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
      <OrValidationErrorModal
        isOpen={showOrModal}
        onClose={() => setShowOrModal(false)}
        message={orModalMessage}
      />
      <ErrorToast message={errorMessage} onClose={() => setErrorMessage("")} />
    </div>
  );
};

export default AlumniRequestForm;