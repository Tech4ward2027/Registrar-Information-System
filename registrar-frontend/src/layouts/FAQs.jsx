import React, { useState, useCallback, useEffect, useMemo, useRef } from 'react';
import {
  ArrowTopRightOnSquareIcon,
  CheckIcon,
  ChevronDownIcon,
  ClipboardDocumentCheckIcon,
  ClipboardDocumentIcon,
  EnvelopeIcon,
  ExclamationTriangleIcon,
  MagnifyingGlassIcon,
  PhoneArrowUpRightIcon,
  XCircleIcon,
} from '@heroicons/react/24/outline';
import { CheckCircleIcon as CheckCircleSolid } from '@heroicons/react/24/solid';
import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthProvider';
import VoiceSearchInput from '../components/VoiceSearchInput.jsx';
import logoImage from '../assets/puplogoimage.png';
import risImage from '../assets/RIS1.png';
import { useTheme } from '../context/ThemeContext';

/* -------------------------------------------------------------------------- */
/*  CONTENT (Policy: Comprehensive FAQs & Visual Step-by-Step User Guide v1.0)  */
/* -------------------------------------------------------------------------- */

const FB_RIS_URL = 'https://www.facebook.com/profile.php?id=61592440295541';
const FB_PUPTAPS_URL = 'https://www.facebook.com/profile.php?id=61573613361533';

const QUICK_TAGS = [
  'OR not found',
  'Cannot log in',
  'Claim ticket',
  'Processing time',
  'Representative',
  'Non-SIS alumni',
];

const CONCERNS = [
  {
    id: 'access',
    icon: ExclamationTriangleIcon,
    title: "I can't access the system",
    summary:
      'Your account is blocked until the prerequisites for your requestor type are done: GUISIS IIR form, PUPTAPS pre-loading, admin approval, or undergraduate onboarding.',
    cta: 'Run the access checklist',
    target: 'diagnostic',
  },
  {
    id: 'or',
    icon: XCircleIcon,
    title: 'My Official Receipt was rejected',
    summary:
      'The Cashier API could not match your receipt. Usual causes are a different name format, a missing middle initial, spacing, or a typo in the digits.',
    cta: 'Fix the receipt error',
    target: 'or-resolver',
  },
  {
    id: 'support',
    icon: PhoneArrowUpRightIcon,
    title: 'I need to follow up or ask for help',
    summary:
      'Check on a request, report a portal glitch, or ask about a special archival record.',
    cta: 'See who to contact',
    target: 'directory',
  },
];

/* ---- Shared walkthrough steps (identical across requestor types) ---- */

const STEP_ACCESS_RIS = {
  title: 'Access RIS and open the Request Form',
  items: [
    'Go to the Registrar Information System (RIS) at pupt-ris.registrar-information-system-bsit2027.com',
    'Click Log in with IDP and enter your One Portal (IdP) credentials.',
    "Make sure payment is already completed at the Cashier's Office.",
    'Open the Request Form.',
  ],
};

const STEP_SUBMIT = {
  title: 'Submit your request and save the Claim Ticket',
  items: [
    'Stage 1 (Terms): Read and accept the Terms & Conditions and the Data Privacy Consent under RA 10173.',
    'Stage 2 (Receipt): Enter your exact 7-digit Official Receipt (OR) number and the date of payment.',
    'Stage 3 (Documents): Check the documents auto-selected from your paid receipt, set the number of copies, and choose the purpose of request.',
    'Stage 4 (Review): Review the summary, then click Submit.',
    'Save or screenshot your Claim Ticket (QR Code and Claim Code).',
  ],
};

const STEP_TRACK = {
  title: 'Track progress and claim your request',
  items: [
    'Open your Dashboard to see the live status: Pending, Processing, Pending Signature, or Ready to Claim.',
    'Check Notifications for updates or submission concerns.',
    "When the status is Ready to Claim, visit the Registrar's Office and present your Claim QR Code or Claim Code together with your physical OR.",
  ],
};

const stepIdp = (role, emailNote) => ({
  title: 'Register and log in to One Portal (IdP)',
  items: [
    'Open One Portal at identity-provider.isaxbsit2027.com and click Register.',
    emailNote ||
      'Enter your First Name, Middle Name, Suffix, Last Name, and Email Address.',
    `Under Select Your Role, select ${role} only. Any other role will lock you out of RIS.`,
    'Click Continue, then sign in with your IdP credentials.',
  ],
});

/* ---- The four requestor groups ---- */

const ROLES = [
  {
    id: 'student',
    label: 'Enrolled student',
    banner:
      'Active students MUST complete their GUISIS Individual Inventory Record (IIR) Form and activate their PUP Central IdP account before the system unlocks document requests.',
    checklist: [
      'PUP Central IdP (One Portal) account registered and activated with the Student role',
      'GUISIS Individual Inventory Record (IIR) Form completed and submitted',
      'Official Receipt (OR) from the Cashier\u2019s Office with paid document fees',
    ],
    steps: [
      stepIdp('student'),
      {
        title: 'Open GUISIS and complete the IIR Form',
        items: [
          'Go to GUISIS at guisis.dllbsit2027.com and click Login.',
          'Sign in with your account credentials.',
          'Open My IIR Profile, then fill out and submit the Individual Inventory Record (IIR) Form. This is a mandatory prerequisite.',
        ],
      },
      STEP_ACCESS_RIS,
      STEP_SUBMIT,
      STEP_TRACK,
    ],
    diag: {
      checks: [
        'GUISIS IIR form is submitted',
        'IdP account is active with the Student role',
      ],
      fix: 'Complete the IIR Form in GUISIS, log out of RIS, then log in again to reload.',
    },
  },
  {
    id: 'alumni-sis',
    label: 'Alumni (SIS)',
    banner:
      'Your graduation record must be pre-loaded in PUPTAPS. Confirm pre-loading before registering your One Portal IdP account with the identical email address.',
    checklist: [
      'Record confirmed as pre-loaded through the official PUPTAPS Facebook page',
      'PUPTAPS account registered and onboarding setup completed',
      'PUP Central IdP account created with the exact same email used in PUPTAPS',
    ],
    steps: [
      {
        title: 'Get your record loaded in PUPTAPS',
        items: [
          'Message the official PUPTAPS Facebook Page or email pupt.alumni.portal2024@gmail.com with your Student Number, Full Name, Course, and Batch Year to confirm your record is pre-loaded.',
          'Once confirmed, go to PUPTAPS at puptaps.ojt-ims-bsit.net and register with your Email Address and Student Number.',
          'Confirm your password using the email sent to your inbox.',
          'Log in and complete the required personal onboarding setup.',
        ],
      },
      stepIdp(
        'alumni',
        'Enter your personal information. The Email Address must be identical to the one you used in PUPTAPS.'
      ),
      STEP_ACCESS_RIS,
      STEP_SUBMIT,
      STEP_TRACK,
    ],
    diag: {
      checks: [
        'Your record is pre-loaded in PUPTAPS',
        'Your IdP email matches your PUPTAPS email exactly',
      ],
      fix: 'Make sure the email on your IdP account is exactly the email registered in PUPTAPS.',
    },
  },
  {
    id: 'alumni-non-sis',
    label: 'Alumni (Non-SIS)',
    banner:
      'Physical archive records require manual registrar cross-referencing and admin approval before portal access can be authorized.',
    checklist: [
      'Non-SIS Alumni Registration submitted in PUPTAPS with your full academic history',
      'Verification granted by the Registrar Staff using the physical campus archives',
      'One Portal IdP account registered with the verified email address',
    ],
    steps: [
      {
        title: 'Register as Non-SIS alumni in PUPTAPS',
        items: [
          'Go to PUPTAPS at puptaps.ojt-ims-bsit.net and select Non-SIS Alumni Registration.',
          'Complete your Personal Information (First Name, Middle Name, Last Name or Maiden Name, Suffix).',
          'Fill in your Academic Details (Course or Program, Batch, Graduation Year).',
          'Enter your Account Credentials (Email Address, Password) and click Submit for Verification.',
          'Wait while Registrar Staff check historical graduation rosters and approve your application.',
          'Once approved, log in to PUPTAPS and finish your onboarding setup.',
        ],
      },
      stepIdp(
        'alumni',
        'Enter your personal information. The Email Address must be identical to the one you used in PUPTAPS.'
      ),
      STEP_ACCESS_RIS,
      STEP_SUBMIT,
      STEP_TRACK,
    ],
    diag: {
      checks: [
        'Your archival data was submitted through PUPTAPS',
        'Registrar Staff approved your verification',
      ],
      fix: 'Email tech4ward.bsit2027@gmail.com or visit the Registrar counter to start physical record verification.',
    },
  },
  {
    id: 'undergrad',
    label: 'Undergraduate',
    banner:
      'Complete the 3-part onboarding form directly on RIS, verify your email, and wait for Admin approval before signing in with IdP.',
    checklist: [
      'RIS Undergraduate Onboarding Registration Form submitted',
      'Email address confirmed through the verification link',
      'Registrar Staff review finished and status marked Approved',
      'PUP Central IdP account activated with the Undergraduate role',
    ],
    steps: [
      {
        title: 'Sign up as an undergraduate requestor in RIS',
        items: [
          'Go to RIS at pupt-ris.registrar-information-system-bsit2027.com and click Not currently enrolled? Sign Up Here, below the central login button.',
          'Confirm that you are not currently enrolled and cannot log in with an active OGOS/GUISIS account.',
          'Part 1 (Personal Info): Email Address, Phone Number, First Name, Middle Name, Last Name, Suffix.',
          'Part 2 (Academic Background): Program or Course, Last School Year Attended, Reason for Non-Enrollment.',
          'Part 3 (Privacy & Consent): Review and accept the Data Privacy Notice (v1.0), then submit.',
        ],
      },
      {
        title: 'Confirm your email and wait for approval',
        items: [
          'Open your inbox and find the email from PUP Taguig Registrar titled "Confirm your email \u2014 PUPT Registrar Undergrad Requestor Registration".',
          'Click Confirm Email Address. This sends your application to the registrar review queue.',
          'Wait for the second email: "Your Undergrad Requestor registration has been approved".',
        ],
      },
      stepIdp(
        'undergraduate',
        'Enter your personal information. The Email Address must be identical to the one approved in RIS.'
      ),
      STEP_ACCESS_RIS,
      STEP_SUBMIT,
      STEP_TRACK,
    ],
    diag: {
      checks: [
        'You confirmed your email with the verification link',
        'Your registration status is Approved',
      ],
      fix: 'Confirm the verification link in your inbox. If approval has been pending for more than 3 working days, follow up with your student number and a valid ID.',
    },
  },
];

/* ---- OR error resolver ---- */

const OR_ERROR =
  "We couldn't validate this receipt \u2014 The OR number could not be found. Please check your Official Receipt and try again.";

const OR_STAGES = [
  {
    id: 1,
    title: 'Check the numbers on your receipt',
    flow: ['7-digit OR number', 'Payment date (YYYY-MM-DD)', 'Amount covers all copies'],
    items: [
      'Confirm the OR number has exactly 7 digits, with no extra prefix and no missing leading zeros.',
      'Confirm the date you entered is the payment date printed on the receipt.',
      'Confirm the total amount paid matches the fee for every document copy you requested.',
    ],
  },
  {
    id: 2,
    title: 'Check that the name matches',
    flow: ['LASTNAME, FIRSTNAME MIDDLEINITIAL', 'No missing middle initial'],
    items: [
      'The name on the receipt must match your portal profile in this format: LASTNAME, FIRSTNAME MIDDLEINITIAL.',
      'Check that middle initials, maiden names, or suffixes (Jr., III) were not misplaced or left out when the cashier encoded your receipt.',
    ],
  },
  {
    id: 3,
    title: 'Ask the Registrar for an override',
    flow: ['Bring the physical OR', 'Bring a school or valid ID', 'Admin runs the override'],
    items: [
      'Use this when your receipt is genuine but the system keeps rejecting it.',
      'Go to the Registrar Admin Counter with your original physical OR and a valid School ID or Government ID.',
      'Registrar Staff verify your documents, then run a scoped Cashier OR Override in the RIS admin console with the verified items, amounts, and a justification.',
      'Back in the Request Form, re-enter your OR number in Step 2 and click Submit. It will validate right away.',
    ],
  },
];

/* ---- FAQ accordion ---- */

const FAQ_CATEGORIES = [
  'System overview and access',
  'Prerequisites and troubleshooting',
  'Processing, security, and claiming',
  'How-to videos',
];

