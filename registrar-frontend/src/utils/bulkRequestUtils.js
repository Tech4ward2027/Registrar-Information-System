/**
 * Bulk Request & Dashboard Grouping Policy Helpers
 * PUPT-RIS (Registrar Information System)
 * 
 * Provides utilities for:
 * 1. Document Processing Time Classification (Simple: 3 Days, Complex: 7 Days, Highly Technical: 20 Days)
 * 2. Per-document progress calculation
 * 3. Grouping requests and line items by Official Receipt (OR Number) for Dashboard dropdown display.
 */

import { PROGRESS_MAP } from './constants';

/**
 * Classifies a document or certificate based on its processing period.
 * Policy 3.4:
 * - Simple: 3 Days
 * - Complex: 7 Days
 * - Highly Technical: 20 Days
 *
 * @param {string|number} processPeriod - e.g. 3, 7, 20 or "3 Days", "7 Days", "20 Days"
 * @param {string} [docName] - Fallback document name for heuristic matching if period is missing
 * @returns {Object} { classification, targetDays, label, badgeClass, darkBadgeClass }
 */
export const getProcessingClassification = (processPeriod, docName = '') => {
  let days = 3;

  if (processPeriod !== undefined && processPeriod !== null) {
    if (typeof processPeriod === 'number') {
      days = processPeriod;
    } else {
      const match = String(processPeriod).match(/\d+/);
      if (match) {
        days = parseInt(match[0], 10);
      }
    }
  } else if (docName) {
    const lower = docName.toLowerCase();
    if (lower.includes('transcript') || lower.includes('tor') || lower.includes('honorable')) {
      days = 20;
    } else if (lower.includes('diploma') || lower.includes('authentication') || lower.includes('cav')) {
      days = 7;
    } else {
      days = 3;
    }
  }

  if (days <= 3) {
    return {
      classification: 'Simple',
      targetDays: 3,
      label: 'Simple (3 Days)',
      shortLabel: 'Simple • 3d',
      badgeClass: 'bg-emerald-50 text-emerald-700 border-emerald-200',
      darkBadgeClass: 'bg-emerald-950/60 text-emerald-300 border-emerald-800/60',
      colorHex: '#10b981',
    };
  }

  if (days <= 7) {
    return {
      classification: 'Complex',
      targetDays: 7,
      label: 'Complex (7 Days)',
      shortLabel: 'Complex • 7d',
      badgeClass: 'bg-blue-50 text-blue-700 border-blue-200',
      darkBadgeClass: 'bg-blue-950/60 text-blue-300 border-blue-800/60',
      colorHex: '#3b82f6',
    };
  }

  return {
    classification: 'Highly Technical',
    targetDays: 20,
    label: 'Highly Technical (20 Days)',
    shortLabel: 'Highly Tech • 20d',
    badgeClass: 'bg-purple-50 text-purple-700 border-purple-200',
    darkBadgeClass: 'bg-purple-950/60 text-purple-300 border-purple-800/60',
    colorHex: '#8b5cf6',
  };
};

/**
 * Computes progress percentage for an individual document line item.
 * @param {number|string} statusId 
 * @returns {number} Progress percentage 0-100
 */
export const getItemProgressPercentage = (statusId) => {
  const numericId = Number(statusId);
  return PROGRESS_MAP[numericId] ?? (numericId === 3 ? 100 : numericId === 2 ? 90 : 30);
};

/**
 * Extracts and unifies line items from a DocumentRequest model into flat item objects.
 * Each item contains: id, type ('document'|'certificate'), name, copies, status_id, status_name,
 * classification, progress, qr_code/claim_code.
 *
 * @param {Object} rawRequest 
 * @param {Function} [docTypeNameFn] 
 * @param {Function} [certNameFn] 
 * @returns {Array} List of extracted line item objects
 */
import { getEffectiveStatus } from './staffDashboardUtils';

