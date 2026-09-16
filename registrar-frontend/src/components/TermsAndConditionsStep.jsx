import React from "react";
import CheckboxItem from "./Checkbox.jsx";

const TermsAndConditionsStep = ({ termsAgreed, onCheckboxChange, isDark }) => {
  return (
    <div className="space-y-3 animate-fadeIn text-xs sm:text-sm leading-relaxed">
      <div className="space-y-3 max-h-[460px] overflow-y-auto pr-1 custom-scrollbar">
        {/* Card A: Data privacy consent */}
        <div
          className={`p-4 rounded-xl border transition-all ${
            isDark
              ? "bg-[#242526] border-[#3e4042]/70 text-gray-200"
              : "bg-white/10 border-white/15 text-white"
          }`}
        >
          <h4 className="font-bold text-sm text-white mb-1.5 tracking-wide flex items-center gap-2">
            <span>Data privacy consent</span>
          </h4>
          <p className={isDark ? "text-gray-300 text-xs sm:text-sm" : "text-white/90 text-xs sm:text-sm"}>
            In compliance with the Data Privacy Act (DPA) of 2012, and its implementing rules
            and regulations (IRR), upon filling up this request through the system constitutes, I am hereby providing my
            consent and authorization to use my personal data for this request.
          </p>
        </div>

        {/* Card B: Transaction type */}
        <div
          className={`p-4 rounded-xl border transition-all ${
            isDark
              ? "bg-[#242526] border-[#3e4042]/70 text-gray-200"
              : "bg-white/10 border-white/15 text-white"
          }`}
        >
          <h4 className="font-bold text-sm text-white mb-1.5 tracking-wide flex items-center gap-2">
            <span>Transaction type</span>
          </h4>
          <p className={isDark ? "text-gray-300 text-xs sm:text-sm" : "text-white/90 text-xs sm:text-sm"}>
            This request is only for ONSITE TRANSACTION with Official Receipt issued by the Cashier's Office.
          </p>
        </div>

        {/* Card C: Processing time */}
        <div
          className={`p-4 rounded-xl border transition-all ${
            isDark
              ? "bg-[#242526] border-[#3e4042]/70 text-gray-200"
              : "bg-white/10 border-white/15 text-white"
          }`}
        >
          <h4 className="font-bold text-sm text-white mb-1.5 tracking-wide flex items-center gap-2">
            <span>Processing time</span>
          </h4>
          <p className={isDark ? "text-gray-300 text-xs sm:text-sm" : "text-white/90 text-xs sm:text-sm"}>
            All CERTIFICATIONS are processed within three (3) working days, while TOR is within 12 working days.
          </p>
        </div>

        {/* Card D: Reminders */}
        <div
          className={`p-4 rounded-xl border transition-all ${
            isDark
              ? "bg-[#242526] border-[#3e4042]/70 text-gray-200"
              : "bg-white/10 border-white/15 text-white"
          }`}
        >
          <h4 className="font-bold text-sm text-white mb-2 tracking-wide flex items-center gap-2">
            <span>Reminders</span>
          </h4>
          <ul
            className={`space-y-1.5 list-disc list-inside text-xs sm:text-sm ${
              isDark ? "text-gray-300" : "text-white/90"
            }`}
          >
            <li>
              Requests must be submitted within one (1) week after receiving the receipt. Requests exceeding this period may be considered invalid.
            </li>
            <li>
              For TOR (First Copy): Bring one (1) documentary stamp, two (2) colored 2x2 ID pictures in academic gown, valid PUP ID, and dummy diploma. In case of loss, an Affidavit of Loss is required.
            </li>
            <li>
              For TOR (Second Copy): Bring one (1) violet documentary stamp and two (2) colored 2x2 ID pictures in formal attire with white background.
            </li>
            <li>
              For Honorable Dismissal and other Certifications: Bring one (1) violet documentary stamp (or two (2) brown documentary stamps) per requested document.
            </li>
          </ul>
        </div>

        {/* Card E: Authorized representatives */}
        <div
          className={`p-4 rounded-xl border transition-all ${
            isDark
              ? "bg-[#242526] border-[#3e4042]/70 text-gray-200"
              : "bg-white/10 border-white/15 text-white"
          }`}
        >
          <h4 className="font-bold text-sm text-white mb-1.5 tracking-wide flex items-center gap-2">
            <span>Authorized representatives</span>
          </h4>
          <p className={isDark ? "text-gray-300 text-xs sm:text-sm" : "text-white/90 text-xs sm:text-sm"}>
            In compliance with R.A. No. 10173 (Data Privacy Act of 2012), representatives must present a signed Authorization Letter (for immediate family) or Special Power of Attorney (for non-family), along with valid IDs of both the student and the representative upon claiming documents.
          </p>
        </div>

        {/* Card F: Unclaimed documents */}
        <div
          className={`p-4 rounded-xl border transition-all ${
            isDark
              ? "bg-[#242526] border-[#3e4042]/70 text-gray-200"
              : "bg-white/10 border-white/15 text-white"
          }`}
        >
          <h4 className="font-bold text-sm text-white mb-1.5 tracking-wide flex items-center gap-2">
            <span>Unclaimed documents</span>
          </h4>
          <p className={isDark ? "text-gray-300 text-xs sm:text-sm" : "text-white/90 text-xs sm:text-sm"}>
            All documents unclaimed within 90 days on the date of request will be shredded automatically.
          </p>
        </div>
      </div>

      <div className={`pt-3 border-t ${isDark ? "border-white/10" : "border-white/10"}`}>
        <CheckboxItem
          name="termsAgreed"
          checked={termsAgreed}
          onChange={onCheckboxChange}
          isDark={isDark}
          textColor="text-white"
          text="I have read, understood, and agree to the Terms & Conditions stated above."
        />
      </div>
    </div>
  );
};

export default TermsAndConditionsStep;