const FAQS = [
  {
    id: 1,
    category: FAQ_CATEGORIES[0],
    question: 'What is the Registrar Information System (RIS)?',
    answer:
      'RIS is the official online document request, tracking, and cashier reconciliation platform for the Polytechnic University of the Philippines \u2013 Taguig Campus. Students, alumni, and undergraduates can submit credential requests digitally and follow the releasing schedule in real time.',
  },
  {
    id: 2,
    category: FAQ_CATEGORIES[0],
    question: 'Who is authorized to use RIS?',
    answer:
      'All bona fide students, alumni (both SIS and Non-SIS batches), and non-enrolled undergraduates of PUP Taguig Campus who have an authenticated Central IdP account and have met the prerequisites for their category.',
  },
  {
    id: 3,
    category: FAQ_CATEGORIES[0],
    question: 'How do I log in to RIS?',
    answer:
      'Sign in with your PUP Central Identity Provider (One Portal / IdP) credentials. The role on your IdP account must match your current status exactly: Student, Alumni, or Undergraduate.',
  },
  {
    id: 4,
    category: FAQ_CATEGORIES[1],
    question: 'Why does the system say "Incomplete Form" or block my access as a student?',
    answer:
      'Enrolled students must complete their Individual Inventory Record (IIR) Form in GUISIS. After you submit it, sign out of RIS and sign back in to refresh your clearance status.',
  },
  {
    id: 5,
    category: FAQ_CATEGORIES[1],
    question: 'What should I do if my receipt shows "OR number could not be found"?',
    answer:
      'Check that the OR number has 7 digits, the date format is correct, and the name on the receipt matches your profile. If it still fails, bring your physical receipt and a valid ID to the Registrar Admin window for an official Cashier Override.',
    jumpTo: 'or-resolver',
  },
  {
    id: 6,
    category: FAQ_CATEGORIES[2],
    question: 'How long does document processing take?',
    answer:
      "Processing follows the university's Citizen's Charter and OPCR delivery standards, typically 3 to 7 working days depending on the document and the signatory clearance needed. Processing time is counted only during official business hours: Tuesday to Friday, 8:00 AM to 8:00 PM.",
  },
  {
    id: 7,
    category: FAQ_CATEGORIES[2],
    question: 'What is the Claim Ticket and why do I need it?',
    answer:
      'The Claim Ticket holds your unique, single-use QR Code and alphanumeric Claim Code. It is your official claiming credential, and you must show it at the registrar releasing window to receive your documents.',
  },
  {
    id: 8,
    category: FAQ_CATEGORIES[2],
    question: 'Can someone else claim my documents for me?',
    answer:
      'Yes. Your representative must bring: (1) a signed Authorization Letter from you, (2) a photocopy of your valid School or Government ID with your signature, (3) their own original valid Government ID, and (4) the printed or digital Claim Ticket (QR or Claim Code).',
  },
  {
    id: 9,
    category: FAQ_CATEGORIES[2],
    question: 'What happens if I do not claim my document within 90 days?',
    answer:
      'Documents left unclaimed 90 calendar days after being marked Ready to Claim are marked FORFEITED and scheduled for secure shredding, in line with records retention policies and the data minimization rules of RA 10173.',
  },
  {
    id: 10,
    category: FAQ_CATEGORIES[2],
    question: 'Is my personal information protected in RIS?',
    answer:
      'Yes. RIS complies with Republic Act No. 10173 (Data Privacy Act of 2012). No personal information is shared with unauthorized outside parties, and every cashier and administrative transaction is recorded in a tamper-evident audit trail.',
  },
  {
    id: 11,
    category: FAQ_CATEGORIES[3],
    question: 'How do PUP students request documents? (Video guide)',
    answer:
      'Active PUP Taguig students sign in through One Portal, pick their document, set the purpose and number of copies, and follow the request status in real time.',
    link: 'https://youtu.be/xXkECQzkhJs?si=ejcO8xggLHaOjHMW',
    linkLabel: 'Watch the student tutorial on YouTube',
    embedUrl: 'https://www.youtube.com/embed/xXkECQzkhJs',
  },
  {
    id: 12,
    category: FAQ_CATEGORIES[3],
    question: 'How do PUP alumni request documents? (Video guide)',
    answer:
      'PUP Taguig alumni log in or register for an alumni account, choose records such as TOR, Diploma, or Certifications, enter their receipt details, and follow fulfillment online.',
    link: 'https://youtu.be/SJo6prU2u24?si=-VzwO2pONrlSj0vl',
    linkLabel: 'Watch the alumni tutorial on YouTube',
    embedUrl: 'https://www.youtube.com/embed/SJo6prU2u24',
  },
];

/* ---- Directory ---- */

const DIRECTORY = [
  {
    id: 'helpdesk',
    office: 'PUP Taguig Campus Helpdesk',
    email: 'taguig@pup.edu.ph',
    purpose: 'Campus-wide administrative inquiries and general student concerns.',
  },
  {
    id: 'registrar',
    office: 'Office of the Registrar',
    email: 'taguig.registrar@pup.edu.ph',
    purpose:
      'Document tracking, physical archive cross-matching, Non-SIS verification, and cashiering escalations.',
  },
  {
    id: 'tech4ward',
    office: 'Tech4Ward technical support (RIS)',
    email: 'tech4ward.bsit2027@gmail.com',
    purpose:
      'Bug reports, portal login glitches, account linking errors, and system downtime alerts.',
  },
  {
    id: 'puptaps',
    office: 'PUPTAPS technical support',
    email: 'pupt.alumni.portal2024@gmail.com',
    purpose:
      'Alumni student number loading, onboarding questions, and portal synchronization.',
  },
  {
    id: 'fb-ris',
    office: 'PUPT Registrar Information System (Facebook)',
    pageName: 'PUPT Registrar Information System',
    url: FB_RIS_URL,
    purpose:
      'Releasing advisories, office holiday closures, and maintenance announcements.',
  },
  {
    id: 'fb-puptaps',
    office: 'PUPT Alumni Portal System (Facebook)',
    pageName: 'PUPT Alumni Portal System',
    url: FB_PUPTAPS_URL,
    purpose:
      'Alumni student number checks, graduating list pre-load status, and alumni portal concerns.',
  },
];

/* -------------------------------------------------------------------------- */
/*  THEME TOKENS                                                               */
/* -------------------------------------------------------------------------- */

const LIGHT = {
  page: 'text-gray-800',
  card: 'bg-white border-gray-200',
  inset: 'bg-gray-50 border-gray-200',
  text: 'text-gray-800',
  muted: 'text-gray-600',
  heading: 'text-[#800000]',
  link: 'text-[#800000] hover:text-[#5c0000]',
  rule: 'border-gray-200',
  primary: 'bg-[#800000] text-white hover:bg-[#660000]',
  tabOn: 'bg-[#800000] text-white border-[#800000]',
  tabOff: 'bg-white text-[#700000] border-gray-300 hover:bg-red-50',
  chip: 'bg-white text-[#700000] border-gray-300 hover:bg-red-50',
  warn: 'bg-amber-50 border-amber-300 text-amber-900',
  error: 'bg-red-50 border-red-200 text-red-800',
  ok: 'bg-emerald-50 border-emerald-300 text-emerald-900',
  stepDot: 'bg-[#800000] text-white',
  stepLine: 'bg-[#800000]/25',
  focus: 'focus-visible:outline-[#800000]',
  field: 'bg-white border-gray-300 text-gray-800 placeholder:text-gray-400',
};

const DARK = {
  page: 'text-[#e4e6eb]',
  card: 'bg-[#242526] border-[#3e4042]',
  inset: 'bg-[#18191a] border-[#3e4042]',
  text: 'text-[#e4e6eb]',
  muted: 'text-[#b0b3b8]',
  heading: 'text-[#eebc48]',
  link: 'text-[#eebc48] hover:text-[#f5d27a]',
  rule: 'border-[#3e4042]',
  primary: 'bg-[#eebc48] text-[#18191a] hover:bg-[#f5cb6b]',
  tabOn: 'bg-[#eebc48] text-[#18191a] border-[#eebc48]',
  tabOff: 'bg-[#242526] text-[#b0b3b8] border-[#3e4042] hover:text-[#e4e6eb] hover:bg-[#3a3b3c]',
  chip: 'bg-[#242526] text-[#b0b3b8] border-[#3e4042] hover:text-[#e4e6eb] hover:bg-[#3a3b3c]',
  warn: 'bg-[#3a2f12] border-[#8a6d1d] text-[#f3dc9c]',
  error: 'bg-[#3a1a1a] border-[#7a2a2a] text-[#f5b5b5]',
  ok: 'bg-[#12301f] border-[#2c6b46] text-[#a8e6c0]',
  stepDot: 'bg-[#eebc48] text-[#18191a]',
  stepLine: 'bg-[#eebc48]/25',
  focus: 'focus-visible:outline-[#eebc48]',
  field: 'bg-[#18191a] border-[#3e4042] text-[#e4e6eb] placeholder:text-[#7c7f85]',
};

const MAROON = {
  page: 'text-white',
  card: 'bg-[#450000] border-white/10',
  inset: 'bg-[#2e0000] border-white/10',
  text: 'text-white',
  muted: 'text-gray-200',
  heading: 'text-[#F8BF1E]',
  link: 'text-[#F8BF1E] hover:text-[#ffd84d]',
  rule: 'border-white/10',
  primary: 'bg-[#F8BF1E] text-[#660000] hover:bg-[#eebc48]',
  tabOn: 'bg-[#F8BF1E] text-[#660000] border-[#F8BF1E]',
  tabOff: 'bg-[#450000] text-gray-200 border-white/10 hover:bg-[#5a0000] hover:text-white',
  chip: 'bg-[#450000] text-gray-200 border-white/10 hover:bg-[#5a0000] hover:text-white',
  warn: 'bg-[#4a3a00]/80 border-[#F8BF1E]/60 text-[#ffe9a8]',
  error: 'bg-[#5a1010] border-red-400/40 text-red-100',
  ok: 'bg-[#0f3d26] border-emerald-400/40 text-emerald-100',
  stepDot: 'bg-[#F8BF1E] text-[#660000]',
  stepLine: 'bg-[#F8BF1E]/30',
  focus: 'focus-visible:outline-[#F8BF1E]',
  field: 'bg-[#2e0000] border-white/20 text-white placeholder:text-gray-400',
};

/* -------------------------------------------------------------------------- */
/*  HELPERS                                                                    */
/* -------------------------------------------------------------------------- */

// Turns emails, known hosts, and "Stage N (Label):" prefixes inside plain strings into rich text.
const RICH_PATTERN =
  /([\w.+-]+@[\w-]+(?:\.[\w-]+)+|\b(?:[a-z0-9-]+\.)+(?:com|net)\b|\bStage \d \([^)]+\):|\bPart \d \([^)]+\):)/gi;

const Rich = ({ text, linkClass }) => {
  const parts = text.split(RICH_PATTERN);
  return parts.map((part, i) => {
    if (i % 2 === 0) return <React.Fragment key={i}>{part}</React.Fragment>;
    if (part.includes('@')) {
      return (
        <a key={i} href={`mailto:${part}`} className={`font-semibold underline ${linkClass}`}>
          {part}
        </a>
      );
    }
    if (/^(Stage|Part) /i.test(part)) {
      return (
        <strong key={i} className="font-bold">
          {part}
        </strong>
      );
    }
    return (
      <a
        key={i}
        href={`https://${part}`}
        target="_blank"
        rel="noopener noreferrer"
        className={`font-semibold underline break-all ${linkClass}`}
      >
        {part}
      </a>
    );
  });
};

const useCopy = () => {
  const [copiedKey, setCopiedKey] = useState(null);
  const timer = useRef(null);

  const copy = useCallback(async (key, text) => {
    try {
      await navigator.clipboard.writeText(text);
    } catch {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try {
        document.execCommand('copy');
      } catch {
        /* clipboard unavailable */
      }
      document.body.removeChild(ta);
    }
    setCopiedKey(key);
    clearTimeout(timer.current);
    timer.current = setTimeout(() => setCopiedKey(null), 1800);
  }, []);

  useEffect(() => () => clearTimeout(timer.current), []);
  return [copiedKey, copy];
};

const getInitialRole = () => {
  if (typeof window === 'undefined') return ROLES[0].id;
  const wanted = new URLSearchParams(window.location.search).get('role');
  return ROLES.some((r) => r.id === wanted) ? wanted : ROLES[0].id;
};

/* -------------------------------------------------------------------------- */
/*  PAGE                                                                       */
/* -------------------------------------------------------------------------- */

/**
 * Used in two places:
 *   - Landing page (#faqs section): <FAQPage />  (the landing navbar already exists)
 *   - Authenticated Help Center (/help): <FAQPage />
 *
 * Optional props:
 *   tone               "maroon" for the landing page (sits on the campus photo + maroon overlay);
 *                      omit it for /help, which follows the light/dark theme
 *   showHeader         render the built-in logo/nav bar (only for a standalone public page)
 *   logoSrc            path to the official PUP logo
 *   onSubmitFeedback   ({ helpful, comment }) => void | Promise<void>
 */
