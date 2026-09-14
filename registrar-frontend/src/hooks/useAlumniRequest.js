import { useState, useMemo } from "react";
import { useMutation } from "@tanstack/react-query";
import { useReferenceData } from "../context/ReferenceDataContext";
import { createDocumentRequest, verifyOfficialReceipt } from "../services/api";
import { DOC_TYPE_MAP, CERTIFICATION_MAP } from "../utils/constants";
import { useFormDraft } from "./useFormDraft";
import {
  ALUMNI_ACCESS_IDS,
  validateProfileStep,
  validateRequestDetailsStep,
} from "../utils/alumniRequestUtils";
import { getTodayDate } from "../utils/helpers";

export const useAlumniRequest = ({ showProfileStep = false }) => {
  const {
    documentTypes,
    certifications,
    purposes: referencePurposes,
    docTypeName,
    certName,
    refreshDocumentTypes,
    refreshCertifications,
  } = useReferenceData();

  const [currentStep, setCurrentStep] = useState(1);
  const [isSubmitted, setIsSubmitted] = useState(false);
  const [errorMessage, setErrorMessage] = useState("");
  const [showConfirmModal, setShowConfirmModal] = useState(false);
  const [showOrModal, setShowOrModal] = useState(false);
  const [orModalMessage, setOrModalMessage] = useState("");
  // See RequestForm.jsx's identical claimTicket state — only the two
  // fields ClaimTicket needs, not the whole created request.
  const [claimTicket, setClaimTicket] = useState(null);

  // OR-first wizard: populated once verifyOfficialReceipt() succeeds.
  // unresolvedItems holds receipt lines the suggester couldn't match to
  // any document/certificate type — surfaced so they're never silently
  // dropped. autoFilledNames tags which currently-selected
  // documents/certifications came from the suggestion (vs. the alumni
  // picking them manually) purely for the "Auto-filled from OR #..."
  // badge; it does not gate anything server-side — final submit
  // re-verifies from scratch regardless of how an item got onto the form.
  const [unresolvedItems, setUnresolvedItems] = useState([]);
  const [autoFilledNames, setAutoFilledNames] = useState([]);
  const [suggestedDocIds, setSuggestedDocIds] = useState({});
  const [suggestedCertIds, setSuggestedCertIds] = useState({});

  // documentTypes comes exclusively from GET /document-types (the
  // document_type table) — every row here is already a genuine document
  // by construction. See RequestForm.jsx's identical availableDocs
  // comment for why filtering further by a "certif" name guess is wrong:
  // it silently hides legitimate Type=Document rows like "Certified True
  // Copy - X" (CTC). Access control alone (ALUMNI_ACCESS_IDS) is the
  // correct and sufficient filter here.
  const availableDocs = useMemo(() => {
    return documentTypes
      .filter((doc) => ALUMNI_ACCESS_IDS.includes(Number(doc.access_id)));
  }, [documentTypes]);

  const availableCertifications = useMemo(() => {
    return certifications.filter((cert) => ALUMNI_ACCESS_IDS.includes(Number(cert.access_id)));
  }, [certifications]);

  const availablePurposes = referencePurposes;

  const [formData, setFormData] = useState({
    termsAgreed: false,
    firstName: "",
    middleName: "",
    surname: "",
    dob: "",
    address: "",
    contactNumber: "",
    documentsRequested: [],
    purposeOfRequest: "",
    certification: [],
    noRequests: false,
    doneRequest: false,
    receiptNumber: "",
    dateOfPayment: getTodayDate(),
    documentCopies: {},
    certCopies: {},
  });

  const { clearDraft } = useFormDraft({
    storageKey: 'alumni_request_draft',
    formData,
    setFormData,
    currentStep,
    setCurrentStep,
    isSubmitted,
  });

  const handleInputChange = (e) => {
    const { name, value } = e.target;
    // MultiSelect dropdowns and Dropdowns pass name/value via custom events or targets
    setFormData((prev) => ({ ...prev, [name]: value }));
  };

  const handleCheckboxChange = (e) => {
    const { name, checked } = e.target;
    setFormData((prev) => {
      if (name === "noRequests") {
        return {
          ...prev,
          noRequests: checked,
          doneRequest: checked ? false : prev.doneRequest,
        };
      }
      if (name === "doneRequest") {
        return {
          ...prev,
          doneRequest: checked,
          noRequests: checked ? false : prev.noRequests,
        };
      }
      return { ...prev, [name]: checked };
    });
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
        [docName]: value,
      },
    }));
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

  const showCertificationDropdown = formData.documentsRequested.some((doc) => {
    return doc.toLowerCase().includes("certif");
  });

  // OR-first step ordering (mirrors RequestForm.jsx):
  //   1: Terms & Conditions
  //   2 (or 3 with profile): Official Receipt Verification
  //   3 (or 4 with profile): Alumni Request (Document & Purpose Selection)
  //   last: Number of Copies & Claim Ticket
  const orStep = showProfileStep ? 3 : 2;
  const docStep = showProfileStep ? 4 : 3;
  const finalStep = showProfileStep ? 5 : 4;

  // --- OR Verification Mutation ---
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
    if (e) e.preventDefault();
    setErrorMessage("");

    if (currentStep === 1 && !formData.termsAgreed) {
      setErrorMessage("You must read and agree to the Terms & Conditions to proceed.");
      return;
    }

    if (showProfileStep && currentStep === 2) {
      const profileError = validateProfileStep(formData);
      if (profileError) {
        setErrorMessage(profileError);
        return;
      }
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
      const detailsError = validateRequestDetailsStep(formData);
      if (detailsError) {
        setErrorMessage(detailsError);
        return;
      }
    }


    if (currentStep < finalStep) {
      setCurrentStep(currentStep + 1);
    }
  };

  const prevStep = (e) => {
    if (e) e.preventDefault();
    setErrorMessage("");
    if (currentStep > 1) {
      setCurrentStep(currentStep - 1);
    }
  };

  const handlePreSubmit = (e) => {
    if (e) e.preventDefault();
    setErrorMessage("");

    // OR Number / Date of Payment are validated and verified against the
    // cashier API earlier now (see handleVerifyOr, triggered when leaving
    // the OR-verification step) — this final step only has copies left
    // to check before confirming.
    // Note: formData.documentsRequested only ever contains document_type
    // names — never certificate_type names. See RequestForm.jsx's
    // identical comment for the full rationale on why this array is no
    // longer re-filtered by a "certif" substring guess.
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

  const mutation = useMutation({
    mutationFn: createDocumentRequest,
    onSuccess: (response) => {
      setClaimTicket({
        uuid: response?.data?.uuid ?? null,
        claimCode: response?.data?.claim_code ?? null,
      });
      clearDraft();
      setIsSubmitted(true);
    },
    onError: (error) => {
      console.error("Submission error:", error.response?.data || error);
      setErrorMessage(
        error.response?.data?.message || "Submission failed. Please check your data."
      );
    },
  });

  const handleSubmit = (e) => {
    if (e && e.preventDefault) e.preventDefault();

    const selectedPurpose = availablePurposes.find(
      (p) => p.purpose_name === formData.purposeOfRequest || Number(p.request_purpose_id) === Number(formData.purposeOfRequest)
    ) ?? referencePurposes.find((p) => p.purpose_name === formData.purposeOfRequest || Number(p.request_purpose_id) === Number(formData.purposeOfRequest));

    const purposeId = selectedPurpose?.request_purpose_id;

    // Map all selected certification names to their IDs
    const certificates = (formData.certification || [])
      .map((name) => {
        const certObj = availableCertifications.find((c) => c.certificate_name === name)
          ?? certifications.find((c) => c.certificate_name === name);
        const id = suggestedCertIds[name] ?? certObj?.certificate_type_id;

        return {
          certificate_type_id: id,
          number_of_copies: parseInt(formData.certCopies[name]) || 1,
        };
      })
      .filter((c) => c.certificate_type_id);

    const documents = (formData.documentsRequested || [])
      .map((name) => {
        const docObj = availableDocs.find((d) => d.document_name === name)
          ?? documentTypes.find((d) => d.document_name === name);
        const id = suggestedDocIds[name] ?? docObj?.document_type_id;

        return {
          document_type_id: id,
          number_of_copies: parseInt(formData.documentCopies[name]) || 1,
        };
      })
      .filter((doc) => doc.document_type_id);

    const payload = {
      request_purpose_id: purposeId,
      or_number: formData.receiptNumber,
      receipt_date: formData.dateOfPayment,
      documents: documents,
      certificates: certificates,
    };

    mutation.mutate(payload);
  };

  const handleConfirm = () => {
    clearDraft();
    setIsSubmitted(false);
    setCurrentStep(1);
    setFormData({
      termsAgreed: false,
      firstName: "",
      middleName: "",
      surname: "",
      dob: "",
      address: "",
      contactNumber: "",
      documentsRequested: [],
      purposeOfRequest: "",
      certification: [],
      noRequests: false,
      doneRequest: false,
      receiptNumber: "",
      dateOfPayment: getTodayDate(),
      documentCopies: {},
      certCopies: {},
    });
    setErrorMessage("");
    mutation.reset();
    verifyOrMutation.reset();
    setUnresolvedItems([]);
    setAutoFilledNames([]);
    setSuggestedDocIds({});
    setSuggestedCertIds({});
  };

  const isLoading = mutation.isPending;
  const isVerifyingOr = verifyOrMutation.isPending;

  const certificationOptions =
    availableCertifications.length > 0
      ? availableCertifications.map((c) => c.certificate_name)
      : Object.values(CERTIFICATION_MAP);

  const purposeOptions =
    availablePurposes.length > 0
      ? availablePurposes.map((p) => p.purpose_name)
      : referencePurposes.map((p) => p.purpose_name);

  const documentOptions =
    availableDocs.length > 0
      ? availableDocs.map((d) => d.document_name)
      : Object.values(DOC_TYPE_MAP);

  const combinedOptions = useMemo(() => {
    return [...documentOptions, ...certificationOptions];
  }, [documentOptions, certificationOptions]);

  // Step labels mirror the RequestForm.jsx ordering:
  // 1: Terms & Conditions
  // (optional) 2: Alumni Profile
  // OR Verification
  // Alumni Request (Document & Purpose Selection)
  // Number of Copies & Claim Ticket
  const stepLabels = showProfileStep
    ? [
        "Terms & Conditions",
        "Alumni Profile",
        "Official Receipt Verification",
        "Alumni Request",
        "Number of Copies & Claim Ticket",
      ]
    : [
        "Terms & Conditions",
        "Official Receipt Verification",
        "Alumni Request",
        "Number of Copies & Claim Ticket",
      ];

  const totalSteps = stepLabels.length;

  const certificationLabel = formData.certification.join(", ");

  return {
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
    stepLabels,
    totalSteps,
    showCertificationDropdown,
    certificationLabel,
    finalStep,
    orStep,
    docStep,
    unresolvedItems,
    autoFilledNames,
  };
};