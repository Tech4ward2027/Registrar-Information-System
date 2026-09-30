import React, { useState, useEffect, useRef, useCallback } from 'react';
import { createPortal } from 'react-dom';
import jsQR from 'jsqr';
import { useQueryClient } from '@tanstack/react-query';
import {
  XCircleIcon,
  CheckCircleIcon,
  ExclamationTriangleIcon,
} from '@heroicons/react/24/outline';
import { lookupItemClaim, confirmItemClaim } from '../services/api';
import { useTheme } from '../context/ThemeContext';
import { formatName } from '../utils/formatters';

/**
 * ClaimScannerModal — Phase 3 Per-Document Claiming implementation.
 *
 * Implements Per-Document Claiming (lookup -> checklist review -> confirm):
 *   1. Lookup: Reads code (QR or manual claim_code) via lookupItemClaim().
 *   2. Review Checklist: Displays all items under the request/ticket.
 *      Ready items are checked by default; already-claimed or processing items
 *      are visible but disabled with their status/reason.
 *   3. Confirm: Staff confirms selected ready items via confirmItemClaim().
 */

const CLAIM_CODE_LENGTH = 6;
const SCAN_INTERVAL_MS = 200; // ~5 scans/sec — plenty for a static QR, kind to CPU

const ClaimScannerModal = ({ open, onClose }) => {
  const { isDark } = useTheme();
  const queryClient = useQueryClient();

  // 'scanning' | 'looking_up' | 'review' | 'confirming' | 'success'
  const [phase, setPhase] = useState('scanning');
  const [mode, setMode] = useState('scan'); // 'scan' | 'manual'
  const [currentCredential, setCurrentCredential] = useState(null);
  const [lookupData, setLookupData] = useState(null);
  const [selectedItemUuids, setSelectedItemUuids] = useState([]);
  const [claimSummary, setClaimSummary] = useState(null);
  const [errorMessage, setErrorMessage] = useState('');
  const [manualCode, setManualCode] = useState(Array(CLAIM_CODE_LENGTH).fill(''));
  const [cameraError, setCameraError] = useState('');

  const videoRef = useRef(null);
  const canvasRef = useRef(null);
  const streamRef = useRef(null);
  const scanTimerRef = useRef(null);
  const submittingRef = useRef(false);
  const lastScannedRef = useRef(null);
  const inputRefs = useRef([]);
  const manualCodeString = manualCode.join('').trim().toUpperCase();

  const stopCamera = useCallback(() => {
    if (scanTimerRef.current) {
      clearInterval(scanTimerRef.current);
      scanTimerRef.current = null;
    }
    if (streamRef.current) {
      streamRef.current.getTracks().forEach((track) => track.stop());
      streamRef.current = null;
    }
  }, []);

  const resetToScanning = useCallback(() => {
    lastScannedRef.current = null;
    setCurrentCredential(null);
    setLookupData(null);
    setSelectedItemUuids([]);
    setClaimSummary(null);
    setErrorMessage('');
    setManualCode(Array(CLAIM_CODE_LENGTH).fill(''));
    setCameraError('');
    setPhase('scanning');
    setMode('scan');
  }, []);

  const submitCredential = useCallback(async (credential) => {
    if (submittingRef.current) return;

    const credKey = credential.uuid || credential.claim_code;
    if (credKey && lastScannedRef.current === credKey) return;
    if (credKey) lastScannedRef.current = credKey;

    submittingRef.current = true;
    stopCamera();
    setPhase('looking_up');
    setErrorMessage('');

    try {
      const res = await lookupItemClaim(credential);
      const data = res.data ?? res;
      setLookupData(data);
      setCurrentCredential(credential);

      // Default checklist: pre-select all claimable / ready items
      const claimable = (data.items || [])
        .filter((item) => item.claimable !== false)
        .map((item) => item.uuid);
      setSelectedItemUuids(claimable);

      setPhase('review');
    } catch (err) {
      const status = err?.response?.status;
      const serverMsg = err?.response?.data?.message;
      let finalMsg = serverMsg || 'Failed to look up claim code. Please try again.';

      if (!serverMsg) {
        if (status === 404) {
          finalMsg = 'No matching request or item found for that code. Please double-check.';
        } else if (status === 422) {
          finalMsg = 'This request cannot be claimed at this time.';
        } else if (status === 403) {
          finalMsg = 'Access denied. You do not have permission to claim requests.';
        }
      }

      setErrorMessage(finalMsg);
      setPhase('scanning');
    } finally {
      submittingRef.current = false;
    }
  }, [stopCamera]);

  const handleConfirmClaim = async () => {
    if (!currentCredential || selectedItemUuids.length === 0 || phase === 'confirming') return;

    setPhase('confirming');
    setErrorMessage('');

    try {
      const res = await confirmItemClaim(currentCredential, selectedItemUuids);
      const data = res.data ?? res;
      setClaimSummary(data);
      setPhase('success');

      queryClient.invalidateQueries({ queryKey: ['documentRequests'] });
      queryClient.invalidateQueries({ queryKey: ['documentRequestsCounts'] });
    } catch (err) {
      const serverMsg = err?.response?.data?.message || 'Failed to complete claim confirmation.';
      setErrorMessage(serverMsg);
      setPhase('review');
    }
  };

  const toggleItemCheck = (uuid) => {
    setSelectedItemUuids((prev) =>
      prev.includes(uuid) ? prev.filter((id) => id !== uuid) : [...prev, uuid]
    );
  };

  // Scan loop: runs only while phase === 'scanning' & mode === 'scan'
  useEffect(() => {
    if (!open || phase !== 'scanning' || mode !== 'scan') return;

    let cancelled = false;

    const startCamera = async () => {
      try {
        const stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: 'environment' },
        });
        if (cancelled) {
          stream.getTracks().forEach((t) => t.stop());
          return;
        }
        streamRef.current = stream;
        if (videoRef.current) {
          videoRef.current.srcObject = stream;
          await videoRef.current.play();
        }

        const canvas = canvasRef.current;
        const ctx = canvas.getContext('2d', { willReadFrequently: true });

        scanTimerRef.current = setInterval(() => {
          const video = videoRef.current;
          if (!video || video.readyState !== video.HAVE_ENOUGH_DATA) return;

          canvas.width = video.videoWidth;
          canvas.height = video.videoHeight;
          ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
          const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
          const code = jsQR(imageData.data, imageData.width, imageData.height);

          if (code?.data) {
            const isUuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(code.data);
            if (!isUuid) {
              setErrorMessage("Invalid QR code format. Please scan a valid ticket QR code.");
            } else {
              submitCredential({ uuid: code.data });
            }
          }
        }, SCAN_INTERVAL_MS);
      } catch (err) {
        if (cancelled) return;
        // Most common causes: permission denied, no camera present, or
        // the site isn't served over HTTPS (getUserMedia requires a
        // secure context). All three leave the manual claim_code field
        // as the only path forward — which is exactly its job.
        const msg = err?.name === 'NotAllowedError'
          ? 'Camera access was denied. Allow camera access, or use the code field below instead.'
          : 'Camera unavailable. Use the code field below instead.';
        setCameraError(msg);
      }
    };

    startCamera();

    return () => {
      cancelled = true;
      stopCamera();
    };
  }, [open, phase, mode, submitCredential, stopCamera]);

  // Belt-and-suspenders: stop the camera on unmount regardless of phase,
  // in case the component unmounts mid-scan (e.g. navigating away).
  useEffect(() => stopCamera, [stopCamera]);

  const handleInputChange = (index, value) => {
    const cleanValue = value.replace(/[^a-zA-Z0-9]/g, '').toUpperCase();
    if (!cleanValue) {
      const newCode = [...manualCode];
      newCode[index] = '';
      setManualCode(newCode);
      return;
    }

    const char = cleanValue[cleanValue.length - 1];
    const newCode = [...manualCode];
    newCode[index] = char;
    setManualCode(newCode);

    if (index < CLAIM_CODE_LENGTH - 1) {
      inputRefs.current[index + 1]?.focus();
    }
  };

  const handleKeyDown = (index, e) => {
    if (e.key === 'Backspace') {
      if (!manualCode[index] && index > 0) {
        const newCode = [...manualCode];
        newCode[index - 1] = '';
        setManualCode(newCode);
        inputRefs.current[index - 1]?.focus();
      } else {
        const newCode = [...manualCode];
        newCode[index] = '';
        setManualCode(newCode);
      }
      e.preventDefault();
    } else if (e.key === 'ArrowLeft' && index > 0) {
      inputRefs.current[index - 1]?.focus();
      e.preventDefault();
    } else if (e.key === 'ArrowRight' && index < CLAIM_CODE_LENGTH - 1) {
      inputRefs.current[index + 1]?.focus();
      e.preventDefault();
    }
  };

  const handlePaste = (e) => {
    e.preventDefault();
    const pastedData = e.clipboardData.getData('text').trim().toUpperCase().replace(/[^a-zA-Z0-9]/g, '');
    if (pastedData.length > 0) {
      const newCode = [...manualCode];
      for (let i = 0; i < CLAIM_CODE_LENGTH; i++) {
        newCode[i] = pastedData[i] || '';
      }
      setManualCode(newCode);
      const focusIndex = Math.min(pastedData.length, CLAIM_CODE_LENGTH - 1);
      inputRefs.current[focusIndex]?.focus();
    }
  };

  const handleFocus = (index) => {
    const firstEmptyIndex = inputRefs.current.findIndex((ref) => ref && ref.value === '');
    if (firstEmptyIndex !== -1 && index > firstEmptyIndex) {
      inputRefs.current[firstEmptyIndex]?.focus();
    } else {
      inputRefs.current[index]?.select();
    }
  };

  const handleManualSubmit = (e) => {
    e.preventDefault();
    if (manualCodeString.length !== CLAIM_CODE_LENGTH || phase === 'looking_up' || phase === 'confirming') return;
    submitCredential({ claim_code: manualCodeString });
  };

  const handleClose = () => {
    stopCamera();
    resetToScanning();
    onClose();
  };

  if (!open) return null;

  const reqObj = lookupData?.request || claimSummary?.request;
  const ownerName = reqObj ? formatName(reqObj) || reqObj.display_name || 'Unknown requester' : '';

  return createPortal(
    <div className="fixed inset-0 z-99999 flex items-center justify-center p-4">
      <div
        className={`absolute inset-0 backdrop-blur-sm ${isDark ? 'bg-black/70' : 'bg-black/50'}`}
        onClick={handleClose}
      />
      <div className={`relative rounded-2xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col ${isDark ? 'bg-[#242526] border border-[#3e4042]' : 'bg-white'}`}>

        {/* Header */}
        <div className={`relative px-5 py-4 flex justify-between items-center shrink-0 ${isDark ? 'bg-[#3a3b3c]' : 'bg-pup-maroon'}`}>
          <div className="flex items-center gap-2">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" className="w-5 h-5 text-white">
              <path d="M4 8V6a2 2 0 0 1 2-2h2M16 4h2a2 2 0 0 1 2 2v2M4 16v2a2 2 0 0 0 2 2h2M16 20h2a2 2 0 0 0 2-2v-2M4 12h16" />
            </svg>
            <h3 className="text-base font-bold text-white">
              {phase === 'review' ? 'Review Claim Checklist' : phase === 'success' ? 'Claim Completed' : 'Scan QR code'}
            </h3>
          </div>
          <button
            type="button"
            onClick={handleClose}
            aria-label="Close scanner"
            className="text-white hover:text-yellow-200 transition cursor-pointer"
          >
            <XCircleIcon className="w-7 h-7" />
          </button>
        </div>

        <div className="p-5 space-y-4 max-h-[80vh] overflow-y-auto">
          <style>{`
            @keyframes scanLine {
              0%, 100% { top: 6%; }
              50% { top: 94%; }
            }
            .animate-scan-line {
              animation: scanLine 5s cubic-bezier(0.4, 0, 0.2, 1) infinite;
            }
          `}</style>

          {/* Alert Error Banner */}
          {errorMessage && (
            <div className={`p-3 rounded-xl flex items-start gap-2.5 text-xs font-semibold border ${
              isDark
                ? 'bg-red-950/40 border-red-800/50 text-red-300'
                : 'bg-red-50 border-red-200 text-red-700'
            }`}>
              <ExclamationTriangleIcon className="w-5 h-5 shrink-0 text-red-500 mt-0.5" />
              <div className="flex-1 leading-snug">{errorMessage}</div>
              <button
                type="button"
                onClick={() => setErrorMessage('')}
                className="text-gray-400 hover:text-gray-600 font-bold ml-1 cursor-pointer text-base leading-none"
              >
                ×
              </button>
            </div>
          )}

          {/* ---------------- PHASE: SUCCESS ---------------- */}
          {phase === 'success' ? (
            <div className="flex flex-col items-center gap-3 py-4 text-center">
              <CheckCircleIcon className="w-14 h-14 text-green-500" />
              <p className={`text-lg font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>Claim Processed</p>
              <p className={`text-sm ${isDark ? 'text-[#b0b3b8]' : 'text-gray-600'}`}>
                Request #{reqObj?.request_id} — {ownerName}
              </p>
              <div className={`w-full text-xs p-3 rounded-xl text-left space-y-1 ${isDark ? 'bg-[#18191a] text-gray-300' : 'bg-gray-50 text-gray-700'}`}>
                <p className="font-semibold text-green-600 dark:text-green-400">
                  Completed items ({claimSummary?.completed?.length ?? 0}):
                </p>
                <ul className="list-disc list-inside space-y-0.5">
                  {(claimSummary?.completed || []).map((item) => (
                    <li key={item.id}>{item.name || item.item_name} (x{item.number_of_copies || 1})</li>
                  ))}
                </ul>
                {(claimSummary?.skipped || []).length > 0 && (
                  <>
                    <p className="font-semibold text-amber-600 dark:text-amber-400 mt-2">
                      Skipped / Not Ready ({claimSummary.skipped.length}):
                    </p>
                    <ul className="list-disc list-inside space-y-0.5">
                      {claimSummary.skipped.map((item) => (
                        <li key={item.id}>{item.name || item.item_name} — {item.reason || item.skipped_reason || 'Skipped'}</li>
                      ))}
                    </ul>
                  </>
                )}
              </div>
              <div className="flex gap-3 mt-2 w-full">
                <button
                  type="button"
                  onClick={resetToScanning}
                  className="flex-1 px-4 py-2 rounded-lg bg-pup-maroon text-white text-sm font-bold hover:bg-pup-dark-maroon transition-colors cursor-pointer"
                >
                  Scan Next
                </button>
                <button
                  type="button"
                  onClick={handleClose}
                  className={`flex-1 px-4 py-2 rounded-lg text-sm font-bold transition-colors cursor-pointer ${
                    isDark ? 'bg-[#3a3b3c] text-white hover:bg-[#4e4f50]' : 'bg-gray-100 text-gray-800 hover:bg-gray-200'
                  }`}
                >
                  Done
                </button>
              </div>
            </div>
          ) : phase === 'review' ? (
            /* ---------------- PHASE: REVIEW CHECKLIST ---------------- */
            <div className="space-y-4">
              <div className={`p-3 rounded-xl border text-xs space-y-1 ${isDark ? 'bg-[#18191a] border-zinc-800' : 'bg-blue-50/50 border-blue-100'}`}>
                <p className={`font-bold text-sm ${isDark ? 'text-white' : 'text-gray-900'}`}>
                  Request #{lookupData?.request?.request_id}
                </p>
                <p className={isDark ? 'text-zinc-300' : 'text-gray-700'}>
                  Requester: <span className="font-semibold">{ownerName}</span>
                </p>
                {lookupData?.request?.student_number && (
                  <p className={isDark ? 'text-zinc-400' : 'text-gray-600'}>
                    Student #: {lookupData.request.student_number}
                  </p>
                )}
              </div>

              <div className="space-y-2">
                <p className={`text-xs font-bold uppercase tracking-wider ${isDark ? 'text-zinc-400' : 'text-gray-500'}`}>
                  Select items to hand over:
                </p>
                <div className="space-y-2 max-h-60 overflow-y-auto pr-1">
                  {(lookupData?.items || []).map((item) => {
                    const isClaimable = item.claimable !== false;
                    const isChecked = selectedItemUuids.includes(item.uuid);

                    return (
                      <label
                        key={item.uuid || item.id}
                        className={`flex items-start gap-3 p-3 rounded-xl border transition-all cursor-pointer ${
                          !isClaimable
                            ? isDark
                              ? 'bg-zinc-900/50 border-zinc-800 opacity-60 cursor-not-allowed'
                              : 'bg-gray-100 border-gray-200 opacity-60 cursor-not-allowed'
                            : isChecked
                            ? isDark
                              ? 'bg-pup-yellow/10 border-pup-yellow/40'
                              : 'bg-amber-50 border-amber-200'
                            : isDark
                            ? 'bg-[#18191a] border-zinc-800'
                            : 'bg-white border-gray-200'
                        }`}
                      >
                        <input
                          type="checkbox"
                          disabled={!isClaimable}
                          checked={isChecked}
                          onChange={() => toggleItemCheck(item.uuid)}
                          className="mt-0.5 w-4 h-4 rounded text-pup-maroon focus:ring-pup-maroon cursor-pointer disabled:cursor-not-allowed"
                        />
                        <div className="flex-1 text-xs space-y-0.5">
                          <div className="flex justify-between items-center">
                            <span className={`font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>
                              {item.name || item.document_name}
                            </span>
                            <span className={`font-semibold text-[11px] px-2 py-0.5 rounded-md ${
                              isClaimable
                                ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                : 'bg-gray-200 text-gray-600 dark:bg-zinc-800 dark:text-zinc-400'
                            }`}>
                              {item.number_of_copies ? `x${item.number_of_copies}` : '1 copy'}
                            </span>
                          </div>
                          {!isClaimable && item.reason && (
                            <p className="text-[11px] text-amber-600 dark:text-amber-400 font-medium">
                              {item.reason}
                            </p>
                          )}
                        </div>
                      </label>
                    );
                  })}
                </div>
              </div>

              <div className="flex justify-between items-center pt-2">
                <button
                  type="button"
                  onClick={resetToScanning}
                  className={`px-4 py-2 rounded-xl font-semibold text-sm transition-all duration-200 cursor-pointer ${
                    isDark
                      ? 'bg-[#242526] text-white hover:bg-zinc-800 border border-zinc-800'
                      : 'bg-gray-100 text-gray-800 hover:bg-gray-200 border border-gray-300'
                  }`}
                >
                  Back to Scan
                </button>

                <button
                  type="button"
                  onClick={handleConfirmClaim}
                  disabled={selectedItemUuids.length === 0 || phase === 'confirming'}
                  className={`px-4 py-2 rounded-xl font-semibold text-sm transition-all duration-200 ${
                    selectedItemUuids.length > 0 && phase !== 'confirming'
                      ? 'bg-pup-maroon text-white hover:bg-pup-dark-maroon cursor-pointer'
                      : 'bg-pup-maroon/50 text-white/50 cursor-not-allowed'
                  }`}
                >
                  {phase === 'confirming'
                    ? 'Completing...'
                    : `Confirm Claim (${selectedItemUuids.length})`}
                </button>
              </div>
            </div>
          ) : (
            /* ---------------- PHASE: SCANNING / MANUAL ---------------- */
            <div className="flex flex-col h-full">
              {phase !== 'looking_up' && (
                <div className={`flex border-b mb-4 ${isDark ? 'border-zinc-800' : 'border-gray-200'}`}>
                  <button
                    type="button"
                    onClick={() => { setMode('scan'); setErrorMessage(''); }}
                    className={`flex-1 pb-3 text-sm font-semibold transition-all relative border-b-2 -mb-0.5 focus:outline-none cursor-pointer ${
                      mode === 'scan'
                        ? isDark ? 'text-pup-yellow border-pup-yellow font-bold' : 'text-pup-maroon border-pup-maroon font-bold'
                        : isDark
                        ? 'text-zinc-400 border-transparent hover:text-white'
                        : 'text-zinc-500 border-transparent hover:text-gray-900'
                    }`}
                  >
                    Scan QR Code
                  </button>
                  <button
                    type="button"
                    onClick={() => { setMode('manual'); setErrorMessage(''); }}
                    className={`flex-1 pb-3 text-sm font-semibold transition-all relative border-b-2 -mb-0.5 focus:outline-none cursor-pointer ${
                      mode === 'manual'
                        ? isDark ? 'text-pup-yellow border-pup-yellow font-bold' : 'text-pup-maroon border-pup-maroon font-bold'
                        : isDark
                        ? 'text-zinc-400 border-transparent hover:text-white'
                        : 'text-zinc-500 border-transparent hover:text-gray-900'
                    }`}
                  >
                    Enter Claim Code
                  </button>
                </div>
              )}

              {mode === 'scan' ? (
                <>
                  <div className={`relative rounded-2xl overflow-hidden aspect-square flex items-center justify-center border transition-all duration-300 ${
                    isDark ? 'bg-[#18191a] border-[#3e4042]' : 'bg-gray-900 border-gray-200'
                  }`}>
                    {phase === 'scanning' && !cameraError && (
                      <>
                        <video ref={videoRef} className="w-full h-full object-cover" muted playsInline />
                        <div className={`absolute left-[6%] right-[6%] h-[2.5px] animate-scan-line pointer-events-none z-10 ${
                          isDark ? 'bg-pup-yellow shadow-[0_0_8px_rgba(248,191,30,0.8)]' : 'bg-pup-maroon shadow-[0_0_8px_rgba(139,0,0,0.8)]'
                        }`} />
                        <div className="absolute top-4 left-4 w-6 h-6 border-t-4 border-l-4 border-white/80 rounded-tl-lg pointer-events-none z-10" />
                        <div className="absolute top-4 right-4 w-6 h-6 border-t-4 border-r-4 border-white/80 rounded-tr-lg pointer-events-none z-10" />
                        <div className="absolute bottom-4 left-4 w-6 h-6 border-b-4 border-l-4 border-white/80 rounded-bl-lg pointer-events-none z-10" />
                        <div className="absolute bottom-4 right-4 w-6 h-6 border-b-4 border-r-4 border-white/80 rounded-br-lg pointer-events-none z-10" />
                      </>
                    )}
                    {phase === 'looking_up' && (
                      <div className="text-white text-sm font-semibold animate-pulse z-10">Looking up claim details…</div>
                    )}
                    {phase === 'scanning' && cameraError && (
                      <div className="text-center px-6 z-10">
                        <ExclamationTriangleIcon className="w-8 h-8 text-yellow-400 mx-auto mb-2" />
                        <p className="text-white text-xs">{cameraError}</p>
                      </div>
                    )}
                    <canvas ref={canvasRef} className="hidden" />
                  </div>

                  <p className={`text-xs text-center mt-3 mb-4 ${isDark ? 'text-zinc-400' : 'text-gray-500'}`}>
                    Point your QR code at the camera. If the camera isn't working, switch to the "Enter Claim Code" tab.
                  </p>

                  <div className="flex justify-start">
                    <button
                      type="button"
                      onClick={handleClose}
                      className={`px-4 py-2 rounded-xl font-semibold text-sm transition-all duration-200 cursor-pointer ${
                        isDark
                          ? 'bg-[#242526] text-white hover:bg-zinc-800 border border-zinc-800'
                          : 'bg-gray-100 text-gray-800 hover:bg-gray-200 border border-gray-300'
                      }`}
                    >
                      Cancel
                    </button>
                  </div>
                </>
              ) : (
                <form onSubmit={handleManualSubmit} className="space-y-4">
                  <div className="space-y-1">
                    <div className="flex items-center gap-2">
                      <svg viewBox="0 0 24 24" fill="currentColor" className={`w-5 h-5 shrink-0 ${isDark ? 'text-zinc-400' : 'text-zinc-500'}`}>
                        <rect x="2" y="6" width="20" height="12" rx="3" fill="none" stroke="currentColor" strokeWidth="2" />
                        <rect x="5" y="10" width="2" height="4" rx="0.5" />
                        <rect x="9" y="10" width="2" height="4" rx="0.5" />
                        <rect x="13" y="10" width="2" height="4" rx="0.5" />
                        <rect x="17" y="10" width="2" height="4" rx="0.5" />
                      </svg>
                      <span className={`text-base font-bold ${isDark ? 'text-white' : 'text-gray-900'}`}>
                        Enter verification code
                      </span>
                    </div>
                    <p className={`text-xs ${isDark ? 'text-zinc-400' : 'text-zinc-500'}`}>
                      Enter the 6-character alphanumeric claim code.
                    </p>
                  </div>

                  <div className="flex justify-between gap-2 sm:gap-3">
                    {manualCode.map((digit, idx) => (
                      <input
                        key={idx}
                        ref={(el) => (inputRefs.current[idx] = el)}
                        type="text"
                        inputMode="text"
                        maxLength={1}
                        value={digit}
                        onChange={(e) => handleInputChange(idx, e.target.value)}
                        onKeyDown={(e) => handleKeyDown(idx, e)}
                        onFocus={() => handleFocus(idx)}
                        onPaste={handlePaste}
                        placeholder="0"
                        disabled={phase === 'looking_up'}
                        className={`w-10 h-10 sm:w-14 sm:h-14 text-center text-base sm:text-xl font-bold rounded-lg sm:rounded-xl border transition-all duration-200 focus:outline-none ${
                          isDark
                            ? 'bg-[#1a1a1a] border-[#27272a] text-white placeholder-zinc-700 focus:border-pup-yellow focus:ring-1 focus:ring-pup-yellow'
                            : 'bg-gray-50 border-gray-200 text-gray-900 placeholder-gray-300 focus:border-pup-maroon focus:ring-1 focus:ring-pup-maroon'
                        }`}
                      />
                    ))}
                  </div>

                  <div className="flex justify-between items-center pt-2">
                    <button
                      type="button"
                      onClick={handleClose}
                      className={`px-4 py-2 rounded-xl font-semibold text-sm transition-all duration-200 cursor-pointer ${
                        isDark
                          ? 'bg-[#242526] text-white hover:bg-zinc-800 border border-zinc-800'
                          : 'bg-gray-100 text-gray-800 hover:bg-gray-200 border border-gray-300'
                      }`}
                    >
                      Cancel
                    </button>

                    <button
                      type="submit"
                      disabled={manualCodeString.length !== CLAIM_CODE_LENGTH || phase === 'looking_up'}
                      className={`px-4 py-2 rounded-xl font-semibold text-sm transition-all duration-200 ${
                        manualCodeString.length === CLAIM_CODE_LENGTH && phase !== 'looking_up'
                          ? 'bg-pup-maroon text-white hover:bg-pup-dark-maroon cursor-pointer'
                          : 'bg-pup-maroon/50 text-white/50 cursor-not-allowed'
                      }`}
                    >
                      {phase === 'looking_up' ? 'Looking up...' : 'Verify'}
                    </button>
                  </div>
                </form>
              )}
            </div>
          )}
        </div>
      </div>
    </div>,
    document.body
  );
};

export default ClaimScannerModal;