export const extractSeparatedItems = (rawRequest, docTypeNameFn = () => null, certNameFn = () => null) => {
  if (!rawRequest) return [];
  const items = [];

  const releaseGroups = rawRequest.release_groups || rawRequest.releaseGroups || [];

  // Extract Documents
  const docs = rawRequest.documents || [];
  docs.forEach((d, idx) => {
    const docType = d.document_type || {};
    const name = docType.document_name || docTypeNameFn(d.document_type_id) || `Document #${d.document_type_id}`;
    const period = docType.document_process_period;
    const classification = getProcessingClassification(period, name);
    const effective = getEffectiveStatus(rawRequest, d);
    const itemStatusId = effective.statusId;
    const itemStatusName = effective.statusName;

    // Find release group matching this item's release group ID
    const relGroup = releaseGroups.find(g => g.request_release_group_id === d.request_release_group_id);
    const itemClaimCode = relGroup?.claim_code || rawRequest.claim_code || rawRequest.uuid || `DOC-${d.request_document_id}`;
    const itemUuid = relGroup?.uuid || rawRequest.uuid;

    items.push({
      itemKey: `doc-${d.request_document_id || idx}`,
      rawItem: d,
      itemType: 'document',
      typeId: d.document_type_id,
      name,
      copies: Number(d.number_of_copies || 1),
      statusId: itemStatusId,
      statusName: itemStatusName,
      classification,
      progress: getItemProgressPercentage(itemStatusId),
      claimCode: itemClaimCode,
      uuid: itemUuid,
      requiresSourceSubmission: Boolean(docType.requires_source_submission),
      releaseGroupId: d.request_release_group_id || null,
    });
  });

  // Extract Certificates
  const certs = rawRequest.certificates || [];
  certs.forEach((c, idx) => {
    const certType = c.certification_type || {};
    const name = certType.certificate_name || certNameFn(c.certificate_type_id) || `Certificate #${c.certificate_type_id}`;
    const period = certType.certificate_process_period;
    const classification = getProcessingClassification(period, name);
    const effective = getEffectiveStatus(rawRequest, c);
    const itemStatusId = effective.statusId;
    const itemStatusName = effective.statusName;

    const relGroup = releaseGroups.find(g => g.request_release_group_id === c.request_release_group_id);
    const itemClaimCode = relGroup?.claim_code || rawRequest.claim_code || rawRequest.uuid || `CERT-${c.request_certificate_id}`;
    const itemUuid = relGroup?.uuid || rawRequest.uuid;

    items.push({
      itemKey: `cert-${c.request_certificate_id || idx}`,
      rawItem: c,
      itemType: 'certificate',
      typeId: c.certificate_type_id,
      name,
      copies: Number(c.number_of_copies || 1),
      statusId: itemStatusId,
      statusName: itemStatusName,
      classification,
      progress: getItemProgressPercentage(itemStatusId),
      claimCode: itemClaimCode,
      uuid: itemUuid,
      generatedAt: c.generated_at,
      releaseGroupId: c.request_release_group_id || null,
    });
  });

  // Fallback if request has no document/certificate relations populated yet
  if (items.length === 0) {
    const fallbackName = rawRequest.doc_names?.[0] || rawRequest.certName || 'Requested Document';
    items.push({
      itemKey: `req-${rawRequest.request_id || 1}`,
      rawItem: null,
      itemType: 'document',
      typeId: null,
      name: fallbackName,
      copies: 1,
      statusId: rawRequest.status_id || 1,
      statusName: rawRequest.status?.status_name || 'Processing',
      classification: getProcessingClassification(null, fallbackName),
      progress: getItemProgressPercentage(rawRequest.status_id || 1),
      claimCode: rawRequest.claim_code || rawRequest.uuid || 'N/A',
      uuid: rawRequest.uuid,
    });
  }

  return items;
};

/**
 * Groups requests sharing the same Official Receipt (OR Number) for Dashboard display.
 * Policy 3.2: Documents/certificates requested under the same OR are grouped into a single entry
 * that expands into a dropdown listing each separated document/certificate.
 *
 * @param {Array} requests - Mapped requests list
 * @returns {Array} Grouped entry structures
 */
export const groupRequestsForDashboard = (requests) => {
  if (!Array.isArray(requests) || requests.length === 0) return [];

  const groupsMap = new Map();

  requests.forEach((req) => {
    const orKey = (req.or_number || req.rawRequest?.or_number || '').trim();
    // Unique key: OR number if present, otherwise fallback to request_id
    const groupKey = orKey ? `OR:${orKey}` : `REQ:${req.id || req.request_id}`;

    if (!groupsMap.has(groupKey)) {
      groupsMap.set(groupKey, {
        groupKey,
        orNumber: orKey || null,
        isBulk: false,
        requests: [],
        primaryRequest: req,
        user_id: req.user_id || req.rawRequest?.user_id,
        requested_at: req.requested_at || req.rawRequest?.requested_at || req.date,
        items: [],
      });
    }

    const group = groupsMap.get(groupKey);
    group.requests.push(req);

    // Extract separated items from raw request
    const reqItems = extractSeparatedItems(req.rawRequest || req);
    reqItems.forEach(item => {
      group.items.push({
        ...item,
        parentRequestId: req.id || req.request_id,
        parentRequest: req,
      });
    });

    group.isBulk = group.items.length > 1;
  });

  return Array.from(groupsMap.values());
};
