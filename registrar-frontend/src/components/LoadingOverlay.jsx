import React, { useEffect, useRef, useCallback } from "react";
import { createPortal } from "react-dom";
import loading1 from "../assets/Loading 1.png";
import loading2 from "../assets/Loading 2.png";
import loading3 from "../assets/Loading 3.png";
import { useTheme } from "../context/ThemeContext";
import useNetworkStatus from "../hooks/useNetworkStatus";
import ConnectionErrorCard from "./ConnectionErrorCard";

const IMGS = [loading1, loading2, loading3];

const TRANSFORMS = [
  "translate(56px, 0px)",
  "translate(28px, 28px)",
  "translate(0px, 56px)",
];

const LoadingOverlay = ({ isVisible = false, message = "Loading...", connectionError = false, onRetry }) => {
  const { isDark } = useTheme();
  const { isOnline, retry } = useNetworkStatus();
  const r0 = useRef(null);
  const r1 = useRef(null);
  const r2 = useRef(null);
  const refs = useRef([r0, r1, r2]);
  const timers = useRef([]);
  const chars = useRef(message.split(""));

  useEffect(() => { chars.current = message.split(""); }, [message]);

  const clearAll = useCallback(() => {
    timers.current.forEach(clearTimeout);
    timers.current = [];
  }, []);

  const after = useCallback((ms, fn) => {
    const id = setTimeout(fn, ms);
    timers.current.push(id);
  }, []);

  const runCycle = useCallback(() => {
    const els = refs.current.map(r => r.current);
    if (els.some(el => !el)) return;

    const FADE  = 400; // fade transition duration
    const GAP   = 250; // stagger between each folder
    const HOLD  = 500; // pause while all invisible
    const CYCLE = (FADE + GAP) * 3 + HOLD + (FADE + GAP) * 3 + 600;

    // FADE OUT: front (index 2) → mid (index 1) → back (index 0)
    [2, 1, 0].forEach((elIdx, order) => {
      after((FADE + GAP) * order, () => {
        const el = els[elIdx];
        if (!el) return;
        el.style.transition = `opacity ${FADE}ms ease`;
        el.style.opacity = "0";
      });
    });

    // FADE IN: back (index 0) → mid (index 1) → front (index 2)
    const inStart = (FADE + GAP) * 3 + HOLD;
    [0, 1, 2].forEach((elIdx, order) => {
      after(inStart + (FADE + GAP) * order, () => {
        const el = els[elIdx];
        if (!el) return;
        el.style.transition = `opacity ${FADE}ms ease`;
        el.style.opacity = "1";
      });
    });

    // Loop
    after(CYCLE, runCycle);
  }, [after]);

  useEffect(() => {
    if (!isVisible || !isOnline) return;

    // Set initial state
    const rs = refs.current;
    rs.forEach((r, i) => {
      if (!r.current) return;
      r.current.style.transition = "none";
      r.current.style.transform  = TRANSFORMS[i];
      r.current.style.zIndex     = String(i + 1);
      r.current.style.opacity    = "1";
    });

    runCycle();
    return () => clearAll();
  }, [isVisible, isOnline, runCycle, clearAll]);

  if (!isVisible) return null;

  return createPortal(
    <div className={`fixed inset-0 z-[2147483647] flex flex-col items-center justify-center px-4 ${
      connectionError ? "" : `backdrop-blur-sm ${isDark ? 'bg-[#18191a]/85' : 'bg-white/80'}`
    }`}>
      <div className="flex flex-col items-center gap-3 sm:gap-4 text-center max-w-[90vw]">

        {!connectionError && isOnline ? (
          <div className="relative h-44 w-44 sm:h-52 sm:w-52">
            {IMGS.map((src, i) => (
              <img
                key={i}
                ref={refs.current[i]}
                src={src}
                alt={`Loading folder ${i + 1}`}
                className="absolute top-0 left-0 w-32 sm:w-36 drop-shadow-[0_14px_22px_rgba(0,0,0,0.18)]"
                style={{
                  willChange: "opacity",
                  backfaceVisibility: "hidden",
                  WebkitBackfaceVisibility: "hidden",
                  transform: TRANSFORMS[i],
                  zIndex: i + 1,
                }}
              />
            ))}
          </div>
        ) : (
          <ConnectionErrorCard isDark={isDark} onRetry={onRetry ?? retry} compact />
        )}

        {!connectionError && isOnline && <div className="flex max-w-full flex-wrap justify-center gap-0.5">
          {chars.current.map((char, i) => (
            <span
              key={i}
              className={`font-bold text-[10px] sm:text-xs uppercase tracking-wide sm:tracking-widest inline-block ${isDark ? 'text-[#e4e6eb]' : 'text-[#800000]'}`}
            >
              {char === " " ? "\u00A0" : char}
            </span>
          ))}
        </div>}

      </div>
    </div>,
    document.body
  );
};

export default React.memo(LoadingOverlay);