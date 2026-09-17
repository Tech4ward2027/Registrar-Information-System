import React, { useState, useEffect } from 'react';
import FieldGroup from '../components/FieldGroup'; 
import { UserIcon } from '@heroicons/react/24/solid';
import ConfirmationModal from '../components/ConfirmationModal';
import { useAuth } from '../context/AuthProvider';
import { useTheme } from '../context/ThemeContext';

const ROLE_CONFIG = {
  student: {
    sectionTitle: "Student Information",
    idLabel: "Student Number ID #",
    showId: true
  },
  admin: {
    sectionTitle: "Admin Information",
    showId: false
  },
  superAdmin: {
    sectionTitle: "Super Admin Information",
    showId: false
  },
  alumni: {
    sectionTitle: "Alumni Information",
    showId: false
  }
};

const capitalizeWord = (word) => {
  if (!word) return "";

  return word
    .split("-")
    .map((part) => (part ? part.charAt(0).toUpperCase() + part.slice(1).toLowerCase() : ""))
    .join("-");
};

const capitalizeName = (value) => {
  if (!value) return "";

  return String(value)
    .trim()
    .split(/\s+/)
    .map(capitalizeWord)
    .join(" ");
};

const formatSuffix = (value) => {
  const normalized = capitalizeName(value);
  if (!normalized) return "";

  return normalized
    .split(" ")
    .map((part) => (/^(i|ii|iii|iv|v|vi|vii|viii|ix|x)$/i.test(part) ? part.toUpperCase() : part))
    .join(" ");
};

