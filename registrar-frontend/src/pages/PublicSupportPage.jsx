import React, { useState, useEffect, useRef, useCallback, useMemo } from "react";
import { Link } from "react-router-dom";
import {
  ChatBubbleLeftRightIcon,
  EnvelopeIcon,
  CheckCircleIcon,
  ExclamationTriangleIcon,
  ArrowPathIcon,
  PaperAirplaneIcon,
} from "@heroicons/react/24/outline";
import { getPublicTicketForm, startPublicTicket, resendPublicTicketLink } from "../services/api";
import LandingPage from "../layouts/LandingPage.jsx";
import InputGroup from "../components/InputGroup.jsx";
import DropdownGroup from "../components/DropDown.jsx";
import VoiceTextareaInput from "../components/VoiceTextareaInput.jsx";
import logoImage from "../assets/puplogoimage.png";
import {
  formatPriorityLabel,
  getPriorityBadgeClass,
  parseTicketApiError,
} from "../utils/ticketHelpers";
import { useRetryAfterCooldown } from "../hooks/useTicketLiveUpdates";

const TURNSTILE_SCRIPT_URL = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";

const PublicSupportPage = () => {
  const [activeTab, setActiveTab] = useState("submit"); // "submit" | "resend"

  const [formMeta, setFormMeta] = useState(null);
  const [loadingMeta, setLoadingMeta] = useState(true);
  const [featureOff, setFeatureOff] = useState(false);
  const [metaError, setMetaError] = useState("");

  const [form, setForm] = useState({
    name: "",
    email: "",
    category_code: "",
    subject: "",
    description: "",
    honeypot: "",
  });
  const [captchaToken, setCaptchaToken] = useState("");
  const [fieldErrors, setFieldErrors] = useState({});
  const [generalError, setGeneralError] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [submittedMessage, setSubmittedMessage] = useState("");
  const submitCooldown = useRetryAfterCooldown();

  // Resend tracking link state
  const [resendEmail, setResendEmail] = useState("");
  const [resendTicketNumber, setResendTicketNumber] = useState("");
  const [resending, setResending] = useState(false);
  const [resendMessage, setResendMessage] = useState("");
  const [resendError, setResendError] = useState("");
  const resendCooldown = useRetryAfterCooldown();

  // Turnstile refs
  const turnstileContainerRef = useRef(null);
  const turnstileWidgetIdRef = useRef(null);

  const loadFormConfig = useCallback(async () => {
    setLoadingMeta(true);
    setMetaError("");
    setFeatureOff(false);
    try {
      const res = await getPublicTicketForm();
      const data = res.data?.data || res.data || {};
      setFormMeta(data);
      const cats = data.categories || [];
      setForm((prev) => ({
        ...prev,
        category_code: prev.category_code || cats[0]?.code || "",
        honeypot: "",
      }));
    } catch (err) {
      if (err?.response?.status === 404) {
        setFeatureOff(true);
      } else {
        const parsed = await parseTicketApiError(err, "Unable to load the support form right now.");
        setMetaError(parsed.message);
      }
    } finally {
      setLoadingMeta(false);
    }
  }, []);

  useEffect(() => {
    loadFormConfig();
  }, [loadFormConfig]);

  // Mount Cloudflare Turnstile when enabled and site_key is provided
  useEffect(() => {
    if (activeTab !== "submit" || submittedMessage) return undefined;
    const captcha = formMeta?.captcha;
    if (!captcha?.enabled || !captcha?.site_key) return undefined;

    let cancelled = false;

    const renderWidget = () => {
      if (cancelled || !turnstileContainerRef.current || !window.turnstile) return;
      if (turnstileWidgetIdRef.current !== null) {
        try {
          window.turnstile.remove(turnstileWidgetIdRef.current);
        } catch {
          // ignore
        }
        turnstileWidgetIdRef.current = null;
      }
      turnstileWidgetIdRef.current = window.turnstile.render(turnstileContainerRef.current, {
        sitekey: captcha.site_key,
        theme: "light",
        callback: (token) => setCaptchaToken(token),
        "expired-callback": () => setCaptchaToken(""),
        "error-callback": () => setCaptchaToken(""),
      });
    };

    if (window.turnstile) {
      renderWidget();
    } else {
      let script = document.querySelector(`script[src="${TURNSTILE_SCRIPT_URL}"]`);
      if (!script) {
        script = document.createElement("script");
        script.src = TURNSTILE_SCRIPT_URL;
        script.async = true;
        script.defer = true;
        document.head.appendChild(script);
      }
      const onLoad = () => renderWidget();
      script.addEventListener("load", onLoad);
      return () => {
        cancelled = true;
        script.removeEventListener("load", onLoad);
      };
    }

    return () => {
      cancelled = true;
      if (turnstileWidgetIdRef.current !== null && window.turnstile) {
        try {
          window.turnstile.remove(turnstileWidgetIdRef.current);
        } catch {
          // ignore
        }
        turnstileWidgetIdRef.current = null;
      }
    };
  }, [formMeta, activeTab, submittedMessage]);

  const resetCaptcha = () => {
    setCaptchaToken("");
    if (turnstileWidgetIdRef.current !== null && window.turnstile) {
      try {
        window.turnstile.reset(turnstileWidgetIdRef.current);
      } catch {
        // ignore
      }
    }
  };

  const limits = formMeta?.limits || {
    subject_max_length: 150,
    description_min_length: 20,
    message_max_length: 5000,
    max_links_per_message: 2,
  };
  const categories = useMemo(() => formMeta?.categories || [], [formMeta]);
  const categoryOptions = useMemo(
    () =>
      categories.map((c) => ({
        code: c.code,
        label: `${c.code} — ${c.name} (${formatPriorityLabel(c.priority)} Priority)`,
      })),
    [categories]
  );
  const selectedCategory = categories.find((c) => c.code === form.category_code) || null;
  const selectedCategoryLabel =
    categoryOptions.find((o) => o.code === form.category_code)?.label || "";
  const honeypotField = formMeta?.honeypot_field || "website";

  const handleSubmit = async (e) => {
    e.preventDefault();
    setFieldErrors({});
    setGeneralError("");

    const local = {};
    if (!form.name.trim()) local.name = ["Your name is required."];
    if (!form.email.trim()) local.email = ["Your email address is required."];
    if (!form.category_code) local.category_code = ["Please select a category."];
    if (!form.subject.trim()) local.subject = ["Subject is required."];
    if (form.description.trim().length < (limits.description_min_length || 20)) {
      local.description = [
        `Please enter at least ${limits.description_min_length || 20} characters describing your concern.`,
      ];
    }
    if (formMeta?.captcha?.enabled && !captchaToken) {
      local.captcha_token = ["Please complete the security verification check."];
    }

    if (Object.keys(local).length > 0) {
      setFieldErrors(local);
      return;
    }

    setSubmitting(true);
    try {
      const payload = {
        name: form.name.trim(),
        email: form.email.trim(),
        category_code: form.category_code,
        subject: form.subject.trim(),
        description: form.description.trim(),
        form_token: formMeta?.form_token || "",
        captcha_token: captchaToken || undefined,
        [honeypotField]: form.honeypot,
      };
      const res = await startPublicTicket(payload);
      setSubmittedMessage(
        res.data?.message ||
          "We sent a confirmation link to your email address. Please open that link and confirm to create your support ticket."
      );
    } catch (err) {
      const parsed = await parseTicketApiError(err, "Unable to submit your inquiry.");
      if (parsed.status === 429) submitCooldown.startCooldown(parsed.retryAfterSeconds);
      if (Object.keys(parsed.fieldErrors).length > 0) setFieldErrors(parsed.fieldErrors);
      setGeneralError(parsed.message);
      resetCaptcha();
      // If form_token expired or tripped minimum-fill-time, issue a fresh form_token
      if (parsed.fieldErrors?.form_token) {
        loadFormConfig();
      }
    } finally {
      setSubmitting(false);
    }
  };

  const handleResendSubmit = async (e) => {
    e.preventDefault();
    setResendError("");
    setResendMessage("");

    if (!resendEmail.trim() || !resendTicketNumber.trim()) {
      setResendError("Please enter both your email address and ticket number (e.g. TKT-2026-00001).");
      return;
    }

    setResending(true);
    try {
      const res = await resendPublicTicketLink(resendEmail.trim(), resendTicketNumber.trim().toUpperCase());
      // Display the backend's 202 response message verbatim without inferring match status
      setResendMessage(
        res.data?.message ||
          "If an active public ticket matches that email and ticket number, a fresh tracking link has been sent."
      );
    } catch (err) {
      const parsed = await parseTicketApiError(err, "Unable to process request right now.");
      if (parsed.status === 429) resendCooldown.startCooldown(parsed.retryAfterSeconds);
      setResendError(parsed.message);
    } finally {
      setResending(false);
    }
  };

  return (
    <div className="relative min-h-screen w-full overflow-hidden">
      <div className="fixed inset-0 z-0 pointer-events-none overflow-hidden">
        <LandingPage />
      </div>

      <div className="fixed inset-0 z-50 flex items-center justify-center p-4 overflow-y-auto custom-scrollbar bg-black/65 backdrop-blur-md">
        <div
          style={{ colorScheme: "light" }}
          className="w-full max-w-2xl my-auto rounded-2xl bg-white text-gray-900 shadow-2xl border border-[#800000]/20 font-sans overflow-hidden relative"
        >
          {/* Header */}
          <div className="bg-[#800000] text-white px-6 py-5 border-b-4 border-[#FFD700] flex items-center justify-between gap-4">
            <div className="flex items-center gap-3.5 min-w-0">
              <img src={logoImage} alt="PUP Logo" className="w-11 h-11 shrink-0 object-contain" />
              <div className="min-w-0">
                <span className="text-[10px] sm:text-xs text-amber-200 font-bold uppercase tracking-wider block">
                  Public Support Portal
                </span>
                <h1 className="text-base sm:text-lg font-black tracking-tight uppercase truncate">
                  PUP Taguig Registrar Support Desk
                </h1>
              </div>
            </div>
            <Link
              to="/"
              className="px-3 py-1.5 rounded-lg text-xs font-bold bg-white/10 hover:bg-white/20 text-white transition-colors shrink-0"
            >
              Back to Home
            </Link>
          </div>

          <div className="p-6">
            {loadingMeta ? (
              <div className="py-12 text-center space-y-3">
                <div className="w-9 h-9 border-3 border-[#800000] border-t-transparent rounded-full animate-spin mx-auto" />
                <p className="text-xs font-semibold text-gray-500">Loading support desk…</p>
              </div>
            ) : featureOff ? (
              <div className="py-8 text-center space-y-3">
                <ExclamationTriangleIcon className="w-11 h-11 text-amber-500 mx-auto" />
                <h2 className="text-lg font-bold text-gray-900">Online Support Tickets Unavailable</h2>
                <p className="text-xs text-gray-600 max-w-md mx-auto leading-relaxed">
                  Public support ticket submission is not currently enabled. If you already have an RIS account, please
                  log in from the home page, or reach the Registrar&apos;s Office via email.
                </p>
                <div className="pt-2">
                  <Link
                    to="/"
                    className="inline-block px-5 py-2.5 rounded-xl text-xs font-bold bg-[#800000] text-white hover:bg-[#660000] transition-colors"
                  >
                    Return to Home
                  </Link>
                </div>
              </div>
            ) : metaError ? (
              <div className="py-8 text-center space-y-3">
                <ExclamationTriangleIcon className="w-10 h-10 text-rose-500 mx-auto" />
                <p className="text-sm font-semibold text-gray-800">{metaError}</p>
                <button
                  type="button"
                  onClick={loadFormConfig}
                  className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold bg-[#800000] text-white hover:bg-[#660000] cursor-pointer"
                >
                  <ArrowPathIcon className="w-4 h-4" />
                  <span>Try Again</span>
                </button>
              </div>
            ) : (
              <>
                {/* Mode Tabs */}
                <div className="flex gap-2 p-1 rounded-xl bg-gray-100 mb-5">
                  <button
                    type="button"
                    onClick={() => setActiveTab("submit")}
                    className={`flex-1 py-2 px-3 rounded-lg text-xs font-bold transition-all cursor-pointer ${
                      activeTab === "submit"
                        ? "bg-[#800000] text-white shadow-xs"
                        : "text-gray-600 hover:text-gray-900"
                    }`}
                  >
                    Submit a New Ticket
                  </button>
                  <button
                    type="button"
                    onClick={() => setActiveTab("resend")}
                    className={`flex-1 py-2 px-3 rounded-lg text-xs font-bold transition-all cursor-pointer ${
                      activeTab === "resend"
                        ? "bg-[#800000] text-white shadow-xs"
                        : "text-gray-600 hover:text-gray-900"
                    }`}
                  >
                    Resend Tracking Link
                  </button>
                </div>

                {activeTab === "submit" ? (
                  submittedMessage ? (
                    <div className="py-6 text-center space-y-4">
                      <div className="w-14 h-14 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center mx-auto">
                        <EnvelopeIcon className="w-8 h-8" />
                      </div>
                      <h2 className="text-lg font-extrabold text-[#800000]">Verify Your Email Address</h2>
                      <p className="text-xs sm:text-sm text-gray-600 max-w-md mx-auto leading-relaxed">
                        {submittedMessage}
                      </p>
                      <div className="p-3.5 rounded-xl bg-amber-50 border border-amber-200 text-amber-900 text-xs max-w-md mx-auto">
                        Your support ticket will be created as soon as you open the verification link sent to{" "}
                        <span className="font-bold">{form.email}</span> and confirm your submission.
                      </div>
                      <div className="pt-2 flex flex-wrap justify-center gap-3">
                        <button
                          type="button"
                          onClick={() => {
                            setSubmittedMessage("");
                            setForm({
                              name: "",
                              email: "",
                              category_code: categories[0]?.code || "",
                              subject: "",
                              description: "",
                              honeypot: "",
                            });
                            loadFormConfig();
                          }}
                          className="px-4 py-2 rounded-xl text-xs font-bold border border-gray-300 text-gray-700 hover:bg-gray-50 cursor-pointer"
                        >
                          Submit Another Inquiry
                        </button>
                        <Link
                          to="/"
                          className="px-5 py-2 rounded-xl text-xs font-bold bg-[#800000] text-white hover:bg-[#660000]"
                        >
                          Return to Home
                        </Link>
                      </div>
                    </div>
                  ) : (
                    <form onSubmit={handleSubmit} noValidate className="space-y-4">
                      <div className="p-3 rounded-xl bg-blue-50 border border-blue-200 text-blue-900 text-xs">
                        Already have a Student or Alumni RIS account?{" "}
                        <Link to="/" className="font-bold underline">
                          Sign in first
                        </Link>{" "}
                        to manage tickets directly from your portal without email verification.
                      </div>

                      {generalError && (
                        <div className="p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-medium">
                          {generalError}
                        </div>
                      )}

                      {/* Visually hidden honeypot — NOT type="hidden" per backend anti-bot contract */}
                      <div
                        aria-hidden="true"
                        style={{
                          position: "absolute",
                          left: "-10000px",
                          top: "auto",
                          width: "1px",
                          height: "1px",
                          overflow: "hidden",
                        }}
                      >
                        <label htmlFor={`hp-${honeypotField}`}>Leave this field blank</label>
                        <input
                          id={`hp-${honeypotField}`}
                          type="text"
                          name={honeypotField}
                          tabIndex={-1}
                          autoComplete="off"
                          value={form.honeypot}
                          onChange={(e) => setForm((prev) => ({ ...prev, honeypot: e.target.value }))}
                        />
                      </div>

                      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 items-start">
                        <InputGroup
                          label="Full Name"
                          name="name"
                          value={form.name}
                          onChange={(e) => {
                            setForm((prev) => ({ ...prev, name: e.target.value }));
                            if (fieldErrors.name) setFieldErrors((prev) => ({ ...prev, name: undefined }));
                          }}
                          placeholder="Juan Dela Cruz"
                          required
                          isDark={false}
                          labelColor="text-gray-700"
                          error={fieldErrors.name?.[0]}
                        />

                        <InputGroup
                          type="email"
                          label="Email Address"
                          name="email"
                          value={form.email}
                          onChange={(e) => {
                            setForm((prev) => ({ ...prev, email: e.target.value }));
                            if (fieldErrors.email) setFieldErrors((prev) => ({ ...prev, email: undefined }));
                          }}
                          placeholder="you@example.com"
                          required
                          voiceEnabled={false}
                          isDark={false}
                          labelColor="text-gray-700"
                          error={fieldErrors.email?.[0]}
                        />
                      </div>

                      <div>
                        <DropdownGroup
                          label="Concern Category"
                          name="category_code"
                          value={selectedCategoryLabel}
                          options={categoryOptions.map((o) => o.label)}
                          required
                          isDark={false}
                          labelColor="text-gray-700"
                          error={fieldErrors.category_code?.[0]}
                          onChange={(e) => {
                            const match = categoryOptions.find((o) => o.label === e.target.value);
                            setForm((prev) => ({ ...prev, category_code: match?.code || "" }));
                            if (fieldErrors.category_code) {
                              setFieldErrors((prev) => ({ ...prev, category_code: undefined }));
                            }
                          }}
                        />
                        {selectedCategory && (
                          <div className="mt-2 flex flex-wrap items-center justify-between gap-2">
                            <span className="text-xs text-gray-500">{selectedCategory.description || ""}</span>
                            <span
                              className={`px-2.5 py-0.5 rounded-full text-[11px] font-bold border ${getPriorityBadgeClass(
                                selectedCategory.priority,
                                false
                              )}`}
                            >
                              {formatPriorityLabel(selectedCategory.priority)} Priority
                            </span>
                          </div>
                        )}
                      </div>

                      <InputGroup
                        label="Subject"
                        name="subject"
                        value={form.subject}
                        onChange={(e) => {
                          setForm((prev) => ({
                            ...prev,
                            subject: e.target.value.slice(0, limits.subject_max_length || 150),
                          }));
                          if (fieldErrors.subject) setFieldErrors((prev) => ({ ...prev, subject: undefined }));
                        }}
                        placeholder="Brief summary of your inquiry"
                        required
                        isDark={false}
                        labelColor="text-gray-700"
                        error={fieldErrors.subject?.[0]}
                      />

                      <VoiceTextareaInput
                        id="public-ticket-description"
                        name="description"
                        label="Description"
                        labelColor="text-gray-700"
                        required
                        isDark={false}
                        minHeightClass="min-h-28"
                        maxLength={limits.message_max_length || 5000}
                        value={form.description}
                        onChange={(val) => {
                          setForm((prev) => ({ ...prev, description: val }));
                          if (fieldErrors.description) {
                            setFieldErrors((prev) => ({ ...prev, description: undefined }));
                          }
                        }}
                        placeholder="Describe your concern in detail…"
                        helperText={`Minimum ${limits.description_min_length || 20} characters, maximum ${limits.max_links_per_message || 2} links.`}
                        error={fieldErrors.description?.[0]}
                      />

                      {formMeta?.captcha?.enabled && (
                        <div>
                          {formMeta.captcha.site_key ? (
                            <div ref={turnstileContainerRef} className="min-h-16" />
                          ) : (
                            <p className="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg p-2.5">
                              Security verification is required by the server, but no CAPTCHA site key is configured.
                            </p>
                          )}
                          {fieldErrors.captcha_token && (
                            <p className="text-xs text-rose-600 mt-1">{fieldErrors.captcha_token[0]}</p>
                          )}
                        </div>
                      )}

                      <div className="flex justify-end pt-2">
                        <button
                          type="submit"
                          disabled={submitting || submitCooldown.isCoolingDown}
                          className="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl text-xs font-bold bg-[#800000] hover:bg-[#4a0000] text-[#FFD700] uppercase tracking-wider shadow transition-all disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                        >
                          <PaperAirplaneIcon className="w-4 h-4" />
                          <span>
                            {submitCooldown.isCoolingDown
                              ? `Wait ${submitCooldown.secondsLeft}s`
                              : submitting
                                ? "Sending Verification Email…"
                                : "Send Verification Link"}
                          </span>
                        </button>
                      </div>
                    </form>
                  )
                ) : (
                  /* Resend Magic Tracking Link Tab */
                  <form onSubmit={handleResendSubmit} noValidate className="space-y-4">
                    <p className="text-xs text-gray-600 leading-relaxed">
                      Lost or expired tracking link? Enter the email address used when filing your public ticket and its
                      ticket number (for example, <code className="font-bold text-[#800000]">TKT-2026-00001</code>) to
                      receive a fresh magic tracking link.
                    </p>

                    {resendError && (
                      <div className="p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-medium">
                        {resendError}
                      </div>
                    )}

                    {resendMessage && (
                      <div className="p-3.5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-900 text-xs flex items-start gap-2">
                        <CheckCircleIcon className="w-4 h-4 shrink-0 mt-0.5 text-emerald-600" />
                        <span>{resendMessage}</span>
                      </div>
                    )}

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 items-start">
                      <InputGroup
                        type="email"
                        label="Email Address"
                        name="resend_email"
                        value={resendEmail}
                        onChange={(e) => setResendEmail(e.target.value)}
                        placeholder="you@example.com"
                        required
                        voiceEnabled={false}
                        isDark={false}
                        labelColor="text-gray-700"
                      />

                      <InputGroup
                        type="text"
                        label="Ticket Number"
                        name="resend_ticket_number"
                        value={resendTicketNumber}
                        onChange={(e) => setResendTicketNumber(e.target.value.toUpperCase())}
                        placeholder="TKT-2026-00001"
                        required
                        voiceEnabled={false}
                        isDark={false}
                        labelColor="text-gray-700"
                      />
                    </div>

                    <div className="flex items-center justify-between pt-2">
                      <Link to="/support/ticket" className="text-xs font-semibold text-[#800000] hover:underline">
                        Open active session ticket
                      </Link>
                      <button
                        type="submit"
                        disabled={resending || resendCooldown.isCoolingDown}
                        className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-xs font-bold bg-[#800000] hover:bg-[#4a0000] text-[#FFD700] uppercase tracking-wider shadow transition-all disabled:opacity-50 cursor-pointer"
                      >
                        <ChatBubbleLeftRightIcon className="w-4 h-4" />
                        <span>
                          {resendCooldown.isCoolingDown
                            ? `Wait ${resendCooldown.secondsLeft}s`
                            : resending
                              ? "Sending…"
                              : "Email Tracking Link"}
                        </span>
                      </button>
                    </div>
                  </form>
                )}
              </>
            )}
          </div>
        </div>
      </div>
    </div>
  );
};

export default PublicSupportPage;
