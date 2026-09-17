import React, { useState, useEffect, useCallback, useMemo } from "react";
import { Link } from "react-router-dom";
import { useTheme } from "../context/ThemeContext";
import { getUndergradRequestorRegistrationNotice, registerUndergradRequestor, getPrograms } from "../services/api";
import LandingPage from "../layouts/LandingPage.jsx";
import InputGroup from "../components/InputGroup";
import DropDown from "../components/DropDown.jsx";
import CheckboxItem from "../components/Checkbox";
import ErrorToast from "../components/ErrorToast.jsx";
import logoImage from "../assets/puplogoimage.png";
import risLogoImg from "../assets/ris_logo.png";
import {
  AcademicCapIcon,
  UserIcon,
  BookOpenIcon,
  ShieldCheckIcon,
  CheckIcon,
  InformationCircleIcon,
  CheckCircleIcon,
} from "@heroicons/react/24/outline";

const getMaxDateOfBirth = () => {
  // Backend rule is `before:-14 years` (StoreUndergradRequestorRegistrationRequest),
  // which is a STRICT "before" — a birthdate exactly 14 years ago today fails
  // server-side validation. Subtract one extra day so the date picker can never
  // offer a value the backend will then reject.
  const d = new Date();
  d.setFullYear(d.getFullYear() - 14);
  d.setDate(d.getDate() - 1);
  return d.toISOString().split("T")[0];
};

const INITIAL_FORM_STATE = {
  email: "",
  first_name: "",
  middle_name: "",
  last_name: "",
  suffix: "",
  student_number: "",
  program: "",
  last_school_year_attended: "",
  date_of_birth: "",
  present_address: "",
  reason_for_non_enrollment: "",
  phone: "",
  data_privacy_consent: false,
};

