import { CATEGORY_MAP } from '../constants/notificationCategories';

export const formatDateFull = (isoString) => {
  if (!isoString) return 'N/A';
  const date = new Date(isoString);
  if (isNaN(date.getTime())) return 'N/A';
  return date.toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
};

export const formatTimeShort = (isoString) => {
  if (!isoString) return '';
  const date = new Date(isoString);
  if (isNaN(date.getTime())) return '';
  const diff = Math.floor((Date.now() - date.getTime()) / 1000);
  if (diff < 0) return 'just now';
  if (diff < 60) return `${diff}s`;
  if (diff < 3600) return `${Math.floor(diff / 60)}m`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h`;
  return `${Math.floor(diff / 86400)}d`;
};

export const getDateGroup = (isoString) => {
  if (!isoString) return 'Earlier';
  const date = new Date(isoString);
  if (isNaN(date.getTime())) return 'Earlier';
  const now = new Date();

  const isToday =
    date.getDate() === now.getDate() &&
    date.getMonth() === now.getMonth() &&
    date.getFullYear() === now.getFullYear();

  if (isToday) return 'Today';

  const yesterday = new Date();
  yesterday.setDate(now.getDate() - 1);
  const isYesterday =
    date.getDate() === yesterday.getDate() &&
    date.getMonth() === yesterday.getMonth() &&
    date.getFullYear() === yesterday.getFullYear();

  if (isYesterday) return 'Yesterday';

  return 'Earlier';
};

export const getNotificationProgress = (n) => {
  if (!n) return null;
  const type = n.type || n._raw?.type || '';
  const title = (n.title || n.subject || n.from || '').toLowerCase();

  if (type === 'request_forfeited' || title.includes('forfeited')) return 0;
  if (type === 'request_completed' || title.includes('completed') || title.includes('claimed')) return 100;
  if (type === 'ready_to_claim' || title.includes('ready')) return 75;
  if (type === 'pending_signature' || title.includes('signature')) return 60;
  if (type === 'request_processing' || title.includes('processing')) return 60;
  if (type === 'payment_verified' || title.includes('payment')) return 25;
  if (type === 'request_submitted' || title.includes('submitted')) return 25;

  if (n._raw?.status_id !== undefined) {
    const map = { 1: 25, 2: 25, 3: 60, 4: 60, 5: 75, 6: 100, 7: 0 };
    if (map[n._raw.status_id] !== undefined) return map[n._raw.status_id];
  }
  return null;
};

export const getProgressLabel = (progress) => {
  switch (progress) {
    case 0: return "Request was forfeited";
    case 25: return "Request received and under review";
    case 60: return "Registrar processing complete — awaiting signature";
    case 75: return "Document is ready to claim";
    case 100: return "Document Claimed";
    default: return "Pending";
  }
};

export const filterEmails = (emails, filterTab, searchText) => {
  let result = emails || [];
  if (filterTab === 'unread') {
    result = result.filter((m) => m.unread);
  } else if (filterTab === 'requests') {
    result = result.filter((m) => m.category !== 'Announcement');
  }

  const key = (searchText || '').trim().toLowerCase();
  if (!key) return result;
  return result.filter(
    (m) =>
      (m.from || '').toLowerCase().includes(key) ||
      (m.subject || '').toLowerCase().includes(key) ||
      (m.preview || '').toLowerCase().includes(key)
  );
};

export const groupEmailsByDate = (filteredEmails) => {
  const groups = { Today: [], Yesterday: [], Earlier: [] };
  (filteredEmails || []).forEach((mail) => {
    const groupName = getDateGroup(mail.time);
    if (!groups[groupName]) groups[groupName] = [];
    groups[groupName].push(mail);
  });
  return groups;
};

export const getNotificationFlags = (selectedMail, progress) => {
  if (!selectedMail) return { isClaiming: false, isCompleted: false, isPendingSignature: false };
  const type = selectedMail._raw?.type || '';
  const title = (selectedMail.subject || selectedMail.from || '').toLowerCase();
  const category = selectedMail.category || '';

  return {
    isClaiming: type === 'ready_to_claim' || title.includes('ready') || progress === 75,
    isCompleted: type === 'request_completed' || title.includes('completed') || title.includes('claimed') || progress === 100,
    isPendingSignature:
      type === 'pending_signature' ||
      category === 'Signature' ||
      title.includes('pending signature') ||
      title.includes('signature') ||
      progress === 50,
  };
};

export const getRequirementsList = (selectedMail, notifications, isClaiming) => {
  if (!selectedMail) return [];

  const type = selectedMail._raw?.type || '';
  const category = selectedMail.category || '';
  const docTitle = (selectedMail.subject || selectedMail.from || '').toLowerCase();
  const progress = getNotificationProgress(selectedMail);

  const isSubmitted =
    type === 'request_submitted' ||
    type === 'payment_verified' ||
    type === 'awaiting_submission' ||
    type === 'admin_new_request' ||
    category === 'Submitted' ||
    docTitle.includes('submitted') ||
    docTitle.includes('source document required') ||
    progress === 25;

  const isReadyToClaim =
    isClaiming ||
    type === 'ready_to_claim' ||
    category === 'Ready' ||
    docTitle.includes('ready') ||
    progress === 75;

  // Requirements card is only for 'submitted' and 'ready to claim' requests
  if (!isSubmitted && !isReadyToClaim) {
    return [];
  }

  if (selectedMail._raw?.requirements?.length > 0) {
    return selectedMail._raw.requirements;
  }

  const reqId = selectedMail._raw?.request_id || selectedMail._raw?.data?.request_id;
  const matchingNotif = Array.isArray(notifications)
    ? notifications.find((n) => {
        if (!n?.requirements || n.requirements.length === 0) return false;
        if (reqId && (n.request_id === reqId || n.data?.request_id === reqId)) return true;
        const nTitle = (n.title || n.message || '').toLowerCase();
        return nTitle.includes(docTitle) || docTitle.includes(nTitle);
      })
    : null;

  if (matchingNotif?.requirements?.length > 0) {
    return matchingNotif.requirements;
  }

  const docName = selectedMail._raw?.document_type || selectedMail.category || 'Requested Document';
  return [
    {
      item: docName,
      process_days: '1 working day/s, 1 hour/s, 18 minute/s',
      requirements:
        'Letter of request stating the purpose - (1) Original Copy,\nProof of payment - (1) Original Copy,\nPUP School Identification Card - (1) Original Copy,\nAuthorization letter (if claimed by a representative) - (1) Original Copy',
    },
  ];
};
