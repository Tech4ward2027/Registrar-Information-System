import React, { useMemo, useState, useEffect } from "react";
import { NavLink, useNavigate, useLocation, useSearchParams } from "react-router-dom";
import {
  UserCircleIcon,
  ArrowRightStartOnRectangleIcon,
  EllipsisHorizontalIcon,
  ChevronDownIcon,
  ChevronUpIcon,
  BriefcaseIcon,
  UserIcon,
  AcademicCapIcon,
  ArrowPathIcon,
} from '@heroicons/react/24/outline';
import { useAuth } from "../context/AuthProvider";
import { useTheme } from "../context/ThemeContext";
import ConfirmationModal from "../components/ConfirmationModal";
import LineLoading from "../components/LineLoading.jsx";
import SwitchRoleModal from "../components/SwitchRoleModal.jsx";
import { hasModuleAccess } from "../utils/policy";
import { ROLE_CONFIG } from "../utils/navigationConfig";

const Navigation = ({ isOpen, onItemClick, role = 'student' }) => {
  const navigate = useNavigate();
  const location = useLocation();
  const [searchParams] = useSearchParams();
  const { user, logout, roleAssignments, switchRole, ROLE_ID_TO_NAME } = useAuth();
  const [isSwitching, setIsSwitching] = useState(false);
  const { isDark } = useTheme();
  const [isLoggingOut, setIsLoggingOut] = useState(false);
  const [isSwitchModalOpen, setIsSwitchModalOpen] = useState(false);
  const [isProfileMenuOpen, setIsProfileMenuOpen] = useState(false);
  const [expandedParents, setExpandedParents] = useState({});

  const [modal, setModal] = useState({
    isOpen: false,
    title: '',
    message: '',
    type: 'default',
    onConfirm: () => { },
  });
  const [headerHeight, setHeaderHeight] = useState(101); // Fallback default

  // canUseSwitcher: enabled whenever this account currently holds more
  // than one Active role_assignments row (e.g. Student + a
  // policy-restricted Admin — the "student staff" case). Server-driven
  // now, via GET /role-assignments/mine, rather than a hardcoded
  // "Student Staff" policy name — any account granted a second role
  // gets the switcher automatically, with no extra flag to maintain.
  const canUseSwitcher = Array.isArray(roleAssignments) && roleAssignments.length > 1;

  const switchableRoles = useMemo(() => {
    const ICONS = { admin: BriefcaseIcon, super_admin: BriefcaseIcon, student: UserIcon, alumni: AcademicCapIcon };
    const GRADS = {
      admin: 'from-[#0052d4] to-[#4364f7]',
      super_admin: 'from-[#0052d4] to-[#4364f7]',
      student: 'from-[#11998e] to-[#38ef7d]',
      alumni: 'from-[#11998e] to-[#38ef7d]',
    };
    const LABELS = { admin: 'Admin', super_admin: 'Super Admin', student: 'Student', alumni: 'Alumni' };
    const DESCRIPTIONS = {
      student: 'Student Role',
      alumni: 'Alumni Role',
      admin: 'Registrar Staff',
      super_admin: 'System Administrator',
    };

    return (roleAssignments || []).map((assignment) => {
      const roleName = ROLE_ID_TO_NAME?.[assignment.role_id];
      return {
        role_id: assignment.role_id,
        label: LABELS[roleName] || roleName || 'Unknown',
        description: assignment.policy?.name || DESCRIPTIONS[roleName] || 'Account Role',
        icon: ICONS[roleName] || UserIcon,
        grad: GRADS[roleName] || 'from-gray-500 to-gray-700',
      };
    });
  }, [roleAssignments, ROLE_ID_TO_NAME]);

  const handleSwitchRole = async (roleId) => {
    if (isSwitching) return;
    setIsSwitching(true);
    try {
      await switchRole(roleId);
      setIsSwitchModalOpen(false);
    } catch (err) {
      console.error('Role switch failed:', err);
    } finally {
      setIsSwitching(false);
    }
  };
  
  const [isCollapsed, setIsCollapsed] = useState(() => {
    try {
      const saved = localStorage.getItem('sidebar-collapsed');
      return saved !== null ? saved === 'true' : true;
    } catch (e) {
      return true;
    }
  });

  useEffect(() => {
    try {
      localStorage.setItem('sidebar-collapsed', isCollapsed);
    } catch (e) {
      console.error('Failed to save sidebar collapsed state:', e);
    }
    if (isCollapsed) {
      document.documentElement.classList.add('sidebar-collapsed');
    } else {
      document.documentElement.classList.remove('sidebar-collapsed');
    }
    return () => {
      document.documentElement.classList.remove('sidebar-collapsed');
    };
  }, [isCollapsed]);

  useEffect(() => {
    const handleSetCollapsed = (event) => {
      if (typeof event?.detail === 'boolean') {
        setIsCollapsed(event.detail);
      } else {
        setIsCollapsed(true);
      }
    };
    window.addEventListener('collapse-sidebar', handleSetCollapsed);
    return () => window.removeEventListener('collapse-sidebar', handleSetCollapsed);
  }, []);

  useEffect(() => {
    const headerElement = document.querySelector('header');
    if (!headerElement) return;

    const resizeObserver = new ResizeObserver((entries) => {
      for (let entry of entries) {
        setHeaderHeight(entry.target.offsetHeight);
      }
    });
    resizeObserver.observe(headerElement);
    return () => resizeObserver.disconnect();
  }, []);

  const baseConfig = ROLE_CONFIG[role] || ROLE_CONFIG.student;
  const config = useMemo(() => {
    const sections = (baseConfig.sections || []).map((sec) => ({
      ...sec,
      items: sec.items.filter((item) => !item.module || hasModuleAccess(user, item.module)),
    })).filter((sec) => sec.items.length > 0);
    return { ...baseConfig, sections };
  }, [baseConfig, user]);

  const profile = config.profileKey ? user?.[config.profileKey] : null;

  useEffect(() => {
    const currentPath = location.pathname;
    setExpandedParents((prev) => {
      const next = { ...prev };
      let changed = false;
      (config.sections || []).forEach((sec) => {
        sec.items.forEach((item) => {
          if (item.children) {
            const basePath = item.to.split('?')[0];
            if (currentPath.includes(basePath)) {
              if (!next[item.name]) {
                next[item.name] = true;
                changed = true;
              }
            }
          }
        });
      });
      return changed ? next : prev;
    });
  }, [location.pathname, searchParams, config]);

  const isChildActive = (childTo, childTabKey) => {
    const basePath = childTo.split('?')[0];
    if (!location.pathname.includes(basePath)) return false;
    if (childTabKey) {
      const currentTab = searchParams.get('tab');
      return currentTab?.toLowerCase() === childTabKey.toLowerCase();
    }
    return true;
  };

  const isParentActive = (item) => {
    const basePath = item.to?.split('?')[0];
    return Boolean(basePath && location.pathname.includes(basePath));
  };

  const fullName = useMemo(() => {
    if (role === 'superAdmin') return 'SUPER ADMIN';

    const p =
      (profile?.first_name || profile?.last_name) ? profile :
        (user?.admin_profile?.first_name || user?.admin_profile?.last_name) ? user.admin_profile :
          (user?.student_profile?.first_name || user?.student_profile?.last_name) ? user.student_profile :
            (user?.alumni_profile?.first_name || user?.alumni_profile?.last_name) ? user.alumni_profile :
              (user?.undergrad_requestor_profile?.first_name || user?.undergrad_requestor_profile?.last_name) ? user.undergrad_requestor_profile :
                null;

    if (p) {
      const name = [p.first_name, p.last_name, p.suffix]
        .filter(Boolean)
        .join(' ')
        .trim();
      if (name) return name;
    }

    if (user?.first_name || user?.last_name) {
      const name = [user.first_name, user.last_name, user.suffix]
        .filter(Boolean)
        .join(' ')
        .trim();
      if (name) return name;
    }

    if (role === 'staff') {
      return user?.policy?.name || 'Staff';
    }

    if (role === 'undergrad') {
      return 'Undergrad';
    }

    if (role === 'student') {
      return 'Student';
    }

    if (role === 'alumni') {
      return 'Alumni';
    }

    return user?.policy?.name || 'Undergrad';
  }, [profile, role, user]);

  const initials = useMemo(() => {
    if (role === 'superAdmin') return 'SA';

    const getInitialsFrom = (name) => {
      if (!name) return null;
      const parts = name.trim().split(/\s+/);
      return parts.length > 1
        ? (parts[0].charAt(0) + parts[1].charAt(0)).toUpperCase()
        : parts[0].charAt(0).toUpperCase();
    };

    if (fullName) {
      return getInitialsFrom(fullName);
    }

    return role === 'undergrad' ? 'U' : (role === 'student' ? 'S' : role === 'alumni' ? 'A' : 'SS');
  }, [fullName, role]);

  const handleLogoutClick = () => {
    setModal({
      isOpen: true,
      title: 'Logout Session',
      message: 'Are you sure you want to log out? Any unsaved changes in the registrar system may be lost.',
      type: 'default',
      onConfirm: async () => {
        setModal((prev) => ({ ...prev, isOpen: false }));
        setIsLoggingOut(true);
        try {
          await logout();
          navigate('/', { replace: true });
        } catch (err) {
          console.error('Logout failed:', err);
          setIsLoggingOut(false);
        }
      },
    });
  };

  const closeModal = () => setModal((prev) => ({ ...prev, isOpen: false }));

  const renderSections = (mobile = false) => {
    const isCompact = !mobile && isCollapsed;

    return (config.sections || []).map((section) => (
      <div key={section.title} className="mb-3">
        {!isCompact && (
          <h3 className={`text-xs font-semibold px-4 pt-2 pb-1.5 select-none tracking-wide ${mobile
            ? 'text-sky-300 font-bold uppercase tracking-widest text-[11px]'
            : (isDark ? 'text-gray-400 uppercase tracking-wider' : 'text-gray-600 uppercase tracking-wider')
            }`}>
            {section.title}
          </h3>
        )}

        <div className="space-y-1">
          {section.items.map((item) => {
            const hasChildren = Boolean(item.children && item.children.length > 0);
            const parentActive = isParentActive(item);
            const isExpanded = Boolean(expandedParents[item.name]);
            const ItemIcon = item.icon;

            if (hasChildren) {
              return (
                <div key={item.name} className="space-y-1">
                  <div className="relative flex items-center">
                    <NavLink
                      to={item.to}
                      onClick={(e) => {
                        if (!isCompact) {
                          setExpandedParents((prev) => ({
                            ...prev,
                            [item.name]: !prev[item.name],
                          }));
                        }
                        if (mobile && onItemClick) onItemClick();
                      }}
                      className={`group relative flex items-center w-full rounded-xl transition-all duration-200 outline-none cursor-pointer ${isCompact ? 'justify-center p-3' : 'justify-between px-4 py-3'
                        } ${parentActive
                          ? (mobile
                            ? 'bg-black/35 text-white font-bold shadow-sm'
                            : (isDark ? 'bg-[#611825] text-white font-bold shadow-sm' : 'bg-pup-dark-maroon text-white font-bold shadow-md'))
                          : (mobile
                            ? 'text-white/85 hover:text-white hover:bg-white/10 font-semibold'
                            : (isDark ? 'text-[#e4e6eb] hover:bg-white/5 font-bold' : 'text-[#700000] hover:bg-black/5 font-bold'))
                        }`}
                    >
                      <div className="flex items-center gap-3.5 min-w-0">
                        <ItemIcon className={`w-5 h-5 shrink-0 transition-transform duration-200 group-hover:scale-105 ${parentActive
                          ? 'text-white'
                          : (mobile ? 'text-white/80' : (isDark ? 'text-[#b91c1c]' : 'text-[#700000]'))
                          }`} />
                        {!isCompact && (
                          <span className="text-sm truncate leading-snug">{item.name}</span>
                        )}
                      </div>

                      {!isCompact && (
                        <button
                          type="button"
                          onClick={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            setExpandedParents((prev) => ({
                              ...prev,
                              [item.name]: !prev[item.name],
                            }));
                          }}
                          className="p-1 rounded hover:bg-white/10 transition-colors cursor-pointer"
                          aria-label="Toggle submenu"
                        >
                          {isExpanded ? (
                            <ChevronUpIcon className={`w-4 h-4 shrink-0 ${parentActive || mobile ? 'text-white' : (isDark ? 'text-gray-400' : 'text-gray-600')}`} />
                          ) : (
                            <ChevronDownIcon className={`w-4 h-4 shrink-0 ${parentActive || mobile ? 'text-white' : (isDark ? 'text-gray-400' : 'text-gray-600')}`} />
                          )}
                        </button>
                      )}

                      {isCompact && (
                        <span className={`pointer-events-none absolute left-full ml-4 z-50 rounded-md px-2.5 py-1.5 text-xs font-semibold shadow-lg border transition-all duration-200 opacity-0 translate-x-[-8px] scale-95 group-hover:opacity-100 group-hover:translate-x-0 group-hover:scale-100 ${isDark ? 'bg-[#242526] text-[#e4e6eb] border-[#3e4042]' : 'bg-white text-[#700000] border-gray-200'
                          }`}>
                          {item.name}
                        </span>
                      )}
                    </NavLink>
                  </div>

                  {isExpanded && !isCompact && (
                    <div className={`relative ml-6 pl-4 border-l my-1 space-y-1 ${mobile ? 'border-white/20' : (isDark ? 'border-neutral-700/80' : 'border-gray-400')
                      }`}>
                      {item.children.map((child) => {
                        const childActive = isChildActive(child.to, child.tabKey);
                        return (
                          <NavLink
                            key={child.name}
                            to={child.to}
                            onClick={() => {
                              if (mobile && onItemClick) onItemClick();
                            }}
                            className={`block py-2 px-3 rounded-lg text-sm transition-all duration-200 outline-none ${childActive
                              ? (mobile
                                ? 'bg-black/40 text-white font-bold shadow-xs'
                                : (isDark ? 'bg-[#4c121e] text-white font-bold shadow-xs border border-red-950/40' : 'bg-[#5c0000] text-white font-bold shadow-xs'))
                              : (mobile
                                ? 'text-white/80 hover:text-white hover:bg-white/10 font-semibold'
                                : (isDark ? 'text-neutral-300 hover:text-white hover:bg-white/5 font-bold' : 'text-gray-700 hover:text-[#700000] hover:bg-black/5 font-semibold'))
                              }`}
                          >
                            {child.name}
                          </NavLink>
                        );
                      })}
                    </div>
                  )}
                </div>
              );
            }

            return (
              <NavLink
                key={item.name}
                to={item.to}
                onClick={() => {
                  if (mobile && onItemClick) onItemClick();
                }}
                className={({ isActive }) => `
                  group relative flex items-center rounded-xl transition-all duration-200 outline-none
                  ${isCompact ? 'justify-center p-3' : 'justify-between px-4 py-3'}
                  ${isActive
                    ? (mobile
                      ? 'bg-black/35 text-white font-bold shadow-sm'
                      : (isDark ? 'bg-[#611825] text-white font-bold shadow-sm' : 'bg-pup-dark-maroon text-white font-bold shadow-md'))
                    : (mobile
                      ? 'text-white/85 hover:text-white hover:bg-white/10 font-semibold'
                      : (isDark ? 'text-[#e4e6eb] hover:bg-white/5 font-bold' : 'text-[#700000] hover:bg-black/5 font-bold'))
                  }
                `}
              >
                {({ isActive }) => (
                  <>
                    <div className="flex items-center gap-3.5 min-w-0">
                      <div className="relative shrink-0">
                        <ItemIcon className={`w-5 h-5 transition-transform duration-200 group-hover:scale-105 ${isActive
                          ? 'text-white'
                          : (mobile ? 'text-white/80' : (isDark ? 'text-[#b91c1c]' : 'text-[#700000]'))
                          }`} />
                        {isCompact && item.badge && (
                          <span className="absolute -top-1 -right-1 w-2.5 h-2.5 rounded-full bg-red-500 ring-2 ring-[#141414]" />
                        )}
                      </div>
                      {!isCompact && <span className="text-sm leading-snug truncate">{item.name}</span>}
                    </div>

                    {!isCompact && item.badge && (
                      <span className="px-2 py-0.5 rounded-full text-xs font-bold bg-[#e53935] text-white shrink-0 ml-2">
                        {item.badge}
                      </span>
                    )}

                    {isCompact && (
                      <span className={`pointer-events-none absolute left-full ml-4 z-50 rounded-md px-2.5 py-1.5 text-xs font-semibold shadow-lg border transition-all duration-200 opacity-0 translate-x-[-8px] scale-95 group-hover:opacity-100 group-hover:translate-x-0 group-hover:scale-100 ${isDark ? 'bg-[#242526] text-[#e4e6eb] border-[#3e4042]' : 'bg-white text-[#700000] border-gray-200'
                        }`}>
                        {item.name}
                      </span>
                    )}
                  </>
                )}
              </NavLink>
            );
          })}
        </div>
      </div>
    ));
  };

  return (
    <>
      <LineLoading isVisible={isLoggingOut} />

      {isOpen && (
        <button
          type="button"
          aria-label="Close navigation menu"
          className="lg:hidden fixed inset-0 z-40 bg-transparent"
          onClick={onItemClick}
        />
      )}

      <div
        className={`lg:hidden fixed inset-x-0 z-50 transition-all duration-300 ease-in-out ${isOpen ? 'opacity-100 translate-y-0' : 'pointer-events-none opacity-0 -translate-y-4'}`}
        style={{ top: `${headerHeight}px` }}
      >
        <div className="w-full overflow-hidden rounded-b-lg shadow-[0_18px_42px_rgba(0,0,0,0.3)]">
          <div className={isDark ? 'bg-[#18191a]' : 'bg-[#7a0000]'}>
            <nav className={`px-4 py-3 max-h-[70vh] overflow-y-auto space-y-3 ${isDark ? 'bg-[#141414]' : 'bg-[#5c0000]'}`}>
              {/* Mobile User Profile & Switch Role Bar */}
              <div className={`pb-3 border-b flex items-center justify-between gap-3 ${isDark ? 'border-neutral-800' : 'border-white/20'}`}>
                <div
                  onClick={() => {
                    if (onItemClick) onItemClick();
                    if (role === 'superAdmin') navigate('/super-admin/profile');
                    else if (role === 'staff') navigate('/staff/profile');
                    else if (role === 'student') navigate('/student/profile');
                    else if (role === 'alumni') navigate('/alumni/profile');
                  }}
                  className="flex items-center gap-3.5 min-w-0 cursor-pointer flex-1"
                >
                  <div className="flex items-center justify-center w-11 h-11 rounded-full font-bold text-white bg-pup-dark-maroon shrink-0 text-sm">
                    {initials}
                  </div>
                  <div className="flex flex-col min-w-0">
                    <span className="font-semibold text-base text-white truncate leading-snug">
                      {fullName}
                    </span>
                    <span className="text-xs text-gray-400 truncate leading-tight">
                      {config.profileLabel(user) || user?.email}
                    </span>
                  </div>
                </div>

                {canUseSwitcher && (
                  <button
                    type="button"
                    onClick={() => {
                      if (onItemClick) onItemClick();
                      setIsSwitchModalOpen(true);
                    }}
                    className={`p-2.5 rounded-xl border transition-all cursor-pointer ${isDark
                      ? 'bg-[#242526] hover:bg-[#3a3b3c] border-[#3e4042] text-white'
                      : 'bg-white/10 hover:bg-white/20 border-white/20 text-white'
                      }`}
                    title="Switch Role"
                  >
                    <ArrowPathIcon className="w-5 h-5 shrink-0" />
                  </button>
                )}
              </div>

              {renderSections(true)}
            </nav>
            <button
              onClick={handleLogoutClick}
              className={`group flex w-full items-center justify-between px-5 py-4 text-[16px] font-bold text-white transition-all duration-200 ${isDark ? 'bg-[#4f0000] hover:bg-[#640000] active:bg-[#750000]' : 'bg-[#4f0000] hover:bg-[#640000] active:bg-[#750000]'}`}
            >
              <span>LOGOUT</span>
              <ArrowRightStartOnRectangleIcon className="h-5 w-5 transition-transform duration-200 group-hover:translate-x-0.5 text-white/85" />
            </button>
          </div>
        </div>
      </div>
      <aside
        className={`
          hidden lg:fixed lg:left-0 lg:z-40 lg:flex lg:flex-col relative
          ${isCollapsed ? 'lg:w-20' : 'lg:w-72'}
          ${isDark ? 'bg-[#141414] border-[#2c2d30]' : 'bg-[#E0E0E0] border-gray-300'} border-r transition-all duration-300 ease-in-out
        `}
        style={{
          top: `${headerHeight}px`,
          height: `calc(100vh - ${headerHeight}px)`
        }}
      >
        <div className={`flex flex-col h-full ${isCollapsed ? 'overflow-visible' : 'overflow-hidden'}`}>
          {/* Top Full-width Profile Container Block with 3-Dots Menu */}
          <div className={`relative shrink-0 transition-all duration-300 ${isDark ? 'bg-[#1b1c1e] border-b border-[#2a2b2e]' : 'bg-gray-200/90 border-b border-gray-300'
            }`}>
            <div
              className={`group relative flex items-center w-full transition-all duration-200 outline-none ${isCollapsed
                ? 'justify-center py-4 px-0'
                : 'gap-3 py-4 px-4 justify-between hover:bg-white/5'
                }`}
            >
              <div
                onClick={() => setIsProfileMenuOpen(!isProfileMenuOpen)}
                className={`flex items-center cursor-pointer min-w-0 ${isCollapsed ? 'justify-center w-full' : 'gap-3 flex-1'
                  }`}
              >
                <div className="relative shrink-0 flex items-center justify-center">
                  <div className={`flex items-center justify-center rounded-full font-black text-white bg-pup-dark-maroon ring-2 ring-white/10 ${isCollapsed ? 'w-11 h-11 text-xs' : 'w-11 h-11 text-xs'
                    }`}>
                    {initials}
                  </div>
                </div>

                {!isCollapsed && (
                  <div className="flex flex-col text-left overflow-hidden min-w-0">
                    <span className={`font-semibold text-sm truncate leading-snug ${isDark ? 'text-white' : 'text-gray-900'}`}>
                      {fullName}
                    </span>
                    <span className={`text-xs truncate leading-tight ${isDark ? 'text-gray-400' : 'text-gray-500'}`}>
                      {config.profileLabel(user) || user?.email}
                    </span>
                  </div>
                )}
              </div>

              {!isCollapsed && (
                <button
                  type="button"
                  onClick={() => setIsProfileMenuOpen(!isProfileMenuOpen)}
                  className={`p-1.5 rounded-lg transition-colors cursor-pointer ${isDark ? 'hover:bg-white/10 text-gray-400 hover:text-white' : 'hover:bg-black/10 text-gray-500 hover:text-gray-900'
                    }`}
                  aria-label="Account options"
                >
                  <EllipsisHorizontalIcon className="w-6 h-6" />
                </button>
              )}

              {isCollapsed && (
                <span className={`pointer-events-none absolute left-full ml-4 z-50 rounded-lg px-3 py-2 text-xs font-semibold shadow-xl border transition-all duration-200 opacity-0 translate-x-[-8px] scale-95 group-hover:opacity-100 group-hover:translate-x-0 group-hover:scale-100 whitespace-nowrap ${isDark
                  ? 'bg-[#242526] text-[#e4e6eb] border-[#3e4042]'
                  : 'bg-white text-gray-900 border-gray-200'
                  }`}>
                  <div className="font-bold">{fullName}</div>
                  <div className="text-[10px] text-gray-400">{user?.email}</div>
                </span>
              )}
            </div>

            {/* 3-Dots Popover Dropdown Menu */}
            {isProfileMenuOpen && (
              <>
                {/* Translucent Dim Backdrop */}
                <div
                  className="fixed inset-0 z-40 bg-black/20 backdrop-blur-[1px] transition-opacity"
                  onClick={() => setIsProfileMenuOpen(false)}
                />

                {/* Popover Card */}
                <div
                  className={`absolute z-50 rounded-xl shadow-xl border p-1.5 overflow-hidden transition-all duration-200 ${isCollapsed ? 'left-full top-2 ml-3 w-44' : 'right-3 top-full mt-1.5 w-44'
                    } ${isDark
                      ? 'bg-[#1a1a1d]/95 backdrop-blur-md border-neutral-800 text-white'
                      : 'bg-white/95 backdrop-blur-md border-gray-200 text-gray-900 shadow-xl'
                    }`}
                >
                  <div className="space-y-0.5">
                    {/* Profile */}
                    <button
                      type="button"
                      onClick={() => {
                        setIsProfileMenuOpen(false);
                        if (role === 'superAdmin') navigate('/super-admin/profile');
                        else if (role === 'staff') navigate('/staff/profile');
                        else if (role === 'student') navigate('/student/profile');
                        else if (role === 'alumni') navigate('/alumni/profile');
                        if (onItemClick) onItemClick();
                      }}
                      className={`w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-xs font-medium transition-colors cursor-pointer ${isDark ? 'hover:bg-white/10 text-white' : 'hover:bg-gray-100 text-gray-900'
                        }`}
                    >
                      <UserCircleIcon className={`w-4 h-4 shrink-0 ${isDark ? 'text-[#b91c1c]' : 'text-[#700000]'}`} />
                      <span>Profile</span>
                    </button>

                    {/* Switch role */}
                    {canUseSwitcher && (
                      <button
                        type="button"
                        onClick={() => {
                          setIsProfileMenuOpen(false);
                          setIsSwitchModalOpen(true);
                        }}
                        className={`w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-xs font-medium transition-colors cursor-pointer ${isDark ? 'hover:bg-white/10 text-white' : 'hover:bg-gray-100 text-gray-900'
                          }`}
                      >
                        <ArrowPathIcon className={`w-4 h-4 shrink-0 ${isDark ? 'text-[#b91c1c]' : 'text-[#700000]'}`} />
                        <span>Switch role</span>
                      </button>
                    )}

                    <div className={`my-0.5 border-t ${isDark ? 'border-neutral-800' : 'border-gray-200'}`} />

                    {/* Log out */}
                    <button
                      type="button"
                      onClick={() => {
                        setIsProfileMenuOpen(false);
                        handleLogoutClick();
                      }}
                      className={`w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-xs font-semibold transition-colors cursor-pointer ${isDark ? 'hover:bg-red-950/40 text-red-400' : 'hover:bg-red-50 text-[#700000]'
                        }`}
                    >
                      <ArrowRightStartOnRectangleIcon className={`w-4 h-4 shrink-0 ${isDark ? 'text-red-400' : 'text-[#700000]'}`} />
                      <span className={isDark ? 'text-red-400' : 'text-[#700000]'}>Log out</span>
                    </button>
                  </div>
                </div>
              </>
            )}
          </div>

          <nav className={`flex-1 custom-scrollbar transition-all duration-300 ${isCollapsed ? 'px-2 py-3 overflow-visible' : 'px-3 py-2 overflow-y-auto'}`}>
            {renderSections(false)}
          </nav>

          {/* Bottom Collapse Sidebar Menu Item */}
          <div className={`shrink-0 transition-all duration-300 ${isCollapsed ? 'p-2' : 'p-3 px-3'}`}>
            <div className={`my-1 border-t ${isDark ? 'border-neutral-800' : 'border-gray-300'}`} />

            <button
              type="button"
              onClick={() => setIsCollapsed(!isCollapsed)}
              className={`group relative flex items-center w-full rounded-xl font-medium transition-all duration-200 outline-none cursor-pointer ${isCollapsed ? 'justify-center p-3' : 'gap-3.5 px-4 py-3'
                } ${isDark
                  ? 'text-[#e4e6eb] hover:bg-white/5'
                  : 'text-[#700000] hover:bg-black/5'
                }`}
            >
              <div className="flex items-center gap-3.5 min-w-0">
                {isCollapsed ? (
                  <svg className={`w-5 h-5 shrink-0 transition-transform duration-200 group-hover:scale-105 ${isDark ? 'text-[#b91c1c]' : 'text-[#700000]'
                    }`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
                    <rect x="3.5" y="3.5" width="17" height="17" rx="3" />
                    <path d="M9 3.5v17" />
                    <path d="M13 10l2 2-2 2" />
                  </svg>
                ) : (
                  <svg className={`w-5 h-5 shrink-0 transition-transform duration-200 group-hover:scale-105 ${isDark ? 'text-[#b91c1c]' : 'text-[#700000]'
                    }`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
                    <rect x="3.5" y="3.5" width="17" height="17" rx="3" />
                    <path d="M9 3.5v17" />
                    <path d="M15 10l-2 2 2 2" />
                  </svg>
                )}
                {!isCollapsed && (
                  <span className="text-sm leading-snug truncate">
                    Collapse sidebar
                  </span>
                )}
              </div>

              {isCollapsed && (
                <span className={`pointer-events-none absolute left-full ml-4 z-50 rounded-md px-2.5 py-1.5 text-xs font-semibold shadow-lg border transition-all duration-200 opacity-0 translate-x-[-8px] scale-95 group-hover:opacity-100 group-hover:translate-x-0 group-hover:scale-100 whitespace-nowrap ${isDark
                  ? 'bg-[#242526] text-[#e4e6eb] border-[#3e4042]'
                  : 'bg-white text-[#700000] border-gray-200'
                  }`}>
                  Expand sidebar
                </span>
              )}
            </button>
          </div>
        </div>
      </aside>

      <ConfirmationModal
        isOpen={modal.isOpen}
        onClose={closeModal}
        onConfirm={modal.onConfirm}
        title={modal.title}
        message={modal.message}
        type={modal.type}
      />

      <SwitchRoleModal
        isOpen={isSwitchModalOpen}
        onClose={() => setIsSwitchModalOpen(false)}
        switchableRoles={switchableRoles}
        currentRoleId={user?.role_id}
        isSwitching={isSwitching}
        onSwitchRole={handleSwitchRole}
        userEmail={user?.email || ''}
        isDark={isDark}
      />
    </>
  );
};

export default Navigation;