const UndergradRequestorRegisterPage = () => {
    const isDark = false;

  const [noticeData, setNoticeData] = useState(null);
  const [noticeLoading, setNoticeLoading] = useState(true);
  const [noticeError, setNoticeError] = useState(null);

  const [form, setForm] = useState(INITIAL_FORM_STATE);
  const [errors, setErrors] = useState({});
  const [submitting, setSubmitting] = useState(false);
  const [generalError, setGeneralError] = useState("");
  const [submittedEmail, setSubmittedEmail] = useState(null);

  const [programs, setPrograms] = useState([]);

  // Fetch OGOS/GUISIS programs on mount for course dropdown
  useEffect(() => {
    let isMounted = true;
    getPrograms()
      .then((res) => {
        if (!isMounted) return;
        const data = res.data?.data || res.data || [];
        setPrograms(Array.isArray(data) ? data : []);
      })
      .catch((err) => {
        console.error("Failed to fetch programs:", err);
      });

    return () => {
      isMounted = false;
    };
  }, []);

  const courseOptions = useMemo(() => programs.map((p) => p.name).filter(Boolean), [programs]);

  /**
   * Return the full program name for a given ogos_course_id, or undefined.
   * Usage: programName(student.course_id) → "BS Information Technology"
   */
  const programName = useCallback(
    (id) => programs.find((p) => Number(p.ogos_course_id) === Number(id))?.name,
    [programs]
  );

  // Fetch registration notice & version on mount
  useEffect(() => {
    let isMounted = true;
    setNoticeLoading(true);
    getUndergradRequestorRegistrationNotice()
      .then((res) => {
        if (!isMounted) return;
        const payload = res.data?.data || res.data;
        setNoticeData(payload);
        setNoticeError(null);
      })
      .catch((err) => {
        if (!isMounted) return;
        setNoticeError("Unable to fetch the Data Privacy Notice. Consent capture is required to submit this registration.");
      })
      .finally(() => {
        if (isMounted) setNoticeLoading(false);
      });

    return () => {
      isMounted = false;
    };
  }, []);

  const handleChange = (e) => {
    const { name, value, type, checked } = e.target;
    const val = type === "checkbox" ? checked : value;

    setForm((prev) => ({ ...prev, [name]: val }));
    if (errors[name]) {
      setErrors((prev) => {
        const next = { ...prev };
        delete next[name];
        return next;
      });
    }
  };

  // Step Completion Status
  const isStep1Complete = Boolean(
    form.email.trim() &&
    form.first_name.trim() &&
    form.last_name.trim() &&
    form.phone.trim() &&
    form.date_of_birth &&
    form.student_number.trim() &&
    form.present_address.trim()
  );

  const isStep2Complete = Boolean(
    form.program.trim() &&
    form.last_school_year_attended.trim()
  );

  const isStep3Complete = Boolean(form.data_privacy_consent && noticeData?.consent_version);

  const validateClientSide = () => {
    const fieldErrors = {};

    const emailTrimmed = form.email.trim();
    if (!emailTrimmed) {
      fieldErrors.email = ["Email is required."];
    }

    if (!form.first_name.trim()) {
      fieldErrors.first_name = ["First name is required."];
    }

    if (!form.last_name.trim()) {
      fieldErrors.last_name = ["Last name is required."];
    }

    const studentNumTrimmed = form.student_number.trim();
    if (!studentNumTrimmed) {
      fieldErrors.student_number = ["Student number is required."];
    } else if (!/^[A-Za-z0-9\-]+$/.test(studentNumTrimmed)) {
      fieldErrors.student_number = ["Student number must contain letters, digits, and hyphens only."];
    }

    if (!form.program.trim()) {
      fieldErrors.program = ["Program is required."];
    }

    if (!form.last_school_year_attended.trim()) {
      fieldErrors.last_school_year_attended = ["Last school year attended is required."];
    }

    if (!form.date_of_birth) {
      fieldErrors.date_of_birth = ["Date of birth is required."];
    }

    if (!form.present_address.trim()) {
      fieldErrors.present_address = ["Present address is required."];
    }

    const phoneTrimmed = form.phone.trim();
    if (!phoneTrimmed) {
      fieldErrors.phone = ["Phone number is required."];
    } else if (!/^[0-9+\-\s()]{7,20}$/.test(phoneTrimmed)) {
      fieldErrors.phone = ["Invalid phone number format."];
    }

    if (!form.data_privacy_consent) {
      fieldErrors.data_privacy_consent = ["You must accept the Data Privacy Notice to proceed."];
    }

    return fieldErrors;
  };

  const handleSubmit = useCallback(
    async (e) => {
      e.preventDefault();
      setGeneralError("");
      setErrors({});

      if (!noticeData?.consent_version) {
        setGeneralError("Cannot submit form: Data Privacy Notice version is missing. Please refresh the page.");
        return;
      }

      const clientErrors = validateClientSide();
      if (Object.keys(clientErrors).length > 0) {
        setErrors(clientErrors);

        const sec1Fields = ["email", "phone", "first_name", "last_name", "date_of_birth", "student_number", "present_address"];
        const sec2Fields = ["program", "last_school_year_attended"];
        const sec3Fields = ["data_privacy_consent"];

        const hasSec1Error = sec1Fields.some((f) => clientErrors[f]);
        const hasSec2Error = sec2Fields.some((f) => clientErrors[f]);
        const hasSec3Error = sec3Fields.some((f) => clientErrors[f]);

        const missingSections = [];
        if (hasSec1Error) missingSections.push("1. Personal & Contact Information");
        if (hasSec2Error) missingSections.push("2. Academic Details");

        if (missingSections.length > 0) {
          let msg = `Please fill in all required fields in ${missingSections.join(" and ")}.`;
          if (hasSec3Error) {
            msg += " Also, please accept the Data Privacy Notice & Consent.";
          }
          setGeneralError(msg);
        } else if (hasSec3Error) {
          setGeneralError("Please accept the Data Privacy Notice & Consent to proceed.");
        } else {
          setGeneralError("Please resolve the highlighted validation errors.");
        }
        return;
      }

      setSubmitting(true);

      const payload = {
        email: form.email.trim(),
        first_name: form.first_name.trim(),
        middle_name: form.middle_name.trim() || null,
        last_name: form.last_name.trim(),
        suffix: form.suffix.trim() || null,
        student_number: form.student_number.trim(),
        program: form.program.trim(),
        last_school_year_attended: form.last_school_year_attended.trim(),
        date_of_birth: form.date_of_birth,
        present_address: form.present_address.trim(),
        reason_for_non_enrollment: form.reason_for_non_enrollment.trim() || null,
        phone: form.phone.trim(),
        // NOTE: consent_version is intentionally NOT sent here. The backend
        // (UndergradRequestorRegistrationService::register()) derives it itself
        // from config('undergrad_requestor.data_privacy.consent_version') — the
        // whole point of that design (see the registration-notice endpoint's
        // docblock) is that the client cannot assert which version it agreed to.
        data_privacy_consent: Boolean(form.data_privacy_consent),
      };

      try {
        await registerUndergradRequestor(payload);
        setSubmittedEmail(payload.email);
        setForm(INITIAL_FORM_STATE);
        setErrors({});
      } catch (err) {
        const status = err?.response?.status;
        const data = err?.response?.data;

        if (status === 422 && data?.errors) {
          setErrors(data.errors);
          setGeneralError(data.message || "Validation failed. Please correct the invalid fields.");
        } else if (status === 429) {
          const retryAfter = err.response?.headers?.["retry-after"] || err.response?.headers?.["Retry-After"];
          let rateMsg = data?.message || "Too many registration attempts.";
          if (retryAfter) {
            const minutes = Math.ceil(parseInt(retryAfter, 10) / 60);
            rateMsg += ` Please try again in ${minutes} minute${minutes > 1 ? "s" : ""}.`;
          }
          setGeneralError(rateMsg);
        } else {
          setGeneralError(data?.message || "Something went wrong during registration. Please try again.");
        }
      } finally {
        setSubmitting(false);
      }
    },
    [form, noticeData]
  );

  const cardClasses = isDark ? "bg-[#242526] text-white border-[#3e4042]" : "bg-white text-gray-900 border-gray-100";
  const subtleText = isDark ? "text-[#b0b3b8]" : "text-gray-500";

  return (
    <div className="relative min-h-screen w-full overflow-hidden">
      {/* Campus Background Layer */}
      <div className="fixed inset-0 z-0 pointer-events-none overflow-hidden">
        <LandingPage />
      </div>

      {/* Fullscreen Overlay with Campus Backdrop Blur */}
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 overflow-hidden bg-black/60 backdrop-blur-md">
        {submittedEmail ? (
          /* Success Screen Card (Slightly Larger, Perfectly Balanced Modal) */
          <div style={{ colorScheme: "light" }} className={`w-full max-w-md sm:max-w-lg my-auto rounded-2xl p-6 sm:p-7 shadow-2xl border text-center font-sans relative z-10 transition-all ${cardClasses}`}>
            <div className="w-14 h-14 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto mb-4 shadow-inner">
              <CheckCircleIcon className="w-8 h-8" />
            </div>

            <h2 className={`text-xl sm:text-2xl font-bold mb-3 ${isDark ? "text-white" : "text-[#800000]"}`}>
              Registration Submitted Successfully!
            </h2>

            <div
              className={`p-4 rounded-xl border text-xs sm:text-sm mb-5 ${
                isDark ? "bg-[#18191a] border-[#3e4042] text-[#e4e6eb]" : "bg-amber-50/90 border-amber-300 text-amber-950"
              }`}
            >
              <p className="font-bold text-xs sm:text-sm mb-1.5 items-center justify-center text-center text-[#800000]">
                Check your email for confirmation
              </p>
              <p className="leading-relaxed text-xs sm:text-sm text-justify">
                We sent a verification email to your email address: <span className="font-bold underline">{submittedEmail}</span>.
                You must click the confirmation link in that email before your registration can be reviewed by a Registrar Admin.
              </p>
            </div>

            <div className="flex flex-col sm:flex-row gap-3 justify-center w-full">
              <button
                type="button"
                onClick={() => setSubmittedEmail(null)}
                className={`px-4.5 py-2.5 rounded-xl font-medium text-xs sm:text-sm transition-colors cursor-pointer ${
                  isDark ? "bg-[#3a3b3c] text-white hover:bg-[#4e4f50]" : "bg-gray-100 text-gray-700 hover:bg-gray-200"
                }`}
              >
                Submit another request
              </button>
              <Link
                to="/"
                className="px-5 py-2.5 rounded-xl font-bold text-xs sm:text-sm bg-[#800000] text-white hover:bg-[#660000] transition-colors shadow-md text-center"
              >
                Return to Home
              </Link>
            </div>
          </div>
        ) : (
          /* Main Form Card Layout (Scrollable Inside Onboarding Modal) */
          <div
            style={{ colorScheme: "light" }}
            className={`w-full max-w-3xl lg:max-w-4xl rounded-2xl p-5 sm:p-7 md:p-8 shadow-2xl border text-left font-sans relative z-10 transition-all max-h-[calc(100vh-3rem)] sm:max-h-[calc(100vh-4rem)] overflow-y-auto custom-scrollbar ${cardClasses}`}
          >
            {/* Top Close / Return to Home Button */}
            <Link
              to="/"
              className="sticky top-0 float-right -mt-2 -mr-2 sm:-mt-4 sm:-mr-4 z-20 p-2 rounded-full text-gray-400 hover:text-gray-700 bg-white/80 hover:bg-gray-100 transition-colors cursor-pointer backdrop-blur-xs"
              aria-label="Close"
              title="Return to Home"
            >
              <svg className="w-6 h-6" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </Link>

            {/* Header with Centered PUP Seal */}
            <div className="text-center mb-6 pb-4 border-b border-gray-200 w-full">
              <img src={logoImage} alt="PUP Logo" className="w-16 h-16 sm:w-20 sm:h-20 mx-auto mb-3 object-contain" />
              <h2 className={`text-2xl sm:text-3xl font-extrabold tracking-tight ${isDark ? "text-white" : "text-[#800000]"}`}>
                Undergraduate Requestor Onboarding
              </h2>
              <p className={`text-xs sm:text-sm font-medium mt-1.5 max-w-xl mx-auto ${subtleText}`}>
                Enter your details to register for self-service document request access with the PUP Registrar.
              </p>
            </div>

            {generalError && <ErrorToast message={generalError} onClose={() => setGeneralError("")} />}

            <div className="flex flex-col gap-6 w-full">
              {/* Stepper Navigation Progress Header */}
              <div className={`rounded-2xl border p-3 shadow-xs ${isDark ? "border-[#383a40] bg-[#1a1a1c]" : "border-gray-200 bg-gray-50"}`}>
                <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                  {/* Step 1 Indicator */}
                  <div
                    className={`flex items-center gap-3 p-2.5 rounded-xl border transition-all ${
                      isStep1Complete
                        ? isDark ? "bg-emerald-950/30 border-emerald-800/60 text-emerald-300" : "bg-emerald-50 border-emerald-200 text-emerald-900"
                        : isDark ? "bg-[#25272c] border-[#383a40]" : "bg-white border-slate-200"
                    }`}
                  >
                    <div
                      className={`w-7 h-7 rounded-full flex items-center justify-center font-extrabold text-xs shrink-0 ${
                        isStep1Complete ? "bg-emerald-500 text-white" : "bg-[#800000] text-white"
                      }`}
                    >
                      {isStep1Complete ? <CheckIcon className="w-4 h-4 stroke-3" /> : "1"}
                    </div>
                    <div className="min-w-0">
                      <p className="text-xs font-bold truncate leading-tight">1. Personal Info</p>
                    </div>
                  </div>

                  {/* Step 2 Indicator */}
                  <div
                    className={`flex items-center gap-3 p-2.5 rounded-xl border transition-all ${
                      isStep2Complete
                        ? isDark ? "bg-emerald-950/30 border-emerald-800/60 text-emerald-300" : "bg-emerald-50 border-emerald-200 text-emerald-900"
                        : isStep1Complete
                        ? isDark ? "bg-amber-950/30 border-amber-800/60" : "bg-amber-50 border-amber-200"
                        : isDark ? "bg-[#25272c] border-[#383a40] opacity-50" : "bg-white border-slate-200 opacity-50"
                    }`}
                  >
                    <div
                      className={`w-7 h-7 rounded-full flex items-center justify-center font-extrabold text-xs shrink-0 ${
                        isStep2Complete
                          ? "bg-emerald-500 text-white"
                          : isStep1Complete
                          ? "bg-amber-500 text-white"
                          : "bg-gray-400 text-white"
                      }`}
                    >
                      {isStep2Complete ? <CheckIcon className="w-4 h-4 stroke-3" /> : "2"}
                    </div>
                    <div className="min-w-0">
                      <p className="text-xs font-bold truncate leading-tight">2. Academic Background</p>
                    </div>
                  </div>

                  {/* Step 3 Indicator */}
                  <div
                    className={`flex items-center gap-3 p-2.5 rounded-xl border transition-all ${
                      isStep3Complete
                        ? isDark ? "bg-emerald-950/30 border-emerald-800/60 text-emerald-300" : "bg-emerald-50 border-emerald-200 text-emerald-900"
                        : isStep2Complete
                        ? isDark ? "bg-amber-950/30 border-amber-800/60" : "bg-amber-50 border-amber-200"
                        : isDark ? "bg-[#25272c] border-[#383a40] opacity-50" : "bg-white border-slate-200 opacity-50"
                    }`}
                  >
                    <div
                      className={`w-7 h-7 rounded-full flex items-center justify-center font-extrabold text-xs shrink-0 ${
                        isStep3Complete
                          ? "bg-emerald-500 text-white"
                          : isStep2Complete
                          ? "bg-amber-500 text-white"
                          : "bg-gray-400 text-white"
                      }`}
                    >
                      {isStep3Complete ? <CheckIcon className="w-4 h-4 stroke-3" /> : "3"}
                    </div>
                    <div className="min-w-0">
                      <p className="text-xs font-bold truncate leading-tight">3. Privacy & Consent</p>
                    </div>
                  </div>
                </div>
              </div>

              {/* Form Content Sections */}
              <form noValidate onSubmit={handleSubmit} className="space-y-6 w-full">
                {/* SECTION 1: Personal & Contact Information */}
                <div className={`p-5 sm:p-6 rounded-2xl border ${isDark ? "bg-[#1f1f1f] border-[#3e4042]" : "bg-gray-50/80 border-gray-200"}`}>
                  <div className="flex items-center gap-2 mb-4 pb-2 border-b border-gray-200">
                    <UserIcon className="w-5 h-5 text-[#800000]" />
                    <h3 className={`text-sm font-bold uppercase tracking-wider ${isDark ? "text-[#F8BF1E]" : "text-[#800000]"}`}>
                      1. Personal & Contact Information
                    </h3>
                  </div>

                  <div className="space-y-4">
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                      <div>
                        <InputGroup
                          label="Email Address"
                          name="email"
                          type="email"
                          value={form.email}
                          onChange={handleChange}
                          required
                          voiceEnabled={false}
                          placeholder="student@example.com"
                          labelColor={isDark ? "text-[#e4e6eb]" : "text-gray-800"}
                          isDark={isDark}
                        />
                        {errors.email && (
                          <p className="text-xs text-red-500 mt-1">{errors.email[0]}</p>
                        )}
                      </div>

                      <div>
                        <InputGroup
                          label="Phone Number"
                          name="phone"
                          type="text"
                          value={form.phone}
                          onChange={handleChange}
                          required
                          voiceEnabled={false}
                          placeholder="09XX XXX XXXX"
                          labelColor={isDark ? "text-[#e4e6eb]" : "text-gray-800"}
                          isDark={isDark}
                        />
                        {errors.phone && (
                          <p className="text-xs text-red-500 mt-1">{errors.phone[0]}</p>
                        )}
                      </div>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
                      <div>
                        <InputGroup
                          label="First Name"
                          name="first_name"
                          value={form.first_name}
                          onChange={handleChange}
                          required
                          voiceEnabled={false}
                          placeholder="Juan"
                          labelColor={isDark ? "text-[#e4e6eb]" : "text-gray-800"}
                          isDark={isDark}
                        />
                        {errors.first_name && (
                          <p className="text-xs text-red-500 mt-1">{errors.first_name[0]}</p>
                        )}
                      </div>

                      <div>
                        <InputGroup
                          label="Middle Name"
                          name="middle_name"
                          value={form.middle_name}
                          onChange={handleChange}
                          voiceEnabled={false}
                          placeholder="Dela"
                          labelColor={isDark ? "text-[#e4e6eb]" : "text-gray-800"}
                          isDark={isDark}
                        />
                        {errors.middle_name && (
                          <p className="text-xs text-red-500 mt-1">{errors.middle_name[0]}</p>
                        )}
                      </div>

                      <div>
                        <InputGroup
                          label="Last Name"
                          name="last_name"
                          value={form.last_name}
                          onChange={handleChange}
                          required
                          voiceEnabled={false}
                          placeholder="Cruz"
                          labelColor={isDark ? "text-[#e4e6eb]" : "text-gray-800"}
                          isDark={isDark}
                        />
                        {errors.last_name && (
                          <p className="text-xs text-red-500 mt-1">{errors.last_name[0]}</p>
                        )}
                      </div>

                      <div>
                        <InputGroup
                          label="Suffix"
                          name="suffix"
                          value={form.suffix}
                          onChange={handleChange}
                          voiceEnabled={false}
                          placeholder="Jr., III"
                          labelColor={isDark ? "text-[#e4e6eb]" : "text-gray-800"}
                          isDark={isDark}
                        />
                        {errors.suffix && (
                          <p className="text-xs text-red-500 mt-1">{errors.suffix[0]}</p>
                        )}
                      </div>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                      <div>
                        <InputGroup
                          label="Date of Birth"
                          name="date_of_birth"
                          type="date"
                          min="1900-01-01"
                          max={getMaxDateOfBirth()}
                          value={form.date_of_birth}
                          onChange={handleChange}
                          required
                          voiceEnabled={false}
                          labelColor={isDark ? "text-[#e4e6eb]" : "text-gray-800"}
                          isDark={isDark}
                        />
                        {errors.date_of_birth && (
                          <p className="text-xs text-red-500 mt-1">{errors.date_of_birth[0]}</p>
                        )}
                      </div>

                      <div>
                        <InputGroup
                          label="Student Number"
                          name="student_number"
                          value={form.student_number}
                          onChange={handleChange}
                          required
                          voiceEnabled={false}
                          placeholder="2020-00123-TG-0"
                          labelColor={isDark ? "text-[#e4e6eb]" : "text-gray-800"}
                          isDark={isDark}
                        />
                        {errors.student_number && (
                          <p className="text-xs text-red-500 mt-1">{errors.student_number[0]}</p>
                        )}
                      </div>
                    </div>

                    <div>
                      <label className={`block text-xs font-semibold mb-1.5 ${isDark ? "text-[#e4e6eb]" : "text-gray-800"}`}>
                        Present Address <span className="text-red-500">*</span>
                      </label>
                      <textarea
                        name="present_address"
                        rows={2}
                        value={form.present_address}
                        onChange={handleChange}
                        required
                        placeholder="Complete residential address"
                        className={`w-full px-3 py-2.5 rounded-xl text-sm font-medium shadow-xs transition-all focus:outline-none focus:border-[#F8BF1E] focus:ring-2 focus:ring-[#F8BF1E]/25 ${
                          isDark
                            ? "bg-[#18191a] text-[#e4e6eb] border-[#3e4042] placeholder:text-[#8f949d]"
                            : "bg-white text-gray-800 border-gray-200 placeholder:text-gray-400"
                        }`}
                      />
                      {errors.present_address && (
                        <p className="text-xs text-red-500 mt-1">{errors.present_address[0]}</p>
                      )}
                    </div>
                  </div>
                </div>

                {/* SECTION 2: Academic Details */}
                <div className={`p-5 sm:p-6 rounded-2xl border ${isDark ? "bg-[#1f1f1f] border-[#3e4042]" : "bg-gray-50/80 border-gray-200"}`}>
                  <div className="flex items-center gap-2 mb-4 pb-2 border-b border-gray-200">
                    <BookOpenIcon className="w-5 h-5 text-[#800000]" />
                    <h3 className={`text-sm font-bold uppercase tracking-wider ${isDark ? "text-[#F8BF1E]" : "text-[#800000]"}`}>
                      2. Academic Details
                    </h3>
                  </div>

                  <div className="space-y-4">
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                      <div>
                        <DropDown
                          label="Program / Course"
                          name="program"
                          value={form.program}
                          onChange={handleChange}
                          options={courseOptions}
                          required
                          labelColor={isDark ? "text-[#e4e6eb]" : "text-gray-800"}
                          isDark={isDark}
                        />
                        {errors.program && (
                          <p className="text-xs text-red-500 mt-1">{errors.program[0]}</p>
                        )}
                      </div>

                      <div>
                        <InputGroup
                          label="Last School Year Attended"
                          name="last_school_year_attended"
                          value={form.last_school_year_attended}
                          onChange={handleChange}
                          required
                          voiceEnabled={false}
                          placeholder="e.g. 2022-2023"
                          labelColor={isDark ? "text-[#e4e6eb]" : "text-gray-800"}
                          isDark={isDark}
                        />
                        {errors.last_school_year_attended && (
                          <p className="text-xs text-red-500 mt-1">{errors.last_school_year_attended[0]}</p>
                        )}
                      </div>
                    </div>

                    <div>
                      <label className={`block text-xs font-semibold mb-1.5 ${isDark ? "text-[#e4e6eb]" : "text-gray-800"}`}>
                        Reason for Non-Enrollment (Optional)
                      </label>
                      <textarea
                        name="reason_for_non_enrollment"
                        rows={2}
                        value={form.reason_for_non_enrollment}
                        onChange={handleChange}
                        placeholder="Briefly describe your current enrollment status or reason if applicable"
                        className={`w-full px-3 py-2.5 rounded-xl text-sm font-medium shadow-xs transition-all focus:outline-none focus:border-[#F8BF1E] focus:ring-2 focus:ring-[#F8BF1E]/25 ${
                          isDark
                            ? "bg-[#18191a] text-[#e4e6eb] border-[#3e4042] placeholder:text-[#8f949d]"
                            : "bg-white text-gray-800 border-gray-200 placeholder:text-gray-400"
                        }`}
                      />
                      {errors.reason_for_non_enrollment && (
                        <p className="text-xs text-red-500 mt-1">{errors.reason_for_non_enrollment[0]}</p>
                      )}
                    </div>
                  </div>
                </div>

                {/* SECTION 3: Data Privacy Notice & Consent */}
                <div className={`p-5 sm:p-6 rounded-2xl border ${isDark ? "bg-[#1f1f1f] border-[#3e4042]" : "bg-gray-50/80 border-gray-200"}`}>
                  <div className="flex items-center gap-2 mb-4 pb-2 border-b border-gray-200">
                    <ShieldCheckIcon className="w-5 h-5 text-[#800000]" />
                    <h3 className={`text-sm font-bold uppercase tracking-wider ${isDark ? "text-[#F8BF1E]" : "text-[#800000]"}`}>
                      3. Data Privacy Notice & Consent
                    </h3>
                  </div>

                  {noticeLoading ? (
                    <div className="p-4 rounded-xl border border-gray-200 animate-pulse text-xs text-gray-500">
                      Loading Data Privacy Notice...
                    </div>
                  ) : noticeError ? (
                    <div className="p-4 rounded-xl bg-red-50 border border-red-200 text-red-700 text-xs flex items-start gap-2">
                      <InformationCircleIcon className="w-5 h-5 shrink-0 mt-0.5" />
                      <div>{noticeError}</div>
                    </div>
                  ) : (
                    <div className="space-y-4">
                      {/* Notice verbatim text container */}
                      <div
                        className={`p-4 sm:p-5 rounded-xl border max-h-60 overflow-y-auto custom-scrollbar shadow-xs ${
                          isDark ? "bg-[#18191a] border-[#3e4042]" : "bg-white border-gray-200"
                        }`}
                      >
                        {(() => {
                          const raw = noticeData?.notice;
                          if (!raw) return null;

                          let items = raw
                            .split(/(?=\b[1-9]\.\s+)/)
                            .map((s) => s.trim())
                            .filter(Boolean);

                          if (items.length <= 1) {
                            items = raw
                              .split(/(?<=\.)\s+(?=[A-Z])|\n+/)
                              .map((s) => s.trim())
                              .filter(Boolean);
                          }

                          // Strictly cap to top 4 points
                          const displayItems = items.slice(0, 4);

                          if (displayItems.length > 0) {
                            return (
                              <ol className="space-y-2.5 list-none pl-0">
                                {displayItems.map((item, idx) => {
                                  const cleanText = item.replace(/^[1-9]\.\s*/, "").trim();
                                  return (
                                    <li key={idx} className="flex items-start gap-2.5">
                                      <span className="font-extrabold text-[#800000] shrink-0 text-xs mt-0.5">
                                        {idx + 1}.
                                      </span>
                                      <span className={`text-xs leading-relaxed ${isDark ? "text-[#b0b3b8]" : "text-gray-700"}`}>
                                        {cleanText}
                                      </span>
                                    </li>
                                  );
                                })}
                              </ol>
                            );
                          }

                          return (
                            <p className={`text-xs leading-relaxed ${isDark ? "text-[#b0b3b8]" : "text-gray-700"}`}>
                              {raw}
                            </p>
                          );
                        })()}
                      </div>

                      {/* Data Retention Disclosure */}
                      {noticeData?.retention && (
                        <div className={`p-4 rounded-xl border text-xs sm:text-sm leading-relaxed ${isDark ? "bg-[#18191a] border-[#3e4042] text-[#8f949d]" : "bg-amber-50/90 border-amber-200 text-amber-950"}`}>
                          <span className="font-bold text-[#800000]">Data Privacy Act Retention Disclosure:</span> Unverified submissions are automatically deleted after {noticeData.retention.unverified_submission_days} days. Rejected registration records are retained for {noticeData.retention.rejected_record_days} days for audit compliance.
                        </div>
                      )}

                      {/* Consent checkbox */}
                      <div className="pt-2">
                        <CheckboxItem
                          id="data_privacy_consent"
                          name="data_privacy_consent"
                          checked={form.data_privacy_consent}
                          onChange={handleChange}
                          isDark={isDark}
                          textColor={isDark ? "text-[#e4e6eb]" : "text-gray-900"}
                          text={`I have read, understood, and agree to the Data Privacy Notice above (Version: ${noticeData?.consent_version || "N/A"}).`}
                        />
                        {errors.data_privacy_consent && (
                          <p className="text-xs text-red-500 mt-1">{errors.data_privacy_consent[0]}</p>
                        )}
                      </div>
                    </div>
                  )}
                </div>

                {/* Submit / Action Footer */}
                <div className="pt-3 border-t border-gray-200 flex justify-end gap-3">
                  <Link
                    to="/"
                    className={`px-5 py-2.5 rounded-xl font-medium text-sm transition-colors ${
                      isDark ? "bg-[#3a3b3c] text-white hover:bg-[#4e4f50]" : "bg-gray-100 text-gray-700 hover:bg-gray-200"
                    }`}
                  >
                    Cancel
                  </Link>
                  <button
                    type="submit"
                    disabled={submitting || noticeLoading || !!noticeError}
                    className="px-6 py-2.5 rounded-xl font-bold text-sm bg-[#800000] text-white hover:bg-[#660000] disabled:opacity-50 disabled:cursor-not-allowed transition-all shadow-md flex items-center gap-2 cursor-pointer"
                  >
                    {submitting ? (
                      <>
                        <div className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
                        <span>Submitting...</span>
                      </>
                    ) : (
                      <span>Submit Registration</span>
                    )}
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

export default UndergradRequestorRegisterPage;