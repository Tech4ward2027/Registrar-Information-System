/**
 * Ticket System Constants and Types
 * Based on RIS Ticketing System Policy v1.0
 */

export const TICKET_STATUS = {
  OPEN: 'Open',
  IN_PROGRESS: 'In Progress',
  WAITING_FOR_STUDENT: 'Waiting for Student',
  RESOLVED: 'Resolved',
  CLOSED: 'Closed',
};

export const TICKET_CATEGORIES = [
  { value: 'Technical', label: 'Technical Issue / Portal Glitch' },
  { value: 'Billing', label: 'Billing / Cashier / Payment Record' },
  { value: 'Document', label: 'Document Request Inquiry / Status' },
  { value: 'Account', label: 'RIS Account / IdP / Profile Access' },
  { value: 'Other', label: 'Other Registrar-related Inquiries' },
];

export const TICKET_PRIORITIES = [
  { value: 'Low', label: 'Low', badgeClass: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' },
  { value: 'Normal', label: 'Normal', badgeClass: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' },
  { value: 'High', label: 'High', badgeClass: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' },
  { value: 'Urgent', label: 'Urgent', badgeClass: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' },
];

export const TICKET_STATUS_CONFIG = {
  Open: {
    label: 'Open',
    badgeClass: 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-800/40 dark:text-slate-300 dark:border-slate-700',
    description: 'Submitted and awaiting staff attention.',
  },
  'In Progress': {
    label: 'In Progress',
    badgeClass: 'bg-blue-100 text-blue-700 border-blue-200 dark:bg-blue-900/30 dark:text-blue-400 dark:border-blue-800',
    description: 'A staff member is actively investigating or addressing the concern.',
  },
  'Waiting for Student': {
    label: 'Waiting for Student',
    badgeClass: 'bg-purple-100 text-purple-700 border-purple-200 dark:bg-purple-900/30 dark:text-purple-400 dark:border-purple-800',
    description: 'Staff requires additional information from the student.',
  },
  Resolved: {
    label: 'Resolved',
    badgeClass: 'bg-emerald-100 text-emerald-700 border-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-400 dark:border-emerald-800',
    description: 'The concern has been addressed and a resolution note has been recorded.',
  },
  Closed: {
    label: 'Closed',
    badgeClass: 'bg-gray-100 text-gray-600 border-gray-300 dark:bg-gray-800/50 dark:text-gray-400 dark:border-gray-700',
    description: 'The ticket has been finalized and is read-only.',
  },
};

export const INITIAL_TICKETS = [
  {
    id: 'tkt-1',
    ticket_number: 'TKT-2025-0048',
    subject: 'Cannot access document',
    category: 'Technical',
    priority: 'High',
    status: 'In Progress',
    created_at: '2025-02-03T10:24:00',
    updated_at: '2025-02-03T11:05:00',
    creator: {
      name: 'John Carlo Santos',
      student_number: '2022-00123-TG-0',
      role: 'student',
      email: 'jcsantos@iskolarngbayan.pup.edu.ph',
    },
    active_handler: {
      id: 'staff-4',
      name: 'Maria Dela Cruz (Registrar Support)',
      started_at: '2025-02-03T10:45:00',
    },
    description: 'Whenever I click on the generate preview for my Certificate of Enrollment, the screen displays a blank overlay and spins indefinitely. I have cleared cache and tried using Chrome and Edge.',
    attachments: [
      { name: 'error_screenshot.png', size: '1.2 MB', type: 'image/png' },
    ],
    messages: [
      {
        id: 'msg-1',
        sender_type: 'student',
        sender_name: 'John Carlo Santos',
        content: 'Whenever I click on the generate preview for my Certificate of Enrollment, the screen displays a blank overlay and spins indefinitely. I have cleared cache and tried using Chrome and Edge.',
        timestamp: '2025-02-03T10:24:00',
        attachments: [{ name: 'error_screenshot.png', size: '1.2 MB' }],
      },
      {
        id: 'msg-2',
        sender_type: 'staff',
        sender_name: 'Maria Dela Cruz (Registrar Support)',
        content: 'Hello John Carlo. We are currently checking your academic term record configuration in the backend server. Please hold on while we run the certificate sync.',
        timestamp: '2025-02-03T10:48:00',
        is_internal: false,
      },
    ],
    activities: [
      { id: 'act-1', text: 'Ticket submitted with status Open', actor: 'John Carlo Santos', timestamp: '2025-02-03T10:24:00' },
      { id: 'act-2', text: 'Status changed to In Progress', actor: 'Maria Dela Cruz', timestamp: '2025-02-03T10:45:00' },
      { id: 'act-3', text: 'Staff reply sent to student', actor: 'Maria Dela Cruz', timestamp: '2025-02-03T10:48:00' },
    ],
    resolution_note: null,
  },
  {
    id: 'tkt-2',
    ticket_number: 'TKT-2025-0047',
    subject: 'Payment not reflected',
    category: 'Billing',
    priority: 'Normal',
    status: 'Open',
    created_at: '2025-02-03T09:12:00',
    updated_at: '2025-02-03T09:12:00',
    creator: {
      name: 'Maria Santos',
      student_number: '2021-00452-TG-0',
      role: 'student',
      email: 'mariasantos@iskolarngbayan.pup.edu.ph',
    },
    active_handler: null,
    description: 'I paid 150 PHP via LandBank Link.BizPortal for my Official Transcript of Records request yesterday morning, but the system still asks for receipt validation and says "No matched transaction found". Attached is my confirmation receipt.',
    attachments: [
      { name: 'landbank_receipt_29482.pdf', size: '420 KB', type: 'application/pdf' },
    ],
    messages: [
      {
        id: 'msg-201',
        sender_type: 'student',
        sender_name: 'Maria Santos',
        content: 'I paid 150 PHP via LandBank Link.BizPortal for my Official Transcript of Records request yesterday morning, but the system still asks for receipt validation and says "No matched transaction found". Attached is my confirmation receipt.',
        timestamp: '2025-02-03T09:12:00',
        attachments: [{ name: 'landbank_receipt_29482.pdf', size: '420 KB' }],
      },
    ],
    activities: [
      { id: 'act-201', text: 'Ticket submitted with status Open', actor: 'Maria Santos', timestamp: '2025-02-03T09:12:00' },
    ],
    resolution_note: null,
  },
  {
    id: 'tkt-3',
    ticket_number: 'TKT-2025-0046',
    subject: 'Certificate of Enrollment request',
    category: 'Document',
    priority: 'Normal',
    status: 'Waiting for Student',
    created_at: '2025-02-02T16:50:00',
    updated_at: '2025-02-02T17:15:00',
    creator: {
      name: 'Carlos Mendoza',
      student_number: '2023-01892-TG-0',
      role: 'student',
      email: 'cmendoza@iskolarngbayan.pup.edu.ph',
    },
    active_handler: null,
    description: 'Requesting clarification if my COE can indicate my Latin honor candidate standing for scholarship renewal.',
    attachments: [],
    messages: [
      {
        id: 'msg-301',
        sender_type: 'student',
        sender_name: 'Carlos Mendoza',
        content: 'Requesting clarification if my COE can indicate my Latin honor candidate standing for scholarship renewal.',
        timestamp: '2025-02-02T16:50:00',
      },
      {
        id: 'msg-302',
        sender_type: 'staff',
        sender_name: 'Registrar Evaluation Officer',
        content: 'Please upload a signed copy of your latest Curriculum Checklist or department evaluation sheet so we can verify with your Academic Department Chairperson.',
        timestamp: '2025-02-02T17:15:00',
      },
    ],
    activities: [
      { id: 'act-301', text: 'Ticket created', actor: 'Carlos Mendoza', timestamp: '2025-02-02T16:50:00' },
      { id: 'act-302', text: 'Status changed to Waiting for Student', actor: 'Registrar Evaluation Officer', timestamp: '2025-02-02T17:15:00' },
    ],
    resolution_note: null,
  },
  {
    id: 'tkt-4',
    ticket_number: 'TKT-2025-0044',
    subject: 'Lost ID / Student number',
    category: 'Account',
    priority: 'Low',
    status: 'Open',
    created_at: '2025-02-02T11:32:00',
    updated_at: '2025-02-02T11:32:00',
    creator: {
      name: 'Bea Clarisse Reyes',
      student_number: '2020-00891-TG-0',
      role: 'student',
      email: 'bcreyes@iskolarngbayan.pup.edu.ph',
    },
    active_handler: null,
    description: 'I lost my physical student ID card during transit. What are the requirements to submit for an ID replacement endorsement from the Registrar?',
    attachments: [],
    messages: [
      {
        id: 'msg-401',
        sender_type: 'student',
        sender_name: 'Bea Clarisse Reyes',
        content: 'I lost my physical student ID card during transit. What are the requirements to submit for an ID replacement endorsement from the Registrar?',
        timestamp: '2025-02-02T11:32:00',
      },
    ],
    activities: [
      { id: 'act-401', text: 'Ticket created', actor: 'Bea Clarisse Reyes', timestamp: '2025-02-02T11:32:00' },
    ],
    resolution_note: null,
  },
  {
    id: 'tkt-5',
    ticket_number: 'TKT-2025-0043',
    subject: 'System error when logging in',
    category: 'Technical',
    priority: 'Urgent',
    status: 'Resolved',
    created_at: '2025-02-02T09:48:00',
    updated_at: '2025-02-02T14:30:00',
    creator: {
      name: 'Kenneth Alcantara',
      student_number: '2019-00331-TG-0',
      role: 'alumni',
      email: 'kalcantara@alumni.pup.edu.ph',
    },
    active_handler: null,
    description: 'Encountered 500 error when completing IdP callback after entering Google 2FA.',
    attachments: [],
    messages: [
      {
        id: 'msg-501',
        sender_type: 'student',
        sender_name: 'Kenneth Alcantara',
        content: 'Encountered 500 error when completing IdP callback after entering Google 2FA.',
        timestamp: '2025-02-02T09:48:00',
      },
      {
        id: 'msg-502',
        sender_type: 'staff',
        sender_name: 'System Admin Tech Team',
        content: 'The IdP session token had a minor schema mismatch during the morning maintenance window. A hotfix was deployed at 14:15. Please try logging in again.',
        timestamp: '2025-02-02T14:28:00',
      },
    ],
    activities: [
      { id: 'act-501', text: 'Ticket created', actor: 'Kenneth Alcantara', timestamp: '2025-02-02T09:48:00' },
      { id: 'act-502', text: 'Status changed to In Progress', actor: 'System Admin Tech Team', timestamp: '2025-02-02T10:00:00' },
      { id: 'act-503', text: 'Marked as Resolved with resolution note recorded', actor: 'System Admin Tech Team', timestamp: '2025-02-02T14:30:00' },
    ],
    resolution_note: 'Resolved by clearing stale session in IdP bridge redis cache and re-syncing alumni account provisioning.',
  },
];
