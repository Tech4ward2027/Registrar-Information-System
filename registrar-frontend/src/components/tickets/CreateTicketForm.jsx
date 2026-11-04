import React, { useState, useEffect, useMemo } from 'react';
import PropTypes from 'prop-types';
import {
  XMarkIcon,
  ShieldCheckIcon,
  ExclamationTriangleIcon,
  ArrowPathIcon,
} from '@heroicons/react/24/outline';
import { getTicketCategories, createTicket } from '../../services/api';
import { useTheme } from '../../context/ThemeContext';
import { useToast } from '../../context/NotificationToastContext';
import {
  formatPriorityLabel,
  getPriorityBadgeClass,
  parseTicketApiError,
} from '../../utils/ticketHelpers';
import { useRetryAfterCooldown } from '../../hooks/useTicketLiveUpdates';
import InputGroup from '../InputGroup.jsx';
import DropdownGroup from '../DropDown.jsx';
import VoiceTextareaInput from '../VoiceTextareaInput.jsx';

const SUBJECT_MAX = 150;
const DESCRIPTION_MIN = 20;
const DESCRIPTION_MAX = 5000;

const CreateTicketForm = ({ onCancel, onSuccess }) => {
  const { isDark } = useTheme();
  const { addToast } = useToast();
  const cooldown = useRetryAfterCooldown();

  const [categories, setCategories] = useState([]);
  const [loadingCategories, setLoadingCategories] = useState(true);

  const [subject, setSubject] = useState('');
  const [categoryCode, setCategoryCode] = useState('');
  const [description, setDescription] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [fieldErrors, setFieldErrors] = useState({});

  const cardClasses = isDark ? 'bg-[#18191a] text-[#e4e6eb]' : 'bg-white text-gray-900';
  const rowBorder = isDark ? 'border-[#3e4042]' : 'border-gray-200';
  const subtleText = isDark ? 'text-[#b0b3b8]' : 'text-gray-500';

  useEffect(() => {
    let active = true;
    const loadCategories = async () => {
      setLoadingCategories(true);
      try {
        const res = await getTicketCategories();
        const rows = Array.isArray(res.data?.data) ? res.data.data : [];
        if (!active) return;
        setCategories(rows);
        if (rows.length > 0) {
          setCategoryCode((prev) => prev || rows[0].code);
        }
      } catch (err) {
        if (!active) return;
        const parsed = await parseTicketApiError(err, 'Unable to load ticket categories.');
        setFieldErrors({ general: parsed.message });
      } finally {
        if (active) setLoadingCategories(false);
      }
    };
    loadCategories();
    return () => {
      active = false;
    };
  }, []);

  const categoryEntries = useMemo(
    () =>
      categories.map((cat) => ({
        code: cat.code,
        label: `${cat.code} — ${cat.name}`,
        priority: cat.priority,
        description: cat.description,
      })),
    [categories]
  );

  const selectedCategory = useMemo(
    () => categoryEntries.find((c) => c.code === categoryCode) || null,
    [categoryEntries, categoryCode]
  );

  const validate = () => {
    const errs = {};
    const trimmedSubject = subject.trim();
    const trimmedDesc = description.trim();

    if (!trimmedSubject) {
      errs.subject = 'Subject is required.';
    } else if (trimmedSubject.length > SUBJECT_MAX) {
      errs.subject = `Subject cannot exceed ${SUBJECT_MAX} characters.`;
    }

    if (!categoryCode) {
      errs.category_code = 'Please select a category.';
    }

    if (!trimmedDesc || trimmedDesc.length < DESCRIPTION_MIN) {
      errs.description = `Please provide a detailed description (at least ${DESCRIPTION_MIN} characters).`;
    } else if (trimmedDesc.length > DESCRIPTION_MAX) {
      errs.description = `Description cannot exceed ${DESCRIPTION_MAX} characters.`;
    }

    setFieldErrors(errs);
    return Object.keys(errs).length === 0;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (isSubmitting || cooldown.isCoolingDown) return;
    if (!validate()) return;

    setIsSubmitting(true);
    setFieldErrors({});
    try {
      const res = await createTicket({
        category_code: categoryCode,
        subject: subject.trim(),
        description: description.trim(),
      });
      const created = res.data?.data;

      addToast?.({
        type: 'success',
        title: 'Ticket Created Successfully',
        message: created?.ticket_number
          ? `Your ticket ${created.ticket_number} has been logged in the Registrar support queue.`
          : 'Your support ticket has been submitted.',
      });

      onSuccess?.(created);
    } catch (err) {
      const parsed = await parseTicketApiError(
        err,
        'An unexpected error occurred while creating your ticket.'
      );
      if (parsed.status === 429) {
        cooldown.startCooldown(parsed.retryAfterSeconds);
      }

      const nextErrors = { general: parsed.message };
      if (parsed.fieldErrors?.subject?.[0]) nextErrors.subject = parsed.fieldErrors.subject[0];
      if (parsed.fieldErrors?.category_code?.[0]) {
        nextErrors.category_code = parsed.fieldErrors.category_code[0];
      }
      if (parsed.fieldErrors?.description?.[0]) {
        nextErrors.description = parsed.fieldErrors.description[0];
      }
      setFieldErrors(nextErrors);
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
              Submit your concern or issue. We&apos;ll get back to you during operating hours (Tue–Fri, 8:00 AM–8:00 PM).
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

        {loadingCategories ? (
          <div className="py-12 text-center space-y-2">
            <ArrowPathIcon
              className={`w-7 h-7 mx-auto animate-spin ${
                isDark ? 'text-yellow-400' : 'text-pup-dark-maroon'
              }`}
            />
            <p className={`text-xs ${subtleText}`}>Loading ticket categories…</p>
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-4" noValidate>
            {/* Subject Field using reusable InputGroup */}
            <div>
              <InputGroup
                label="Subject"
                name="subject"
                value={subject}
                maxLength={SUBJECT_MAX}
                onChange={(e) => {
                  setSubject(e.target.value);
                  setFieldErrors((prev) => ({ ...prev, subject: null, general: null }));
                }}
                placeholder="e.g. Official Receipt verification concern"
                required
                error={fieldErrors.subject}
                labelColor="text-[11px] sm:text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-300"
              />
            </div>

            {/* Category & Backend-Assigned Priority Row */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 items-start">
              <div>
                <DropdownGroup
                  label="Category"
                  name="category"
                  value={selectedCategory?.label || ''}
                  onChange={(e) => {
                    const matched = categoryEntries.find((c) => c.label === e.target.value);
                    if (matched) {
                      setCategoryCode(matched.code);
                      setFieldErrors((prev) => ({ ...prev, category_code: null, general: null }));
                    }
                  }}
                  options={categoryEntries.map((c) => c.label)}
                  required
                  error={fieldErrors.category_code}
                  labelColor="text-[11px] sm:text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-300"
                />
                {fieldErrors.category_code && (
                  <p className="text-xs text-red-500 mt-1">{fieldErrors.category_code}</p>
                )}
                {selectedCategory?.description && (
                  <p className={`text-[11px] mt-1.5 ${subtleText}`}>{selectedCategory.description}</p>
                )}
              </div>

              <div>
                <span className="block text-[11px] sm:text-xs font-semibold uppercase tracking-wide mb-1.5 text-gray-700 dark:text-[#e4e6eb]">
                  Assigned Priority
                </span>
                <div
                  className={`w-full flex items-center justify-between gap-2 px-3.5 py-2.5 rounded-lg border text-sm ${
                    isDark
                      ? 'bg-[#1f1f1f] border-[#3e4042] text-[#e4e6eb]'
                      : 'bg-gray-50 border-gray-200 text-gray-700'
                  }`}
                >
                  <span className={`text-xs ${subtleText}`}>Determined by category</span>
                  {selectedCategory?.priority ? (
                    <span
                      className={`px-2.5 py-0.5 rounded-full text-[11px] font-bold border ${getPriorityBadgeClass(
                        selectedCategory.priority,
                        isDark
                      )}`}
                    >
                      {formatPriorityLabel(selectedCategory.priority)}
                    </span>
                  ) : (
                    <span className={`text-xs ${subtleText}`}>—</span>
                  )}
                </div>
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
                maxLength={DESCRIPTION_MAX}
                onChange={(val) => {
                  setDescription(val);
                  setFieldErrors((prev) => ({ ...prev, description: null, general: null }));
                }}
                placeholder="Provide as much detail as possible (reference numbers, document type, dates, or error message)..."
                rows={5}
                required
                helperText={`Minimum ${DESCRIPTION_MIN} characters, up to 2 links allowed.`}
                error={fieldErrors.description}
              />
            </div>

            {/* Privacy Reminder */}
            <div
              className={`p-3.5 rounded-xl border flex items-start gap-2.5 text-xs leading-relaxed ${
                isDark
                  ? 'bg-[#1f1f1f] border-[#3e4042] text-[#b0b3b8]'
                  : 'bg-amber-50/70 border-amber-200 text-amber-900'
              }`}
            >
              <ShieldCheckIcon className="w-4 h-4 sm:w-5 sm:h-5 text-amber-500 shrink-0 mt-0.5" />
              <span>
                <strong>Data Privacy Notice (RA 10173):</strong> Share relevant request details or receipt numbers only.
                Never include passwords, OTP codes, or unnecessary confidential information.
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
                disabled={isSubmitting || cooldown.isCoolingDown || categories.length === 0}
                className={`px-6 py-2.5 rounded-full text-xs font-bold transition-all shadow cursor-pointer uppercase tracking-wider ${
                  isDark
                    ? 'bg-yellow-400 text-gray-900 hover:bg-yellow-300'
                    : 'bg-pup-dark-maroon text-white hover:bg-[#3a0303]'
                } disabled:opacity-50`}
              >
                {cooldown.isCoolingDown
                  ? `Wait ${cooldown.secondsLeft}s`
                  : isSubmitting
                    ? 'Submitting...'
                    : 'Submit Ticket'}
              </button>
            </div>
          </form>
        )}
      </div>
    </div>
  );
};

CreateTicketForm.propTypes = {
  onCancel: PropTypes.func,
  onSuccess: PropTypes.func,
};

export default CreateTicketForm;
