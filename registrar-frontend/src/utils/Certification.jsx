import React from "react";
import {
  CertificateTitle,
  RegistrarDateTitle,
  FooterInfo,
  TextBlock,
  ReceiptInfo,
  RegistrarSignature,
  DirectorSignature,
  StandardCertLayout,
  CertParagraph,
  IssuedLine,
  IssuedLineAforementioned,
  PupLetterhead,
  EndorsementNoteBlock,
  bold,
  fillOrLine,
} from "../utils/helpers.jsx";
import { formatDateFormal, formatDateOrdinal, CURRENT_YEAR } from "./formatters.js";
import puplogoimage from "../assets/puplogoimage.png";

/** "syAdmitted is not yet fully functional - need update" */
export const CERT_CONFIG = {
  1: {
    id: 1,
    name: "Certificate of GWA",
    otherNames: ["General Weighted Average", "Certification Fee - General Weighted Average", "Certified True Copy - General Weighted Average", "General Weighted Average (GWA)", "Certificate of General Weighted Average", "GWA"],
    fields: ["fullName", "course", "gwa", "officialReceiptNum","educationLevel"],
    renderBody: (data) => (
      <StandardCertLayout date={data.date}>
        <CertParagraph>
          This is to certify that {fillOrLine(data.fullName)} is a {fillOrLine(data.educationLevel)} of the
          Polytechnic University of the Philippines Taguig Campus with the degree of{" "}
          {fillOrLine(data.course)} and obtained a General Weighted Average of {fillOrLine(data.gwa)}.
        </CertParagraph>
        <IssuedLineAforementioned date={data.date} />
        <RegistrarSignature signee={data.signee} />
        <ReceiptInfo officialReceiptNum={data.officialReceiptNum} date={bold(formatDateFormal(data.date))} />
      </StandardCertLayout>
    ),
  },

  2: {
    id: 2,
    name: "Non Issuance of SO",
    otherNames: ["Non-Issuance of S.O.", "Non-Issuance of Special Order", "Non Issuance of Special Order", "Certification Fee - Non-Issuance of S.O.", "Certification of Non-Issuance of Special Order", "Special Order"],
    fields: ["fullName", "course", "major", "officialReceiptNum"],
    renderBody: (data) => (
      <StandardCertLayout date={data.date}>
        <CertParagraph className="mb-5">
          As a {bold("State University the Polytechnic University of the Philippines (PUP)")} does
          not issue "{bold("Special Order")}" to its graduates.
        </CertParagraph>
        <CertParagraph className="mb-5">
          Pursuant to its Charter, the PUP Academic Council fixes the requirement for graduation
          and recommends to the Board of Regents students who are recipient of the degrees and who
          late received such recommendation.
        </CertParagraph>
        <CertParagraph className="mb-15">
          Issued this {formatDateOrdinal(data.date)} upon request of {fillOrLine(data.fullName)} a{" "}
          {fillOrLine(data.course)} {bold("major in")} {fillOrLine(data.major)} graduate of this University.
        </CertParagraph>
        <DirectorSignature signee={data.signee} />
        <ReceiptInfo className="mt-4" officialReceiptNum={data.officialReceiptNum} date={bold(formatDateFormal(data.date))} />
      </StandardCertLayout>
    ),
  },

  3: {
    id: 3,
    name: "Certification of Medium of Instruction",
    otherNames: ["English as Medium of Instruction", "Medium of Instruction", "Certification Fee - Medium of Instruction", "Certification Fee - English as Medium of Instruction", "English as Medium of Instruction (MOI)"],
    fields: ["fullName", "course", "dateGraduated", "officialReceiptNum", "date"],
    renderBody: (data) => (
      <>
        <RegistrarDateTitle date={data.date} />
        <CertificateTitle title="C E R T I F I C A T I O N" />
        <div className="space-y-4 text-[12px] sm:text-[13px] leading-relaxed text-justify px-2 sm:px-4 print:text-[12pt]">
          <TextBlock className="font-lucida">To Whom It May Concern:</TextBlock>
          <CertParagraph>
            This is to certify that {fillOrLine(data.fullName)} has attended
            tertiary education in this institution with a degree in{" "}
            {fillOrLine(`${data.course || ""}${data.dateGraduated ? ` — Batch ${new Date(data.dateGraduated).getFullYear()} graduate` : ""}`)}.
          </CertParagraph>
          <CertParagraph>
            This certifies further that {bold("English language")} is the{" "}
            {bold("medium of instruction")} used in all courses offered by the University.
          </CertParagraph>
          <CertParagraph>
            This certification is issued this {formatDateOrdinal(data.date)} upon the request of
            the aforementioned name for whatever purpose it may serve.
          </CertParagraph>
        </div>
        <DirectorSignature signee={data.signee} />
        <ReceiptInfo officialReceiptNum={data.officialReceiptNum} date={bold(formatDateFormal(data.date))} />
      </>
    ),
  },

  4: {
    id: 4,
    name: "Certification of Medium of Instruction with Units",
    otherNames: ["Certification of Medium of Instruction with Units", "Medium of Instruction with Units"],
    fields: ["fullName", "course", "dateGraduated", "semestersNum", "units", "officialReceiptNum", "date"],
    renderBody: (data) => (
      <>
        <RegistrarDateTitle date={data.date} />
        <CertificateTitle title="C E R T I F I C A T I O N" />
        <div className="space-y-4 text-[12px] sm:text-[13px] leading-relaxed text-justify px-2 sm:px-4 print:text-[12pt]">
          <TextBlock className="font-lucida">To Whom It May Concern:</TextBlock>
          <CertParagraph>
            This is to certify that {fillOrLine(data.fullName)} has attended
            tertiary education in this institution with a degree in{" "}
            {fillOrLine(`${data.course || ""}${data.dateGraduated ? ` — Batch ${new Date(data.dateGraduated).getFullYear()} graduate` : ""}`)}.
          </CertParagraph>
          <CertParagraph>
            The {fillOrLine(data.course)} is a{" "}
            {fillOrLine(`${data.semestersNum || ""} year degree program`)} with a total of{" "}
            {fillOrLine(`${data.units || ""} academic units`)}.
          </CertParagraph>
          <CertParagraph>
            This certifies further that {bold("English language")} is the{" "}
            {bold("medium of instruction")} used in all courses offered by the University.
          </CertParagraph>
          <CertParagraph>
            This certification is issued this {formatDateOrdinal(data.date)} upon the request of
            the aforementioned name for whatever purpose it may serve.
          </CertParagraph>
        </div>
        <DirectorSignature signee={data.signee} />
        <ReceiptInfo officialReceiptNum={data.officialReceiptNum} date={bold(formatDateFormal(data.date))} />
      </>
    ),
  },

  5: {
    id: 5,
    name: "Certificate of Attendance",
    fields: ["fullName", "course", "semesters", "syAdmitted", "officialReceiptNum"],
    renderBody: (data) => (
      <StandardCertLayout date={data.date}>
        <CertParagraph>
          This is to certify that {fillOrLine(data.fullName)} has attended classes as a student of the
          Polytechnic University of the Philippines Taguig Campus in the {data.semesters} of S.Y {fillOrLine(data.syAdmitted)}, under our {fillOrLine(data.course)} program.
        </CertParagraph>
        <IssuedLine date={data.date} />
        <RegistrarSignature signee={data.signee} />
        <ReceiptInfo officialReceiptNum={data.officialReceiptNum} date={bold(formatDateFormal(data.date))} />
      </StandardCertLayout>
    ),
  },

  6: {
    id: 6,
    name: "Certificate of Graduation",
    otherNames: ["Certificate of Graduation", "Certificate of Graduation - 2nd copy", "Certification of Graduation"],
    fields: ["fullName", "course", "latinHonors", "dateGraduated", "diplomaNum"],
    renderBody: (data) => (
      <StandardCertLayout date={data.date}>
        <CertParagraph>
          This is to certify that {fillOrLine(data.fullName)} is a {fillOrLine(data.educationLevel)} of the
          Polytechnic University of the Philippines Taguig Campus and received the degree of{" "}
          {fillOrLine(data.course)} {fillOrLine(data.latinHonors)} on {fillOrLine(formatDateFormal(data.dateGraduated))}.
        </CertParagraph>
        <IssuedLineAforementioned date={data.date} />
        <RegistrarSignature signee={data.signee} />
        <FooterInfo className="mt-4" diplomaNum={data.diplomaNum} date={bold(formatDateFormal(data.date))} />
      </StandardCertLayout>
    ),
  },

  7: {
    id: 7,
    name: "Certified True Copy of Records",
    fields: ["fullName", "course", "dateGraduated", "officialReceiptNum"],
    renderBody: (data) => (
      <StandardCertLayout date={data.date}>
        <CertParagraph>
          This is to certify that the attached are certified true copies of the official academic records of {fillOrLine(data.fullName)}, 
          a graduate of the Polytechnic University of the Philippines Taguig Campus with a degree in {fillOrLine(data.course)}{data.dateGraduated ? ` — Batch ${new Date(data.dateGraduated).getFullYear()}` : ""}.
        </CertParagraph>
        <CertParagraph>
          These records are issued upon request of the aforementioned individual for whatever legal purpose it may serve.
        </CertParagraph>
        <IssuedLineAforementioned date={data.date} />
        <RegistrarSignature signee={data.signee} />
        <ReceiptInfo officialReceiptNum={data.officialReceiptNum} date={bold(formatDateFormal(data.date))} />
      </StandardCertLayout>
    ),
  },

  8: {
    id: 8,
    name: "Certificate of Graduate Honor",
    otherNames: ["Latin Honor", "Latin Honors", "Graduate Honor", "Certificate of Latin Honor", "Certificate of Graduate Honor"],
    fields: ["fullName", "course", "latinHonors", "major", "eligibilityType", "officialReceiptNum", "dateGraduated"],
    renderBody: (data) => (
      <StandardCertLayout date={data.date}>
        <CertParagraph>
          This is to certify that {fillOrLine(data.fullName)} is a {fillOrLine(data.educationLevel)} of the
          Polytechnic University of the Philippines Taguig Campus with the degree of{" "}
          {fillOrLine(data.course)} {bold("major in")} {fillOrLine(data.major)} {fillOrLine(data.latinHonors)}{" "}
          on {fillOrLine(formatDateFormal(data.dateGraduated))}.
        </CertParagraph>
        <CertParagraph className="mb-10">
          This certification is issued this {formatDateFormal(data.date)} upon request of the
          aforementioned individual in support of their application for {fillOrLine(data.eligibilityType)}{" "}
          conferred by the Civil Service Commission
        </CertParagraph>
        <RegistrarSignature signee={data.signee} />
        <ReceiptInfo officialReceiptNum={data.officialReceiptNum} date={bold(formatDateFormal(data.date))} />
      </StandardCertLayout>
    ),
  },

  9: {
    id: 9,
    name: "Consular Certification",
    fields: ["fullName", "course", "officialReceiptNum", "major", "dateGraduated"],
    renderBody: (data) => (
      <div className="text-[9px] sm:text-[10px] text-gray-800 leading-relaxed">
        <TextBlock className="text-left text-gray-700 print:text-[10pt]">{formatDateFormal(data.date)}</TextBlock>
        <TextBlock className="mb-4 print:text-[10pt]">
          <p>The Consular Section</p>
          <p>Notarials and Authentication Unit</p>
          <p>Philippine Embassy</p>
          <p>P.O. Box 3215</p>
          <p>United Arab Emirates</p>
          <p>Fax +971 2 6390002</p>
          <p>Email: auhpe@philembassy.ae</p>
          <p>Website: www.abudhabipe.dfa.gov.ph</p>
        </TextBlock>
        <TextBlock className="mb-5 print:text-[10pt]">Gentlemen:</TextBlock>
        <CertificateTitle title="Certification of Completed Degree and Special Order" />
        <div className="space-y-3 leading-[1.9] text-justify print:text-[11pt]">
          <p>
            This is to certify that {fillOrLine(data.fullName)} graduated from this University with a
            degree in {fillOrLine(data.course)} {bold("major in")} {fillOrLine(data.major)}{" "}
            on {fillOrLine(formatDateFormal(data.dateGraduated))}.
          </p>
          <p>
            As a State University, the Polytechnic University of the Philippines does not issue
            "Special Order" to its graduates.
          </p>
          <p>
            This Certification has been issued upon the request of the aforementioned name for
            whatever legal purpose it may serve.
          </p>
        </div>
        <DirectorSignature
          signee={data.signee}
          salutation="Very truly yours,"
          className="mb-2 mt-4 flex justify-end print:mt-6"
        />
        <ReceiptInfo
          officialReceiptNum={data.officialReceiptNum}
          date={bold(formatDateFormal(data.date))}
          className="mt-6 pt-2 print:mt-10 font-lucida"
        />
      </div>
    ),
  },

  10: {
    id: 10,
    name: "Certificate of Enrollment - PRESENT",
    otherNames: ["Enrollment", "Certificate of Enrollment", "Certificate of Enrollment - PRESENT", "Certificate of Enrollment (PRESENT)"],
    fields: ["fullName", "course", "semesters", "syAdmitted", "diplomaNum"],
    renderBody: (data) => (
      <StandardCertLayout date={data.date}>
        <CertParagraph>
          This is to certify that {fillOrLine(data.fullName)} is enrolled in this Campus,{" "}
          {data.semesters} of S.Y {fillOrLine(data.syAdmitted)}, under our {fillOrLine(data.course)} program.
        </CertParagraph>
        <IssuedLine date={data.date} />
        <RegistrarSignature signee={data.signee} />
        <ReceiptInfo officialReceiptNum={data.officialReceiptNum} date={bold(formatDateFormal(data.date))} />
      </StandardCertLayout>
    ),
  },

  11: {
    id: 11,
    name: "Certificate of Enrollment - UNDERGRAD",
    otherNames: ["Certificate of Enrollment - UNDERGRAD", "Enrollment - UNDERGRAD", "Certificate of Enrollment (UNDERGRAD)"],
    fields: ["fullName", "course", "semesters", "lastSemesters", "syAdmitted", "lastSy", "diplomaNum", "units", "semestersNum"],
    renderBody: (data) => (
      <StandardCertLayout date={data.date}>
        <CertParagraph>
          This is to certify that {fillOrLine(data.fullName)} was enrolled in this Campus,{" "}
          {data.semesters} of S.Y {fillOrLine(data.syAdmitted)}, until {data.lastSemesters} of S.Y{" "}
          {fillOrLine(data.lastSy)}, under our {fillOrLine(data.course)} with a total of {fillOrLine(data.units)}{" "}
          {bold("units")} for {fillOrLine(data.semestersNum)} {bold("semester")}.
        </CertParagraph>
        <IssuedLine date={data.date} />
        <DirectorSignature signee={data.signee} />
        <ReceiptInfo officialReceiptNum={data.officialReceiptNum} date={bold(formatDateFormal(data.date))} />
      </StandardCertLayout>
    ),
  },

  12: {
    id: 12,
    name: "Certificate of Ladderized Course",
    fields: ["fullName", "course", "major", "ladderizedDegree", "officialReceiptNum"],
    renderBody: (data) => (
      <StandardCertLayout date={data.date}>
        <CertParagraph className="mb-5">
          This is to certify that {fillOrLine(data.fullName)} is a bonafide student of this University
          taking up the {fillOrLine(data.course)} {bold("major in")} {fillOrLine(data.major)}
        </CertParagraph>
        <CertParagraph className="mb-5">
          This also certifies that this course is under a ladderized program. Holders of this
          program have the opportunity to further their studies to {fillOrLine(data.ladderizedDegree)}{" "}
          when all the requirements have been met.
        </CertParagraph>
        <CertParagraph className="mb-15">
          This certification is being issued this upon request of the aforementioned name for
          whatever legal purpose it may serve.
        </CertParagraph>
        <RegistrarSignature signee={data.signee} />
        <ReceiptInfo className="mt-4" officialReceiptNum={data.officialReceiptNum} date={bold(formatDateFormal(data.date))} />
      </StandardCertLayout>
    ),
  },

  13: {
    id: 13,
    name: "CAV Request Letter",
    otherNames: ["CAV/Apostille (DFA)", "CAV (DFA)", "CAV (DFA) - undergraduate", "CAV (DFA) with Special Certification", "CAV/Apostille (DFA) - undergraduate", "CAV Request Letter"],
    hideHeaderFooter: true,
    fields: ["fullName", "course", "major", "studentStatus", "date"],
    renderBody: (data, layout) => (
      <>
        <TextBlock className="text-right text-xs sm:text-sm text-gray-700 -mt-5 font-lucida">
          <p>{formatDateFormal(data.date)}</p>
          <p className="text-[10px] text-gray-500">Date</p>
        </TextBlock>
        <TextBlock className="mb-3 text-[11px] sm:text-[12px] print:text-[9.5pt] print:leading-tight font-lucida">
          <p className="mb-1">CAV Request Letter</p>
          <p>Atty. Marco Cicero F. Domingo, CESE</p>
          <p>Director IV</p>
          <p>CHED-NCR</p>
        </TextBlock>
        <TextBlock className="mb-2 text-[11px] sm:text-[12px] print:text-[9.5pt] font-lucida">Madam:</TextBlock>
        <TextBlock className="indent-8 text-[11px] sm:text-[12px] print:text-[9.5pt] print:leading-normal leading-relaxed font-lucida text-justify mb-3">
          I, <strong className="underline">{fillOrLine(data.fullName)}</strong>, would
          like to request your good office, for the authentication of my academic records in{" "}
          <strong className="underline">
            {fillOrLine(data.course)}
            {data.major ? ` major in ${data.major}` : ""}
          </strong>{" "}
          issued by Polytechnic University of the Philippines - Taguig. In this connection, I am
          submitting the following records through the Office of the Branch Registrar.
        </TextBlock>
        <EndorsementNoteBlock
          items={[
            "Certified True Copy of Transcript of Records",
            "Certified True Copy of Diploma",
            "Certification of Non-Issuance of Special Order",
          ]}
          twoCol={false}
          className="print:text-[9.5pt] mb-2"
        />
        <TextBlock className="text-[11px] sm:text-[12px] print:text-[9.5pt] font-lucida mb-3">Thank you,</TextBlock>
        <TextBlock className="flex justify-end pr-4 sm:pr-8 -mt-3 font-lucida">
          <div className="text-center w-56">
            <p className="text-[11px] sm:text-[12px] print:text-[9.5pt] mb-1 font-lucida">Respectfully yours,</p>
            <p className="mt-4 border-b border-gray-800 font-lucida w-full text-[11px] sm:text-[12px] print:text-[9.5pt]">
              {fillOrLine(data.fullName)}
            </p>
            <p className="text-[10px] sm:text-[11px] print:text-[9pt] mt-0.5 font-lucida">Student</p>
            <p className="text-[8px] sm:text-[9px] print:text-[8pt] text-gray-500 font-lucida">(Signature over printed name)</p>
          </div>
        </TextBlock>
        <TextBlock className="border-t-2 border-gray-800 text-center py-0.5 my-2 font-lucida mb-0">
          <p className="font-bold text-[11px] sm:text-[12px] print:text-[10pt]">1<sup>st</sup> Endorsement</p>
        </TextBlock>
        <PupLetterhead date={data.date} layout={layout}/>
        <TextBlock className="text-[11px] sm:text-[12px] print:text-[9.5pt] print:leading-normal leading-relaxed text-justify mb-2 font-lucida">
          Respectfully forwarded to the Director IV, Commission on Higher Education-National Capital
          Region, the request of
        </TextBlock>
        <div className="flex gap-4 mb-2 mt-1 font-lucida">
          {[
            [fillOrLine(data.fullName), "(Name of Student,"],
            [fillOrLine(data.studentStatus), "Status"],
            [fillOrLine(data.course), "etc.)"],
          ].map(([value, label], i) => (
            <div key={i} className="flex-1 text-center">
              <p className="font-bold text-[11px] sm:text-[12px] print:text-[9.5pt] border-b border-gray-800 pb-0.5">{value}</p>
              <p className="text-[9px] print:text-[8.5pt] text-gray-500">{label}</p>
            </div>
          ))}
        </div>
        <TextBlock className="text-[11px] sm:text-[12px] print:text-[9.5pt] print:leading-normal leading-relaxed text-justify mb-3 font-lucida">
          for the Authentication of her record, recommending approval, with the certification that
          the documents forwarded herewith are true and authentic copies of the documents issued
          and/or kept by this institution
        </TextBlock>
        <div className="flex justify-end pr-4 sm:pr-8 font-lucida mb-2">
          <div className="text-center">
            <DirectorSignature signee={data.signee} className="mb-1 mt-2 flex justify-end print:mt-4" />
          </div>
        </div>
        <EndorsementNoteBlock
          items={[
            "Certified True Copy of Transcript of Records",
            "Certified True Copy of Diploma",
            "Certification of Non-Issuance of Special Order",
          ]}
          twoCol={false}
          className="print:text-[9.5pt] mb-2"
        />
      </>
    ),
  },

  14: {
    id: 14,
    name: "CAV",
    otherNames: ["CAV (CHED)", "CAV (CHED) AND CAV/Apostille (DFA) -undergraduate", "Certification, Authentication, and Verification"],
    fields: ["fullName", "course", "major", "syAdmitted", "dateGraduated", "cavNum", "cavSeries", "officialReceiptNum", "amount", "date"],
    renderBody: (data) => (
      <>
        <RegistrarDateTitle date={data.date} />
        <TextBlock className="mb-6 text-[10px] sm:text-[11px] font-lucida -mt-2">
          <p className="font-bold">CAV-PUP No. {data.cavNum}</p>
          <p className="text-[9px]">Series {data.cavSeries || new Date().getFullYear()}</p>
        </TextBlock>
        <div className="text-center mb-2 font-lucida text-[12px] sm:text-[15px] md:text-[20px] font-bold uppercase tracking-normal leading-tight text-black">
          Certification, Authentication, and Verification
        </div>
        <div className="space-y-4 text-[9px] sm:text-[9px] leading-relaxed text-justify font-lucida print:text-[9pt]">
          <TextBlock>To Whom It May Concern:</TextBlock>
          <p className="indent-8">This is to certify that based on our record, mentioned below:</p>
          <div className="text-[10px] sm:text-[11px]">
            <table className="w-full font-lucida">
              <thead className="sr-only"><tr><th>Field</th><th>Sep</th><th>Value</th></tr></thead>
              <tbody>
                {[
                  ["Name of Student", <span key="name-of-student" className="font-medium uppercase">{fillOrLine(data.fullName, 15)}</span>],
                  ["Degree", <span key="degree" className="font-medium uppercase">{fillOrLine(`${data.course || ""}${data.major ? ` MAJOR IN ${data.major.toUpperCase()}` : ""}`, 20)}</span>],
                  ["Date of Admission/Enrollment", data.syAdmitted ? new Date(data.syAdmitted).getFullYear() : "___"],
                  ["Date of Graduation", data.dateGraduated ? formatDateFormal(data.dateGraduated) : "___________________"],
                  ["Mode of Study", "Conventional"],
                  ["Name of Institution", <span key="name-of-institution" className="font-medium uppercase">Polytechnic University of the Philippines – Taguig</span>],
                  ["Address", "Gen. Santos Avenue, Taguig City"],
                ].map(([label, value], i) => (
                  <tr key={i} className="align-top font-lucida">
                    <td className="w-[45%] py-1">{label}</td>
                    <td className="w-[5%] py-1">:</td>
                    <td className="py-1">{value}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <TextBlock className="indent-8 text-justify font-lucida">
            This is to certify further that the above institution is a duly authorized public higher
            education institution (HEI) created by virtue of P.D. No. 1341, hence it is exempted
            from the issuance of Special Order by the Commission on Higher Education.
          </TextBlock>
          <TextBlock className="indent-8 text-justify font-lucida">
            This certification must not be honored if the copies of the student's Transcript of
            Record and Diploma presented are not duly authenticated/ certified by the Campus Registrar.
          </TextBlock>
          <TextBlock className="indent-8 text-justify mb-5 font-lucida">
            Issued upon request of{" "}
            <strong className="uppercase">{fillOrLine(data.fullName)}</strong>{" "}
            for whatever legal purpose it may serve.
          </TextBlock>
        </div>
        <div className="print:-mt-5">
          <DirectorSignature signee={data.signee} />
        </div>
        <TextBlock className="text-[7px] sm:text-[8px] space-y-1 font-lucida">
          <div className="mt-2 grid grid-cols-2 gap-x-2 text-[7px]" style={{ maxWidth: "260px" }}>
            <div className="col-span-2 font-bold tracking-tight">
              NOT VALID WITHOUT UNIVERSITY DRY SEAL
            </div>
            <div className="col-span-2 tracking-tight">
              OR WITH ERASURE OR ALTERATION
            </div>
            {[
              ["PROCESSED BY", "S G. SESE"],
              ["REVIEWED BY", "M.P. GARCIA"],
              ["O.R. No.", data.officialReceiptNum || "___________"],
              ["Date Issued", data.date || "___________"],
              ["AMOUNT", `PHP ${data.amount || "___________"}`],
            ].map(([label, value], i) => (
              <React.Fragment key={i}>
                <p>{label}</p>
                <p>: {value}</p>
              </React.Fragment>
            ))}
          </div>
        </TextBlock>
      </>
    ),
  },

  15: {
    id: 15,
    name: "Certification of NSTP-CWTS",
    otherNames: ["NSTP Serial No.", "NSTP Serial Number", "NSTP", "NSTP-CWTS", "Certification of NSTP-CWTS"],
    fields: ["fullName", "semesters", "syAdmitted", "nstpSerialNum", "officialReceiptNum", "date"],
    renderBody: (data) => (
      <>
        <RegistrarDateTitle date={data.date} />
        <CertificateTitle title="C E R T I F I C A T I O N" />
        <div className="space-y-4 text-[12px] sm:text-[13px] leading-relaxed text-justify px-2 sm:px-4 print:text-[12pt]">
          <TextBlock className=" font-lucida">To Whom It May Concern:</TextBlock>
          <CertParagraph>
            This is to certify that {fillOrLine(data.fullName)} has completed{" "}
            {bold("NSTP CWTS")} in the {data.semesters || "___________________"}, S.Y.{" "}
            {data.syAdmitted ? new Date(data.syAdmitted).getFullYear() : "____"}-
            {data.syAdmitted ? new Date(data.syAdmitted).getFullYear() + 1 : "____"} in this
            University with Serial Number {fillOrLine(data.nstpSerialNum)} in
            compliance with RA 9163.
          </CertParagraph>
          <CertParagraph className="mb-15">
            This certification has been issued upon the request of the aforementioned name for
            whatever legal purpose it may serve.
          </CertParagraph>
        </div>
        <RegistrarSignature signee={data.signee} />
        <ReceiptInfo officialReceiptNum={data.officialReceiptNum} date={bold(formatDateFormal(data.date))} />
      </>
    ),
  },

  16: {
    id: 16,
    name: "Endorsement Letter",
    otherNames: ["Endorsement", "1st Endorsement", "Endorsement Letter"],
    hideHeaderFooter: true,
    fields: ["fullName", "course", "major", "date"],
    renderBody: (data, layout) => (
      <>
        <TextBlock className="text-right text-xs sm:text-sm text-gray-700 -mt-5 font-lucida">
          <p>{formatDateFormal(data.date)}</p>
        </TextBlock>
        <TextBlock className="mb-3 text-[11px] sm:text-[12px] print:text-[9.5pt] print:leading-tight font-lucida">
          <p className="mb-1">The Head</p>
          <p>DFA Authentical Division</p>
          <p>Roxas Boulevard</p>
          <p>Pasay City</p>
        </TextBlock>
        <TextBlock className="mb-2 text-[11px] sm:text-[12px] print:text-[9.5pt] font-lucida">Dear Sir/Madame:</TextBlock>
        <TextBlock className="indent-6 text-[11px] sm:text-[12px] print:text-[9.5pt] print:leading-normal leading-relaxed text-justify mb-3 font-lucida">
          I, <strong className="underline">{fillOrLine(data.fullName)}</strong>, would
          like to request your good office, for the authentication of my academic records in{" "}
          <strong className="underline">
            {fillOrLine(data.course)}
            {data.major ? ` major in ${data.major}` : ""}
          </strong>{" "}
          issued by Polytechnic University of the Philippines - Taguig. In this connection, I am
          submitting the following records through the Office of the Branch Registrar.
        </TextBlock>
        <EndorsementNoteBlock
          items={[
            "Official Transcript of Records",
            "Diploma",
            "Certification of Enrollment (for undergraduate only)",
            "Certification of Clinical Experience (if applicable)",
            "Certified Copy of Special Order"
          ]}
          twoCol={false}
          className="print:text-[9.5pt] mb-2"
        />
        <TextBlock className="text-[11px] sm:text-[12px] print:text-[9.5pt] font-lucida mb-3">Thank you,</TextBlock>
        <div className="flex justify-end pr-4 sm:pr-8 -mt-3 font-lucida">
          <div className="text-center w-56">
            <TextBlock className="text-[11px] sm:text-[12px] print:text-[9.5pt] mb-0 font-lucida font-lucida">Respectfully yours,</TextBlock>
            <TextBlock className="mt-4 text-center">
              <p className="text-[10px] sm:text-[11px] print:text-[9.5pt] font-semibold font-lucida">
                {fillOrLine(data.fullName)}
              </p>
              <div className="border-b border-gray-800 w-48 mx-auto mt-1" />
              <p className="text-[9px] sm:text-[10px] print:text-[8.5pt] mt-0.5 font-lucida">
                Student
              </p>
            </TextBlock>
          </div>
        </div>
        <div className="border-t-2 border-gray-800 text-center py-0.5 my-2 font-lucida mb-0">
          <TextBlock className="mb-0 font-bold text-[11px] sm:text-[12px] print:text-[10pt]">1<sup>st</sup> Endorsement</TextBlock>
        </div>
        <PupLetterhead date={data.date} layout={layout} />
        <TextBlock className="indent-6 text-[11px] sm:text-[12px] print:text-[9.5pt] print:leading-normal leading-relaxed text-justify mb-2 font-lucida">
          Respectfully forwarded to the Director, Authentication Department Region, the request of{" "}
          {fillOrLine(data.fullName)} {bold("GRADUATED -")} {fillOrLine(data.course)} for the
          Authentication of her record, recommending approval, with the certification that the
          documents forwarded herewith are true and authentic copies of the documents issued and/or
          kept by this institution
        </TextBlock>
        <div className="flex justify-end pr-4 sm:pr-8 font-lucida mb-2">
          <div className="text-center">
            <DirectorSignature signee={data.signee} className="mb-1 mt-2 flex justify-end print:mt-4" />
          </div>
        </div>
        <EndorsementNoteBlock
          items={[
            "Diploma",
            "Transcript of Records",
            "Certification of Graduation",
            "Certification of without Special Order",
            "Certification of Enrollment (for undergraduate only)",
            "Others__________________________________________",
          ]}
          twoCol={false} //true if you want two columns, false for one column
          className="print:text-[9.5pt] mb-2"
        />
      </>
    ),
  },

  17: {
    id: 17,
    name: "Certificate of Eligibility to Transfer",
    hideHeaderFooter: true,
    fields: ["fullName", "date"],
    renderBody: (data,layout) => (
      <>
        <div className="flex justify-between items-start mb-4">
          <div className="flex items-center gap-3">
              <img src={layout?.headerLeftUrl || puplogoimage} alt="PUP Logo" className="w-12 h-12 object-contain" />            <TextBlock className="text-[8px] font-serif leading-tight text-center">
              <p>REPUBLIC OF THE PHILIPPINES</p>
              <p className="font-bold uppercase">Polytechnic University of the Philippines</p>
              <p>OFFICE OF THE VICE PRESIDENT FOR CAMPUSES</p>
              <p className="font-bold uppercase">Taguig Campus</p>
              <p>Office of the Campus Registrar</p>
            </TextBlock>
          </div>
          <TextBlock className="text-[7px] text-right font-serif">
            <p>PUP-HODI-5-UNRO-024</p>
            <p>REV.0</p>
            <p>May 15, 2018</p>
          </TextBlock>
        </div>
        <CertificateTitle title="Certificate of Eligibility to Transfer" />
        <div className="space-y-1 text-[11px] sm:text-[12px] leading-relaxed text-justify px-2 sm:px-4 print:text-[10pt]">
          <div className="text-right font-bold mb-2">{formatDateFormal(data.date)}</div>
          <TextBlock className="font-lucida">TO WHOM IT MAY CONCERN:</TextBlock>
          <CertParagraph>
            This is to certify that {fillOrLine(data.fullName)} is hereby granted{" "}
            {bold("CERTIFICATE OF ELIGIBILITY TO TRANSFER CREDENTIAL/HONORABLE DISMISSAL")} from
            this University effective {bold(formatDateFormal(data.date))}.
          </CertParagraph>
          <TextBlock className="text-[10px] italic">Note: Not valid without University's seal.</TextBlock>
        </div>
        <div className="flex justify-end pr-4 sm:pr-8">
          <div className="text-center">
            <RegistrarSignature signee={data.signee} />
          </div>
        </div>
        <div className="flex items-center gap-2 my-6 -mt-6">
          <span className="text-lg">✂</span>
          <div className="flex-1 border-t-2 border-dashed border-gray-400" />
        </div>
        <div className="text-center mb-4">
          <TextBlock className="font-bold text-[13px] sm:text-[14px] uppercase tracking-wide">Request Form</TextBlock>
        </div>
        <div className="flex gap-4">
          <img src={layout?.headerLeftUrl || puplogoimage} alt="PUP Logo" className="w-12 h-12 object-contain" />          
          <TextBlock className="flex-1 space-y-3 text-[11px] sm:text-[12px]">
            {["Name of School:", "Address:", "Date:"].map((label) => (
              <div key={label} className="flex items-end gap-2">
                <span>{label}</span>
                <div className="flex-1 border-b border-gray-800" />
              </div>
            ))}
          </TextBlock>
        </div>
        <TextBlock className="text-[11px] sm:text-[12px] space-y-3 font-serif">
          <TextBlock>The Campus Registrar</TextBlock>
          <TextBlock className="font-bold uppercase">Polytechnic University of the Philippines</TextBlock>
          <TextBlock>Taguig City</TextBlock>
          <TextBlock className="font-bold">Sir/Madam:</TextBlock>
          <TextBlock className="indent-8 text-justify">
            I have the honor to request to send us the Transcript of Records of Mr./Ms.{" "}
            <strong>{fillOrLine(data.fullName)}</strong>, who has been temporarily
            enrolled in this school for the _____________ semester/summer, _____________ upon
            presentation of his/her Certificate of Eligibility to Transfer/Honorable Dismissal.
          </TextBlock>
          <div className="flex justify-between items-end mt-3">
            <TextBlock>
              <p className="italic text-[10px]">This is to certify that I am actually</p>
              <p className="italic text-[10px]">Enrolled in the school mentioned above</p>
            </TextBlock>
            <div className="text-right space-y-6">
              <p>Very Respectfully,</p>
              <TextBlock className="border-t border-gray-800 pt-1 w-48">
                <p className="text-[10px] italic text-center">Registrar's Signature over printed name</p>
              </TextBlock>
              <TextBlock className="w-48 text-center mb-0">
                <p className="font-semibold text-[10px] uppercase font-lucida text-center">
                  {fillOrLine(data.fullName)}
                </p>
                <div className="border-t border-gray-800 w-full mt-1" />
                <p className="text-[10px] italic text-center mt-1">Student's Signature over printed name</p>
              </TextBlock>
            </div>
          </div>
          <p className="text-[9px]">/shgsese{CURRENT_YEAR}</p>
        </TextBlock>
      </>
    ),
  },
};