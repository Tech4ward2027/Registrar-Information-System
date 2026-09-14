import { getTodayDate } from "./helpers";

// Re-exported from the single source of truth so existing imports of
// ALUMNI_ACCESS_IDS from this file keep working unchanged. See
// src/constants/accessTypes.js for the canonical definition.
export { ALUMNI_ACCESS_IDS } from "../constants/accessTypes";

export const validateProfileStep = (formData) => {
  if (!(formData.firstName || "").trim()) {
    return "Please enter your first name.";
  }
  if (!(formData.middleName || "").trim()) {
    return "Please enter your middle name.";
  }
  if (!(formData.surname || "").trim()) {
    return "Please enter your surname.";
  }
  if (!formData.dob) {
    return "Please select the date of birth.";
  }
  if (!(formData.address || "").trim()) {
    return "Please enter your present/permanent mailing address.";
  }
  if (!(formData.contactNumber || "").trim()) {
    return "Please enter your contact number.";
  }
  return null;
};

export const validateRequestDetailsStep = (formData) => {
  const hasDocs = (formData.documentsRequested || []).length > 0;
  const hasCerts = (formData.certification || []).length > 0;

  if (!hasDocs && !hasCerts) {
    return "Please select at least one document or certification to proceed.";
  }
  if (!formData.purposeOfRequest || formData.purposeOfRequest.length === 0) {
    return "Please select a purpose for your request.";
  }
  return null;
};

export const validateReceiptStep = (formData) => {
  if (!(formData.receiptNumber || "").trim()) {
    return "Please enter the Official Receipt Number.";
  }
  if (!/^\d{7}$/.test((formData.receiptNumber || "").trim())) {
    return "Official Receipt Number must be exactly 7 digits.";
  }
  if (!formData.dateOfPayment) {
    return "Please select the date of payment.";
  }
  if (formData.dateOfPayment > getTodayDate()) {
    return "Date of payment cannot be in the future.";
  }

  // Validate document copies count limits
  const hasInvalidDocCopy = (formData.documentsRequested || [])
    .filter((doc) => !doc.toLowerCase().includes("certif"))
    .some((doc) => {
      const copies = Number(formData.documentCopies?.[doc] || 1);
      return !Number.isInteger(copies) || copies < 1 || copies > 10;
    });

  const hasInvalidCertCopy = (formData.certification || []).some((cert) => {
    const copies = Number(formData.certCopies?.[cert] || 1);
    return !Number.isInteger(copies) || copies < 1 || copies > 10;
  });

  if (hasInvalidDocCopy || hasInvalidCertCopy) {
    return "Number of copies must be between 1 and 10.";
  }

  return null;
};