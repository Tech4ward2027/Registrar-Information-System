import { useState, useEffect } from "react";
import { useQuery, useMutation } from "@tanstack/react-query";
import {
  ShieldExclamationIcon,
  DocumentTextIcon,
  CalendarDaysIcon,
  TrophyIcon,
  IdentificationIcon,
  SparklesIcon,
  CheckIcon,
  ArrowPathIcon,
  ChevronLeftIcon,
  ChevronRightIcon
} from "@heroicons/react/24/outline";
import { useTheme } from "../context/ThemeContext";
import { useAuth } from "../context/AuthProvider";
import {
  searchFreeRequestAccounts,
  checkFreeRequestEligibility,
  fileFreeRequest,
  getDocumentTypes,
  getCertifications,
  getRequestPurposes
} from "../services/api";
import { MODULE_KEYS, hasModuleAction } from "../utils/policy";
import ModuleRoute from "../components/ModuleRoute";
import SuccessToast from "../components/SuccessToast";
import ErrorToast from "../components/ErrorToast";
import VoiceSearchInput from "../components/VoiceSearchInput";
import DropdownGroup from "../components/DropDown";
import ConfirmationModal from "../components/ConfirmationModal";

const ITEMS_PER_PAGE = 6;

const FreeRequestPageContent = () => {
  const { isDark } = useTheme();
  const { user: currentUser } = useAuth();

  // Can this user perform eligibility overrides?
  const canOverride = hasModuleAction(currentUser, MODULE_KEYS.FREE_REQUESTS, "Override");

  // Step 1: Account Search
  const [searchQuery, setSearchQuery] = useState("");
  const [selectedAccount, setSelectedAccount] = useState(null);

  // TanStack Query: Reference catalog
  const { data: catalogs, isLoading: catalogsLoading } = useQuery({
    queryKey: ["freeRequestCatalogs"],
    queryFn: async () => {
      const [docsRes, certsRes, purpRes] = await Promise.all([
        getDocumentTypes(),
        getCertifications(),
        getRequestPurposes()
      ]);
      return {
        documentTypes: docsRes.data?.data || docsRes.data || [],
        certificateTypes: certsRes.data?.data || certsRes.data || [],
        requestPurposes: purpRes.data?.data || purpRes.data || [],
      };
    },
    staleTime: 5 * 60 * 1000,
  });

  const documentTypes = catalogs?.documentTypes || [];
  const certificateTypes = catalogs?.certificateTypes || [];
  const requestPurposes = catalogs?.requestPurposes || [];

  // Item Filtering & Pagination
  const [catalogSearchQuery, setCatalogSearchQuery] = useState("");
  const [catalogPage, setCatalogPage] = useState(1);

  // Step 2: Form Selections
  const [selectedPurposeId, setSelectedPurposeId] = useState("");
  const [selectedDocuments, setSelectedDocuments] = useState([]);
  const [selectedCertificates, setSelectedCertificates] = useState([]);

  // Auto-set default purpose when requestPurposes load
  useEffect(() => {
    if (requestPurposes.length > 0 && !selectedPurposeId) {
      const defaultPurp = requestPurposes[0].request_purpose_id || requestPurposes[0].purpose_id || requestPurposes[0].id || "";
      setSelectedPurposeId(String(defaultPurp));
    }
  }, [requestPurposes, selectedPurposeId]);

  // TanStack Query: Debounced Account Search
  const { data: searchResults = [], isLoading: searching } = useQuery({
    queryKey: ["freeRequestAccounts", searchQuery.trim()],
    queryFn: async () => {
      const res = await searchFreeRequestAccounts(searchQuery.trim());
      return res.data?.data || res.data || [];
    },
    enabled: searchQuery.trim().length >= 2,
    staleTime: 30 * 1000,
  });

  // TanStack Query: Eligibility Evaluation
  const { data: eligibilityResults = {} } = useQuery({
    queryKey: ["freeRequestEligibility", selectedAccount?.user_id, selectedDocuments, selectedCertificates],
    queryFn: async () => {
      const payload = {
        target_user_id: selectedAccount.user_id,
        documents: selectedDocuments,
        certificates: selectedCertificates
      };
      const res = await checkFreeRequestEligibility(payload);
      const resultsArray = res.data?.results || [];
      const map = {};
      resultsArray.forEach((r) => {
        const key = `${r.kind}_${r.type_id}`;
        map[key] = r;
      });
      return map;
    },
    enabled: Boolean(selectedAccount) && (selectedDocuments.length > 0 || selectedCertificates.length > 0),
  });

  const [checklist, setChecklist] = useState({
    idVerified: false,
    clearanceChecked: false,
    formAccomplished: false,
  });

  const [verification, setVerification] = useState({
    togaPictureVerified: false,
    recordsChecked: false,
  });

  const [overrideEnabled, setOverrideEnabled] = useState(false);
  const [overrideReason, setOverrideReason] = useState("");
  const [successToast, setSuccessToast] = useState("");
  const [errorToast, setErrorToast] = useState("");
  const [validationErrors, setValidationErrors] = useState({});
  const [showChangeAccountModal, setShowChangeAccountModal] = useState(false);

  // TanStack Mutation: Submit Free Request
  const storeMutation = useMutation({
    mutationFn: (payload) => fileFreeRequest(payload),
    onSuccess: () => {
      setSuccessToast("Free document request has been submitted successfully!");

      // Reset form
      setSelectedAccount(null);
      setSelectedDocuments([]);
      setSelectedCertificates([]);
      setChecklist({ idVerified: false, clearanceChecked: false, formAccomplished: false });
      setVerification({ togaPictureVerified: false, recordsChecked: false });
      setOverrideEnabled(false);
      setOverrideReason("");
      setValidationErrors({});
    },
    onError: (err) => {
      console.error("Failed to store free request:", err);
      setErrorToast(err.response?.data?.message || "Failed to file free request.");
    },
  });

  const submitting = storeMutation.isPending;

  useEffect(() => {
    setCatalogPage(1);
  }, [catalogSearchQuery]);

  const requiresGraduateVerification = () => {
    return (
      selectedDocuments.some((item) => {
        const doc = documentTypes.find((d) => (d.document_type_id || d.id) === item.document_type_id);
        const name = (doc?.document_name || doc?.document_type_name || doc?.name || "").toLowerCase();
        return name.includes("graduation") || name.includes("transcript") || name.includes("tor") || name.includes("cog");
      }) ||
      selectedCertificates.some((item) => {
        const cert = certificateTypes.find((c) => (c.certificate_type_id || c.id) === item.certificate_type_id);
        const name = (cert?.certificate_name || cert?.certificate_type_name || cert?.name || "").toLowerCase();
        return name.includes("graduation") || name.includes("transcript") || name.includes("tor") || name.includes("cog");
      })
    );
  };

  // Determine if any selected item is currently marked ineligible
  const hasIneligibleSelection = () => {
    const selectedKeys = [
      ...selectedDocuments.map((item) => `document_${item.document_type_id}`),
      ...selectedCertificates.map((item) => `certificate_${item.certificate_type_id}`),
    ];

    return selectedKeys.some((key) => eligibilityResults[key] && !eligibilityResults[key].eligible);
  };

  const totalItemTypesCount = selectedDocuments.length + selectedCertificates.length;

  const isFreeEligible = (item) => item.is_free_eligible === true || item.is_free_eligible === 1 || item.is_free_eligible === "1";

  const getPurposeName = () => {
    const purpose = requestPurposes.find((item) => String(item.request_purpose_id || item.purpose_id || item.id) === String(selectedPurposeId));
    return (purpose?.purpose_name || purpose?.name || "").toLowerCase();
  };

  const isBoardExamPurpose = () => getPurposeName().includes("board");

  const getCatalogIcon = (name) => {
    const normalizedName = name.toLowerCase();
    if (normalizedName.includes("transcript") || normalizedName.includes("record")) return DocumentTextIcon;
    if (normalizedName.includes("leave") || normalizedName.includes("absence")) return CalendarDaysIcon;
    if (normalizedName.includes("graduation") || normalizedName.includes("graduate")) return TrophyIcon;
    return IdentificationIcon;
  };

  const hasUnsavedSelections = () => totalItemTypesCount > 0 || Object.values(checklist).some(Boolean) || Object.values(verification).some(Boolean) || overrideReason.trim();

  const handleConfirmChangeAccount = () => {
    setSelectedAccount(null);
    setSelectedDocuments([]);
    setSelectedCertificates([]);
    setChecklist({ idVerified: false, clearanceChecked: false, formAccomplished: false });
    setVerification({ togaPictureVerified: false, recordsChecked: false });
    setOverrideEnabled(false);
    setOverrideReason("");
    setValidationErrors({});
    setShowChangeAccountModal(false);
    setSuccessToast("Requestor account selection cleared.");
  };

  const handleChangeAccountClick = () => {
    if (hasUnsavedSelections()) {
      setShowChangeAccountModal(true);
    } else {
      handleConfirmChangeAccount();
    }
  };

  const validateForm = () => {
    const errors = {};
    if (!selectedAccount) errors.account = "Please search and select a requestor account.";
    if (totalItemTypesCount === 0) errors.items = "Select at least one free document or certificate.";
    if (!selectedPurposeId) errors.purpose = "Select a purpose for this request.";
    if (!checklist.idVerified || !checklist.clearanceChecked || !checklist.formAccomplished) {
      errors.checklist = "Complete all three policy checklist requirements before submitting.";
    }
    if (requiresGraduateVerification() && (!verification.togaPictureVerified || !verification.recordsChecked)) {
      errors.verification = "Complete both graduate verification attestations before submitting.";
    }
    if (hasIneligibleSelection() && (!overrideEnabled || !overrideReason.trim())) {
      errors.overrideReason = "An audit explanation is required when overriding ineligible items.";
    }
    setValidationErrors(errors);
    return errors;
  };

  // Select account handler
  const handleSelectAccount = (account) => {
    setSelectedAccount(account);
    setSearchQuery("");
    setValidationErrors((prev) => ({ ...prev, account: null }));
    setSuccessToast(`Requestor account verified: ${account.full_name || account.email}`);
  };

  // Toggle item selection helper (fixed to 1 copy per selected document/certificate)
  const toggleDocumentSelection = (docId) => {
    setSelectedDocuments((prev) => {
      const exists = prev.find((item) => item.document_type_id === docId);
      if (exists) {
        return prev.filter((item) => item.document_type_id !== docId);
      } else {
        return [...prev, { document_type_id: docId, number_of_copies: 1 }];
      }
    });
    setValidationErrors((prev) => ({ ...prev, items: null }));
  };

  const toggleCertificateSelection = (certId) => {
    setSelectedCertificates((prev) => {
      const exists = prev.find((item) => item.certificate_type_id === certId);
      if (exists) {
        return prev.filter((item) => item.certificate_type_id !== certId);
      } else {
        return [...prev, { certificate_type_id: certId, number_of_copies: 1 }];
      }
    });
    setValidationErrors((prev) => ({ ...prev, items: null }));
  };

  const applyQuickBundle = (bundleTerms) => {
    const matches = (name, terms) => terms.some((term) => name.toLowerCase().includes(term));

    const docMatches = documentTypes
      .filter((doc) => isFreeEligible(doc) && matches(doc.document_name || doc.document_type_name || doc.name || "", bundleTerms))
      .map((doc) => ({ document_type_id: doc.document_type_id || doc.id, number_of_copies: 1 }));

    const certMatches = certificateTypes
      .filter((cert) => isFreeEligible(cert) && matches(cert.certificate_name || cert.certificate_type_name || cert.name || "", bundleTerms))
      .map((cert) => ({ certificate_type_id: cert.certificate_type_id || cert.id, number_of_copies: 1 }));

    if (docMatches.length > 0) setSelectedDocuments(docMatches);
    if (certMatches.length > 0) setSelectedCertificates(certMatches);
    setValidationErrors((prev) => ({ ...prev, items: null }));
    setSuccessToast("Quick access preset bundle applied.");
  };

  // Submit Handler
  const handleSubmit = async (e) => {
    if (e) e.preventDefault();
    if (submitting) return;

    const validationErrorsFound = validateForm();
    if (Object.keys(validationErrorsFound).length > 0) {
      setErrorToast(Object.values(validationErrorsFound).join(" "));
      return;
    }

    setErrorToast("");

    const payload = {
      target_user_id: selectedAccount.user_id,
      request_purpose_id: Number(selectedPurposeId),
      documents: selectedDocuments,
      certificates: selectedCertificates,
      override: overrideEnabled,
      override_reason: overrideEnabled ? overrideReason.trim() : null,
      verification: requiresGraduateVerification()
        ? {
          credentials_verified: verification.togaPictureVerified,
          records_checked: verification.recordsChecked,
        }
        : [],
    };

    storeMutation.mutate(payload);
  };



  // Filtered lists for documents and certificates based on catalogSearchQuery
  const filteredDocuments = documentTypes.filter((doc) => {
    if (!isFreeEligible(doc)) return false;
    const name = (doc.document_name || doc.document_type_name || doc.name || "").toLowerCase();
    const query = catalogSearchQuery.toLowerCase().trim();
    return !query || name.includes(query);
  });

  const filteredCertificates = certificateTypes.filter((cert) => {
    if (!isFreeEligible(cert)) return false;
    const name = (cert.certificate_name || cert.certificate_type_name || cert.name || "").toLowerCase();
    const query = catalogSearchQuery.toLowerCase().trim();
    return !query || name.includes(query);
  });

  const allFilteredCatalogItems = [
    ...filteredDocuments.map((doc) => ({
      ...doc,
      catalogKind: "document",
      itemId: doc.document_type_id || doc.id,
      itemName: doc.document_name || doc.document_type_name || doc.name || "Document",
    })),
    ...filteredCertificates.map((cert) => ({
      ...cert,
      catalogKind: "certificate",
      itemId: cert.certificate_type_id || cert.id,
      itemName: cert.certificate_name || cert.certificate_type_name || cert.name || "Certificate",
    })),
  ];

  // Unified Pagination Slice
  const totalCatalogPages = Math.max(1, Math.ceil(allFilteredCatalogItems.length / ITEMS_PER_PAGE));
  const safeCatalogPage = Math.min(catalogPage, totalCatalogPages);
  const paginatedCatalogItems = allFilteredCatalogItems.slice(
    (safeCatalogPage - 1) * ITEMS_PER_PAGE,
    safeCatalogPage * ITEMS_PER_PAGE
  );

  // Dropdown Options setup for DropdownGroup
  const purposeOptions = requestPurposes.map((p) => p.purpose_name || p.name || "");
  const selectedPurposeObj = requestPurposes.find((p) => String(p.request_purpose_id || p.purpose_id || p.id) === String(selectedPurposeId));
  const selectedPurposeName = selectedPurposeObj ? (selectedPurposeObj.purpose_name || selectedPurposeObj.name || "") : "";

  const handlePurposeDropdownChange = (e) => {
    const val = e.target.value;
    const found = requestPurposes.find((p) => (p.purpose_name || p.name) === val);
    if (found) {
      const pId = String(found.request_purpose_id || found.purpose_id || found.id);
      setSelectedPurposeId(pId);
      setValidationErrors((prev) => ({ ...prev, purpose: null }));
    }
  };

  // Step Status Calculator
  const isStep1Done = Boolean(selectedAccount);
  const isStep2Done = isStep1Done && totalItemTypesCount > 0 && Boolean(selectedPurposeId);
  const isStep3Done = isStep2Done && checklist.idVerified && checklist.clearanceChecked && checklist.formAccomplished;
  const isStep4Done = isStep3Done && (!requiresGraduateVerification() || (verification.togaPictureVerified && verification.recordsChecked));

  const cardClasses = isDark ? "bg-[#242526] border-[#3e4042] text-white" : "bg-white border-gray-200 text-gray-900";
  const subtleText = isDark ? "text-[#b0b3b8]" : "text-gray-500";

  return (
    <div className={`font-sans rounded-2xl p-4 border ${cardClasses}`}>

      {/* Main Top Header Bar */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 py-3 mb-3 border-b border-gray-100 dark:border-[#3e4042]">
        <div>
          <h2 className="text-base sm:text-lg font-bold">Free Document Requests</h2>
          <p className={`text-xs mt-0.5 max-w-xl ${subtleText}`}>
            Process eligible free-issuance documents and certifications for Students & Alumni in compliance with official University Registrar policy rules.
          </p>
        </div>
      </div>

      <div className="flex flex-col gap-4">

        {/* Stepper Navigation Progress Header */}
        <div className={`rounded-xl border p-2 shadow-sm ${isDark ? "border-[#383a40] bg-[#1a1a1c]" : "border-gray-200 bg-gray-50/50"}`}>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-1.5 sm:gap-2">
            {/* Step 1 Indicator */}
            <div className={`flex items-center gap-2 p-1.5 rounded-lg border transition-all ${selectedAccount
                ? isDark ? "bg-emerald-950/30 border-emerald-800/60" : "bg-emerald-50 border-emerald-200"
                : isDark ? "bg-[#25272c] border-[#383a40]" : "bg-white border-slate-200"
              }`}>
              <div className={`w-5 h-5 rounded-full flex items-center justify-center font-extrabold text-[10px] shrink-0 ${selectedAccount ? "bg-emerald-500 text-white" : "bg-amber-500 text-white"
                }`}>
                {selectedAccount ? <CheckIcon className="w-3 h-3 stroke-3" /> : "1"}
              </div>
              <div className="min-w-0">
                <p className="text-[11px] font-bold truncate leading-tight">1. Account</p>
                <p className={`text-[9.5px] truncate leading-tight mt-0.5 ${subtleText}`}>
                  {selectedAccount ? selectedAccount.first_name || "Selected" : "Select Student"}
                </p>
              </div>
            </div>

            {/* Step 2 Indicator */}
            <div className={`flex items-center gap-2 p-1.5 rounded-lg border transition-all ${isStep2Done
                ? isDark ? "bg-emerald-950/30 border-emerald-800/60" : "bg-emerald-50 border-emerald-200"
                : selectedAccount
                  ? isDark ? "bg-amber-950/30 border-amber-800/60" : "bg-amber-50 border-amber-200"
                  : isDark ? "bg-[#25272c] border-[#383a40] opacity-50" : "bg-white border-slate-200 opacity-50"
              }`}>
              <div className={`w-5 h-5 rounded-full flex items-center justify-center font-extrabold text-[10px] shrink-0 ${isStep2Done ? "bg-emerald-500 text-white" : selectedAccount ? "bg-amber-500 text-white" : "bg-gray-400 text-white"
                }`}>
                {isStep2Done ? <CheckIcon className="w-3 h-3 stroke-3" /> : "2"}
              </div>
              <div className="min-w-0">
                <p className="text-[11px] font-bold truncate leading-tight">2. Documents</p>
                <p className={`text-[9.5px] truncate leading-tight mt-0.5 ${subtleText}`}>
                  {totalItemTypesCount > 0 ? `${totalItemTypesCount} selected` : "Choose items"}
                </p>
              </div>
            </div>

            {/* Step 3 Indicator */}
            <div className={`flex items-center gap-2 p-1.5 rounded-lg border transition-all ${isStep3Done
                ? isDark ? "bg-emerald-950/30 border-emerald-800/60" : "bg-emerald-50 border-emerald-200"
                : isStep2Done
                  ? isDark ? "bg-amber-950/30 border-amber-800/60" : "bg-amber-50 border-amber-200"
                  : isDark ? "bg-[#25272c] border-[#383a40] opacity-50" : "bg-white border-slate-200 opacity-50"
              }`}>
              <div className={`w-5 h-5 rounded-full flex items-center justify-center font-extrabold text-[10px] shrink-0 ${isStep3Done ? "bg-emerald-500 text-white" : isStep2Done ? "bg-amber-500 text-white" : "bg-gray-400 text-white"
                }`}>
                {isStep3Done ? <CheckIcon className="w-3 h-3 stroke-3" /> : "3"}
              </div>
              <div className="min-w-0">
                <p className="text-[11px] font-bold truncate leading-tight">3. Checklist</p>
                <p className={`text-[9.5px] truncate leading-tight mt-0.5 ${subtleText}`}>
                  {isStep3Done ? "Complete" : "Policy check"}
                </p>
              </div>
            </div>

            {/* Step 4 Indicator */}
            <div className={`flex items-center gap-2 p-1.5 rounded-lg border transition-all ${isStep4Done
                ? isDark ? "bg-emerald-950/30 border-emerald-800/60" : "bg-emerald-50 border-emerald-200"
                : isStep3Done
                  ? isDark ? "bg-amber-950/30 border-amber-800/60" : "bg-amber-50 border-amber-200"
                  : isDark ? "bg-[#25272c] border-[#383a40] opacity-50" : "bg-white border-slate-200 opacity-50"
              }`}>
              <div className={`w-5 h-5 rounded-full flex items-center justify-center font-extrabold text-[10px] shrink-0 ${isStep4Done ? "bg-emerald-500 text-white" : isStep3Done ? "bg-amber-500 text-white" : "bg-gray-400 text-white"
                }`}>
                {isStep4Done ? <CheckIcon className="w-3 h-3 stroke-3" /> : "4"}
              </div>
              <div className="min-w-0">
                <p className="text-[11px] font-bold truncate leading-tight">4. Verification</p>
                <p className={`text-[9.5px] truncate leading-tight mt-0.5 ${subtleText}`}>
                  {isStep4Done ? "Ready to File" : "Final checks"}
                </p>
              </div>
            </div>
          </div>
        </div>

        {/* STEP 1: TARGET ACCOUNT SELECTION */}
        <div className={`rounded-xl border p-4 transition-all ${isDark ? "border-[#383a40] bg-[#1a1a1c]" : "border-gray-200 bg-gray-50/30"
          }`}>
          <div className="mb-3">
            <h3 className={`text-xs font-bold uppercase tracking-wide ${isDark ? "text-white" : "text-gray-900"
              }`}>
              Step 1: Select Requestor Account
            </h3>
          </div>

          {selectedAccount ? (
            <div className={`flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3 rounded-xl border ${isDark ? "bg-[#141517] border-emerald-800/60" : "bg-emerald-50/50 border-emerald-200"
              }`}>
              <div className="flex items-center gap-3">
                <div className="w-8 h-8 rounded-full bg-pup-dark-maroon text-white flex items-center justify-center font-extrabold text-xs shadow-sm shrink-0">
                  {(selectedAccount.first_name?.[0] || selectedAccount.email?.[0] || "U").toUpperCase()}
                </div>
                <div>
                  <div className="flex items-center gap-2 flex-wrap">
                    <h4 className={`font-extrabold text-xs sm:text-sm ${isDark ? "text-white" : "text-gray-900"}`}>
                      {selectedAccount.full_name || `${selectedAccount.first_name || ""} ${selectedAccount.last_name || ""}`.trim()}
                    </h4>
                    <span className={`px-1.5 py-0.5 text-[10px] font-bold rounded ${selectedAccount.role_name === "alumni"
                        ? isDark ? "bg-emerald-950/60 text-emerald-400 border border-emerald-800" : "bg-emerald-100 text-emerald-800"
                        : isDark ? "bg-blue-950/60 text-blue-400 border border-blue-800" : "bg-blue-100 text-blue-800"
                      }`}>
                      {selectedAccount.role_name ? selectedAccount.role_name.toUpperCase() : "STUDENT"}
                    </span>
                  </div>
                  <p className={`text-[11px] mt-0.5 ${subtleText}`}>
                    <span className="font-semibold">Email:</span> {selectedAccount.email} • <span className="font-semibold">ID:</span> {selectedAccount.student_number || "N/A"}
                  </p>
                </div>
              </div>
              <button
                type="button"
                onClick={handleChangeAccountClick}
                className={`self-start sm:self-center px-2.5 py-1 text-[11px] font-bold rounded-md border transition-all cursor-pointer ${isDark
                    ? "border-[#383a40] bg-[#25272c] text-gray-200 hover:bg-[#32353b] hover:text-white"
                    : "border-gray-300 bg-white text-gray-700 hover:bg-slate-50"
                  }`}
              >
                Change
              </button>
            </div>
          ) : (
            <div className="relative">
              <VoiceSearchInput
                value={searchQuery}
                onChange={setSearchQuery}
                placeholder="Type student name, email, or student ID..."
              />

              {validationErrors.account && (
                <p className="mt-1.5 text-xs font-semibold text-rose-500">{validationErrors.account}</p>
              )}

              {/* Dropdown Results */}
              {searchQuery.trim().length >= 2 && searchResults.length > 0 && (
                <div className={`absolute z-30 mt-1 w-full rounded-lg border shadow-xl overflow-hidden max-h-60 overflow-y-auto ${isDark ? "bg-[#1e1f23] border-[#383a40]" : "bg-white border-gray-200"
                  }`}>
                  <div className={`px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider border-b ${isDark ? "bg-[#141517] border-[#2e3035] text-gray-400" : "bg-slate-50 border-gray-100 text-gray-500"
                    }`}>
                    Search Results ({searchResults.length} accounts found)
                  </div>
                  {searchResults.map((account) => (
                    <button
                      key={account.user_id}
                      type="button"
                      onClick={() => handleSelectAccount(account)}
                      className={`w-full text-left px-3 py-2 flex items-center justify-between border-b last:border-0 transition-colors cursor-pointer ${isDark ? "border-[#2e3035] hover:bg-[#25272c]" : "border-gray-100 hover:bg-amber-50/50"
                        }`}
                    >
                      <div className="flex items-center gap-2.5">
                        <div className="w-7 h-7 rounded-full bg-pup-dark-maroon/20 text-pup-dark-maroon dark:text-rose-400 font-bold flex items-center justify-center text-[11px] shrink-0">
                          {(account.first_name?.[0] || account.email?.[0] || "U").toUpperCase()}
                        </div>
                        <div>
                          <p className={`text-xs font-bold ${isDark ? "text-white" : "text-gray-900"}`}>
                            {account.full_name || `${account.first_name || ""} ${account.last_name || ""}`.trim()}
                          </p>
                          <p className={`text-[10px] ${subtleText}`}>
                            {account.email} {account.student_number ? `• ID: ${account.student_number}` : ""}
                          </p>
                        </div>
                      </div>
                      <span className={`px-1.5 py-0.5 text-[10px] font-semibold rounded ${account.role_name === "alumni"
                          ? isDark ? "bg-emerald-950/60 text-emerald-400 border border-emerald-800" : "bg-emerald-50 text-emerald-700"
                          : isDark ? "bg-blue-950/60 text-blue-400 border border-blue-800" : "bg-blue-50 text-blue-700"
                        }`}>
                        {account.role_name || "student"}
                      </span>
                    </button>
                  ))}
                </div>
              )}

              {searchQuery.trim().length >= 2 && !searching && searchResults.length === 0 && (
                <div className={`absolute z-30 mt-1 w-full p-3 rounded-lg border text-center shadow-lg ${isDark ? "bg-[#1e1f23] border-[#383a40] text-gray-400" : "bg-white border-gray-200 text-gray-500"
                  }`}>
                  <p className="text-xs font-medium">No matching student or alumni accounts found.</p>
                </div>
              )}
            </div>
          )}
        </div>

        {/* STEP 2: PURPOSE & DOCUMENT SELECTION CATALOG */}
        {selectedAccount && (
          <div className={`rounded-xl border p-4 transition-all ${isDark ? "border-[#383a40] bg-[#1a1a1c]" : "border-gray-200 bg-gray-50/30"
            }`}>
            <h3 className={`text-xs font-bold uppercase tracking-wide mb-1 ${isDark ? "text-white" : "text-gray-900"
              }`}>
              Step 2: Request Purpose & Document Catalog
            </h3>
            <p className={`text-[11px] mb-4 ${subtleText}`}>
              Select the purpose of request and choose eligible document types or certifications.
            </p>

            {/* Reusable DropdownGroup for Purpose Selection */}
            <div className="mb-4">
              <DropdownGroup
                label="Purpose of Request *"
                name="purpose"
                value={selectedPurposeName}
                onChange={handlePurposeDropdownChange}
                options={purposeOptions}
                required
                labelColor={isDark ? "text-gray-300" : "text-gray-700"}
              />
              {validationErrors.purpose && (
                <p className="mt-1 text-[11px] font-semibold text-rose-500">{validationErrors.purpose}</p>
              )}
            </div>

            {/* Quick Access Preset Bundles & Search Bar (Same Row) */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4 pt-3 border-t border-gray-200 dark:border-[#383a40]">
              {/* Preset Buttons */}
              <div className="flex items-center gap-2 flex-wrap min-w-0">
                <span className={`text-[10px] font-bold uppercase tracking-wider shrink-0 ${subtleText}`}>
                  Presets:
                </span>
                <div className="flex flex-wrap gap-1.5">
                  {[
                    { label: "Board Exam", terms: ["transcript", "record", "graduation"] },
                    { label: "Employment", terms: ["good moral", "certificate"] },
                  ].map((bundle) => (
                    <button
                      key={bundle.label}
                      type="button"
                      onClick={() => applyQuickBundle(bundle.terms)}
                      className={`inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-[11px] font-bold transition-all cursor-pointer ${isDark
                          ? "border-[#383a40] bg-[#25272c] text-gray-200 hover:bg-[#32353b]"
                          : "border-gray-200 bg-slate-100 text-gray-700 hover:bg-slate-200"
                        }`}
                    >
                      <SparklesIcon className="w-3 h-3 text-gray-400" />
                      {bundle.label}
                    </button>
                  ))}
                </div>
              </div>

              {/* Catalog Search Input */}
              <div className="w-full sm:w-60 shrink-0">
                <VoiceSearchInput
                  value={catalogSearchQuery}
                  onChange={setCatalogSearchQuery}
                  placeholder="Filter items..."
                />
              </div>
            </div>



            {/* List Layout with Standard Checkbox Rows (No copy quantity stepper) */}
            <div className="space-y-4">

              {/* Unified Catalog Grid with 2 Items Per Row */}
              {allFilteredCatalogItems.length > 0 ? (
                <div>
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                    {paginatedCatalogItems.map((item) => {
                      const isDoc = item.catalogKind === "document";
                      const itemId = item.itemId;
                      const isSelected = isDoc
                        ? selectedDocuments.some((i) => i.document_type_id === itemId)
                        : selectedCertificates.some((i) => i.certificate_type_id === itemId);

                      const resultKey = isDoc ? `document_${itemId}` : `certificate_${itemId}`;
                      const evalResult = eligibilityResults[resultKey];
                      const itemName = item.itemName;
                      const CatalogIcon = getCatalogIcon(itemName);
                      const isRecommended = isBoardExamPurpose() && /transcript|record|graduation/.test(itemName.toLowerCase());

                      const limitText = item.free_issuance_limit !== null && item.free_issuance_limit !== undefined
                        ? `Free limit: ${item.free_issuance_limit} ${item.free_issuance_limit === 1 ? 'copy' : 'copies'}`
                        : "Unlimited free copy";

                      return (
                        <div
                          key={`${item.catalogKind}_${itemId}`}
                          onClick={() => isDoc ? toggleDocumentSelection(itemId) : toggleCertificateSelection(itemId)}
                          className={`w-full flex items-center justify-between p-3 rounded-lg border transition-all cursor-pointer ${isSelected
                              ? isDark
                                ? "border-amber-400 bg-amber-950/20 ring-1 ring-amber-400"
                                : "border-pup-dark-maroon bg-rose-50/20 ring-1 ring-pup-dark-maroon"
                              : isDark
                                ? "border-[#2e3035] bg-[#141517] hover:border-gray-600"
                                : "border-gray-200/80 bg-white hover:border-gray-300"
                            }`}
                        >
                          <div className="flex items-center gap-3 min-w-0">
                            <input
                              type="checkbox"
                              checked={isSelected}
                              onChange={() => { }}
                              className="w-3.5 h-3.5 rounded text-pup-dark-maroon accent-pup-dark-maroon cursor-pointer shrink-0"
                            />
                            <CatalogIcon className={`w-4 h-4 shrink-0 ${isDark ? "text-gray-400" : "text-gray-500"}`} />
                            <div className="min-w-0">
                              <p className={`font-bold text-xs ${isDark ? "text-white" : "text-gray-900"}`}>
                                {itemName}
                              </p>
                              <p className="text-[11px] text-gray-400 font-medium mt-0.5">
                                {limitText}
                              </p>
                            </div>
                          </div>

                          <div className="flex items-center gap-2 shrink-0 ml-3">
                            {isRecommended && !isSelected && (
                              <span className="text-[11px] font-semibold text-amber-700 dark:text-amber-400">
                                Recommended
                              </span>
                            )}

                            {isSelected && evalResult && !evalResult.eligible && (
                              <span className="text-[11px] font-bold text-rose-500">
                                Limit Exceeded
                              </span>
                            )}
                          </div>
                        </div>
                      );
                    })}
                  </div>

                  {/* Single Unified Catalog Pagination Footer */}
                  <div className="flex items-center justify-between mt-3 pt-2 border-t border-gray-100 dark:border-[#383a40]">
                    <p className="text-[11px] text-gray-400 font-medium">
                      Showing {Math.min((safeCatalogPage - 1) * ITEMS_PER_PAGE + 1, allFilteredCatalogItems.length)}–{Math.min(safeCatalogPage * ITEMS_PER_PAGE, allFilteredCatalogItems.length)} of {allFilteredCatalogItems.length} items
                    </p>
                    <div className="flex items-center gap-1">
                      <button
                        type="button"
                        onClick={() => setCatalogPage((p) => Math.max(1, p - 1))}
                        disabled={safeCatalogPage === 1}
                        className="w-6 h-6 rounded-full border border-gray-200 dark:border-[#383a40] flex items-center justify-center text-[11px] text-gray-500 disabled:opacity-40 cursor-pointer hover:bg-gray-50 dark:hover:bg-[#25272c]"
                      >
                        <ChevronLeftIcon className="w-3 h-3" />
                      </button>
                      {Array.from({ length: totalCatalogPages }, (_, i) => i + 1).map((p) => (
                        <button
                          key={p}
                          type="button"
                          onClick={() => setCatalogPage(p)}
                          className={`w-6 h-6 rounded-full text-[11px] font-bold transition-all cursor-pointer ${safeCatalogPage === p
                              ? isDark ? "bg-amber-400 text-black font-extrabold" : "bg-pup-dark-maroon text-white font-extrabold"
                              : "border border-gray-200 dark:border-[#383a40] text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-[#25272c]"
                            }`}
                        >
                          {p}
                        </button>
                      ))}
                      <button
                        type="button"
                        onClick={() => setCatalogPage((p) => Math.min(totalCatalogPages, p + 1))}
                        disabled={safeCatalogPage === totalCatalogPages}
                        className="w-6 h-6 rounded-full border border-gray-200 dark:border-[#383a40] flex items-center justify-center text-[11px] text-gray-500 disabled:opacity-40 cursor-pointer hover:bg-gray-50 dark:hover:bg-[#25272c]"
                      >
                        <ChevronRightIcon className="w-3 h-3" />
                      </button>
                    </div>
                  </div>
                </div>
              ) : (
                <p className="text-center text-xs text-gray-400 py-4">No matching documents or certificates found.</p>
              )}

            </div>
          </div>
        )}

        {/* STEP 3: MANDATORY COMPLIANCE CHECKLIST */}
        {selectedAccount && totalItemTypesCount > 0 && (
          <div className={`rounded-xl border p-4 transition-all ${isDark ? "border-[#383a40] bg-[#1a1a1c]" : "border-gray-200 bg-gray-50/30"
            }`}>
            <div className="flex items-center justify-between mb-1">
              <h3 className={`text-xs font-bold uppercase tracking-wide ${isDark ? "text-white" : "text-gray-900"
                }`}>
                Step 3: Mandatory Policy Checklist
              </h3>
              <button
                type="button"
                onClick={() => {
                  const allSelected = checklist.idVerified && checklist.clearanceChecked && checklist.formAccomplished;
                  setChecklist({
                    idVerified: !allSelected,
                    clearanceChecked: !allSelected,
                    formAccomplished: !allSelected,
                  });
                  setValidationErrors((prev) => ({ ...prev, checklist: null }));
                }}
                className="text-[11px] font-semibold text-pup-dark-maroon dark:text-amber-400 hover:underline cursor-pointer"
              >
                {checklist.idVerified && checklist.clearanceChecked && checklist.formAccomplished
                  ? "Unselect All"
                  : "Select All"}
              </button>
            </div>
            <p className={`text-[11px] mb-3 ${subtleText}`}>
              All three compliance requirements must be verified before confirmation.
            </p>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
              <label className={`w-full flex items-center justify-between p-3 rounded-lg border cursor-pointer transition-all ${checklist.idVerified
                  ? isDark ? "border-emerald-500 bg-emerald-950/20 ring-1 ring-emerald-500" : "border-emerald-600 bg-emerald-50/40 ring-1 ring-emerald-600"
                  : isDark ? "border-[#2e3035] bg-[#141517] hover:border-gray-600" : "border-gray-200/80 bg-white hover:border-gray-300"
                }`}>
                <div className="flex items-center gap-3 min-w-0">
                  <input
                    type="checkbox"
                    checked={checklist.idVerified}
                    onChange={(e) => {
                      setChecklist({ ...checklist, idVerified: e.target.checked });
                      setValidationErrors((prev) => ({ ...prev, checklist: null }));
                    }}
                    className="w-3.5 h-3.5 rounded text-pup-dark-maroon accent-pup-dark-maroon cursor-pointer shrink-0"
                  />
                  <div className="min-w-0">
                    <p className={`font-bold text-xs ${isDark ? "text-white" : "text-gray-900"}`}>
                      1. Valid Government / Student ID Verified
                    </p>
                    <p className="text-[11px] text-gray-400 font-medium mt-0.5">
                      Identity of the requestor or authorized representative confirmed in person or via verified portal account.
                    </p>
                  </div>
                </div>
              </label>

              <label className={`w-full flex items-center justify-between p-3 rounded-lg border cursor-pointer transition-all ${checklist.clearanceChecked
                  ? isDark ? "border-emerald-500 bg-emerald-950/20 ring-1 ring-emerald-500" : "border-emerald-600 bg-emerald-50/40 ring-1 ring-emerald-600"
                  : isDark ? "border-[#2e3035] bg-[#141517] hover:border-gray-600" : "border-gray-200/80 bg-white hover:border-gray-300"
                }`}>
                <div className="flex items-center gap-3 min-w-0">
                  <input
                    type="checkbox"
                    checked={checklist.clearanceChecked}
                    onChange={(e) => {
                      setChecklist({ ...checklist, clearanceChecked: e.target.checked });
                      setValidationErrors((prev) => ({ ...prev, checklist: null }));
                    }}
                    className="w-3.5 h-3.5 rounded text-pup-dark-maroon accent-pup-dark-maroon cursor-pointer shrink-0"
                  />
                  <div className="min-w-0">
                    <p className={`font-bold text-xs ${isDark ? "text-white" : "text-gray-900"}`}>
                      2. Student Clearance & Account Record Verification
                    </p>
                    <p className="text-[11px] text-gray-400 font-medium mt-0.5">
                      Account verified to have no active financial or registrar holds blocking document issuance.
                    </p>
                  </div>
                </div>
              </label>

              <label className={`w-full flex items-center justify-between p-3 rounded-lg border cursor-pointer transition-all ${checklist.formAccomplished
                  ? isDark ? "border-emerald-500 bg-emerald-950/20 ring-1 ring-emerald-500" : "border-emerald-600 bg-emerald-50/40 ring-1 ring-emerald-600"
                  : isDark ? "border-[#2e3035] bg-[#141517] hover:border-gray-600" : "border-gray-200/80 bg-white hover:border-gray-300"
                }`}>
                <div className="flex items-center gap-3 min-w-0">
                  <input
                    type="checkbox"
                    checked={checklist.formAccomplished}
                    onChange={(e) => {
                      setChecklist({ ...checklist, formAccomplished: e.target.checked });
                      setValidationErrors((prev) => ({ ...prev, checklist: null }));
                    }}
                    className="w-3.5 h-3.5 rounded text-pup-dark-maroon accent-pup-dark-maroon cursor-pointer shrink-0"
                  />
                  <div className="min-w-0">
                    <p className={`font-bold text-xs ${isDark ? "text-white" : "text-gray-900"}`}>
                      3. Accomplished Free Request Form / Intent Verified
                    </p>
                    <p className="text-[11px] text-gray-400 font-medium mt-0.5">
                      Official request form or board exam application proof submitted and verified by counter staff.
                    </p>
                  </div>
                </div>
              </label>
            </div>

          </div>
        )}

        {/* STEP 4: GRADUATE VERIFICATION ATTESTATION (CONDITIONAL FOR TOR / COG) */}
        {selectedAccount && requiresGraduateVerification() && (
          <div className={`rounded-xl border p-4 transition-all ${isDark ? "border-[#383a40] bg-[#1a1a1c]" : "border-gray-200 bg-gray-50/30"
            }`}>
            <div className="flex items-center justify-between mb-1">
              <h3 className={`text-xs font-bold uppercase tracking-wide ${isDark ? "text-white" : "text-gray-900"}`}>
                Step 4: Graduate Verification Attestation (COG / TOR)
              </h3>
              <button
                type="button"
                onClick={() => {
                  const allSelected = verification.togaPictureVerified && verification.recordsChecked;
                  setVerification({
                    togaPictureVerified: !allSelected,
                    recordsChecked: !allSelected,
                  });
                  setValidationErrors((prev) => ({ ...prev, verification: null }));
                }}
                className="text-[11px] font-semibold text-pup-dark-maroon dark:text-amber-400 hover:underline cursor-pointer"
              >
                {verification.togaPictureVerified && verification.recordsChecked
                  ? "Unselect All"
                  : "Select All"}
              </button>
            </div>
            <p className={`text-[11px] mb-3 ${subtleText}`}>
              In-person visual verification required for Certificate of Graduation and Transcript of Records.
            </p>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
              <label className={`w-full flex items-center justify-between p-3 rounded-lg border cursor-pointer transition-all ${verification.togaPictureVerified
                  ? isDark ? "border-emerald-500 bg-emerald-950/20 ring-1 ring-emerald-500" : "border-emerald-600 bg-emerald-50/40 ring-1 ring-emerald-600"
                  : isDark ? "border-[#2e3035] bg-[#141517] hover:border-gray-600" : "border-gray-200/80 bg-white hover:border-gray-300"
                }`}>
                <div className="flex items-center gap-3 min-w-0">
                  <input
                    type="checkbox"
                    checked={verification.togaPictureVerified}
                    onChange={(e) => {
                      setVerification({ ...verification, togaPictureVerified: e.target.checked });
                      setValidationErrors((prev) => ({ ...prev, verification: null }));
                    }}
                    className="w-3.5 h-3.5 rounded text-pup-dark-maroon accent-pup-dark-maroon cursor-pointer shrink-0"
                  />
                  <div className="min-w-0">
                    <p className={`font-bold text-xs ${isDark ? "text-white" : "text-gray-900"}`}>
                      Physically Verified 2x2 Toga Picture & Credentials
                    </p>
                    <p className="text-[11px] text-gray-400 font-medium mt-0.5">
                      Confirmed requestor presented valid 2x2 toga photo and graduation credentials at the Registrar counter.
                    </p>
                  </div>
                </div>
              </label>

              <label className={`w-full flex items-center justify-between p-3 rounded-lg border cursor-pointer transition-all ${verification.recordsChecked
                  ? isDark ? "border-emerald-500 bg-emerald-950/20 ring-1 ring-emerald-500" : "border-emerald-600 bg-emerald-50/40 ring-1 ring-emerald-600"
                  : isDark ? "border-[#2e3035] bg-[#141517] hover:border-gray-600" : "border-gray-200/80 bg-white hover:border-gray-300"
                }`}>
                <div className="flex items-center gap-3 min-w-0">
                  <input
                    type="checkbox"
                    checked={verification.recordsChecked}
                    onChange={(e) => {
                      setVerification({ ...verification, recordsChecked: e.target.checked });
                      setValidationErrors((prev) => ({ ...prev, verification: null }));
                    }}
                    className="w-3.5 h-3.5 rounded text-pup-dark-maroon accent-pup-dark-maroon cursor-pointer shrink-0"
                  />
                  <div className="min-w-0">
                    <p className={`font-bold text-xs ${isDark ? "text-white" : "text-gray-900"}`}>
                      Checked Against Paper Graduate Archive Records
                    </p>
                    <p className="text-[11px] text-gray-400 font-medium mt-0.5">
                      Verified graduation status, program, and completion year against official paper archives.
                    </p>
                  </div>
                </div>
              </label>
            </div>

          </div>
        )}

        {/* OVERRIDE CONTROL BOX (CONDITIONAL ON INELIGIBLE SELECTION) */}
        {selectedAccount && hasIneligibleSelection() && canOverride && (
          <div className={`rounded-xl border p-4 transition-all ${isDark ? "border-rose-800/60 bg-[#251818]" : "border-rose-200 bg-rose-50/50"
            }`}>
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-1.5">
                <ShieldExclamationIcon className="w-4 h-4 text-rose-500" />
                <h3 className={`text-xs font-bold uppercase tracking-wide ${isDark ? "text-white" : "text-gray-900"}`}>
                  Administrative Override Control
                </h3>
              </div>
              <label className="relative inline-flex items-center cursor-pointer">
                <input
                  type="checkbox"
                  checked={overrideEnabled}
                  onChange={(e) => {
                    setOverrideEnabled(e.target.checked);
                    setValidationErrors((prev) => ({ ...prev, overrideReason: null }));
                  }}
                  className="sr-only peer"
                />
                <div className="w-9 h-5 bg-gray-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-rose-600" />
              </label>
            </div>
            <p className={`text-[11px] mt-1 ${isDark ? "text-rose-200/80" : "text-rose-900/80"}`}>
              One or more items exceed free limits or fail rules. Authorized staff can enable override with audit logging.
            </p>

            {overrideEnabled && (
              <div className="mt-3">
                <label className={`block text-[10px] font-bold uppercase tracking-wider mb-1 ${isDark ? "text-rose-200" : "text-rose-900"}`}>
                  Mandatory Audit Trail Explanation *
                </label>
                <textarea
                  rows={2}
                  value={overrideReason}
                  onChange={(e) => {
                    setOverrideReason(e.target.value);
                    setValidationErrors((prev) => ({ ...prev, overrideReason: null }));
                  }}
                  placeholder="State the official justification for this override (e.g. Approved Registrar Exemption Memo #2026-88)..."
                  className={`w-full p-2 rounded-lg text-xs border focus:outline-none focus:ring-2 ${validationErrors.overrideReason
                      ? "border-rose-500 focus:ring-rose-500"
                      : isDark
                        ? "bg-[#141517] text-white border-rose-900/80 focus:ring-rose-500"
                        : "bg-white text-gray-900 border-rose-300 focus:ring-rose-600"
                    }`}
                />

              </div>
            )}
          </div>
        )}

        {/* COMPACT FINAL SUBMIT ACTION CARD */}
        {selectedAccount && (
          <div className={`rounded-xl border p-3.5 transition-all mt-2 flex justify-end ${isDark ? "border-[#383a40] bg-[#1a1a1c] text-white" : "border-gray-200 bg-gray-50/50 text-gray-900"
            }`}>
            <button
              type="button"
              disabled={submitting}
              onClick={handleSubmit}
              className={`px-5 py-1.5 rounded-lg text-xs font-black uppercase tracking-wider shadow-sm transition-all cursor-pointer flex items-center gap-1.5 disabled:opacity-40 disabled:cursor-not-allowed ${isDark
                  ? "bg-linear-to-r from-amber-400 to-amber-500 text-black hover:from-amber-500 hover:to-amber-600"
                  : "bg-pup-dark-maroon text-white hover:bg-[#4d0000]"
                }`}
            >
              {submitting && <ArrowPathIcon className="w-3.5 h-3.5 animate-spin" />}
              {submitting ? "Filing Request..." : "Confirm & File Request"}
            </button>
          </div>
        )}

      </div>

      {/* Toasts */}
      <SuccessToast message={successToast} onClose={() => setSuccessToast("")} />
      <ErrorToast message={errorToast} onClose={() => setErrorToast("")} />

      {/* CONFIRMATION MODAL FOR CHANGING ACCOUNT */}
      <ConfirmationModal
        isOpen={showChangeAccountModal}
        onClose={() => setShowChangeAccountModal(false)}
        onConfirm={handleConfirmChangeAccount}
        title="Change Requestor Account?"
        message="Changing the account will reset selected documents and compliance details. Are you sure you want to continue?"
        type="confirm"
        confirmText="Yes, Change"
      />
    </div>
  );
};

const FreeRequestPage = () => {
  return (
    <ModuleRoute module={MODULE_KEYS.FREE_REQUESTS}>
      <FreeRequestPageContent />
    </ModuleRoute>
  );
};

export default FreeRequestPage;
