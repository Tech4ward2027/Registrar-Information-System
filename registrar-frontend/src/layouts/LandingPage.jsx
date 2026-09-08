import { useState, useEffect } from "react";
import { useNavigate } from "react-router-dom";
import { useAuth } from "../context/AuthProvider";
import risImage  from "../assets/RIS1.png";
import logoImage from "../assets/puplogoimage.png";
import risLogoImg from "../assets/ris_logo.png";
import { EyeIcon, EyeSlashIcon } from "@heroicons/react/24/solid";
import LineLoading from "../components/LineLoading.jsx";

const SSO_LOGIN_URL = import.meta.env.VITE_SSO_LOGIN_URL;
const GMAIL_COMPOSE_URL = "https://mail.google.com/mail/?view=cm&fs=1&to=tech4ward.bsit2027@gmail.com";

const NAV_ITEMS = [
  { id: "hero", label: "Home" },
  { id: "announcements", label: "Announcements" },
  { id: "about", label: "About Us" },
];

const LandingPage = () => {
  const navigate = useNavigate();
  const { user, login, localLogin } = useAuth();

  const [email, setEmail]           = useState("");
  const [password, setPassword]     = useState("");
  const [loading, setLoading]       = useState(false);
  const [loginError, setLoginError] = useState(null);
  const [showModal, setShowModal]   = useState(false);
  // null | "idp-fallback" | "no-local-auth"
  const [authMode, setAuthMode]     = useState(null);

  // Redirect if already logged in
  useEffect(() => {
    if (!user) return;
    const destination = {
      student:     "/student",
      alumni:      "/alumni",
      admin:       "/staff",
      super_admin: "/super-admin",
    }[user.role_name];
    if (destination) navigate(destination, { replace: true });
  }, [user, navigate]);

  // Prevent background scrolling when modal is open
  useEffect(() => {
    if (showModal) {
      document.body.style.overflow = "hidden";
    } else {
      document.body.style.overflow = "";
    }
    return () => {
      document.body.style.overflow = "";
    };
  }, [showModal]);

  // Hide browser scrollbar on landing page
  useEffect(() => {
    document.documentElement.classList.add("no-scrollbar");
    document.body.classList.add("no-scrollbar");
    return () => {
      document.documentElement.classList.remove("no-scrollbar");
      document.body.classList.remove("no-scrollbar");
    };
  }, []);

  // ── IDP-first Sign In ────────────────────────────────────────────────────
  const handleLogin = async (e) => {
    e.preventDefault();
    // client-side: show one validation error at a time
    setLoginError(null);
    if (!email) {
      setLoginError("Email is required.");
      return;
    }
    if (!password) {
      setLoginError("Password is required.");
      return;
    }
    setLoading(true);
    setAuthMode(null);
    try {
      await login(email, password);
      // login() navigates on success — if we reach here, it threw.
    } catch (err) {
      const data   = err.response?.data;
      const status = err.response?.status;

      if (data?.idp_offline) {
        setLoginError("The identity provider is currently unavailable and this account does not have local login enabled. Please contact the registrar.");
        setAuthMode("no-local-auth");
      } else if (status === 403) {
        setLoginError(data?.message || "Your account is not authorised to access RIS.");
      } else {
        setLoginError(data?.message || "Login failed. Please check your credentials.");
      }
    } finally {
      setLoading(false);
    }
  };

  // ── Explicit local-only login ────────────────────────────────────────────
  const handleLocalLogin = async (e) => {
    e.preventDefault();
    setLoginError(null);
    if (!email) {
      setLoginError("Email is required.");
      return;
    }
    if (!password) {
      setLoginError("Password is required.");
      return;
    }
    setLoading(true);
    setAuthMode(null);
    try {
      await localLogin(email, password);
    } catch (err) {
      const data = err.response?.data;
      setLoginError(data?.message || "Local login failed.");
    } finally {
      setLoading(false);
    }
  };

  const openModal  = () => { setLoginError(null); setShowModal(true); };
  const closeModal = () => setShowModal(false);

  const scrollTo = (id) => {
    const element = document.getElementById(id);
    if (element) {
      element.scrollIntoView({ behavior: "smooth" });
    }
  };

  return (
    <div className="lp-root">
      <LineLoading isVisible={loading} />

      {/* ── NAVBAR ── */}
      <header
        className="w-full shadow-sm fixed top-0 left-0 right-0 border-b-4 border-yellow-400 transition-all duration-200"
        style={{ backgroundColor: "#660000", zIndex: 9999 }}
      >
      <div className="w-full px-4 py-4 z-9999 flex justify-between items-center h-full">
        <div className="flex space-x-4 items-center">
          <img
            src={logoImage}
            alt="PUP Logo"
            className="w-16 h-16 lg:w-20 lg:h-20 drop-shadow-lg dark:drop-shadow-2xl transition-all duration-200"
          />
          <div className="flex flex-col justify-center grow">
            <h1 className="text-white dark:text-white font-semibold text-[13px] lg:text-[22px] leading-tight font-lucida tracking-wider">
              Polytechnic University of the Philippines
            </h1>
            <p className="text-[#e6e0e0] dark:text-gray-300 text-[9px] lg:text-[13px] font-lucida tracking-widest mt-0.5">
              The Country's 1st PolytechnicU
            </p>
          </div>
        </div>

          {/* Right: nav links + sign in — hidden on mobile */}
          <div className="hidden md:relative md:flex items-center space-x-2 lg:space-x-3">
            <div className="hidden md:flex items-center gap-1">
              {NAV_ITEMS.map(({ id, label }) => (
                <button
                  key={id}
                  onClick={() => scrollTo(id)}
                  className="px-3 py-2 text-white text-sm font-medium rounded-md hover:bg-white/10 transition-colors font-inter cursor-pointer"
                >
                  {label}
                </button>
              ))}
            </div>

            <button
              onClick={openModal}
              className="px-6 py-2.5 text-sm font-semibold rounded-lg border border-yellow-400 text-yellow-400 hover:bg-yellow-400 hover:text-[#660000] active:scale-95 transition-all font-inter cursor-pointer"
            >
              Sign In
            </button>
          </div>
        </div>
      </header>

      {/* ── HERO ── */}
      <section id="hero" className="lp-hero h-screen">
        {/* Campus background photo */}
        <div className="lp-hero-bg-container">
          <img src={risImage} alt="PUP Campus" className="lp-hero-bg-img" />
          <div className="lp-hero-bg-overlay" />
        </div>

        {/* Glow blobs */}
        <div className="lp-glow lp-glow--1" />
        <div className="lp-glow lp-glow--2" />

        {/* Split layout */}
        <div className="lp-hero-split">
          {/* LEFT: text content */}
          <div className="lp-hero-left">
            <h1 className="lp-hero-title">
              <span className="text-[#F8BF1E]">R</span>egistrar
              <br />
              <span className="text-[#F8BF1E]">I</span>nformation
              <br />
              <span className="text-[#F8BF1E]">S</span>ystem
            </h1>
            <p className="lp-hero-subtitle font-light">
              Academic Request. Redefined Simplicity.
            </p>
            {/* Yellow line */}
            <div className="w-48 h-2.5 bg-[#F8BF1E] my-8 rounded-full shadow-lg" />
            
            <div className="lp-hero-btns">
              <a href={SSO_LOGIN_URL} className="lp-btn-primary">
                Log in with IDP
              </a>
              <button className="lp-btn-outline" onClick={openModal}>
                Sign In Locally
              </button>
            </div>
            
            {/* Clickable icons under buttons */}
            <div className="lp-hero-contact-links">
              <a href={GMAIL_COMPOSE_URL} target="_blank" rel="noopener noreferrer" className="lp-hero-contact-link" title="Email: tech4ward.bsit2027@gmail.com">
                <svg className="w-6 h-6" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                </svg>
              </a>
              <a href="https://www.facebook.com/profile.php?id=61592440295541" target="_blank" rel="noopener noreferrer" className="lp-hero-contact-link" title="Facebook: PUP Taguig Official">
                <svg className="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                  <path fillRule="evenodd" d="M22 12c0-5.523-4.477-10-10-10S2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.878v-6.987h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.988C18.343 21.128 22 16.991 22 12z" clipRule="evenodd" />
                </svg>
              </a>
            </div>
          </div>

          {/* RIGHT: registrar logo */}
          <div className="lp-hero-right">
            <img src={risLogoImg} alt="Registrar Logo" className="lp-hero-logo" />
          </div>
        </div>

        {/* Semi-visible scroll down indicator */}
        <button 
          className="lp-scroll-down-btn" 
          onClick={() => scrollTo("announcements")}
          aria-label="Scroll to Announcements"
        >
          <span className="lp-scroll-down-text">Scroll Down</span>
          <svg className="lp-scroll-down-icon" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
          </svg>
        </button>
      </section>

      {/* ── LOCAL LOGIN MODAL ── */}
      {showModal && (
        <div className="lp-modal-overlay" onClick={closeModal}>
          <div className="lp-modal" style={{ colorScheme: "light" }} onClick={(e) => e.stopPropagation()}>
            <button className="lp-modal-close" onClick={closeModal} aria-label="Close">
              <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>

            <img src={logoImage} alt="PUP Logo" className="lp-modal-logo" />
            <h2 className="lp-modal-title">Welcome Back!</h2>
            <p className="lp-modal-subtitle font-medium">Enter your credentials to access your account.</p>

            {/* IDP offline advisory banner */}
            {authMode === "idp-fallback" && (
              <div className="w-full mb-4 px-4 py-3 rounded-lg bg-amber-50 border border-amber-300 text-amber-800 text-xs flex items-start gap-2 text-left">
                <span className="mt-0.5 shrink-0">⚠️</span>
                <span>
                  The identity provider is currently unreachable. You are logged in
                  using your local credentials. Some IDP-dependent features may be
                  limited.
                </span>
              </div>
            )}

            {/* Error message */}
            {loginError && (
              <div className="lp-modal-error">{loginError}</div>
            )}

            {/* Main login form (IDP-first) */}
            <form onSubmit={handleLogin} className="lp-modal-form">
              <InputField
                type="email"
                placeholder="Email Address"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
              />
              <InputField
                type="password"
                placeholder="Password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
              />
              <div className="mt-3 space-y-3 w-full">
                <button
                  type="button"
                  onClick={handleLocalLogin}
                  disabled={loading}
                  className="lp-modal-submit"
                >
                  {loading ? "Signing in…" : "Sign In Locally"}
                </button>
                <p className="text-center text-gray-400 text-[11px] leading-snug">
                  Local login uses your RIS password directly — no IDP required.
                  Only available if your account has local auth enabled.
                </p>
              </div>
            </form>

            <div className="lp-modal-or"><span>OR</span></div>

            {/* IDP SSO button */}
            <a
              href={SSO_LOGIN_URL}
              className="lp-modal-sso"
            >
              Log in with IDP
            </a>

            <div className="mt-5 text-[10px] text-gray-400 leading-tight text-center">
              <p>
                By using this service, you agree to the PUP Online Services{" "}
                <a
                  href="https://www.pup.edu.ph/terms/"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="text-blue-500 hover:underline mx-1"
                >
                  Terms of Use
                </a>
                and
                <a
                  href="https://www.pup.edu.ph/privacy/"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="text-blue-500 hover:underline mx-1"
                >
                  Privacy Statement
                </a>
                .
              </p>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

const InputField = ({ type, placeholder, value, onChange }) => {
  const [showPassword, setShowPassword] = useState(false);
  const isPassword = type === "password";
  const inputType  = isPassword ? (showPassword ? "text" : "password") : type;

  return (
    <div className="w-full relative group">
      <input
        type={inputType}
        placeholder={placeholder}
        value={value}
        onChange={onChange}
        className="w-full p-3.5 bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-[#eebc48] focus:bg-white transition-all text-sm text-gray-700 placeholder-gray-400 shadow-sm pr-11"
      />
      {isPassword && (
        <button
          type="button"
          onClick={() => setShowPassword((p) => !p)}
          className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition lp-input-eye"
          tabIndex={-1}
        >
          {showPassword ? <EyeIcon className="w-5 h-5" /> : <EyeSlashIcon className="w-5 h-5" />}
        </button>
      )}
    </div>
  );
};

export default LandingPage;
