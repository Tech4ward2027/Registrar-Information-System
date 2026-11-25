import { ArrowPathIcon, SignalSlashIcon } from "@heroicons/react/24/outline";

const ConnectionErrorCard = ({ isDark = false, onRetry, compact = false }) => (
  <div className={`relative z-2147483647 flex w-full items-center justify-center ${compact ? "px-0 py-0" : "min-h-[60vh] px-4 py-10"} ${isDark ? "bg-[#18191a]" : "bg-white"}`}>
    <div className={`w-full max-w-xl min-h-80 rounded-3xl border p-7 text-center shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-200 sm:px-10 ${isDark ? "border-[#3e4042] bg-[#242526] text-[#e4e6eb]" : "border-gray-200 bg-white"}`}>
      <div className={`mx-auto flex h-20 w-20 items-center justify-center rounded-full ${isDark ? "bg-[#3a3b3d]" : "bg-gray-100"}`}>
        <SignalSlashIcon className={`h-10 w-10 ${isDark ? "text-gray-300" : "text-gray-600"}`} />
      </div>
      <h2 className={`mt-6 text-xl font-semibold ${isDark ? "text-white" : "text-gray-950"}`}>
        Can&apos;t connect right now
      </h2>
      <p className={`mx-auto mt-2 max-w-md text-sm leading-6 sm:text-base ${isDark ? "text-gray-300" : "text-gray-600"}`}>
        Check your internet connection and try again. Your progress hasn&apos;t been lost.
      </p>
      <button
        type="button"
        onClick={onRetry}
        className="mt-7 inline-flex items-center gap-2 rounded-lg bg-[#800000] px-6 py-3 text-sm font-semibold text-white transition-colors hover:bg-[#660000] focus:outline-none focus:ring-2 focus:ring-[#800000] focus:ring-offset-2"
      >
        <ArrowPathIcon className="h-5 w-5" />
        Try again
      </button>
    </div>
  </div>
);

export default ConnectionErrorCard;