const FAQPage = ({
  tone,
  showHeader = false,
  logoSrc = logoImage,
  onSubmitFeedback,
}) => {
  const { isDark } = useTheme();
  const onImage = tone === 'maroon';
  const dark = isDark || onImage;
  const c = onImage ? MAROON : isDark ? DARK : LIGHT;
  const accent = onImage ? 'accent-[#F8BF1E]' : 'accent-[#800000]';

  const [searchQuery, setSearchQuery] = useState('');
  const [roleId, setRoleId] = useState(getInitialRole);
  const [diagId, setDiagId] = useState(null);
  const [ready, setReady] = useState({});
  const [diagChecked, setDiagChecked] = useState({});
  const [openIds, setOpenIds] = useState(() => new Set());
  const [copiedKey, copy] = useCopy();
  const [feedbackOpen, setFeedbackOpen] = useState(false);
  const [feedback, setFeedback] = useState({ helpful: null, comment: '', sent: false });

  const role = ROLES.find((r) => r.id === roleId);
  const diagRole = ROLES.find((r) => r.id === diagId);

  const jumpTo = useCallback((id) => {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }, []);

  /* Search */
  const query = searchQuery.trim().toLowerCase();
  const visibleFaqs = useMemo(
    () =>
      FAQS.filter(
        (f) =>
          !query ||
          f.question.toLowerCase().includes(query) ||
          f.answer.toLowerCase().includes(query)
      ),
    [query]
  );
  const allExpanded =
    visibleFaqs.length > 0 && visibleFaqs.every((f) => openIds.has(f.id));

  const toggleFaq = (id) =>
    setOpenIds((prev) => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });

  const toggleAll = () =>
    setOpenIds(allExpanded ? new Set() : new Set(visibleFaqs.map((f) => f.id)));

  const pickTag = (tag) => {
    setSearchQuery(tag);
    jumpTo('faq-list');
  };

  /* Tabs: arrow-key support */
  const onTabKey = (e, index) => {
    if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
    e.preventDefault();
    const dir = e.key === 'ArrowRight' ? 1 : -1;
    const next = ROLES[(index + dir + ROLES.length) % ROLES.length];
    setRoleId(next.id);
    document.getElementById(`tab-${next.id}`)?.focus();
  };

  const readyCount = role.checklist.filter((_, i) => ready[`${role.id}:${i}`]).length;
  const allReady = readyCount === role.checklist.length;

  const submitFeedback = async () => {
    try {
      await onSubmitFeedback?.({ helpful: feedback.helpful, comment: feedback.comment.trim() });
    } finally {
      setFeedback((f) => ({ ...f, sent: true }));
    }
  };

  const focusRing = `focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 ${c.focus}`;

  const sectionTitle = (text, sub) => (
    <header className="mb-5">
      <h2 className={`text-xl sm:text-2xl font-black ${c.heading}`}>{text}</h2>
      {sub && <p className={`mt-1 text-sm sm:text-base ${c.muted} max-w-2xl`}>{sub}</p>}
    </header>
  );

  return (
    <div className={`min-h-screen font-sans ${c.page} ${onImage ? '' : dark ? 'bg-[#18191a]' : ''}`}>
      {/* 1. Header & branding (landing page only) */}
      {showHeader && (
        <div className={`border-b ${c.rule} ${onImage ? 'bg-[#660000]' : dark ? 'bg-[#242526]' : 'bg-white'}`}>
          <div className="max-w-5xl mx-auto px-4 py-3 flex items-center justify-between gap-3">
            <a href="/" className="flex items-center gap-3 min-w-0">
              <img src={logoSrc} alt="Polytechnic University of the Philippines seal" className="h-10 w-10 shrink-0" />
              <span className="min-w-0">
                <span className={`block text-sm font-black leading-tight ${c.heading}`}>
                  Registrar Information System
                </span>
                <span className={`block text-xs ${c.muted} truncate`}>PUP Taguig Campus</span>
              </span>
            </a>
            <nav className="flex items-center gap-1 sm:gap-3 text-sm font-bold" aria-label="Help center">
              <a href="/" className={`hidden sm:inline px-2 py-1 rounded ${c.link} ${focusRing}`}>
                Home
              </a>
              <button type="button" onClick={() => jumpTo('guide')} className={`hidden sm:inline px-2 py-1 rounded ${c.link} ${focusRing}`}>
                User guide
              </button>
              <button type="button" onClick={() => jumpTo('faq-list')} className={`px-2 py-1 rounded ${c.link} ${focusRing}`}>
                FAQs
              </button>
              <button
                type="button"
                onClick={() => jumpTo('directory')}
                className={`ml-1 px-4 py-2 rounded-lg ${c.primary} ${focusRing}`}
              >
                Contact us
              </button>
            </nav>
          </div>
        </div>
      )}

      <div className="max-w-5xl mx-auto px-4 pb-16">
        {/* 2. Hero search */}
        <section className="pt-8 pb-10" aria-labelledby="help-title">
          <h1 id="help-title" className={`text-2xl sm:text-4xl font-black ${c.heading}`}>
            How can we help with your request?
          </h1>
          <p className={`mt-2 max-w-2xl ${c.muted}`}>
            Find out what to do first, follow the steps for your requestor type, and fix the most common problems without lining up at the counter.
          </p>

          <div className="mt-6 max-w-2xl">
            <VoiceSearchInput
              value={searchQuery}
              onChange={setSearchQuery}
              placeholder="Search questions or keywords..."
              language="en-US"
            />
          </div>

          <div className="mt-3 flex flex-wrap gap-2" aria-label="Popular searches">
            {QUICK_TAGS.map((tag) => (
              <button
                key={tag}
                type="button"
                onClick={() => pickTag(tag)}
                className={`inline-flex items-center gap-1 rounded-full border px-3 py-1.5 text-sm font-semibold ${c.chip} ${focusRing}`}
              >
                <MagnifyingGlassIcon className="h-3.5 w-3.5" aria-hidden="true" />
                {tag}
              </button>
            ))}
          </div>

          {query && (
            <p className={`mt-3 text-sm ${c.muted}`} role="status">
              {visibleFaqs.length} result{visibleFaqs.length !== 1 ? 's' : ''} for{' '}
              <span className={`font-bold ${c.heading}`}>"{searchQuery.trim()}"</span> in the FAQs.{' '}
              <button type="button" onClick={() => jumpTo('faq-list')} className={`font-bold underline ${c.link} ${focusRing}`}>
                Go to results
              </button>
            </p>
          )}
        </section>

        {/* 3. Top 3 common concerns */}
        <section id="concerns" className="scroll-mt-28 pb-12" aria-label="Most common concerns">
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            {CONCERNS.map(({ id, icon: Icon, title, summary, cta, target }) => (
              <article key={id} className={`flex flex-col rounded-2xl border p-5 ${c.card}`}>
                <Icon className={`h-7 w-7 ${c.heading}`} aria-hidden="true" />
                <h3 className={`mt-3 text-lg font-black ${c.text}`}>{title}</h3>
                <p className={`mt-1 flex-1 text-sm leading-relaxed ${c.muted}`}>{summary}</p>
                <button
                  type="button"
                  onClick={() => {
                    if (id === 'access') setDiagId((d) => d || roleId);
                    jumpTo(target);
                  }}
                  className={`mt-4 self-start rounded-lg px-4 py-2 text-sm font-bold ${c.primary} ${focusRing}`}
                >
                  {cta}
                </button>
              </article>
            ))}
          </div>
        </section>

        {/* 4-6. Role tabs, readiness checklist, walkthrough */}
        <section id="guide" className="scroll-mt-28 pb-12" aria-label="Step-by-step guide">
          {sectionTitle(
            'Step-by-step guide',
            'Pick the group that describes you. The steps below change right away.'
          )}

          <div
            role="tablist"
            aria-label="Requestor type"
            className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-2"
          >
            {ROLES.map((r, i) => (
              <button
                key={r.id}
                id={`tab-${r.id}`}
                role="tab"
                type="button"
                aria-selected={roleId === r.id}
                aria-controls={`panel-${r.id}`}
                tabIndex={roleId === r.id ? 0 : -1}
                onClick={() => setRoleId(r.id)}
                onKeyDown={(e) => onTabKey(e, i)}
                className={`whitespace-nowrap rounded-full border px-4 py-2 text-sm font-bold transition-colors ${
                  roleId === r.id ? c.tabOn : c.tabOff
                } ${focusRing}`}
              >
                {r.label}
              </button>
            ))}
          </div>

          <div id={`panel-${role.id}`} role="tabpanel" aria-labelledby={`tab-${role.id}`} className="mt-4">
            {/* Warning banner */}
            <div className={`flex gap-3 rounded-xl border p-4 ${c.warn}`} role="note">
              <ExclamationTriangleIcon className="h-6 w-6 shrink-0" aria-hidden="true" />
              <p className="text-sm sm:text-base font-semibold leading-relaxed">{role.banner}</p>
            </div>

            {/* Readiness checklist */}
            <div className={`mt-4 rounded-2xl border p-5 ${c.card}`}>
              <div className="flex items-center justify-between gap-3">
                <h3 className={`flex items-center gap-2 text-lg font-black ${c.text}`}>
                  <ClipboardDocumentCheckIcon className={`h-6 w-6 ${c.heading}`} aria-hidden="true" />
                  Before you start
                </h3>
                <span className={`text-sm font-bold ${allReady ? '' : c.muted}`} aria-live="polite">
                  {readyCount} of {role.checklist.length} ready
                </span>
              </div>
              <ul className="mt-3 space-y-2">
                {role.checklist.map((item, i) => {
                  const key = `${role.id}:${i}`;
                  const on = !!ready[key];
                  return (
                    <li key={key}>
                      <label className={`flex cursor-pointer items-start gap-3 rounded-lg p-2 -mx-2 ${onImage ? 'hover:bg-white/5' : 'hover:bg-black/5'}`}>
                        <input
                          type="checkbox"
                          checked={on}
                          onChange={() => setReady((r) => ({ ...r, [key]: !r[key] }))}
                          className={`mt-1 h-4 w-4 shrink-0 ${accent}`}
                        />
                        <span className={`text-sm sm:text-base ${on ? c.muted : c.text}`}>{item}</span>
                      </label>
                    </li>
                  );
                })}
              </ul>
              {allReady && (
                <p className={`mt-3 flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-bold ${c.ok}`}>
                  <CheckCircleSolid className="h-5 w-5" aria-hidden="true" />
                  You are ready. Follow the steps below.
                </p>
              )}
            </div>

            {/* Walkthrough */}
            <ol className="mt-6">
              {role.steps.map((step, i) => (
                <li key={`${role.id}-${i}`} className="relative flex gap-4 pb-8 last:pb-0">
                  {i < role.steps.length - 1 && (
                    <span className={`absolute left-4 top-9 bottom-0 w-0.5 -translate-x-1/2 ${c.stepLine}`} aria-hidden="true" />
                  )}
                  <span className={`relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-black ${c.stepDot}`}>
                    {i + 1}
                  </span>
                  <div className="min-w-0 flex-1">
                    <h3 className={`text-lg font-black leading-8 ${c.text}`}>{step.title}</h3>
                    <ul className={`mt-1 space-y-1.5 text-sm sm:text-base leading-relaxed ${c.muted}`}>
                      {step.items.map((item, j) => (
                        <li key={j} className="flex gap-2">
                          <span className={`mt-2.5 h-1.5 w-1.5 shrink-0 rounded-full ${dark ? 'bg-[#b0b3b8]' : 'bg-gray-400'}`} aria-hidden="true" />
                          <span className="min-w-0">
                            <Rich text={item} linkClass={c.link} />
                          </span>
                        </li>
                      ))}
                    </ul>
                  </div>
                </li>
              ))}
            </ol>
          </div>
        </section>

        {/* 7. Interactive diagnostic */}
        <section id="diagnostic" className="scroll-mt-28 pb-12" aria-label="Access diagnostic">
          {sectionTitle(
            "Can't access the system?",
            'Choose your requestor type to see what to check and how to fix it.'
          )}

          <div className="flex flex-wrap gap-2">
            {ROLES.map((r) => (
              <button
                key={r.id}
                type="button"
                aria-pressed={diagId === r.id}
                onClick={() => setDiagId(r.id)}
                className={`rounded-lg border px-4 py-2 text-sm font-bold transition-colors ${
                  diagId === r.id ? c.tabOn : c.tabOff
                } ${focusRing}`}
              >
                {r.label}
              </button>
            ))}
          </div>

          {diagRole ? (
            <div className={`mt-4 rounded-2xl border p-5 ${c.card}`} aria-live="polite">
              <h3 className={`text-lg font-black ${c.text}`}>Check these first</h3>
              <ul className="mt-3 space-y-2">
                {diagRole.diag.checks.map((chk, i) => {
                  const key = `${diagRole.id}:${i}`;
                  return (
                    <li key={key}>
                      <label className="flex cursor-pointer items-start gap-3">
                        <input
                          type="checkbox"
                          checked={!!diagChecked[key]}
                          onChange={() => setDiagChecked((d) => ({ ...d, [key]: !d[key] }))}
                          className={`mt-1 h-4 w-4 shrink-0 ${accent}`}
                        />
                        <span className={c.text}>{chk}</span>
                      </label>
                    </li>
                  );
                })}
              </ul>
              <div className={`mt-4 rounded-xl border p-4 ${c.inset}`}>
                <p className={`text-sm font-black ${c.heading}`}>How to fix it</p>
                <p className={`mt-1 ${c.text}`}>
                  <Rich text={diagRole.diag.fix} linkClass={c.link} />
                </p>
              </div>
              <button
                type="button"
                onClick={() => {
                  setRoleId(diagRole.id);
                  jumpTo('guide');
                }}
                className={`mt-4 rounded-lg px-4 py-2 text-sm font-bold ${c.primary} ${focusRing}`}
              >
                Open the {diagRole.label.toLowerCase()} steps
              </button>
            </div>
          ) : (
            <p className={`mt-4 rounded-xl border border-dashed p-5 text-sm ${c.rule} ${c.muted}`}>
              Nothing selected yet. Pick your requestor type above.
            </p>
          )}
        </section>

        {/* 8. OR error & cashier override */}
        <section id="or-resolver" className="scroll-mt-28 pb-12" aria-label="Official Receipt error resolver">
          {sectionTitle(
            'Official Receipt (OR) error',
            'Work through these three stages in order. Most receipt errors are solved by stage 1 or 2.'
          )}

          <div className={`rounded-xl border p-4 ${c.error}`} role="alert">
            <p className="flex items-start gap-2 text-sm sm:text-base font-semibold">
              <XCircleIcon className="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
              <span>{OR_ERROR}</span>
            </p>
          </div>

          {/* Quick visual flow */}
          <ol className="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-3">
            {OR_STAGES.map((s) => (
              <li key={s.id} className={`rounded-xl border p-3 ${c.card}`}>
                <p className={`text-sm font-black ${c.heading}`}>Stage {s.id}: {s.title}</p>
                <ul className={`mt-1 space-y-0.5 text-xs ${c.muted}`}>
                  {s.flow.map((f) => (
                    <li key={f} className="flex items-center gap-1.5">
                      <CheckIcon className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                      {f}
                    </li>
                  ))}
                </ul>
              </li>
            ))}
          </ol>

          {/* Detail */}
          <div className="mt-6 space-y-6">
            {OR_STAGES.map((s) => (
              <div key={s.id} className="flex gap-4">
                <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-black ${c.stepDot}`}>
                  {s.id}
                </span>
                <div className="min-w-0 flex-1">
                  <h3 className={`text-lg font-black leading-8 ${c.text}`}>{s.title}</h3>
                  <ul className={`mt-1 space-y-1.5 text-sm sm:text-base leading-relaxed ${c.muted}`}>
                    {s.items.map((it, i) => (
                      <li key={i} className="flex gap-2">
                        <span className={`mt-2.5 h-1.5 w-1.5 shrink-0 rounded-full ${dark ? 'bg-[#b0b3b8]' : 'bg-gray-400'}`} aria-hidden="true" />
                        <span>{it}</span>
                      </li>
                    ))}
                  </ul>
                  {s.id === 2 && (
                    <div className={`mt-3 flex items-center justify-between gap-2 rounded-lg border px-3 py-2 ${c.inset}`}>
                      <code className={`text-sm font-bold ${c.text}`}>LASTNAME, FIRSTNAME MIDDLEINITIAL</code>
                      <button
                        type="button"
                        onClick={() => copy('or-format', 'LASTNAME, FIRSTNAME MIDDLEINITIAL')}
                        className={`inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-bold ${c.link} ${focusRing}`}
                      >
                        {copiedKey === 'or-format' ? (
                          <>
                            <CheckIcon className="h-4 w-4" aria-hidden="true" /> Copied
                          </>
                        ) : (
                          <>
                            <ClipboardDocumentIcon className="h-4 w-4" aria-hidden="true" /> Copy
                          </>
                        )}
                      </button>
                    </div>
                  )}
                </div>
              </div>
            ))}
          </div>
        </section>

        {/* 9. FAQ accordion */}
        <section id="faq-list" className="scroll-mt-28 pb-12" aria-label="Frequently asked questions">
          <div className="flex flex-wrap items-end justify-between gap-3">
            {sectionTitle('Frequently asked questions')}
            {visibleFaqs.length > 0 && (
              <button
                type="button"
                onClick={toggleAll}
                className={`mb-5 rounded-lg border px-4 py-2 text-sm font-bold ${c.chip} ${focusRing}`}
              >
                {allExpanded ? 'Collapse all' : 'Expand all'}
              </button>
            )}
          </div>

          {visibleFaqs.length === 0 ? (
            <div className={`rounded-2xl border border-dashed p-10 text-center ${c.rule}`}>
              <p className={`text-lg font-black ${c.text}`}>No answers match "{searchQuery.trim()}"</p>
              <p className={`mt-1 ${c.muted}`}>Try a shorter keyword, or contact the Registrar directly.</p>
              <div className="mt-4 flex flex-wrap justify-center gap-2">
                <button type="button" onClick={() => setSearchQuery('')} className={`rounded-lg px-4 py-2 text-sm font-bold ${c.primary} ${focusRing}`}>
                  Clear search
                </button>
                <button type="button" onClick={() => jumpTo('directory')} className={`rounded-lg border px-4 py-2 text-sm font-bold ${c.chip} ${focusRing}`}>
                  Contact the Registrar
                </button>
              </div>
            </div>
          ) : (
            <div className="space-y-8">
              {FAQ_CATEGORIES.map((cat) => {
                const items = visibleFaqs.filter((f) => f.category === cat);
                if (!items.length) return null;
                return (
                  <div key={cat}>
                    <h3 className={`mb-3 text-base font-black ${c.text}`}>{cat}</h3>
                    <div className="space-y-3">
                      {items.map((faq) => {
                        const open = openIds.has(faq.id);
                        return (
                          <div
                            key={faq.id}
                            className={`overflow-hidden rounded-2xl border transition-colors ${c.card} ${
                              open ? (dark ? 'border-[#eebc48]/60' : 'border-[#800000]') : ''
                            }`}
                          >
                            <h4>
                              <button
                                type="button"
                                id={`faq-btn-${faq.id}`}
                                aria-expanded={open}
                                aria-controls={`faq-panel-${faq.id}`}
                                onClick={() => toggleFaq(faq.id)}
                                className={`flex w-full items-center justify-between gap-4 p-4 sm:p-5 text-left ${focusRing}`}
                              >
                                <span className={`text-base sm:text-lg font-bold ${dark ? 'text-white' : 'text-[#800000]'}`}>
                                  {faq.question}
                                </span>
                                <ChevronDownIcon
                                  className={`h-5 w-5 shrink-0 transition-transform duration-200 ${open ? 'rotate-180' : ''} ${c.muted}`}
                                  aria-hidden="true"
                                />
                              </button>
                            </h4>
                            <div
                              id={`faq-panel-${faq.id}`}
                              role="region"
                              aria-labelledby={`faq-btn-${faq.id}`}
                              className={`grid transition-[grid-template-rows] duration-200 ease-out ${
                                open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'
                              }`}
                            >
                              <div className="overflow-hidden">
                                <div className="px-4 pb-5 sm:px-5">
                                  <p className={`leading-relaxed ${c.muted}`}>{faq.answer}</p>

                                  {faq.jumpTo && (
                                    <button
                                      type="button"
                                      onClick={() => jumpTo(faq.jumpTo)}
                                      className={`mt-3 text-sm font-bold underline ${c.link} ${focusRing}`}
                                    >
                                      Open the 3-stage receipt guide
                                    </button>
                                  )}

                                  {open && faq.embedUrl && (
                                    <div
                                      className="relative mt-4 w-full max-w-lg overflow-hidden rounded-xl bg-black"
                                      style={{ aspectRatio: '16 / 9' }}
                                    >
                                      <iframe
                                        src={faq.embedUrl}
                                        title={faq.question}
                                        className="absolute inset-0 h-full w-full border-0"
                                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                        allowFullScreen
                                        loading="lazy"
                                      />
                                    </div>
                                  )}

                                  {faq.link && (
                                    <a
                                      href={faq.link}
                                      target="_blank"
                                      rel="noopener noreferrer"
                                      className={`mt-4 inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-bold ${c.primary} ${focusRing}`}
                                    >
                                      {faq.linkLabel || 'Watch on YouTube'}
                                      <ArrowTopRightOnSquareIcon className="h-4 w-4" aria-hidden="true" />
                                    </a>
                                  )}
                                </div>
                              </div>
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </section>

        {/* 10. Helpdesk directory */}
        <section id="directory" className="scroll-mt-28 pb-12" aria-label="Registrar contact and helpdesk directory">
          {sectionTitle(
            'Contact the Registrar and helpdesk',
            'Copy an address with one click, or write directly from your email app.'
          )}

          <ul className="grid grid-cols-1 gap-3 md:grid-cols-2">
            {DIRECTORY.map((d) => (
              <li key={d.id} className={`flex flex-col rounded-2xl border p-4 ${c.card}`}>
                <h3 className={`font-black ${c.text}`}>{d.office}</h3>
                <p className={`mt-1 flex-1 text-sm leading-relaxed ${c.muted}`}>{d.purpose}</p>

                {d.email ? (
                  <div className={`mt-3 flex items-center justify-between gap-2 rounded-lg border px-3 py-2 ${c.inset}`}>
                    <a href={`mailto:${d.email}`} className={`flex min-w-0 items-center gap-2 text-sm font-bold ${c.link} ${focusRing}`}>
                      <EnvelopeIcon className="h-4 w-4 shrink-0" aria-hidden="true" />
                      <span className="truncate">{d.email}</span>
                    </a>
                    <button
                      type="button"
                      onClick={() => copy(d.id, d.email)}
                      aria-label={`Copy ${d.email}`}
                      className={`inline-flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-xs font-bold ${c.link} ${focusRing}`}
                    >
                      {copiedKey === d.id ? (
                        <>
                          <CheckIcon className="h-4 w-4" aria-hidden="true" /> Copied
                        </>
                      ) : (
                        <>
                          <ClipboardDocumentIcon className="h-4 w-4" aria-hidden="true" /> Copy
                        </>
                      )}
                    </button>
                  </div>
                ) : d.url ? (
                  <a
                    href={d.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className={`mt-3 inline-flex items-center gap-2 text-sm font-bold underline ${c.link} ${focusRing}`}
                  >
                    Open on Facebook
                    <ArrowTopRightOnSquareIcon className="h-4 w-4" aria-hidden="true" />
                  </a>
                ) : (
                  <p className={`mt-3 text-sm font-semibold ${c.muted}`}>Search “{d.pageName}” on Facebook.</p>
                )}
              </li>
            ))}
          </ul>

          <div className="mt-4">
            <a
              data-voice-action="compose-email"
              href="https://mail.google.com/mail/u/0/?view=cm&fs=1&to=taguig@pup.edu.ph%2Ctaguig.registrar@pup.edu.ph&su=Inquiry%3A%20PUP%20Registrar%20Office%20Concern"
              target="_blank"
              rel="noopener noreferrer"
              className={`inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-bold ${c.primary} ${focusRing}`}
            >
              <EnvelopeIcon className="h-4 w-4" aria-hidden="true" />
              Compose an email to the Registrar
            </a>
            <p className={`mt-2 max-w-xl text-sm ${c.muted}`}>
              Include your full name, student number, and a clear description of your concern so we can reply faster.
            </p>
          </div>
        </section>

        {/* 11. Footer */}
        <footer className={`border-t pt-6 ${c.rule}`}>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <p className={`text-sm ${c.muted}`}>
              &copy; {new Date().getFullYear()} Polytechnic University of the Philippines &ndash; Taguig Campus.
              Registrar Information System developed by Tech4Ward.
            </p>
            <button
              type="button"
              aria-expanded={feedbackOpen}
              onClick={() => setFeedbackOpen((o) => !o)}
              className={`rounded-lg border px-4 py-2 text-sm font-bold ${c.chip} ${focusRing}`}
            >
              Give feedback on this guide
            </button>
          </div>

          {feedbackOpen && (
            <div className={`mt-4 max-w-lg rounded-2xl border p-4 ${c.card}`}>
              {feedback.sent ? (
                <p className={`flex items-center gap-2 font-bold ${c.text}`} role="status">
                  <CheckCircleSolid className="h-5 w-5" aria-hidden="true" />
                  Thanks. Your feedback was sent.
                </p>
              ) : (
                <>
                  <p className={`font-black ${c.text}`}>Did this guide solve your problem?</p>
                  <div className="mt-2 flex gap-2">
                    {[
                      { v: true, label: 'Yes' },
                      { v: false, label: 'Not yet' },
                    ].map(({ v, label }) => (
                      <button
                        key={label}
                        type="button"
                        aria-pressed={feedback.helpful === v}
                        onClick={() => setFeedback((f) => ({ ...f, helpful: v }))}
                        className={`rounded-lg border px-4 py-1.5 text-sm font-bold ${
                          feedback.helpful === v ? c.tabOn : c.tabOff
                        } ${focusRing}`}
                      >
                        {label}
                      </button>
                    ))}
                  </div>
                  <label className="mt-3 block">
                    <span className={`text-sm font-semibold ${c.muted}`}>What should we improve? (optional)</span>
                    <textarea
                      rows={3}
                      maxLength={500}
                      value={feedback.comment}
                      onChange={(e) => setFeedback((f) => ({ ...f, comment: e.target.value }))}
                      className={`mt-1 w-full rounded-lg border p-2 text-sm ${c.field} ${focusRing}`}
                    />
                  </label>
                  <button
                    type="button"
                    disabled={feedback.helpful === null}
                    onClick={submitFeedback}
                    className={`mt-3 rounded-lg px-4 py-2 text-sm font-bold disabled:cursor-not-allowed disabled:opacity-50 ${c.primary} ${focusRing}`}
                  >
                    Send feedback
                  </button>
                </>
              )}
            </div>
          )}
        </footer>
      </div>
    </div>
  );
};

/* -------------------------------------------------------------------------- */
/*  COMPACT VERSION (landing page)                                             */
/*  One role picker drives everything. One step at a time, 5 FAQs, 3 contacts. */
/*  The complete guide lives on its own public page: /faqs                     */
/* -------------------------------------------------------------------------- */

const COMPACT_ROLE_BLURB = {
  student: 'Currently enrolled at PUP Taguig',
  'alumni-sis': 'Graduated, record loaded in PUPTAPS',
  'alumni-non-sis': 'Graduated, record in the physical archive',
  undergrad: 'Not currently enrolled',
};
const COMPACT_FAQ_IDS = [3, 5, 6, 7, 8];
const COMPACT_CONTACT_IDS = ['registrar', 'tech4ward', 'helpdesk'];

const COMPACT_HELP_TABS = [
  { id: 'access', label: "I can't access the system" },
  { id: 'receipt', label: 'My receipt was rejected' },
];

const CollapsiblePanel = ({ id, title, hint, className, children }) => {
  const [open, setOpen] = useState(false);
  return (
    <div className={className}>
      <button
        type="button"
        id={`${id}-btn`}
        aria-expanded={open}
        aria-controls={`${id}-body`}
        onClick={() => setOpen((o) => !o)}
        className="flex w-full items-center justify-between gap-4 rounded-lg text-left focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#F8BF1E]"
      >
        <span className="min-w-0">
          <span className="block text-xl font-black">{title}</span>
          <span className="mt-0.5 block text-sm text-gray-300">{hint}</span>
        </span>
        <ChevronDownIcon
          className={`h-6 w-6 shrink-0 text-[#F8BF1E] transition-transform duration-200 ${open ? 'rotate-180' : ''}`}
          aria-hidden="true"
        />
      </button>
      <div
        id={`${id}-body`}
        role="region"
        aria-labelledby={`${id}-btn`}
        className={`grid transition-[grid-template-rows] duration-200 ease-out ${open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'}`}
      >
        <div className={`overflow-hidden ${open ? '' : 'invisible'}`}>
          <div className="pt-4">{children}</div>
        </div>
      </div>
    </div>
  );
};

export const UserGuideCompact = ({ fullGuideHref = '/faqs' }) => {
  const c = MAROON;
  const [roleId, setRoleId] = useState(ROLES[0].id);
  const [step, setStep] = useState(0);
  const [showAll, setShowAll] = useState(false);
  const [helpTab, setHelpTab] = useState('access');
  const [ready, setReady] = useState({});
  const [copiedKey, copy] = useCopy();

  const role = ROLES.find((r) => r.id === roleId);
  const total = role.steps.length;
  const current = role.steps[step];
  const faqs = COMPACT_FAQ_IDS.map((id) => FAQS.find((f) => f.id === id)).filter(Boolean);
  const contacts = DIRECTORY.filter((d) => COMPACT_CONTACT_IDS.includes(d.id));
  const readyCount = role.checklist.filter((_, i) => ready[`${role.id}:${i}`]).length;

  const pickRole = (id) => {
    setRoleId(id);
    setStep(0);
    setShowAll(false);
  };

  const focusRing = `focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 ${c.focus}`;
  const panel = 'mt-6 rounded-3xl border border-white/10 bg-[#450000]/95 p-5 md:p-8';
  const dot = 'mt-2.5 h-1.5 w-1.5 shrink-0 rounded-full bg-gray-300';

  return (
    <section className="mx-auto max-w-5xl px-4 py-16 text-white" aria-labelledby="ug-title">
      <header className="text-center">
        <h2 id="ug-title" className="text-3xl md:text-4xl font-extrabold tracking-tight">
          User guide
        </h2>
        <p className="mx-auto mt-3 max-w-xl text-sm md:text-base leading-relaxed text-gray-200">
          Tell us who you are and we will show exactly what to do before you request a document.
        </p>
      </header>

      {/* Role picker */}
      <div role="group" aria-label="I am a" className="mt-8 grid grid-cols-2 gap-3 md:grid-cols-4">
        {ROLES.map((r) => {
          const on = roleId === r.id;
          return (
            <button
              key={r.id}
              type="button"
              aria-pressed={on}
              onClick={() => pickRole(r.id)}
              className={`relative rounded-2xl border p-4 text-left transition-colors ${
                on ? 'border-[#F8BF1E] bg-[#5a0000]' : 'border-white/10 bg-[#450000] hover:bg-[#520000]'
              } ${focusRing}`}
            >
              {on && (
                <CheckCircleSolid className="absolute right-3 top-3 h-5 w-5 text-[#F8BF1E]" aria-hidden="true" />
              )}
              <span className="block pr-6 font-black">{r.label}</span>
              <span className="mt-1 block text-xs leading-snug text-gray-300">
                {COMPACT_ROLE_BLURB[r.id]}
              </span>
            </button>
          );
        })}
      </div>

      {/* Requirements + stepper */}
      <div className={`${panel} grid gap-8 md:grid-cols-5`}>
        <div className="md:col-span-2">
          <div className={`flex gap-3 rounded-xl border p-3 ${c.warn}`} role="note">
            <ExclamationTriangleIcon className="h-5 w-5 shrink-0" aria-hidden="true" />
            <p className="text-sm font-semibold leading-relaxed">{role.banner}</p>
          </div>

          <div className="mt-5 flex items-center justify-between gap-2">
            <h3 className="font-black">Before you start</h3>
            <span className="text-xs font-bold text-gray-300" aria-live="polite">
              {readyCount} of {role.checklist.length} ready
            </span>
          </div>
          <ul className="mt-2 space-y-1">
            {role.checklist.map((item, i) => {
              const key = `${role.id}:${i}`;
              return (
                <li key={key}>
                  <label className="-mx-2 flex cursor-pointer items-start gap-3 rounded-lg p-2 hover:bg-white/5">
                    <input
                      type="checkbox"
                      checked={!!ready[key]}
                      onChange={() => setReady((r) => ({ ...r, [key]: !r[key] }))}
                      className="mt-1 h-4 w-4 shrink-0 accent-[#F8BF1E]"
                    />
                    <span className={`text-sm leading-snug ${ready[key] ? 'text-gray-400' : ''}`}>{item}</span>
                  </label>
                </li>
              );
            })}
          </ul>
        </div>

        <div className="md:col-span-3">
          <div className="flex items-center justify-between gap-3">
            <p className="text-sm font-bold text-[#F8BF1E]">
              {showAll ? `All ${total} steps` : `Step ${step + 1} of ${total}`}
            </p>
            <button
              type="button"
              onClick={() => setShowAll((v) => !v)}
              className={`text-xs font-bold underline ${c.link} ${focusRing}`}
            >
              {showAll ? 'Show one step at a time' : 'View all steps'}
            </button>
          </div>

          {!showAll && (
            <div
              className="mt-2 h-1.5 overflow-hidden rounded-full bg-white/10"
              role="progressbar"
              aria-valuemin={1}
              aria-valuemax={total}
              aria-valuenow={step + 1}
              aria-label="Guide progress"
            >
              <div
                className="h-full rounded-full bg-[#F8BF1E] transition-all duration-300"
                style={{ width: `${((step + 1) / total) * 100}%` }}
              />
            </div>
          )}

          {showAll ? (
            <ol className="mt-4 space-y-5">
              {role.steps.map((st, i) => (
                <li key={i}>
                  <h4 className="font-black">
                    {i + 1}. {st.title}
                  </h4>
                  <ul className="mt-1 space-y-1 text-sm leading-relaxed text-gray-200">
                    {st.items.map((it, j) => (
                      <li key={j} className="flex gap-2">
                        <span className={dot} aria-hidden="true" />
                        <span className="min-w-0"><Rich text={it} linkClass={c.link} /></span>
                      </li>
                    ))}
                  </ul>
                </li>
              ))}
            </ol>
          ) : (
            <>
              <div className="mt-4" aria-live="polite">
                <h4 className="text-lg font-black">{current.title}</h4>
                <ul className="mt-2 space-y-1.5 text-sm leading-relaxed text-gray-200">
                  {current.items.map((it, j) => (
                    <li key={j} className="flex gap-2">
                      <span className={dot} aria-hidden="true" />
                      <span className="min-w-0"><Rich text={it} linkClass={c.link} /></span>
                    </li>
                  ))}
                </ul>
              </div>

              <div className="mt-5 flex items-center justify-between gap-3">
                <button
                  type="button"
                  onClick={() => setStep((s) => Math.max(0, s - 1))}
                  disabled={step === 0}
                  className={`rounded-lg border px-4 py-2 text-sm font-bold disabled:cursor-not-allowed disabled:opacity-40 ${c.tabOff} ${focusRing}`}
                >
                  Back
                </button>
                {step < total - 1 ? (
                  <button
                    type="button"
                    onClick={() => setStep((s) => Math.min(total - 1, s + 1))}
                    className={`rounded-lg px-5 py-2 text-sm font-bold ${c.primary} ${focusRing}`}
                  >
                    Next step
                  </button>
                ) : (
                  <p className="text-sm font-bold text-[#F8BF1E]">That is the whole process.</p>
                )}
              </div>
            </>
          )}
        </div>
      </div>

      {/* Troubleshooting */}
      <CollapsiblePanel
        id="ug-trouble"
        className={panel}
        title="Something not working?"
        hint="Fix login blocks and receipt errors"
      >
        <div role="tablist" aria-label="Troubleshooting" className="flex flex-wrap gap-2">
          {COMPACT_HELP_TABS.map((t) => (
            <button
              key={t.id}
              type="button"
              role="tab"
              id={`ug-tab-${t.id}`}
              aria-selected={helpTab === t.id}
              aria-controls={`ug-panel-${t.id}`}
              onClick={() => setHelpTab(t.id)}
              className={`rounded-full border px-4 py-2 text-sm font-bold transition-colors ${
                helpTab === t.id ? c.tabOn : c.tabOff
              } ${focusRing}`}
            >
              {t.label}
            </button>
          ))}
        </div>

        {helpTab === 'access' ? (
          <div id="ug-panel-access" role="tabpanel" aria-labelledby="ug-tab-access" className="mt-4">
            <p className="text-sm text-gray-300">
              Checks for <strong className="text-white">{role.label.toLowerCase()}</strong>. Not you? Change your type above.
            </p>
            <ul className="mt-3 space-y-2">
              {role.diag.checks.map((chk) => (
                <li key={chk} className="flex items-start gap-2 text-sm">
                  <CheckIcon className="mt-0.5 h-4 w-4 shrink-0 text-[#F8BF1E]" aria-hidden="true" />
                  {chk}
                </li>
              ))}
            </ul>
            <div className={`mt-4 rounded-xl border p-4 ${c.inset}`}>
              <p className="text-sm font-black text-[#F8BF1E]">How to fix it</p>
              <p className="mt-1 text-sm leading-relaxed">
                <Rich text={role.diag.fix} linkClass={c.link} />
              </p>
            </div>
          </div>
        ) : (
          <div id="ug-panel-receipt" role="tabpanel" aria-labelledby="ug-tab-receipt" className="mt-4">
            <p className={`flex items-start gap-2 rounded-xl border p-3 text-sm font-semibold ${c.error}`} role="alert">
              <XCircleIcon className="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
              {OR_ERROR}
            </p>
            <div className="mt-3 divide-y divide-white/10">
              {OR_STAGES.map((st) => (
                <details key={st.id} open={st.id === 1} className="group py-3">
                  <summary className="flex cursor-pointer list-none items-center justify-between gap-4 font-bold [&::-webkit-details-marker]:hidden">
                    <span>Stage {st.id}: {st.title}</span>
                    <ChevronDownIcon className="h-5 w-5 shrink-0 transition-transform group-open:rotate-180" aria-hidden="true" />
                  </summary>
                  <ul className="mt-2 space-y-1.5 text-sm leading-relaxed text-gray-200">
                    {st.items.map((it, i) => (
                      <li key={i} className="flex gap-2">
                        <span className={dot} aria-hidden="true" />
                        <span>{it}</span>
                      </li>
                    ))}
                  </ul>
                </details>
              ))}
            </div>
          </div>
        )}
      </CollapsiblePanel>

      {/* FAQ (top 5) */}
      <CollapsiblePanel
        id="ug-faq"
        className={panel}
        title="Common questions"
        hint="Quick answers about logging in, receipts, and claiming"
      >
        <div className="divide-y divide-white/10">
          {faqs.map((f) => (
            <details key={f.id} name="ug-faq" className="group py-3">
              <summary className="flex cursor-pointer list-none items-center justify-between gap-4 font-bold [&::-webkit-details-marker]:hidden">
                <span>{f.question}</span>
                <ChevronDownIcon className="h-5 w-5 shrink-0 transition-transform group-open:rotate-180" aria-hidden="true" />
              </summary>
              <p className="mt-2 text-sm leading-relaxed text-gray-200">{f.answer}</p>
            </details>
          ))}
        </div>
      </CollapsiblePanel>

      {/* Contacts (top 3) */}
      <div className="mt-6 grid grid-cols-1 gap-3 md:grid-cols-3">
        {contacts.map((d) => (
          <div key={d.id} className="flex flex-col rounded-2xl border border-white/10 bg-[#450000] p-4">
            <p className="font-black">{d.office}</p>
            <p className="mt-1 flex-1 text-xs leading-relaxed text-gray-300">{d.purpose}</p>
            <div className={`mt-3 flex items-center justify-between gap-2 rounded-lg border px-3 py-2 ${c.inset}`}>
              <a href={`mailto:${d.email}`} className={`flex min-w-0 items-center gap-2 text-xs font-bold ${c.link} ${focusRing}`}>
                <EnvelopeIcon className="h-4 w-4 shrink-0" aria-hidden="true" />
                <span className="truncate">{d.email}</span>
              </a>
              <button
                type="button"
                onClick={() => copy(d.id, d.email)}
                aria-label={`Copy ${d.email}`}
                className={`inline-flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-xs font-bold ${c.link} ${focusRing}`}
              >
                {copiedKey === d.id ? (
                  <><CheckIcon className="h-4 w-4" aria-hidden="true" /> Copied</>
                ) : (
                  <><ClipboardDocumentIcon className="h-4 w-4" aria-hidden="true" /> Copy</>
                )}
              </button>
            </div>
          </div>
        ))}
      </div>

      {/* Link to the complete guide */}
      <div className="mt-10 text-center">
        <Link
          to={fullGuideHref}
          className={`inline-flex items-center gap-2 rounded-xl px-6 py-3 font-bold ${c.primary} ${focusRing}`}
        >
          Open the full user guide
          <ArrowTopRightOnSquareIcon className="h-4 w-4" aria-hidden="true" />
        </Link>
        <p className="mt-2 text-xs text-gray-300">
          Every FAQ, the complete receipt guide, a search box, and all official contacts.
        </p>
      </div>
    </section>
  );
};

/* Public full-page version (route: /faqs) */
export const PublicUserGuidePage = () => (
  <div className="relative min-h-screen">
    <div className="fixed inset-0" aria-hidden="true">
      <img src={risImage} alt="" className="h-full w-full object-cover" />
      <div className="absolute inset-0 lp-hero-bg-overlay" />
    </div>
    <div className="relative z-10">
      <FAQPage tone="maroon" showHeader />
    </div>
  </div>
);

/* -------------------------------------------------------------------------- */
/*  HELP CENTER (authenticated /help)                                          */
/*  Role-aware and task-first. Unified search with keyboard support, shareable */
/*  answer links, per-answer feedback, accessible and touch-friendly.          */
/*  Default export of this file.                                               */
/* -------------------------------------------------------------------------- */

const HELP_SECTIONS = [
  { id: 'help-request', label: 'Make a request' },
  { id: 'help-trouble', label: 'Fix a problem' },
  { id: 'help-faq', label: 'Questions' },
  { id: 'help-contact', label: 'Contact support' },
];

const SHARED_REQUEST_STEPS = [STEP_ACCESS_RIS, STEP_SUBMIT, STEP_TRACK];
const PRIMARY_CONTACT_IDS = ['registrar', 'tech4ward', 'helpdesk'];
const POPULAR_SEARCHES = ['Cashier Override', 'Official Receipt', 'PUPTAPS Alumni', 'Undergraduate'];

// One searchable index across answers, guide steps, and receipt fixes
const SEARCH_INDEX = [
  ...FAQS.map((f) => ({
    key: `faq-${f.id}`,
    type: 'Answer',
    title: f.question,
    body: f.answer,
    go: { kind: 'faq', id: f.id },
  })),
  ...[STEP_SUBMIT, STEP_TRACK].map((st, i) => ({
    key: `step-${i}`,
    type: 'Guide',
    title: st.title,
    body: st.items.join(' '),
    go: { kind: 'request' },
  })),
  ...OR_STAGES.map((st) => ({
    key: `or-${st.id}`,
    type: 'Receipt fix',
    title: `Stage ${st.id}: ${st.title}`,
    body: st.items.join(' '),
    go: { kind: 'receipt' },
  })),
  ...ROLES.flatMap((r) =>
    r.steps
      .filter((st) => !SHARED_REQUEST_STEPS.includes(st))
      .map((st, i) => ({
        key: `setup-${r.id}-${i}`,
        type: `Setup \u00b7 ${r.label}`,
        title: st.title,
        body: st.items.join(' '),
        go: { kind: 'setup', role: r.id },
      }))
  ),
  ...DIRECTORY.map((d) => ({
    key: `contact-${d.id}`,
    type: 'Contact',
    title: d.office,
    body: `${d.purpose} ${d.email || ''} ${d.pageName || ''}`,
    go: { kind: 'contact' },
  })),
];

const escapeRe = (str) => str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

const Highlight = ({ text, terms }) => {
  if (!terms.length) return text;
  const re = new RegExp(`(${terms.map(escapeRe).join('|')})`, 'gi');
  return text.split(re).map((part, i) =>
    i % 2 ? (
      <mark key={i} className="rounded bg-[#F8BF1E]/60 px-0.5 text-inherit">
        {part}
      </mark>
    ) : (
      <React.Fragment key={i}>{part}</React.Fragment>
    )
  );
};

const snippetOf = (body, term) => {
  const at = term ? body.toLowerCase().indexOf(term) : 0;
  const start = Math.max(0, at - 40);
  return `${start > 0 ? '\u2026' : ''}${body.slice(start, start + 120)}${body.length > start + 120 ? '\u2026' : ''}`;
};

// Maps the logged-in account to one of the four guide types.
const roleFromUser = (user) => {
  const r = String(user?.role_name || '').toLowerCase();
  if (r.includes('undergrad')) return 'undergrad';
  if (r.includes('alumni')) return 'alumni-sis';
  return 'student';
};

const StepList = ({ steps, c, dark }) => (
  <ol>
    {steps.map((step, i) => (
      <li key={i} className="relative flex gap-4 pb-7 last:pb-0">
        {i < steps.length - 1 && (
          <span className={`absolute left-4 top-9 bottom-0 w-0.5 -translate-x-1/2 ${c.stepLine}`} aria-hidden="true" />
        )}
        <span className={`relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-black ${c.stepDot}`}>
          {i + 1}
        </span>
        <div className="min-w-0 flex-1">
          <h4 className={`text-base sm:text-lg font-black leading-8 ${c.text}`}>{step.title}</h4>
          <ul className={`mt-1 max-w-prose space-y-1.5 text-sm sm:text-base leading-relaxed ${c.muted}`}>
            {step.items.map((item, j) => (
              <li key={j} className="flex gap-2">
                <span className={`mt-2.5 h-1.5 w-1.5 shrink-0 rounded-full ${dark ? 'bg-[#b0b3b8]' : 'bg-gray-400'}`} aria-hidden="true" />
                <span className="min-w-0"><Rich text={item} linkClass={c.link} /></span>
              </li>
            ))}
          </ul>
        </div>
      </li>
    ))}
  </ol>
);

const ContactEmail = ({ d, c, copiedKey, copy, focusRing }) => (
  <div className={`mt-3 flex items-center justify-between gap-2 rounded-lg border px-3 py-2 ${c.inset}`}>
    <a href={`mailto:${d.email}`} className={`flex min-h-9 min-w-0 items-center gap-2 text-sm font-bold ${c.link} ${focusRing}`}>
      <EnvelopeIcon className="h-4 w-4 shrink-0" aria-hidden="true" />
      <span className="truncate">{d.email}</span>
    </a>
    <button
      type="button"
      onClick={() => copy(d.id, d.email)}
      aria-label={`Copy ${d.email}`}
      className={`inline-flex min-h-9 shrink-0 items-center gap-1 rounded-md px-2 text-xs font-bold ${c.link} ${focusRing}`}
    >
      {copiedKey === d.id ? (
        <><CheckIcon className="h-4 w-4" aria-hidden="true" /> Copied</>
      ) : (
        <><ClipboardDocumentIcon className="h-4 w-4" aria-hidden="true" /> Copy</>
      )}
    </button>
  </div>
);

const Disclosure = ({ id, title, hint, open, onToggle, c, card, focusRing, children }) => (
  <div className={card}>
    <button
      type="button"
      id={`${id}-btn`}
      aria-expanded={open}
      aria-controls={`${id}-body`}
      onClick={onToggle}
      className={`flex min-h-14 w-full items-center justify-between gap-4 rounded-2xl p-4 text-left sm:p-5 ${focusRing}`}
    >
      <span className="min-w-0">
        <span className={`block font-black ${c.text}`}>{title}</span>
        {hint && <span className={`block text-sm font-normal ${c.muted}`}>{hint}</span>}
      </span>
      <ChevronDownIcon
        className={`h-5 w-5 shrink-0 transition-transform duration-200 motion-reduce:transition-none ${open ? 'rotate-180' : ''} ${c.muted}`}
        aria-hidden="true"
      />
    </button>
    <div
      id={`${id}-body`}
      role="region"
      aria-labelledby={`${id}-btn`}
      className={`grid transition-[grid-template-rows] duration-200 ease-out motion-reduce:transition-none ${open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'}`}
    >
      <div className={`overflow-hidden ${open ? '' : 'invisible'}`}>
        <div className="px-4 pb-5 sm:px-5">{children}</div>
      </div>
    </div>
  </div>
);

const HelpCenter = () => {
  const { isDark } = useTheme();
  const c = isDark ? DARK : LIGHT;
  const auth = useAuth();
  const user = auth?.user;
  const detectedRole = roleFromUser(user);
  const [roleId, setRoleId] = useState(detectedRole);
  useEffect(() => setRoleId(detectedRole), [detectedRole]);
  const role = ROLES.find((r) => r.id === roleId);
  const setupSteps = role.steps.filter((st) => !SHARED_REQUEST_STEPS.includes(st));

  const [query, setQuery] = useState('');
  const [activeIdx, setActiveIdx] = useState(0);
  const [category, setCategory] = useState('All');
  const [openIds, setOpenIds] = useState(() => new Set());
  const [helpTab, setHelpTab] = useState('receipt');
  const [activeSection, setActiveSection] = useState(HELP_SECTIONS[0].id);
  const [panels, setPanels] = useState({ steps: true });
  const [copiedKey, copy] = useCopy();

  const focusRing = `focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 ${c.focus}`;
  const card = `rounded-2xl border ${c.card}`;
  const inner = `rounded-xl border ${c.inset}`;
  const pill = 'inline-flex min-h-11 items-center rounded-full border px-4 text-sm font-bold transition-colors motion-reduce:transition-none';

  const isOpen = (id) => !!panels[id];
  const togglePanel = (id) => setPanels((p) => ({ ...p, [id]: !p[id] }));
  const openPanel = useCallback(
    (...ids) =>
      setPanels((p) => {
        const next = { ...p };
        ids.forEach((id) => (next[id] = true));
        return next;
      }),
    []
  );

  const jumpTo = useCallback((id) => {
    const el = document.getElementById(id);
    if (!el) return;
    const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    el.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
    if (!el.hasAttribute('tabindex')) el.setAttribute('tabindex', '-1');
    el.focus({ preventScroll: true });
  }, []);

  const goRequest = () => { openPanel('steps'); jumpTo('help-request'); };
  const goReceipt = () => { setHelpTab('receipt'); openPanel('trouble'); jumpTo('help-trouble'); };
  const goContact = () => { openPanel('contacts'); jumpTo('help-contact'); };

  /* ---------------- Search ---------------- */
  const terms = useMemo(
    () => query.trim().toLowerCase().split(/\s+/).filter(Boolean),
    [query]
  );
  const results = useMemo(() => {
    if (!terms.length) return [];
    return SEARCH_INDEX.map((item) => {
      const title = item.title.toLowerCase();
      const body = item.body.toLowerCase();
      if (!terms.every((t) => title.includes(t) || body.includes(t))) return null;
      const score = terms.reduce((n, t) => n + (title.includes(t) ? 2 : 1), 0);
      return { ...item, score };
    })
      .filter(Boolean)
      .sort((a, b) => b.score - a.score)
      .slice(0, 6);
  }, [terms]);

  useEffect(() => setActiveIdx(0), [query]);

  // "/" focuses the search box, like most docs sites
  useEffect(() => {
    const onKey = (e) => {
      if (e.key !== '/' || e.metaKey || e.ctrlKey || e.altKey) return;
      const t = e.target;
      const tag = t?.tagName;
      if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || t?.isContentEditable) return;
      e.preventDefault();
      document.querySelector('#help-search input')?.focus();
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, []);

  /* ---------------- Answers ---------------- */
  const toggleFaq = (id) =>
    setOpenIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });

  const openFaq = useCallback(
    (id) => {
      setQuery('');
      setCategory('All');
      setOpenIds((prev) => new Set(prev).add(id));
      setTimeout(() => jumpTo(`faq-item-${id}`), 80);
    },
    [jumpTo]
  );

  const visibleFaqs = FAQS.filter((f) => category === 'All' || f.category === category);
  const allOpen = visibleFaqs.length > 0 && visibleFaqs.every((f) => openIds.has(f.id));
  const toggleAll = () =>
    setOpenIds((prev) => {
      const next = new Set(prev);
      visibleFaqs.forEach((f) => (allOpen ? next.delete(f.id) : next.add(f.id)));
      return next;
    });

  const goTo = (item) => {
    if (item.go.kind === 'faq') return openFaq(item.go.id);
    setQuery('');
    if (item.go.kind === 'receipt') return goReceipt();
    if (item.go.kind === 'contact') return goContact();
    if (item.go.kind === 'setup') {
      setRoleId(item.go.role);
      openPanel('setup');
      return jumpTo('help-request');
    }
    return goRequest();
  };

  const onSearchKey = (e) => {
    if (e.key === 'Escape') {
      setQuery('');
      return;
    }
    if (!terms.length || !results.length) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setActiveIdx((i) => Math.min(i + 1, results.length - 1));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setActiveIdx((i) => Math.max(i - 1, 0));
    } else if (e.key === 'Enter') {
      e.preventDefault();
      goTo(results[activeIdx]);
    }
  };

  // Highlights the section currently in view in the side nav
  useEffect(() => {
    if (typeof IntersectionObserver === 'undefined') return undefined;
    const els = HELP_SECTIONS.map((sec) => document.getElementById(sec.id)).filter(Boolean);
    const io = new IntersectionObserver(
      (entries) => entries.forEach((e) => e.isIntersecting && setActiveSection(e.target.id)),
      { rootMargin: '-15% 0px -70% 0px' }
    );
    els.forEach((el) => io.observe(el));
    return () => io.disconnect();
  }, []);

  const primaryContacts = DIRECTORY.filter((d) => PRIMARY_CONTACT_IDS.includes(d.id));
  const otherContacts = DIRECTORY.filter((d) => !PRIMARY_CONTACT_IDS.includes(d.id));
  const dl = { c, card, focusRing };
  const bullet = `mt-2 h-1.5 w-1.5 shrink-0 rounded-full ${isDark ? 'bg-[#b0b3b8]' : 'bg-gray-400'}`;

  const QUICK_ACTIONS = [
    { icon: ClipboardDocumentCheckIcon, title: 'Make a request', hint: 'Steps and your Claim Ticket', run: goRequest },
    { icon: XCircleIcon, title: 'Receipt rejected', hint: 'Fix an OR error', run: goReceipt },
    { icon: ClipboardDocumentIcon, title: 'Claim my documents', hint: 'What to bring', run: () => openFaq(7) },
    { icon: PhoneArrowUpRightIcon, title: 'Talk to someone', hint: 'Registrar and tech support', run: goContact },
  ];

  return (
    <div className={`min-h-screen font-sans ${c.page} ${isDark ? 'bg-[#18191a]' : ''}`}>
      <a
        href="#help-request"
        onClick={(e) => {
          e.preventDefault();
          jumpTo('help-request');
        }}
        className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:font-bold focus:text-[#800000]"
      >
        Skip to help content
      </a>

      <div className="mx-auto max-w-6xl px-4 pb-16 pt-6">
        {/* Hero */}
        <section
          className="rounded-3xl border-b-4 border-[#F8BF1E] bg-[#660000] px-5 py-8 text-center sm:px-10 sm:py-12"
          aria-labelledby="help-title"
        >
          <span className="inline-block rounded-full border border-[#F8BF1E]/70 px-4 py-1 text-xs font-semibold text-[#F8BF1E] sm:text-sm">
            Step-by-Step Portal Guide
          </span>

          <h1
            id="help-title"
            className="mx-auto mt-4 max-w-3xl text-3xl font-extrabold leading-tight tracking-tight text-white sm:text-4xl lg:text-5xl"
          >
            How can we help your Registrar transaction today?
          </h1>
          <p className="mx-auto mt-3 max-w-xl text-xs text-gray-200 sm:text-sm">
            Access guides, verify required accounts, resolve verification errors, and track your request seamlessly.
          </p>

          <div id="help-search" className="mx-auto mt-6 max-w-2xl text-left" onKeyDown={onSearchKey}>
            <div
              className="overflow-hidden rounded-full border-2 border-[#F8BF1E] bg-white shadow-md transition-shadow focus-within:shadow-[0_0_0_4px_rgba(248,191,30,0.35)] motion-reduce:transition-none [&>*]:!rounded-full [&>*]:!border-0 [&>*]:!shadow-none [&_input]:!rounded-full [&_input]:!border-0 [&_input]:!shadow-none [&_input]:!ring-0 [&_input]:!outline-none"
            >
              <VoiceSearchInput
                value={query}
                onChange={setQuery}
                placeholder="Search keywords: ‘OR error’, ‘GUISIS’, ‘Claim ticket’…"
                language="en-US"
              />
            </div>
          </div>

          <div className="mt-4 flex flex-wrap items-center justify-center gap-2" aria-label="Popular searches">
            <span className="text-xs font-semibold text-gray-200">Popular:</span>
            {POPULAR_SEARCHES.map((tag) => (
              <button
                key={tag}
                type="button"
                onClick={() => setQuery(tag)}
                className="inline-flex min-h-9 items-center rounded-full border border-white/30 bg-white/10 px-3.5 text-xs font-semibold text-white transition-colors hover:bg-white/20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#F8BF1E] motion-reduce:transition-none sm:text-sm"
              >
                {tag}
              </button>
            ))}
          </div>

          <p className="sr-only" role="status" aria-live="polite">
            {terms.length ? `${results.length} result${results.length === 1 ? '' : 's'}` : ''}
          </p>

          {terms.length > 0 && (
            <div className="mx-auto mt-4 max-w-2xl overflow-hidden rounded-2xl border border-gray-200 bg-white text-left text-gray-800 shadow-lg">
              {results.length === 0 ? (
                <div className="p-4 text-sm">
                  <p className="font-bold text-[#800000]">No results for “{query.trim()}”</p>
                  <p className="mt-1 text-gray-600">Check the spelling, try fewer words, or ask the Registrar directly.</p>
                  <button
                    type="button"
                    onClick={goContact}
                    className="mt-3 inline-flex min-h-11 items-center rounded-lg bg-[#800000] px-4 font-bold text-white hover:bg-[#660000]"
                  >
                    Contact support
                  </button>
                </div>
              ) : (
                <ul className="divide-y divide-gray-100" role="listbox" aria-label="Search results">
                  {results.map((r, idx) => (
                    <li key={r.key} role="option" aria-selected={idx === activeIdx}>
                      <button
                        type="button"
                        onClick={() => goTo(r)}
                        onMouseEnter={() => setActiveIdx(idx)}
                        className={`flex min-h-11 w-full items-start gap-3 p-3 text-left text-sm ${idx === activeIdx ? 'bg-red-50' : ''}`}
                      >
                        <MagnifyingGlassIcon className="mt-0.5 h-4 w-4 shrink-0 text-[#800000]" aria-hidden="true" />
                        <span className="min-w-0">
                          <span className="block font-bold text-[#800000]">
                            <Highlight text={r.title} terms={terms} />
                          </span>
                          <span className="block text-xs text-gray-600">
                            <Highlight text={snippetOf(r.body, terms[0])} terms={terms} />
                          </span>
                          <span className="mt-1 inline-block rounded bg-gray-100 px-1.5 py-0.5 text-[11px] font-bold text-gray-600">
                            {r.type}
                          </span>
                        </span>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}
        </section>

        {/* Quick actions */}
        <section className="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Quick actions">
          {QUICK_ACTIONS.map(({ icon: Icon, title, hint, run }) => (
            <button
              key={title}
              type="button"
              onClick={run}
              className={`flex min-h-24 flex-col items-start gap-1 p-4 text-left transition-shadow hover:shadow-md motion-reduce:transition-none ${card} ${focusRing}`}
            >
              <Icon className={`h-6 w-6 ${c.heading}`} aria-hidden="true" />
              <span className={`mt-1 font-black ${c.text}`}>{title}</span>
              <span className={`text-xs ${c.muted}`}>{hint}</span>
            </button>
          ))}
        </section>

        <div className="mt-10 grid gap-8 lg:grid-cols-[200px_minmax(0,1fr)]">
          {/* Section nav */}
          <nav
            aria-label="On this page"
            className="-mx-4 flex gap-2 overflow-x-auto px-4 lg:sticky lg:top-24 lg:mx-0 lg:flex-col lg:gap-1 lg:self-start lg:px-0"
          >
            {HELP_SECTIONS.map((sec) => {
              const on = activeSection === sec.id;
              return (
                <button
                  key={sec.id}
                  type="button"
                  aria-current={on ? 'true' : undefined}
                  onClick={() => jumpTo(sec.id)}
                  className={`${pill} whitespace-nowrap lg:rounded-lg lg:text-left ${on ? c.tabOn : c.tabOff} ${focusRing}`}
                >
                  {sec.label}
                </button>
              );
            })}
          </nav>

          <div className="min-w-0 space-y-12">
            {/* Make a request */}
            <section id="help-request" className="scroll-mt-24 focus:outline-none" aria-labelledby="help-request-title">
              <h2 id="help-request-title" className={`text-xl font-black sm:text-2xl ${c.heading}`}>Make a request</h2>
              <p className={`mt-1 text-sm sm:text-base ${c.muted}`}>
                Guide shown for <strong className={c.text}>{role.label.toLowerCase()}</strong>.
              </p>

              <div role="group" aria-label="Guide type" className="mt-4 flex flex-wrap gap-2">
                {ROLES.map((r) => (
                  <button
                    key={r.id}
                    type="button"
                    aria-pressed={roleId === r.id}
                    onClick={() => setRoleId(r.id)}
                    className={`${pill} ${roleId === r.id ? c.tabOn : c.tabOff} ${focusRing}`}
                  >
                    {r.label}
                  </button>
                ))}
              </div>

              <div className="mt-4 space-y-3">
                <Disclosure
                  id="dl-steps"
                  title="Submit and claim: 2 steps"
                  hint="Fill in the Request Form, then track and claim"
                  open={isOpen('steps')}
                  onToggle={() => togglePanel('steps')}
                  {...dl}
                >
                  <StepList steps={[STEP_SUBMIT, STEP_TRACK]} c={c} dark={isDark} />
                </Disclosure>

                <Disclosure
                  id="dl-setup"
                  title="First-time setup steps"
                  hint="Already done if you can see your dashboard"
                  open={isOpen('setup')}
                  onToggle={() => togglePanel('setup')}
                  {...dl}
                >
                  <p className={`mb-4 rounded-lg border p-3 text-sm font-semibold ${c.warn}`}>{role.banner}</p>
                  <StepList steps={setupSteps} c={c} dark={isDark} />
                </Disclosure>
              </div>
            </section>

            {/* Fix a problem */}
            <section id="help-trouble" className="scroll-mt-24 focus:outline-none" aria-labelledby="help-trouble-title">
              <h2 id="help-trouble-title" className={`mb-4 text-xl font-black sm:text-2xl ${c.heading}`}>Fix a problem</h2>

              <Disclosure
                id="dl-trouble"
                title="Receipt rejected or feature blocked"
                hint="Choose what you are seeing"
                open={isOpen('trouble')}
                onToggle={() => togglePanel('trouble')}
                {...dl}
              >
                <div role="tablist" aria-label="Problem type" className="flex flex-wrap gap-2">
                  {[
                    { id: 'receipt', label: 'My receipt was rejected' },
                    { id: 'access', label: 'A feature is blocked' },
                  ].map((t) => (
                    <button
                      key={t.id}
                      type="button"
                      role="tab"
                      id={`help-tab-${t.id}`}
                      aria-selected={helpTab === t.id}
                      aria-controls={`help-panel-${t.id}`}
                      onClick={() => setHelpTab(t.id)}
                      className={`${pill} ${helpTab === t.id ? c.tabOn : c.tabOff} ${focusRing}`}
                    >
                      {t.label}
                    </button>
                  ))}
                </div>

                {helpTab === 'receipt' ? (
                  <div id="help-panel-receipt" role="tabpanel" aria-labelledby="help-tab-receipt" className="mt-4">
                    <p className={`flex items-start gap-2 rounded-xl border p-4 text-sm font-semibold sm:text-base ${c.error}`} role="alert">
                      <XCircleIcon className="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
                      {OR_ERROR}
                    </p>
                    <p className={`mt-3 text-sm ${c.muted}`}>Try these in order. Most receipts are fixed by stage 1 or 2.</p>
                    <div className="mt-3 grid gap-3 md:grid-cols-3">
                      {OR_STAGES.map((st) => (
                        <article key={st.id} className={`p-4 ${inner}`}>
                          <div className="flex items-center gap-3">
                            <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-black ${c.stepDot}`}>{st.id}</span>
                            <h3 className={`font-black leading-tight ${c.text}`}>{st.title}</h3>
                          </div>
                          <ul className={`mt-3 space-y-1.5 text-sm leading-relaxed ${c.muted}`}>
                            {st.items.map((it, i) => (
                              <li key={i} className="flex gap-2">
                                <span className={bullet} aria-hidden="true" />
                                <span>{it}</span>
                              </li>
                            ))}
                          </ul>
                          {st.id === 2 && (
                            <div className={`mt-3 flex items-center justify-between gap-2 rounded-lg border px-3 py-2 ${c.card}`}>
                              <code className={`text-xs font-bold ${c.text}`}>LASTNAME, FIRSTNAME MIDDLEINITIAL</code>
                              <button
                                type="button"
                                onClick={() => copy('or-format', 'LASTNAME, FIRSTNAME MIDDLEINITIAL')}
                                className={`inline-flex min-h-9 items-center gap-1 px-1 text-xs font-bold ${c.link} ${focusRing}`}
                              >
                                {copiedKey === 'or-format' ? <CheckIcon className="h-4 w-4" aria-hidden="true" /> : <ClipboardDocumentIcon className="h-4 w-4" aria-hidden="true" />}
                                {copiedKey === 'or-format' ? 'Copied' : 'Copy'}
                              </button>
                            </div>
                          )}
                        </article>
                      ))}
                    </div>
                  </div>
                ) : (
                  <div id="help-panel-access" role="tabpanel" aria-labelledby="help-tab-access" className="mt-4">
                    <p className={`text-sm ${c.muted}`}>
                      Checks for <strong className={c.text}>{role.label.toLowerCase()}</strong>. Not you? Change the guide type under “Make a request”.
                    </p>
                    <ul className="mt-3 space-y-2">
                      {role.diag.checks.map((chk) => (
                        <li key={chk} className={`flex items-start gap-2 text-sm sm:text-base ${c.text}`}>
                          <CheckIcon className={`mt-1 h-4 w-4 shrink-0 ${c.heading}`} aria-hidden="true" />
                          {chk}
                        </li>
                      ))}
                    </ul>
                    <div className={`mt-4 p-4 ${inner}`}>
                      <p className={`text-sm font-black ${c.heading}`}>How to fix it</p>
                      <p className={`mt-1 ${c.text}`}><Rich text={role.diag.fix} linkClass={c.link} /></p>
                    </div>
                  </div>
                )}
              </Disclosure>
            </section>

            {/* Questions */}
            <section id="help-faq" className="scroll-mt-24 focus:outline-none" aria-labelledby="help-faq-title">
              <div className="flex flex-wrap items-end justify-between gap-3">
                <h2 id="help-faq-title" className={`text-xl font-black sm:text-2xl ${c.heading}`}>Questions</h2>
                <button type="button" onClick={toggleAll} className={`${pill} ${c.chip} ${focusRing}`}>
                  {allOpen ? 'Collapse all' : 'Expand all'}
                </button>
              </div>

              <div className="-mx-1 mb-4 mt-3 flex gap-2 overflow-x-auto px-1 pb-1" role="group" aria-label="Question topics">
                {['All', ...FAQ_CATEGORIES].map((cat) => (
                  <button
                    key={cat}
                    type="button"
                    aria-pressed={category === cat}
                    onClick={() => setCategory(cat)}
                    className={`${pill} whitespace-nowrap ${category === cat ? c.tabOn : c.tabOff} ${focusRing}`}
                  >
                    {cat}
                  </button>
                ))}
              </div>

              <div className="space-y-3">
                {visibleFaqs.map((f) => {
                  const open = openIds.has(f.id);
                  return (
                    <div
                      key={f.id}
                      id={`faq-item-${f.id}`}
                      className={`scroll-mt-24 overflow-hidden focus:outline-none ${card} ${open ? (isDark ? 'border-[#eebc48]/60' : 'border-[#800000]') : ''}`}
                    >
                      <h3>
                        <button
                          type="button"
                          aria-expanded={open}
                          aria-controls={`faq-body-${f.id}`}
                          onClick={() => toggleFaq(f.id)}
                          className={`flex min-h-14 w-full items-center justify-between gap-4 p-4 text-left sm:p-5 ${focusRing}`}
                        >
                          <span className={`text-base font-bold sm:text-lg ${isDark ? 'text-white' : 'text-[#800000]'}`}>{f.question}</span>
                          <ChevronDownIcon className={`h-5 w-5 shrink-0 transition-transform duration-200 motion-reduce:transition-none ${open ? 'rotate-180' : ''} ${c.muted}`} aria-hidden="true" />
                        </button>
                      </h3>
                      <div
                        id={`faq-body-${f.id}`}
                        role="region"
                        aria-label={f.question}
                        className={`grid transition-[grid-template-rows] duration-200 ease-out motion-reduce:transition-none ${open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'}`}
                      >
                        <div className={`overflow-hidden ${open ? '' : 'invisible'}`}>
                          <div className="px-4 pb-5 sm:px-5">
                            <p className={`max-w-prose leading-relaxed ${c.muted}`}>{f.answer}</p>

                            {f.jumpTo && (
                              <button
                                type="button"
                                onClick={goReceipt}
                                className={`mt-3 inline-flex min-h-11 items-center text-sm font-bold underline ${c.link} ${focusRing}`}
                              >
                                Open the 3-stage receipt guide
                              </button>
                            )}

                            {open && f.embedUrl && (
                              <div className="relative mt-4 w-full max-w-lg overflow-hidden rounded-xl bg-black" style={{ aspectRatio: '16 / 9' }}>
                                <iframe
                                  src={f.embedUrl}
                                  title={f.question}
                                  className="absolute inset-0 h-full w-full border-0"
                                  allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                  allowFullScreen
                                  loading="lazy"
                                />
                              </div>
                            )}
                            {f.link && (
                              <a
                                href={f.link}
                                target="_blank"
                                rel="noopener noreferrer"
                                className={`mt-4 inline-flex min-h-11 items-center gap-2 rounded-lg px-4 text-sm font-bold ${c.primary} ${focusRing}`}
                              >
                                {f.linkLabel || 'Watch on YouTube'}
                                <ArrowTopRightOnSquareIcon className="h-4 w-4" aria-hidden="true" />
                              </a>
                            )}
                          </div>
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            </section>

            {/* Contact support */}
            <section id="help-contact" className="scroll-mt-24 focus:outline-none" aria-labelledby="help-contact-title">
              <h2 id="help-contact-title" className={`text-xl font-black sm:text-2xl ${c.heading}`}>Contact support</h2>
              <p className={`mt-1 text-sm sm:text-base ${c.muted}`}>
                Official business hours are Tuesday to Friday, 8:00 AM to 8:00 PM.
              </p>

              <a
                data-voice-action="compose-email"
                href="https://mail.google.com/mail/u/0/?view=cm&fs=1&to=taguig@pup.edu.ph%2Ctaguig.registrar@pup.edu.ph&su=Inquiry%3A%20PUP%20Registrar%20Office%20Concern"
                target="_blank"
                rel="noopener noreferrer"
                className={`mt-4 inline-flex min-h-12 items-center justify-center gap-2 rounded-xl px-6 font-bold ${c.primary} ${focusRing}`}
              >
                <EnvelopeIcon className="h-5 w-5" aria-hidden="true" />
                Email the Registrar
              </a>

              <div className="mt-4 space-y-3">
                <Disclosure
                  id="dl-contacts"
                  title="Contact directory"
                  hint="Registrar, helpdesk, tech support, and social pages"
                  open={isOpen('contacts')}
                  onToggle={() => togglePanel('contacts')}
                  {...dl}
                >
                  <div className="grid gap-3 md:grid-cols-3">
                    {primaryContacts.map((d) => (
                      <article key={d.id} className={`flex flex-col p-4 ${inner}`}>
                        <h3 className={`font-black ${c.text}`}>{d.office}</h3>
                        <p className={`mt-1 flex-1 text-sm leading-relaxed ${c.muted}`}>{d.purpose}</p>
                        <ContactEmail d={d} c={c} copiedKey={copiedKey} copy={copy} focusRing={focusRing} />
                      </article>
                    ))}
                  </div>
                  <p className={`mb-2 mt-5 text-sm font-black ${c.text}`}>More channels</p>
                  <ul className="grid gap-3 md:grid-cols-3">
                    {otherContacts.map((d) => (
                      <li key={d.id} className={`p-3 ${inner}`}>
                        <p className={`text-sm font-black ${c.text}`}>{d.office}</p>
                        <p className={`mt-1 text-xs leading-relaxed ${c.muted}`}>{d.purpose}</p>
                        {d.email ? (
                          <ContactEmail d={d} c={c} copiedKey={copiedKey} copy={copy} focusRing={focusRing} />
                        ) : d.url ? (
                          <a href={d.url} target="_blank" rel="noopener noreferrer" className={`mt-2 inline-flex min-h-11 items-center gap-1 text-sm font-bold underline ${c.link} ${focusRing}`}>
                            Open on Facebook <ArrowTopRightOnSquareIcon className="h-4 w-4" aria-hidden="true" />
                          </a>
                        ) : (
                          <p className={`mt-2 text-xs font-semibold ${c.muted}`}>Search “{d.pageName}” on Facebook.</p>
                        )}
                      </li>
                    ))}
                  </ul>
                </Disclosure>

                <Disclosure
                  id="dl-include"
                  title="What to include in your message"
                  hint="Helps us answer faster"
                  open={isOpen('include')}
                  onToggle={() => togglePanel('include')}
                  {...dl}
                >
                  <ul className={`space-y-1.5 text-sm sm:text-base ${c.muted}`}>
                    {['Your full name and student number', 'What you were trying to do', 'A screenshot of any error message', 'Your Claim Code, if you have one'].map((t) => (
                      <li key={t} className="flex gap-2">
                        <CheckIcon className={`mt-1 h-4 w-4 shrink-0 ${c.heading}`} aria-hidden="true" />
                        {t}
                      </li>
                    ))}
                  </ul>
                </Disclosure>
              </div>
            </section>
          </div>
        </div>
      </div>
    </div>
  );
};

export { FAQPage };
export default HelpCenter;