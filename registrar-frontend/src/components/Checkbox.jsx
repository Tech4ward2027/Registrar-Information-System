import React from 'react';
import PropTypes from 'prop-types';
import { useTheme } from '../context/ThemeContext';

const CheckboxItem = ({ text, name, checked, onChange, id, textColor, isDark: isDarkProp }) => {
  const { isDark: themeIsDark } = useTheme();
  const isDark = isDarkProp !== undefined ? isDarkProp : themeIsDark;
  const inputId = id || name;

  const textClass = textColor || (isDark ? 'text-[#e4e6eb]' : 'text-gray-900');
  const accentClass = 'accent-[#FFC72C]';

  return (
    <label
      htmlFor={inputId}
      className="flex items-start space-x-3 cursor-pointer select-none group"
    >
      <input
        id={inputId}
        type="checkbox"
        name={name}
        checked={checked}
        onChange={onChange}
        className={`mt-1 w-5 h-5 ${accentClass} cursor-pointer shrink-0 rounded transition-transform duration-150 active:scale-90`}
      />
      <span
        className={`text-xs sm:text-sm font-medium leading-relaxed transition-colors duration-150 group-hover:opacity-90 ${textClass}`}
      >
        {text}
      </span>
    </label>
  );
};

CheckboxItem.propTypes = {
  text: PropTypes.string.isRequired,
  name: PropTypes.string.isRequired,
  checked: PropTypes.bool.isRequired,
  onChange: PropTypes.func.isRequired,
  id: PropTypes.string,
  textColor: PropTypes.string,
  isDark: PropTypes.bool,
};

export default CheckboxItem;