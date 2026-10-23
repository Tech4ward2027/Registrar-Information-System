import React, { useState } from 'react';
import PropTypes from 'prop-types';
import {
  XMarkIcon,
  ArrowUpTrayIcon,
  ShieldCheckIcon,
  ExclamationTriangleIcon,
} from '@heroicons/react/24/outline';
import { TICKET_CATEGORIES, TICKET_PRIORITIES } from '../../constants/ticketConstants';
import { useTickets } from '../../context/TicketContext';
import { useTheme } from '../../context/ThemeContext';
import { useToast } from '../../context/NotificationToastContext';
import InputGroup from '../InputGroup.jsx';
import DropdownGroup from '../DropDown.jsx';
import VoiceTextareaInput from '../VoiceTextareaInput.jsx';

const CreateTicketForm = ({ onCancel, onSuccess }) => {
  const { isDark } = useTheme();
  const { createTicket } = useTickets();
  const { addToast } = useToast();

  const [subject, setSubject] = useState('');
  const [category, setCategory] = useState('');
  const [priority, setPriority] = useState('Normal');
  const [description, setDescription] = useState('');
  const [files, setFiles] = useState([]);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [dragActive, setDragActive] = useState(false);
  const [fieldErrors, setFieldErrors] = useState({});

  const cardClasses = isDark ? 'bg-[#18191a] text-[#e4e6eb]' : 'bg-white text-gray-900';
  const rowBorder = isDark ? 'border-[#3e4042]' : 'border-gray-200';
  const subtleText = isDark ? 'text-[#b0b3b8]' : 'text-gray-500';

  const categoryOptions = TICKET_CATEGORIES.map((c) => ({
    value: c.value,
    label: c.label,
  }));

  const priorityOptions = TICKET_PRIORITIES.map((p) => ({
    value: p.value,
    label: p.label,
  }));

  const handleFiles = (incomingFiles) => {
    const validFiles = Array.from(incomingFiles);
    const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
    const maxSizeBytes = 10 * 1024 * 1024; // 10MB

    for (let f of validFiles) {
      if (!allowedTypes.includes(f.type) && !f.name.match(/\.(pdf|jpe?g|png)$/i)) {
        setFieldErrors((prev) => ({
          ...prev,
          attachments: `Invalid file format: ${f.name}. Only PDF, JPG, and PNG are allowed.`,
        }));
        return;
      }
      if (f.size > maxSizeBytes) {
        setFieldErrors((prev) => ({
          ...prev,
          attachments: `File too large: ${f.name} exceeds the 10MB limit.`,
        }));
        return;
      }
    }

    if (files.length + validFiles.length > 5) {
      setFieldErrors((prev) => ({
        ...prev,
        attachments: 'You can upload a maximum of 5 files per ticket.',
      }));
      return;
    }

    setFieldErrors((prev) => ({ ...prev, attachments: null }));
    setFiles((prev) => [...prev, ...validFiles]);
  };

  const handleDrop = (e) => {
    e.preventDefault();
    e.stopPropagation();
    setDragActive(false);
    if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
      handleFiles(e.dataTransfer.files);
    }
  };

  const handleDrag = (e) => {
    e.preventDefault();
    e.stopPropagation();
    if (e.type === 'dragenter' || e.type === 'dragover') {
      setDragActive(true);
    } else if (e.type === 'dragleave') {
      setDragActive(false);
    }
  };

  const handleRemoveFile = (index) => {
    setFiles((prev) => prev.filter((_, i) => i !== index));
  };

  const validate = () => {
    const errs = {};
    if (!subject.trim()) {
      errs.subject = 'Subject is required.';
    }
    if (!category) {
      errs.category = 'Please select a category.';
    }
    if (!description.trim() || description.trim().length < 10) {
      errs.description = 'Please provide a detailed description (at least 10 characters).';
    }
    setFieldErrors(errs);
    return Object.keys(errs).length === 0;
  };

  const handleSubmit = (e) => {
    e.preventDefault();
    if (!validate()) return;

    setIsSubmitting(true);
    try {
      const created = createTicket({
        subject,
        category,
        priority,
        description,
        attachments: files,
      });

      addToast?.({
        type: 'success',
        title: 'Ticket Created Successfully',
        message: `Your ticket ${created.ticket_number} has been logged in the shared queue.`,
      });

      onSuccess?.(created);
    } catch (err) {
      console.error(err);
      setFieldErrors((prev) => ({
        ...prev,
        general: 'An unexpected error occurred while creating your ticket.',
      }));
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className={`rounded-xl border ${rowBorder} overflow-hidden shadow-sm ${cardClasses}`}>
      {/* Top Header matching Cashier OR Overrides */}
      <div
        className={`px-4 py-4 sm:px-6 sm:py-5 border-b-4 shrink-0 ${
          isDark ? 'bg-[#1f1f1f] border-[#b98b00]' : 'bg-[#800000] border-[#FFD700]'
        }`}
      >
        <div className="flex items-center justify-between gap-2 sm:gap-4">
          <div className="min-w-0 flex-1">
            <span className="text-[10px] sm:text-xs text-amber-200 font-bold uppercase tracking-wider block">
              Registrar Support Desk
            </span>
            <h3 className="text-lg sm:text-2xl text-white font-black uppercase tracking-tight mt-0.5 truncate">
              Create a Ticket
            </h3>
            <p className="text-[11px] sm:text-xs text-white/90 mt-0.5 truncate font-normal">
              Submit your concern or issue. We'll get back to you as soon as possible.
            </p>
          </div>
          {onCancel && (
            <button
              onClick={onCancel}
              className="p-1 rounded hover:opacity-90 shrink-0 text-white cursor-pointer"
              title="Close"
              type="button"
            >
              <XMarkIcon className="w-5 h-5 sm:w-6 sm:h-6" />
            </button>
          )}
        </div>
      </div>

      <div className="p-4 sm:p-6 space-y-4 sm:space-y-5">
        {fieldErrors.general && (
          <div
            className={`p-3 sm:p-3.5 rounded-xl border flex items-start gap-2.5 sm:gap-3 text-xs leading-relaxed ${
              isDark ? 'bg-red-950/30 border-red-800/40 text-red-300' : 'bg-red-50 border-red-200 text-red-700'
            }`}
          >
            <ExclamationTriangleIcon className="w-4 h-4 sm:w-5 sm:h-5 text-red-400 shrink-0 mt-0.5" />
            <span>{fieldErrors.general}</span>
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4">
          {/* Subject Field using reusable InputGroup */}
          <div>
            <InputGroup
              label="Subject"
              name="subject"
              value={subject}
              onChange={(e) => {
                setSubject(e.target.value);
                setFieldErrors((prev) => ({ ...prev, subject: null }));
              }}
              placeholder="e.g. Cannot access document"
              required
              labelColor="text-[11px] sm:text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-300"
            />
            {fieldErrors.subject && <p className="text-xs text-red-500 mt-1">{fieldErrors.subject}</p>}
          </div>

          {/* Category & Priority Row using reusable DropdownGroup */}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 items-start">
            <div>
              <DropdownGroup
                label="Category"
                name="category"
                value={category}
                onChange={(e) => {
                  setCategory(e.target.value);
                  setFieldErrors((prev) => ({ ...prev, category: null }));
                }}
                options={categoryOptions}
                required
                labelColor="text-[11px] sm:text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-300"
              />
              {fieldErrors.category && <p className="text-xs text-red-500 mt-1">{fieldErrors.category}</p>}
            </div>

            <div>
              <DropdownGroup
                label="Priority"
                name="priority"
                value={priority}
                onChange={(e) => setPriority(e.target.value)}
                options={priorityOptions}
                labelColor="text-[11px] sm:text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-300"
              />
            </div>
          </div>

          {/* Description using reusable VoiceTextareaInput */}
          <div>
            <VoiceTextareaInput
              id="ticket-description"
              name="description"
              label="Description"
              labelColor="text-[11px] sm:text-xs font-semibold uppercase tracking-wide mb-1 block"
              value={description}
              onChange={(val) => {
                setDescription(val);
                setFieldErrors((prev) => ({ ...prev, description: null }));
              }}
              placeholder="Provide as much detail as possible..."
              rows={4}
              required
              error={fieldErrors.description}
            />
          </div>

          {/* Attachments Dropzone */}
          <div>
            <div className="flex items-center justify-between mb-1">
              <label className="text-[11px] sm:text-xs font-semibold uppercase tracking-wide block">
                Attachments <span className={`font-normal lowercase ${subtleText}`}>(optional)</span>
              </label>
              <span className={`text-[10px] sm:text-xs ${subtleText}`}>Max 5 files, 10MB each (PDF, JPG, PNG)</span>
            </div>

            <div
              onDragEnter={handleDrag}
              onDragLeave={handleDrag}
              onDragOver={handleDrag}
              onDrop={handleDrop}
              className={`border-2 border-dashed rounded-xl p-5 sm:p-6 text-center transition flex flex-col items-center justify-center relative cursor-pointer ${
                dragActive
                  ? isDark
                    ? 'border-yellow-400 bg-yellow-400/10'
                    : 'border-[#800000] bg-[#800000]/5'
                  : isDark
                  ? 'border-[#3e4042] bg-[#1a1b1e]/40 hover:bg-[#1a1b1e] hover:border-yellow-400/50'
                  : 'border-gray-200 bg-gray-50/50 hover:bg-gray-50 hover:border-[#800000]/40'
              }`}
            >
              <input
                type="file"
                id="ticket-file-input"
                multiple
                accept=".pdf,.jpg,.jpeg,.png"
                onChange={(e) => handleFiles(e.target.files)}
                className="hidden"
              />

              <ArrowUpTrayIcon className={`w-8 h-8 mb-2 ${isDark ? 'text-gray-400' : 'text-gray-500'}`} />

              <p className="text-xs font-semibold mb-1">
                <label
                  htmlFor="ticket-file-input"
                  className={`underline cursor-pointer hover:opacity-80 ${
                    isDark ? 'text-yellow-400' : 'text-pup-maroon font-bold'
                  }`}
                >
                  Click to upload
                </label>{' '}
                or drag and drop files here
              </p>
              <p className={`text-[11px] ${subtleText}`}>PNG, JPG, PDF</p>
            </div>

            {fieldErrors.attachments && <p className="text-xs text-red-500 mt-1">{fieldErrors.attachments}</p>}

            {/* Uploaded Files Preview */}
            {files.length > 0 && (
              <div className="mt-3 space-y-2">
                {files.map((file, idx) => (
                  <div
                    key={idx}
                    className={`flex items-center justify-between p-2.5 rounded-xl border text-xs font-medium ${
                      isDark ? 'bg-[#1a1b1e]/60 border-[#3e4042]' : 'bg-gray-50 border-gray-200'
                    }`}
                  >
                    <div className="flex items-center gap-2 truncate">
                      <span>📎</span>
                      <span className="truncate">{file.name}</span>
                      <span className={`text-[10px] ${subtleText}`}>({(file.size / 1024).toFixed(0)} KB)</span>
                    </div>
                    <button
                      type="button"
                      onClick={() => handleRemoveFile(idx)}
                      className="p-1 hover:text-red-500 rounded-lg transition cursor-pointer"
                      aria-label="Remove file"
                    >
                      <XMarkIcon className="w-4 h-4" />
                    </button>
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* Privacy Reminder */}
          <div
            className={`p-3.5 rounded-xl border flex items-start gap-2.5 text-xs leading-relaxed ${
              isDark ? 'bg-[#1f1f1f] border-[#3e4042] text-[#b0b3b8]' : 'bg-amber-50/70 border-amber-200 text-amber-900'
            }`}
          >
            <ShieldCheckIcon className="w-4 h-4 sm:w-5 sm:h-5 text-amber-500 shrink-0 mt-0.5" />
            <span>
              <strong>Data Privacy Notice (RA 10173):</strong> Upload relevant documents only. Never upload passwords,
              credentials, or unnecessary confidential information.
            </span>
          </div>

          {/* Footer matching Cashier OR Overrides modals */}
          <div
            className={`pt-4 border-t-2 flex items-center justify-end gap-3 ${
              isDark ? 'border-[#3e4042]' : 'border-gray-200'
            }`}
          >
            {onCancel && (
              <button
                type="button"
                onClick={onCancel}
                className={`px-4 py-2 sm:px-5 sm:py-2.5 rounded-full text-xs font-bold transition-all cursor-pointer border ${
                  isDark
                    ? 'border-[#3e4042] hover:bg-[#3a3b3c] text-[#e4e6eb]'
                    : 'border-gray-300 hover:bg-gray-100 text-gray-700'
                }`}
              >
                Cancel
              </button>
            )}

            <button
              type="submit"
              disabled={isSubmitting}
              className={`px-6 py-2.5 rounded-full text-xs font-bold transition-all shadow cursor-pointer uppercase tracking-wider ${
                isDark
                  ? 'bg-yellow-400 text-gray-900 hover:bg-yellow-300'
                  : 'bg-pup-dark-maroon text-white hover:bg-[#3a0303]'
              } disabled:opacity-50`}
            >
              {isSubmitting ? 'Submitting...' : 'Submit Ticket'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};

CreateTicketForm.propTypes = {
  onCancel: PropTypes.func,
  onSuccess: PropTypes.func,
};

export default CreateTicketForm;
