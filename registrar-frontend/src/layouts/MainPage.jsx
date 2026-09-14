// import LineLoading from "../components/LineLoading.jsx";
import risImage from "../assets/RIS1.png";
import risLogo from "../assets/ris_logo.png";
import Tech4wardProfile from "../components/Tech4wardProfile.jsx";
import { useEffect } from 'react';
import { useNavigate, useSearchParams, useLocation } from 'react-router-dom';
import { useAuth } from '../context/AuthProvider';
import { useState } from 'react';
import LineLoading from "../components/LineLoading.jsx";
import tech4ward from "../assets/Tech4ward_Logo.png";
import LandingPage from "../layouts/LandingPage.jsx";
import logoImage from "../assets/puplogoimage.png";

const ROLE_HOME = {
  student: "/student",
  alumni: "/alumni",
  admin: "/staff",
  super_admin: "/super-admin",
};

const MainPage = () => {
  const [loading, setLoading] = useState(false);
  const navigate = useNavigate();
  const location = useLocation();
  const [searchParams] = useSearchParams();
  const { user, loading: authLoading } = useAuth();

  // If the user already has a valid session, send them straight to their dashboard.
  //
  // Guard against the loop where ROLE_HOME has no module the user's policy
  // allows: ForbiddenPage -> "Go to Home" -> here -> ROLE_HOME -> policy
  // check fails -> ForbiddenPage again, forever. When we've arrived here
  // *from* /forbidden with a "policy" reason, don't immediately re-navigate
  // away — let the user actually land on the home page and use its
  // logout/help options instead.
  const cameFromPolicyForbidden = location.state?.fromForbidden === 'policy';

  useEffect(() => {
    if (!user) return;
    if (cameFromPolicyForbidden) return;
    const destination = ROLE_HOME[user.role_name];
    if (destination) navigate(destination, { replace: true });
  }, [user, navigate, cameFromPolicyForbidden]);

  // Auto-SSO: when the portal links to us with ?sso=1 (or ?auto_login=1),
  // skip showing the login page entirely and bounce straight to the IDP.
  // The IDP already has the user's session from the portal, so it will
  // immediately redirect back to /auth/callback?code=... with no interaction.
  // We wait until AuthProvider has finished its /me check first — if the user
  // already has a cookie session, the effect above will redirect them before
  // this one fires.
  useEffect(() => {
    if (authLoading) return;         // wait for session check to complete
    if (user) return;                // already handled above
    const autoLogin = searchParams.get('sso') === '1' || searchParams.get('auto_login') === '1';
    if (!autoLogin) return;

    setLoading(true);
    setTimeout(() => {
      window.location.href = import.meta.env.VITE_SSO_LOGIN_URL;
    }, 0);
  }, [authLoading, user, searchParams]);

  const handleSsoLogin = () => {
    setLoading(true);
    // Defer the hard-navigation so React's synthetic event handling and
    // the Router's location subscriptions finish before the document is
    // replaced.  Without this, Chromium logs:
    //   "Prevented /auth/callback from accessing QueryParameters"
    setTimeout(() => {
      window.location.href = import.meta.env.VITE_SSO_LOGIN_URL;
    }, 0);
  };
  return (
    <div className="relative min-h-screen w-full overflow-x-hidden bg-white">
      <LineLoading isVisible={loading} />
      <LandingPage />
      <div className="relative min-h-screen w-full overflow-x-hidden bg-white z-30">
        <Tech4wardProfile bgImage={risImage} />
      </div>
      <div id="about" className="w-full relative z-40 bg-gray-50/50 py-20 border-t border-gray-100 scroll-mt-28">
        <div className="max-w-5xl mx-auto px-6 flex flex-col md:flex-row items-start justify-start gap-12 md:gap-16 text-[#800000]">
          {/* Logo container with hover effects */}
          <div className="shrink-0 relative group">
            <div className="absolute inset-0 bg-yellow-400/10 rounded-full blur-2xl opacity-0 group-hover:opacity-100 transition-opacity duration-500" />
            <img
              src={tech4ward}
              alt="Tech4ward Logo"
              width="224"
              height="224"
              className="relative z-10 w-44 md:w-52 h-44 md:h-52 object-contain drop-shadow-2xl transition-all duration-700 ease-out group-hover:scale-108 group-hover:rotate-3"
            />
          </div>

          {/* Text block */}
          <div className="grow text-left relative">
            <div className="text-[11px] font-bold uppercase tracking-[0.25em] text-[#F8BF1E] mb-3">
              Developer Team
            </div>

            <h2 className="text-4xl md:text-5xl font-black text-[#800000] uppercase leading-none tracking-tight mb-5 font-sans">
              Together, We <br className="hidden md:block" />
              <span>Think </span>
              <span className="text-[#F8BF1E]">Forward</span>
            </h2>

            <div className="w-20 h-1.5 bg-[#F8BF1E] mb-6 rounded-full mx-0 shadow-sm" />

            <p className="max-w-2xl text-gray-600 text-sm md:text-base leading-relaxed font-light">
              Tech4ward is a dynamic team of four BSIT students from PUP–Taguig,
              united by a shared passion for technology and innovation. Together,
              we aim to create practical, forward-thinking solutions that address
              real-world problems and showcase our growing expertise in the field
              of information technology.
            </p>
          </div>
        </div>
      </div>
      <footer className="relative w-full bg-[#660000] border-t-4 border-yellow-400 text-white font-inter z-40">
        <div className="max-w-5xl mx-auto py-10 px-6">
          <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-8">
            {/* Column 1: Brand details */}
            <div className="flex flex-col items-start text-left">
              <div className="flex items-center gap-3 mb-3">
                <img src={logoImage} alt="PUP Logo" className="w-9 h-9 select-none" />
                <span className="text-sm font-bold tracking-wider text-white uppercase leading-none">
                  PUP Taguig
                </span>
              </div>
              <p className="text-[11px] text-gray-300 leading-relaxed mb-4">
                Registrar Information System. Providing high-quality administrative support for students, faculty, and alumni.
              </p>
              {/* Social Icons */}
              <div className="flex gap-4">
                <a
                  href="https://www.facebook.com/profile.php?id=61592440295541"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="text-gray-300 hover:text-yellow-400 transition-colors"
                  title="Facebook Page"
                >
                  <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                    <path fillRule="evenodd" d="M22 12c0-5.523-4.477-10-10-10S2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.878v-6.987h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.988C18.343 21.128 22 16.991 22 12z" clipRule="evenodd" />
                  </svg>
                </a>
                <a
                  href="mailto:tech4ward.bsit2027@gmail.com"
                  className="text-gray-300 hover:text-yellow-400 transition-colors"
                  title="Email Support"
                >
                  <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M1.5 8.67v8.58a3 3 0 003 3h15a3 3 0 003-3V8.67l-8.928 5.493a3 3 0 01-3.144 0L1.5 8.67z" />
                    <path d="M22.5 6.908V6.75a3 3 0 00-3-3h-15a3 3 0 00-3 3v.158l9.714 5.978a1.5 1.5 0 001.572 0L22.5 6.908z" />
                  </svg>
                </a>
              </div>
            </div>

            {/* Column 2: Quick Links */}
            <div className="flex flex-col items-start text-left">
              <h4 className="text-xs font-bold uppercase tracking-widest text-[#F8BF1E] mb-3">
                Quick Links
              </h4>
              <ul className="space-y-2 text-[11px] text-gray-300">
                {["hero", "announcements", "about us"].map((id) => (
                  <li key={id}>
                    {id === "features" ? (
                      <span className="capitalize text-gray-300 select-none">
                        {id}
                      </span>
                    ) : (
                      <button
                        onClick={() => {
                          const el = document.getElementById(id);
                          if (el) el.scrollIntoView({ behavior: "smooth" });
                        }}
                        className="hover:text-yellow-400 transition-colors cursor-pointer capitalize text-left"
                      >
                        {id === "hero" ? "Home" : id}
                      </button>
                    )}
                  </li>
                ))}
              </ul>
            </div>

            {/* Column 3: Features */}
            <div className="flex flex-col items-start text-left">
              <h4 className="text-xs font-bold uppercase tracking-widest text-[#F8BF1E] mb-3">
                Features
              </h4>
              <ul className="space-y-2 text-[11px] text-gray-300 select-none">
                <li>Online Requesting</li>
                <li>Real-time Tracking</li>
                <li>Digital Approvals</li>
                <li>Alumni Registry</li>
              </ul>
            </div>

            {/* Column 4: Contact Us */}
            <div className="flex flex-col items-start text-left">
              <h4 className="text-xs font-bold uppercase tracking-widest text-[#F8BF1E] mb-3">
                Contact Us
              </h4>
              <ul className="space-y-3 text-[11px] text-gray-300">
                <li className="flex items-start gap-2.5">
                  <svg className="w-4 h-4 text-[#F8BF1E] shrink-0 mt-0.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                  </svg>
                  <span>Gen. Santos Ave. Lower Bicutan, Taguig, Philippines, 1630</span>
                </li>
                <li className="flex items-center gap-2.5">
                  <svg className="w-4 h-4 text-[#F8BF1E] shrink-0" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                  </svg>
                  <a href="mailto:tech4ward.bsit2027@gmail.com" className="hover:text-yellow-400 transition-colors break-all">
                    tech4ward.bsit2027@gmail.com
                  </a>
                </li>
                <li className="flex items-center gap-2.5">
                  <svg className="w-4 h-4 text-[#F8BF1E] shrink-0" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-2.824-1.47-5.112-3.758-6.58-6.58l1.293-.97c.362-.271.527-.834.417-1.173L6.111 2.22a1.091 1.091 0 0 0-1.091-.852H3.75A2.25 2.25 0 0 0 1.5 3.75v2.25Z" />
                  </svg>
                  <a href="tel:+639380192649" className="hover:text-yellow-400 transition-colors">
                    +63 938 019 2649
                  </a>
                </li>
              </ul>
            </div>
          </div>
        </div>

        {/* Bottom Bar */}
        <div className="w-full bg-[#4a0000] py-4 px-6 border-t border-white/5">
          <div className="max-w-5xl mx-auto flex flex-col md:flex-row justify-between items-center gap-3 text-[9px] text-gray-300">
            <div className="text-center md:text-left">
              © 1998–{new Date().getFullYear()} Polytechnic University of the Philippines. All Rights Reserved.
            </div>
            <div className="flex flex-wrap justify-center gap-3">
              <a href="https://www.pup.edu.ph/terms/" target="_blank" rel="noopener noreferrer" className="hover:text-yellow-400 text-gray-300 transition-colors">
                Terms of Use
              </a>
              <span className="text-white/10">|</span>
              <a href="https://www.pup.edu.ph/privacy/" target="_blank" rel="noopener noreferrer" className="hover:text-yellow-400 text-gray-300 transition-colors">
                Privacy Statement
              </a>
            </div>
          </div>
        </div>
      </footer>
    </div>
  );
};

export default MainPage;