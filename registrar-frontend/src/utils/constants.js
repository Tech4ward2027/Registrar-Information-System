/**
 * constants.js
 * ------------
 * Static, presentation-only constants that are safe to hardcode because
 * they are determined by the frontend design, not the database.
 *
 * NOTE: DOC_TYPE_MAP, CERTIFICATION_MAP, and PURPOSE_MAP have been removed.
 * Those values now come from the API via ReferenceDataContext:
 *
 *   import { useReferenceData } from "../context/ReferenceDataContext";
 *   const { documentTypes, certifications, docTypeName, certName } = useReferenceData();
 *
 * STATUS_CONFIG has also moved to ReferenceDataContext (statusConfig helper).
 * The exports below are kept for components that have not yet been migrated —
 * they will continue to work but should be updated to use the context.
 */

// Status IDs mirror RequestStatusEnum in the backend:
//   1 = Processing  2 = ReadyToClaim  3 = Completed
//   4 = Forfeited   5 = Cancelled     6 = PendingSignature
//   12 = AwaitingSubmission 13 = Withdrawn
// Keep in sync with backend Enums/RequestStatusEnum.php
export const STATUS_CONFIG = {
  1:  { label: "Processing",         classes: "bg-yellow-100 text-yellow-700 border-yellow-200" },
  2:  { label: "Ready to Claim",     classes: "bg-green-100 text-green-700 border-green-200"   },
  3:  { label: "Completed",          classes: "bg-gray-100 text-gray-700 border-gray-200"      },
  4:  { label: "Forfeited",          classes: "bg-red-100 text-red-700 border-red-200"         },
  5:  { label: "Cancelled",          classes: "bg-orange-100 text-orange-700 border-orange-200"},
  6:  { label: "Awaiting Signature", classes: "bg-orange-100 text-orange-700 border-orange-200"},
  12: { label: "Awaiting Submission",classes: "bg-purple-100 text-purple-700 border-purple-200"},
  13: { label: "Withdrawn",          classes: "bg-red-100 text-red-700 border-red-200"       },
  14: { label: "Closed - Unable to Process", classes: "bg-red-100 text-red-700 border-red-200" },
};

export const TAB_MAP = {
  1: "pending",   // Processing
  2: "ready",     // Ready to Claim
  3: "history",   // Completed
  4: "history",   // Forfeited
  5: "history",   // Cancelled
  6: "pending",   // Pending Signature — registrar-side view: still "in progress",
                  // not yet claimable. Kept out of "ready" so a student doesn't
                  // see "To Claim" for a document that isn't actually there yet.
  12: "pending",  // Awaiting Submission — same reasoning as Pending Signature:
                  // it's the request's starting, in-progress state, not yet
                  // claimable, so it belongs in "pending" not "ready".
};

export const TABS = [
  {
    label: "Pending",
    value: "pending",
    active: "bg-yellow-50 border-yellow-500 text-yellow-900",
    inactive: "bg-white border-gray-200 text-gray-500 hover:bg-yellow-50",
    darkActive: "bg-yellow-900/20 border-yellow-600 text-yellow-400",
    darkInactive: "bg-[#242526] border-[#3e4042] text-[#b0b3b8] hover:bg-[#3a3b3c] hover:text-[#e4e6eb]",
  },
  {
    label: "To Claim",
    value: "ready",
    active: "bg-green-50 border-green-500 text-green-900",
    inactive: "bg-white border-gray-200 text-gray-500 hover:bg-green-50",
    darkActive: "bg-green-900/20 border-green-600 text-green-400",
    darkInactive: "bg-[#242526] border-[#3e4042] text-[#b0b3b8] hover:bg-[#3a3b3c] hover:text-[#e4e6eb]",
  },
  {
    label: "History",
    value: "history",
    active: "bg-gray-100 border-gray-500 text-gray-900",
    inactive: "bg-white border-gray-200 text-gray-500 hover:bg-gray-50",
    darkActive: "bg-gray-700/20 border-gray-400 text-gray-300",
    darkInactive: "bg-[#242526] border-[#3e4042] text-[#b0b3b8] hover:bg-[#3a3b3c] hover:text-[#e4e6eb]",
  },
];

export const PROGRESS_MAP = {
  12: 10,  // Awaiting Submission — request exists but registrar can't start
           // until the client hands over the source document. Deliberately
           // less than Processing's 25%, since no registrar work has begun
           // yet; deliberately not 0%, since 0% is read elsewhere (see
           // RequestDetailModal's getProgressLabel/Claim Ticket visibility)
           // as "nothing left to claim" (Forfeited/Cancelled), which this
           // is not.
  1: 25,   // Processing
  2: 75,   // Ready to Claim
  3: 100,  // Completed
  4: 0,    // Forfeited
  5: 0,    // Cancelled
  6: 60,   // Pending Signature — registrar's part is done, waiting externally
  13: 0,   // Withdrawn — terminal and no longer claimable
  14: 0,   // Closed - Unable to Process — terminal and no longer claimable
};

// @deprecated-shims
// ---------------------------------------------------------------------------
// DEPRECATED — kept as fallback shims so existing components don't break.
// These maps are now served from the API via ReferenceDataContext.
//
// To migrate a component:
//   1. Replace: import { DOC_TYPE_MAP, PURPOSE_MAP, CERTIFICATION_MAP } from '../utils/constants';
//      With:    import { useReferenceData } from '../context/ReferenceDataContext';
//   2. Inside the component: const { docTypeName, certName, purposeName } = useReferenceData();
//   3. Replace DOC_TYPE_MAP[id]      → docTypeName(id)
//              PURPOSE_MAP[id]       → purposeName(id)
//              CERTIFICATION_MAP[id] → certName(id)
//   4. Remove the import below once all usages are gone.
//
// Files still using these shims (as of the fix):
//   - src/layouts/RequestForm.jsx
//   - src/layouts/RequestDetailModal.jsx  (via components/)
//   - src/layouts/StudentDashboard.jsx
//   - src/layouts/StaffDashboard.jsx
//   - src/layouts/AlumniRequest.jsx
//   - src/layouts/Logbook.jsx
// ---------------------------------------------------------------------------

export const DOC_TYPE_MAP = {
  1:  "Recommendation Letter",
  2:  "Course Subject Description",
  3:  "Certificates",
  4:  "CAV / Apostille",
  5:  "Transcript of Records",
  6:  "Certificate of Good Moral Character",
  7:  "Academic Verification",
  8:  "New Identification Card",
  9:  "Replacement Identification Card",
  10: "Consultation Service",
  11: "Counseling Service",
  12: "Permit to Conduct an Activity",
  13: "Application for Graduation",
  14: "Grade Correction",
  15: "Name Correction",
  16: "SHS Course Accreditation",
  17: "Transferee Course Accreditation",
  18: "Informative Copy of Grades",
  19: "Leave of Absence",
  20: "Re-Admission Certificate",
};

export const PURPOSE_MAP = {
  1: "DFA",
  2: "Employment - Local",
  3: "Employment - Abroad",
  4: "Further Studies",
  5: "Board Exam",
  6: "Scholarship",
  7: "Personal Copy",
};

export const CERTIFICATION_MAP = {
  1:  "Certificate of Attendance",
  2:  "Certificate of Graduation",
  3:  "Medium of Instruction",
  4:  "General Weighted Average",
  5:  "Non-Issuance of Special Order",
  6:  "Certified True Copy",
  7:  "Good Moral Character",
  8:  "Re-Admission Certificate",
  9:  "Leave of Absence",
  10: "Course Accreditation",
  13: "history",  // Withdrawn — terminal and staff-mediated.
};