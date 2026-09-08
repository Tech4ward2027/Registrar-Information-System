import React, { useState } from "react";
import { PlusIcon } from "@heroicons/react/24/outline";
import InputGroup from "../components/InputGroup";
import ProcessPeriodInput from "../components/ProcessPeriodInput.jsx";
import VoiceTextareaInput from "../components/VoiceTextareaInput.jsx";
import VoiceSearchInput from "../components/VoiceSearchInput.jsx";
import DropdownGroup from "../components/DropDown.jsx";
import SuccessToast from "../components/SuccessToast.jsx";
import ErrorToast from "../components/ErrorToast.jsx";
import DeleteConfirmModal from "../components/DeleteConfirmModal.jsx";
import { useTheme } from "../context/ThemeContext";
import { useReferenceData } from "../context/ReferenceDataContext";
import { useDocumentManagement } from "../hooks/useDocumentManagement";
import { createLogbookCategory, createFulfillmentTrack } from "../services/api";
import {
  EXCLUSIVE_FOR,
  FOLDER_COLORS,
} from "../utils/documentManagementUtils";
import {
  ManagementCard,
  ManagementCarousel,
} from "../components/DocumentManagementComponents";
import { EnabledSwitch } from "../components/BusinessCalendarComponents.jsx";

const DocumentManagement = ({
  documents,
  setDocuments,
  certifications,
  setCertifications,
  loading,
  onArchiveDoc,
  onArchiveCert,
}) => {
  const { isDark } = useTheme();
  const { logbookCategories, refreshLogbookCategories, fulfillmentTracks, refreshFulfillmentTracks } = useReferenceData();

  // Inline "add a new logbook category" flow — lets an admin create the
  // umbrella label (e.g. "Certified True Copy of Records") right from
  // this form the first time they need it, instead of requiring a
  // separate admin screen just to seed rows into logbook_category.
  const [addingCategory, setAddingCategory] = useState(false);
  const [newCategoryName, setNewCategoryName] = useState("");
  const [categorySaving, setCategorySaving] = useState(false);
  const [categoryError, setCategoryError] = useState("");

  // Same inline-create pattern as Logbook Category above, for
  // fulfillment_track. Until this existed, no document/certificate type
  // could ever be assigned a non-null track from the UI — the only way
  // was a direct DB write, which meant RequestReleaseGroupService::
  // assignReleaseGroups() never had more than one bucket to work with
  // and Phase 3 claim-ticket grouping never actually triggered for any
  // real request.
  const [addingTrack, setAddingTrack] = useState(false);
  const [newTrackName, setNewTrackName] = useState("");
  const [trackSaving, setTrackSaving] = useState(false);
  const [trackError, setTrackError] = useState("");

  const {
    search,
    setSearch,
    selected,
    selectedType,
    form,
    setForm,
    isAdding,
    successMsg,
    setSuccessMsg,
    errorMsg,
    setErrorMsg,
    deleteModal,
    setDeleteModal,
    deleteLoading,
    handleChange,
    handleVoiceTextareaChange,
    handleEditDoc,
    handleEditCert,
    handleAddDoc,
    handleAddCert,
    handleCancel,
    handleSave,
    confirmDelete,
    handleDelete,
    handleArchiveDoc,
    handleArchiveCert,
  } = useDocumentManagement({
    documents,
    setDocuments,
    certifications,
    setCertifications,
    onArchiveDoc,
    onArchiveCert,
  });

  const handleCreateCategory = async () => {
    const name = newCategoryName.trim();
    if (!name) {
      setCategoryError("Category name is required.");
      return;
    }
    setCategorySaving(true);
    setCategoryError("");
    try {
      const res = await createLogbookCategory({ name });
      const created = res?.data;
      await refreshLogbookCategories();
      if (created?.logbook_category_id) {
        setForm((prev) => ({ ...prev, logbook_category_id: created.logbook_category_id }));
      }
      setNewCategoryName("");
      setAddingCategory(false);
    } catch (err) {
      setCategoryError(err.response?.data?.message || "Failed to create category.");
    } finally {
      setCategorySaving(false);
    }
  };

  const handleCreateTrack = async () => {
    const name = newTrackName.trim();
    if (!name) {
      setTrackError("Track name is required.");
      return;
    }
    setTrackSaving(true);
    setTrackError("");
    try {
      const res = await createFulfillmentTrack({ name });
      const created = res?.data;
      await refreshFulfillmentTracks();
      if (created?.fulfillment_track_id) {
        setForm((prev) => ({ ...prev, fulfillment_track_id: created.fulfillment_track_id }));
      }
      setNewTrackName("");
      setAddingTrack(false);
    } catch (err) {
      setTrackError(err.response?.data?.message || "Failed to create track.");
    } finally {
      setTrackSaving(false);
    }
  };

  // Filter lists by search query and active status
  const searchTerm = search.trim().toLowerCase();

  const filteredDocs = (documents || []).filter((d) => {
    if (d.is_archived) return false;
    if (!searchTerm) return true;

    const name = String(d?.document_name ?? "").toLowerCase();
    const reqs = String(d?.document_requirements ?? "").toLowerCase();
    const desc = String(d?.document_description ?? "").toLowerCase();
    const period = String(d?.document_process_period ?? "").toLowerCase();
    const patterns = Array.isArray(d?.cashier_document_patterns)
      ? d.cashier_document_patterns.join(" ").toLowerCase()
      : String(d?.cashier_document_patterns ?? "").toLowerCase();

    return (
      name.includes(searchTerm) ||
      reqs.includes(searchTerm) ||
      desc.includes(searchTerm) ||
      period.includes(searchTerm) ||
      patterns.includes(searchTerm)
    );
  });

  const filteredCerts = (certifications || []).filter((c) => {
    if (c.is_archived) return false;
    if (!searchTerm) return true;

    const name = String(c?.certificate_name ?? "").toLowerCase();
    const reqs = String(c?.certificate_requirements ?? "").toLowerCase();
    const period = String(c?.certificate_process_period ?? "").toLowerCase();
    const patterns = Array.isArray(c?.cashier_document_patterns)
      ? c.cashier_document_patterns.join(" ").toLowerCase()
      : String(c?.cashier_document_patterns ?? "").toLowerCase();

    return (
      name.includes(searchTerm) ||
      reqs.includes(searchTerm) ||
      period.includes(searchTerm) ||
      patterns.includes(searchTerm)
    );
  });

  return (
    <div className={`font-sans rounded-2xl p-4 sm:px-6 ${isDark ? "bg-[#18191a] text-[#e4e6eb]" : "bg-white text-gray-900"}`}>
      <style>{`
        .no-scrollbar::-webkit-scrollbar {
          display: none !important;
          width: 0 !important;
          height: 0 !important;
          background: transparent !important;
        }
        .no-scrollbar::-webkit-scrollbar-track {
          background: transparent !important;
          display: none !important;
        }
        .no-scrollbar::-webkit-scrollbar-thumb {
          background: transparent !important;
          display: none !important;
        }
        .no-scrollbar {
          -ms-overflow-style: none !important;
          scrollbar-width: none !important;
        }
      `}</style>

      {/* Top bar */}
      <div className="flex flex-col py-5 lg:flex-row gap-4 sm:gap-6 items-start mb-6">
        <div className="w-full lg:max-w-sm">
          <div className="w-full">
            <VoiceSearchInput
              value={search}
              onChange={(value) => {
                setSearch(value);
              }}
              placeholder="Search"
            />
          </div>
        </div>

        <div className="w-full lg:flex-1 flex justify-start lg:justify-end gap-3 flex-wrap">
          <button
            onClick={handleAddDoc}
            className={`flex-1 sm:flex-none flex items-center justify-center gap-2 px-5 py-2.5 rounded-full text-sm font-semibold shadow transition-all cursor-pointer ${
              isAdding && selectedType === "document"
                ? isDark
                  ? "bg-yellow-400 text-gray-900 border-2 border-yellow-400 shadow-md scale-102"
                  : "bg-pup-dark-maroon text-white border-2 border-pup-dark-maroon shadow-md scale-102"
                : isDark
                ? "bg-[#2a2a2f] text-[#e4e6eb] hover:bg-[#353539] border border-[#3e4042]"
                : "bg-pup-dark-maroon text-white hover:bg-[#3a0303]"
            }`}
          >
            Add Document <PlusIcon className="w-4 h-4" />
          </button>
          <button
            onClick={handleAddCert}
            className={`flex-1 sm:flex-none flex items-center justify-center gap-2 px-5 py-2.5 rounded-full text-sm font-semibold shadow transition-all cursor-pointer ${
              isAdding && selectedType === "certificate"
                ? isDark
                  ? "bg-yellow-400 text-gray-900 border-2 border-yellow-400 shadow-md scale-102"
                  : "bg-pup-dark-maroon text-white border-2 border-pup-dark-maroon shadow-md scale-102"
                : isDark
                ? "bg-[#2a2a2f] text-[#e4e6eb] hover:bg-[#353539] border border-[#3e4042]"
                : "bg-pup-dark-maroon text-white hover:bg-[#3a0303]"
            }`}
          >
            Add Certificate <PlusIcon className="w-4 h-4" />
          </button>
        </div>
      </div>

      {/* Content */}
      <div className="flex flex-col gap-6 w-full">
        {/* 2 in a Row Grid Wrapper */}
        <div className="grid grid-cols-1 xl:grid-cols-2 gap-6 w-full items-stretch">
          {/* List of Documents Container Box */}
          <ManagementCarousel
            title="List of Documents"
            items={filteredDocs}
            loading={loading}
            emptyMessage="No documents found"
            isDark={isDark}
            renderItem={(doc, idx) => {
              const style = FOLDER_COLORS[idx % FOLDER_COLORS.length];
              const isSelected = selectedType === "document" && selected?.document_type_id === doc.document_type_id;
              return (
                <ManagementCard
                  key={doc.document_type_id}
                  id={doc.document_type_id}
                  name={doc.document_name}
                  isSelected={isSelected}
                  onClick={() => handleEditDoc(doc)}
                  onArchive={handleArchiveDoc}
                  onDelete={(id) => confirmDelete(id, "document")}
                  style={style}
                  archiveTooltip="Archive Document"
                  deleteTooltip="Delete Document"
                  isDark={isDark}
                />
              );
            }}
          />

          {/* List of Certificates Container Box */}
          <ManagementCarousel
            title="List of Certificates"
            items={filteredCerts}
            loading={loading}
            emptyMessage="No certificates found"
            isDark={isDark}
            renderItem={(cert, idx) => {
              const style = FOLDER_COLORS[idx % FOLDER_COLORS.length];
              const isSelected = selectedType === "certificate" && selected?.certificate_type_id === cert.certificate_type_id;
              return (
                <ManagementCard
                  key={cert.certificate_type_id}
                  id={cert.certificate_type_id}
                  name={cert.certificate_name}
                  isSelected={isSelected}
                  onClick={() => handleEditCert(cert)}
                  onArchive={handleArchiveCert}
                  onDelete={(id) => confirmDelete(id, "certificate")}
                  style={style}
                  archiveTooltip="Archive Certificate"
                  deleteTooltip="Delete Certificate"
                  isDark={isDark}
                />
              );
            }}
          />
        </div>

        <form
          onSubmit={handleSave}
          noValidate
          className={`rounded-xl p-6 sm:p-10 py-4 w-full flex flex-col gap-5 shadow h-fit ${isDark ? "bg-[#242526] border border-[#3e4042]" : "bg-white border border-gray-200"
            }`}
        >
          <h2 className={`font-bold text-xl mb-2 ${isDark ? "text-white" : "text-pup-dark-maroon"}`}>
            {isAdding
              ? selectedType === "document"
                ? "Add Document"
                : "Add Certificate"
              : selectedType === "document"
                ? "Edit Document"
                : "Edit Certificate"}
          </h2>

          <InputGroup
            label={selectedType === "document" ? "Document Name" : "Certificate Name"}
            name="document_name"
            value={form.document_name}
            onChange={handleChange}
            placeholder={selectedType === "document" ? "e.g. ICOG" : "e.g. Certificate of Enrollment"}
            required
            labelColor={isDark ? "text-[#b0b3b8]" : "text-gray-600"}
          />

          {/* Logbook Category & Fulfillment Track — key classification fields placed high in the form for easy visibility */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6 w-full">
            {/* Logbook Category */}
            <div className="flex flex-col gap-1.5 w-full">
              <label className={`text-sm font-medium ${isDark ? "text-[#e4e6eb]" : "text-gray-700"}`}>
                Logbook category
              </label>
              {!addingCategory ? (
                <div className="flex items-center gap-2 w-full">
                  <div className="flex-1">
                    <DropdownGroup
                      name="logbook_category_id"
                      value={
                        logbookCategories.find((c) => String(c.logbook_category_id) === String(form.logbook_category_id))?.name ||
                        "None"
                      }
                      onChange={(e) => {
                        const selectedName = e.target.value;
                        const matched = logbookCategories.find((c) => c.name === selectedName);
                        setForm((prev) => ({
                          ...prev,
                          logbook_category_id: matched ? matched.logbook_category_id : "",
                        }));
                      }}
                      options={[
                        "None",
                        ...logbookCategories.map((c) => c.name),
                      ]}
                      labelColor={isDark ? "text-[#b0b3b8]" : "text-gray-600"}
                    />
                  </div>
                  <button
                    type="button"
                    onClick={() => setAddingCategory(true)}
                    className={`px-4 py-3 rounded-lg text-sm font-medium border whitespace-nowrap transition-colors cursor-pointer shrink-0 ${isDark
                        ? "bg-[#1f1f1f] border-[#3e4042] text-[#e4e6eb] hover:bg-[#2a2a2f]"
                        : "bg-white border-gray-200 text-gray-700 hover:bg-gray-50 shadow-xs"
                      }`}
                  >
                    + New
                  </button>
                </div>
              ) : (
                <div className="flex flex-col gap-2 w-full">
                  <div className="flex gap-2 w-full">
                    <input
                      type="text"
                      value={newCategoryName}
                      onChange={(e) => setNewCategoryName(e.target.value)}
                      placeholder="e.g. Certified True Copy of Records"
                      className={`flex-1 px-4 py-2.5 rounded-lg text-sm border transition-colors ${isDark
                          ? "bg-[#1f1f1f] border-[#3e4042] text-[#e4e6eb] placeholder-[#6b6b6b]"
                          : "bg-white border-gray-200 text-gray-700 placeholder-gray-400"
                        }`}
                    />
                    <button
                      type="button"
                      disabled={categorySaving}
                      onClick={handleCreateCategory}
                      className={`px-4 py-2.5 rounded-lg text-sm font-semibold whitespace-nowrap transition-colors cursor-pointer disabled:opacity-50 ${isDark ? "bg-yellow-400 text-gray-900" : "bg-pup-dark-maroon text-white"
                        }`}
                    >
                      {categorySaving ? "Saving..." : "Save"}
                    </button>
                    <button
                      type="button"
                      onClick={() => {
                        setAddingCategory(false);
                        setNewCategoryName("");
                        setCategoryError("");
                      }}
                      className={`px-4 py-2.5 rounded-lg text-sm font-semibold whitespace-nowrap transition-colors cursor-pointer ${isDark ? "text-[#b0b3b8] hover:bg-[#2a2a2f]" : "text-gray-600 hover:bg-gray-100"
                        }`}
                    >
                      Cancel
                    </button>
                  </div>
                  {categoryError && <span className="text-xs text-red-400">{categoryError}</span>}
                </div>
              )}
            </div>

            {/* Fulfillment Track */}
            <div className="flex flex-col gap-1.5 w-full">
              <label className={`text-sm font-medium ${isDark ? "text-[#e4e6eb]" : "text-gray-700"}`}>
                Fulfillment track
              </label>
              {!addingTrack ? (
                <div className="flex items-center gap-2 w-full">
                  <div className="flex-1">
                    <DropdownGroup
                      name="fulfillment_track_id"
                      value={
                        fulfillmentTracks.find((t) => String(t.fulfillment_track_id) === String(form.fulfillment_track_id))?.name ||
                        "Standard"
                      }
                      onChange={(e) => {
                        const selectedName = e.target.value;
                        const matched = fulfillmentTracks.find((t) => t.name === selectedName);
                        setForm((prev) => ({
                          ...prev,
                          fulfillment_track_id: matched ? matched.fulfillment_track_id : "",
                        }));
                      }}
                      options={[
                        "Standard",
                        ...fulfillmentTracks.map((t) => t.name),
                      ]}
                      labelColor={isDark ? "text-[#b0b3b8]" : "text-gray-600"}
                    />
                  </div>
                  <button
                    type="button"
                    onClick={() => setAddingTrack(true)}
                    className={`px-4 py-3 rounded-lg text-sm font-medium border whitespace-nowrap transition-colors cursor-pointer shrink-0 ${isDark
                        ? "bg-[#1f1f1f] border-[#3e4042] text-[#e4e6eb] hover:bg-[#2a2a2f]"
                        : "bg-white border-gray-200 text-gray-700 hover:bg-gray-50 shadow-xs"
                      }`}
                  >
                    + New
                  </button>
                </div>
              ) : (
                <div className="flex flex-col gap-2 w-full">
                  <div className="flex gap-2 w-full">
                    <input
                      type="text"
                      value={newTrackName}
                      onChange={(e) => setNewTrackName(e.target.value)}
                      placeholder="e.g. Awaiting Submission"
                      className={`flex-1 px-4 py-2.5 rounded-lg text-sm border transition-colors ${isDark
                          ? "bg-[#1f1f1f] border-[#3e4042] text-[#e4e6eb] placeholder-[#6b6b6b]"
                          : "bg-white border-gray-200 text-gray-700 placeholder-gray-400"
                        }`}
                    />
                    <button
                      type="button"
                      disabled={trackSaving}
                      onClick={handleCreateTrack}
                      className={`px-4 py-2.5 rounded-lg text-sm font-semibold whitespace-nowrap transition-colors cursor-pointer disabled:opacity-50 ${isDark ? "bg-yellow-400 text-gray-900" : "bg-pup-dark-maroon text-white"
                        }`}
                    >
                      {trackSaving ? "Saving..." : "Save"}
                    </button>
                    <button
                      type="button"
                      onClick={() => {
                        setAddingTrack(false);
                        setNewTrackName("");
                        setTrackError("");
                      }}
                      className={`px-4 py-2.5 rounded-lg text-sm font-semibold whitespace-nowrap transition-colors cursor-pointer ${isDark ? "text-[#b0b3b8] hover:bg-[#2a2a2f]" : "text-gray-600 hover:bg-gray-100"
                        }`}
                    >
                      Cancel
                    </button>
                  </div>
                  {trackError && <span className="text-xs text-red-400">{trackError}</span>}
                </div>
              )}
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            {selectedType === "document" ? (
              <>
                <VoiceTextareaInput
                  id="document_description"
                  label="Document Description"
                  value={form.document_description}
                  onChange={handleVoiceTextareaChange("document_description")}
                  placeholder="Text"
                  minHeightClass="min-h-48"
                  required
                  labelColor={isDark ? "text-[#b0b3b8]" : "text-gray-600"}
                />
                <VoiceTextareaInput
                  id="document_requirements"
                  label="List of Requirements"
                  value={form.document_requirements}
                  onChange={handleVoiceTextareaChange("document_requirements")}
                  placeholder="Text"
                  minHeightClass="min-h-48"
                  required
                  labelColor={isDark ? "text-[#b0b3b8]" : "text-gray-600"}
                />
              </>
            ) : (
              <div className="col-span-2">
                <VoiceTextareaInput
                  id="document_requirements"
                  label="List of Requirements"
                  value={form.document_requirements}
                  onChange={handleVoiceTextareaChange("document_requirements")}
                  placeholder="Text"
                  minHeightClass="min-h-48"
                  required
                  labelColor={isDark ? "text-[#b0b3b8]" : "text-gray-600"}
                />
              </div>
            )}
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 items-start">
            <ProcessPeriodInput
              label="Process Period"
              name="document_process_period"
              value={form.document_process_period}
              onChange={handleChange}
              labelColor={isDark ? "text-[#b0b3b8]" : "text-gray-600"}
              required
            />

            <div className="flex flex-col gap-3 w-full">
              <span className={`text-sm font-medium ${isDark ? "text-[#b0b3b8]" : "text-gray-600"}`}>
                Exclusive For <span className="text-red-400">*</span>
              </span>
              <div className="flex gap-3">
                {EXCLUSIVE_FOR.map((option) => {
                  const isActive = form.access_id === option;
                  return (
                    <button
                      key={option}
                      type="button"
                      onClick={() => setForm((prev) => ({ ...prev, access_id: option }))}
                      className={`flex-1 px-4 py-2 rounded-lg text-sm font-semibold border transition-all duration-200 cursor-pointer flex items-center justify-center ${isActive
                          ? isDark
                            ? "bg-yellow-400 border-yellow-400 text-gray-900 shadow-md scale-[1.02]"
                            : "bg-pup-dark-maroon border-pup-dark-maroon text-white shadow-sm scale-[1.02]"
                          : isDark
                            ? "bg-[#1f1f1f] border-[#3e4042] text-[#e4e6eb] hover:bg-[#2a2a2f] hover:text-white"
                            : "bg-white border-gray-200 text-gray-700 hover:bg-gray-50"
                        }`}
                    >
                      {option}
                    </button>
                  );
                })}
              </div>
            </div>
          </div>



          {/* Divider line */}
          <div className={`h-px w-full my-1 ${isDark ? "bg-[#3e4042]" : "bg-gray-200"}`} />

          {/* requires_source_submission — the CTC / Authentication Fee case.
              Gates the request into RequestStatusEnum::AwaitingSubmission
              at creation instead of the usual Processing (see
              DocumentRequestService::createRequest() on the backend). */}
          <div className="flex items-center justify-between gap-3 w-full pt-1">
            <div className="flex flex-col">
              <span className={`text-sm font-medium ${isDark ? "text-[#e4e6eb]" : "text-gray-800"}`}>
                Requires source submission
              </span>
              <span className={`text-xs ${isDark ? "text-gray-400" : "text-gray-500"}`}>
                Client must hand over the physical source document before staff can start processing.
              </span>
            </div>
            <EnabledSwitch
              isDark={isDark}
              enabled={Boolean(form.requires_source_submission)}
              onToggle={() =>
                setForm((prev) => ({ ...prev, requires_source_submission: !prev.requires_source_submission }))
              }
            />
          </div>

          <div className="flex flex-col sm:flex-row items-stretch sm:items-center sm:justify-end gap-3 pt-2">
            {!isAdding && (
              <button
                type="button"
                onClick={handleCancel}
                className={`px-5 py-2 rounded-lg text-sm font-semibold transition-colors ${isDark ? "text-[#b0b3b8] hover:bg-[#2a2a2f]" : "text-gray-600 hover:bg-gray-100"
                  }`}
              >
                Cancel
              </button>
            )}
            <button
              type="submit"
              className={`px-6 py-2.5 rounded-full text-sm font-bold transition-all shadow cursor-pointer ${isDark
                  ? "bg-[#2a2a2f] text-[#e4e6eb] hover:bg-[#353539] border border-[#3e4042]"
                  : "bg-pup-dark-maroon text-white hover:bg-[#3a0303]"
                }`}
            >
              {isAdding ? (selectedType === "document" ? "Add Document" : "Add Certificate") : "Save Changes"}
            </button>
            <SuccessToast message={successMsg} onClose={() => setSuccessMsg("")} />
            <ErrorToast message={errorMsg} onClose={() => setErrorMsg("")} />
          </div>
        </form>
      </div>
      <DeleteConfirmModal
        open={deleteModal.isOpen}
        count={1}
        loading={deleteLoading}
        onCancel={() => setDeleteModal({ isOpen: false, docId: null, type: "document" })}
        onConfirm={() => {
          if (deleteModal.docId) handleDelete(deleteModal.docId);
        }}
      />
    </div>
  );
};

export default DocumentManagement;