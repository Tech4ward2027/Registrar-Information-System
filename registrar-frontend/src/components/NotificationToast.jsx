import { useNavigate, useLocation } from 'react-router-dom';
import { XMarkIcon } from '@heroicons/react/24/solid';
import { useTheme } from '../context/ThemeContext';
import { useToast } from '../context/NotificationToastContext';
import { useHeaderResponsiveState } from '../utils/helpers';
import { getNotificationRequestRef } from '../utils/requestRef';

// CATEGORY_MAP lives in src/constants/notificationCategories.js
// — edit it there; changes apply to both NotificationModal and NotificationToast.
import { CATEGORY_MAP } from '../constants/notificationCategories';

// -------------------------------------------------------
// SingleToast
// -------------------------------------------------------
const SingleToast = ({ toast, onDismiss }) => {
    const navigate = useNavigate();
    const location = useLocation();
    const { isDark } = useTheme();
    const meta = CATEGORY_MAP[toast.type] ?? { category: 'Notification', color: 'bg-blue-400' };

    const handleClick = () => {
        onDismiss(toast.id);

        if (!getNotificationRequestRef(toast)) return;

        const roleRoot      = location.pathname.split('/')[1];
        const validRoles    = ['student', 'alumni', 'staff', 'super-admin'];
        const targetRole    = validRoles.includes(roleRoot) ? roleRoot : 'student';

        navigate(`/${targetRole}/inbox`, {
            state: { selectedNotificationId: toast.id, notification: toast },
        });
    };

    return (
        <div
            className={`
                flex items-start gap-3 w-full
                rounded-2xl shadow-[0_8px_32px_rgba(0,0,0,0.5)]
                px-4 py-3 cursor-pointer
                animate-in slide-in-from-right-4 fade-in duration-300
                transition-colors group
                ${isDark ? 'bg-[#242526] border border-[#3e4042] hover:border-[#4e4f50]' : 'bg-pup-dark-maroon border border-white/10 hover:border-white/20'}
            `}
            onClick={handleClick}
        >
            {/* Color dot */}
            <div className={`mt-1 w-2.5 h-2.5 rounded-full shrink-0 ring-4 ring-black/20 ${meta.color}`} />

            {/* Text */}
            <div className="flex-1 min-w-0">
                <p className={`text-[9px] font-black uppercase tracking-[0.15em] mb-0.5 ${isDark ? 'text-[#f5c542]' : 'text-pup-yellow'}`}>
                    {meta.category}
                </p>
                <p className={`text-[12px] font-bold leading-snug truncate ${isDark ? 'text-[#e4e6eb]' : 'text-white'}`}>
                    {toast.title}
                </p>
                <p className={`text-[11px] leading-normal line-clamp-2 mt-0.5 ${isDark ? 'text-[#b0b3b8]' : 'text-white/60'}`}>
                    {toast.message}
                </p>
            </div>

            {/* Dismiss button */}
            <button
                onClick={(e) => { e.stopPropagation(); onDismiss(toast.id); }}
                className={`shrink-0 mt-0.5 p-0.5 rounded-full transition-colors ${isDark ? 'hover:bg-white/6' : 'hover:bg-white/10'}`}
            >
                <XMarkIcon className={`w-3.5 h-3.5 transition-colors ${isDark ? 'text-[#b0b3b8] hover:text-[#e4e6eb]' : 'text-white/40 hover:text-white/80'}`} />
            </button>
        </div>
    );
};

// -------------------------------------------------------
// NotificationToast — the fixed stack container
// Positioned bottom-right, renders up to 3 toasts
// -------------------------------------------------------
const NotificationToast = () => {
    const { toasts, dismissToast } = useToast();
    const { headerHeight } = useHeaderResponsiveState(toasts.length > 0);

    if (toasts.length === 0) return null;

    return (
        <div 
            style={{
                top: `${headerHeight}px`,
            }}
            className="fixed toast-container-shifted right-3 lg:right-5 md:right-5 z-9999 flex flex-col gap-2 w-[min(340px,calc(100vw-2rem))] pointer-events-none"
        >            {toasts.map(toast => (
                <div key={toast.id} className="pointer-events-auto">
                    <SingleToast toast={toast} onDismiss={dismissToast} />
                </div>
            ))}
        </div>
    );
};

export default NotificationToast;