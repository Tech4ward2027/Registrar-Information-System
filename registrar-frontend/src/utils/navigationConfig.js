import {
  Squares2X2Icon,
  TableCellsIcon,
  DocumentDuplicateIcon,
  ClipboardDocumentCheckIcon,
  QuestionMarkCircleIcon,
  UserCircleIcon,
  AcademicCapIcon,
  ChartBarSquareIcon,
  BookOpenIcon,
  Cog6ToothIcon,
  InboxIcon,
  ShieldCheckIcon,
  CalendarDaysIcon,
} from '@heroicons/react/24/outline';
import { MODULE_KEYS } from './policy';

export const ROLE_CONFIG = {
  student: {
    profileKey: 'student_profile',
    profileLabel: (user) => user?.academic_record?.student_number || user?.email || 'Student',
    sections: [
      {
        title: 'Overview',
        items: [
          { name: 'Dashboard', to: 'home', icon: Squares2X2Icon },
          { name: 'Inbox', to: 'inbox', icon: InboxIcon },
        ],
      },
      {
        title: 'Student services',
        items: [
          { name: 'Document Lists', to: 'lists', icon: TableCellsIcon },
          { name: 'Student Requests', to: 'request', icon: ClipboardDocumentCheckIcon },
          { name: 'FAQs & Support', to: 'faqs', icon: QuestionMarkCircleIcon },
        ],
      },
    ],
  },
  alumni: {
    profileKey: 'alumni_profile',
    profileLabel: (user) => user?.email || 'Alumni',
    sections: [
      {
        title: 'Overview',
        items: [
          { name: 'Dashboard', to: 'home', icon: Squares2X2Icon },
          { name: 'Inbox', to: 'inbox', icon: InboxIcon },
        ],
      },
      {
        title: 'Alumni Services',
        items: [
          { name: 'Document Lists', to: 'lists', icon: TableCellsIcon },
          { name: 'Alumni Request', to: 'request', icon: AcademicCapIcon },
          { name: 'FAQs & Support', to: 'faqs', icon: QuestionMarkCircleIcon },
        ],
      },
    ],
  },
  staff: {
    profileKey: 'admin_profile',
    profileLabel: (user) => user?.email,
    sections: [
      {
        title: 'Overview',
        items: [
          { name: 'Dashboard', to: 'dashboard', icon: Squares2X2Icon, module: MODULE_KEYS.DASHBOARD },
          { name: 'Inbox', to: 'inbox', icon: InboxIcon, module: MODULE_KEYS.INBOX },
          { name: 'Admin Analytics', to: 'analytics', icon: ChartBarSquareIcon, module: MODULE_KEYS.ANALYTICS },
          { name: 'Admin Logbook', to: 'logbook', icon: BookOpenIcon, module: MODULE_KEYS.LOGBOOK },
        ],
      },
      {
        title: 'Management',
        items: [
          { name: 'Access Requests', to: 'access-requests', icon: ClipboardDocumentCheckIcon, module: MODULE_KEYS.ACCESS_REQUESTS },
          { name: 'Cashier OR Overrides', to: 'cashier-overrides', icon: ShieldCheckIcon, module: MODULE_KEYS.CASHIER_OVERRIDES },
          { name: 'Free Requests', to: 'free-requests', icon: DocumentDuplicateIcon, module: MODULE_KEYS.FREE_REQUESTS },
        ],
      },
      {
        title: 'Scheduling',
        items: [
          {
            name: 'Business Calendar',
            to: 'business-calendar',
            icon: CalendarDaysIcon,
            module: MODULE_KEYS.BUSINESS_CALENDAR,
            children: [
              { name: 'Schedule list', to: 'business-calendar?tab=Schedule-List', tabKey: 'Schedule-List' },
            ],
          },
        ],
      },
    ],
  },
  superAdmin: {
    profileKey: null,
    profileLabel: (user) => user?.email,
    sections: [
      {
        title: 'Overview',
        items: [
          {
            name: 'System analytics',
            to: 'system-analytics',
            icon: ChartBarSquareIcon,
            children: [
              { name: 'System analytics', to: 'system-analytics?tab=analytics', tabKey: 'analytics' },
              { name: 'Admin roster health', to: 'system-analytics?tab=roster', tabKey: 'roster' },
            ],
          },
          { name: 'Audit trail', to: 'report', icon: UserCircleIcon },
        ],
      },
      {
        title: 'Management',
        items: [
          {
            name: 'Admin management',
            to: 'user',
            icon: Squares2X2Icon,
            children: [
              { name: 'Policy management', to: 'user?tab=policies', tabKey: 'policies' },
              { name: 'Access requests', to: 'user?tab=access-requests', tabKey: 'access-requests' },
            ],
          },
          {
            name: 'Document management',
            to: 'documents',
            icon: DocumentDuplicateIcon,
            children: [
              { name: 'Certificate logo management', to: 'documents?tab=certificates', tabKey: 'certificates' },
              { name: 'Signatories', to: 'documents?tab=signatories', tabKey: 'signatories' },
              { name: 'Unmatched cashier items', to: 'documents?tab=unmatched-cashier', tabKey: 'unmatched-cashier' },
              { name: 'Archived documents', to: 'documents?tab=archived', tabKey: 'archived' },
            ],
          },
          { name: 'Announcement management', to: 'settings', icon: Cog6ToothIcon },
          { name: 'Cashier overrides', to: 'cashier-overrides', icon: ShieldCheckIcon },
        ],
      },
      {
        title: 'Scheduling',
        items: [
          {
            name: 'Business calendar',
            to: 'business-calendar',
            icon: CalendarDaysIcon,
            children: [
              { name: 'Schedule list', to: 'business-calendar?tab=Schedule-List', tabKey: 'Schedule-List' },
            ],
          },
        ],
      },
    ],
  },
};