const ProfilePage = ({ userType = "student" }) => {

  const { user } = useAuth();
  const effectiveUserType = (user?.role_id === 4 || userType === "superAdmin") ? "superAdmin" : userType;
  const config = ROLE_CONFIG[effectiveUserType] || ROLE_CONFIG[userType] || ROLE_CONFIG.student;

  const { isDark } = useTheme();

  const [profileData, setProfileData] = useState({
    firstName: "",
    middleName: "",
    lastName: "",
    suffix: "",
    studentId: "",
    email: ""
  });

  useEffect(() => {
    if (!user) return;

    if (user.role_id === 1 && user.student_profile) {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setProfileData({
        firstName: user.student_profile.first_name || "",
        middleName: user.student_profile.middle_name || "",
        lastName: user.student_profile.last_name || "",
        suffix: user.student_profile.suffix || "",
        studentId: user.academic_record.student_number || "",
        email: user.email || ""
      });
    }

    else if ((user.role_id === 3 || user.role_id === 4) && user.admin_profile) {
      setProfileData({
        firstName: user.admin_profile.first_name || "",
        middleName: user.admin_profile.middle_name || "",
        lastName: user.admin_profile.last_name || "",
        suffix: user.admin_profile.suffix || "",
        email: user.email || ""
      });
    }

    else if (user.role_id === 2 && user.alumni_profile) {
      setProfileData({
        firstName: user.alumni_profile.first_name || "",
        middleName: user.alumni_profile.middle_name || "",
        lastName: user.alumni_profile.last_name || "",
        suffix: user.alumni_profile.suffix || "",
        email: user.email || ""
      });
    }

    else if (user.role_id === 5 && user.undergrad_requestor_profile) {
      setProfileData({
        firstName: user.undergrad_requestor_profile.first_name || "",
        middleName: user.undergrad_requestor_profile.middle_name || "",
        lastName: user.undergrad_requestor_profile.last_name || "",
        suffix: user.undergrad_requestor_profile.suffix || "",
        email: user.email || ""
      });
    }

    else {
      const parts = (user.name || user.email?.split('@')[0] || 'Super Admin').split(' ');
      setProfileData({
        firstName: user.first_name || parts[0] || "Super",
        middleName: user.middle_name || (parts.length > 2 ? parts[1] : ""),
        lastName: user.last_name || (parts.length > 1 ? parts[parts.length - 1] : "Admin"),
        suffix: user.suffix || "",
        email: user.email || ""
      });
    }

  }, [user]);

  const [modal, setModal] = useState({
    isOpen: false,
    title: '',
    message: '',
    type: 'default',
    onConfirm: () => {},
  });

  const closeModal = () => {
    setModal(prev => ({ ...prev, isOpen: false }));
  };

  const displayProfile = {
    firstName: capitalizeName(profileData.firstName),
    middleName: capitalizeName(profileData.middleName),
    lastName: capitalizeName(profileData.lastName),
    suffix: formatSuffix(profileData.suffix),
  };


  return (
    <div className="min-h-[calc(100vh-12rem)] flex items-start justify-center font-sans">
      <div className={`w-full max-w-6xl shadow-2xl overflow-hidden flex flex-col relative rounded-sm min-h-140 ${isDark ? 'bg-[#18191a] border border-[#3e4042]' : 'bg-pup-dark-maroon'}`}>
        
        <div className="h-3 w-full bg-[#eebc48]"></div>

        {/* --- HEADER --- */}
        <div className="px-8 py-10">
          <div className="flex flex-col md:flex-row items-start md:items-center gap-6 flex-1">
            <div className="flex flex-col md:flex-row items-center md:items-start gap-6 w-full">
              <div className={`w-24 h-24 rounded-full flex items-center justify-center shrink-0 overflow-hidden border-2 ${isDark ? 'bg-[#1a1b1e] border-[#3e4042]' : 'bg-[#222222] border-[#4a1010]'}`}>
                <UserIcon className={`${isDark ? 'text-[#e4e6eb]' : 'text-gray-400'} h-16 w-16 translate-y-2`} />
              </div>

              <div className={`${isDark ? 'text-[#e4e6eb]' : 'text-white'} space-y-1 mt-2 text-center md:text-left`}>
                <h2 className="text-3xl font-bold tracking-wide flex flex-wrap gap-3 justify-center md:justify-start">
                  <span>{displayProfile.firstName}</span>
                  <span>{displayProfile.middleName}</span>
                  <span>{displayProfile.lastName}</span>
                  {displayProfile.suffix && <span>{displayProfile.suffix}</span>}
                </h2>
                {/* Dynamic ID Display */}
                <p className="text-sm font-medium opacity-90">{profileData.studentId}</p>
                <p className="text-sm font-medium opacity-90">{profileData.email}</p>
                
                {/* Role Badge */}
                <span className="inline-block px-2.5 py-0.5 rounded bg-[#eebc48] text-[#4a1010] text-xs font-bold uppercase tracking-wider mt-1">
                  {effectiveUserType === "superAdmin" ? "Super Admin" : effectiveUserType}
                </span>
              </div>
            </div>

          </div>
        </div>

        <div className="w-full px-8">
            <div className={`h-0.5 w-full ${isDark ? 'bg-white/10' : 'bg-white'}`}></div>
        </div>

        <div className="p-8 grow flex flex-col">
          <h3 className={`text-3xl font-bold text-center mb-8 tracking-wide uppercase ${isDark ? 'text-[#e4e6eb]' : 'text-white'}`}>
            {config.sectionTitle}
          </h3>

          <div className="space-y-6">
            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
              
              <FieldGroup 
                label="First Name" 
                name="firstName" 
                value={displayProfile.firstName} 
                isEditing={false} 
                required
              />
              <FieldGroup 
                label="Middle Name" 
                name="middleName" 
                value={displayProfile.middleName} 
                isEditing={false} 
                required
              />
              <FieldGroup 
                label="Last Name" 
                name="lastName" 
                value={displayProfile.lastName} 
                isEditing={false} 
                required
              />
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-6 items-end">
              
              {/* DYNAMIC FIELD: Label and Validation change based on 'userType' */}
              {config.showId && (
                <FieldGroup 
                  label={config.idLabel} 
                  name="studentId" 
                  value={profileData.studentId} 
                  isEditing={false} 
                  required
                />
              )}
              
              <FieldGroup 
                label="Email Address" 
                name="email" 
                value={profileData.email} 
                isEditing={false} 
                type="email"
                pattern="[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$"
                title="Please enter a valid email address"
                required
              />
              {displayProfile.suffix && (
                <FieldGroup 
                  label="Suffix" 
                  name="suffix" 
                  value={displayProfile.suffix} 
                  isEditing={false} 
                  placeholder="e.g. Jr., Sr., III (Optional)"
                  type="text"
                />
              )}
            </div>
          </div>
        </div>
          <ConfirmationModal
            isOpen={modal.isOpen}
            onClose={closeModal}
            onConfirm={modal.onConfirm}
            title={modal.title}
            message={modal.message}
            type={modal.type}
          />
      </div>
    </div>
  );
};

export default ProfilePage;