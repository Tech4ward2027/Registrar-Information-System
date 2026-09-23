import { useState, useEffect, useMemo, useRef } from "react";
import {
  getCertifications,
  getCertificationLayouts,
  updateCertificationLayout,
  uploadCertificationLayoutLogo,
} from "../services/api";
import {
  CERT_TEMPLATE_LAYOUT_CHANGED,
  DEFAULT_CERTIFICATE_LAYOUT,
  normalizeCertificateLayout,
  toLayoutPayload,
} from "../utils/certificateTemplateSettings.js";
import {
  toCertificateRows,
  toDataUrl,
  hasPreviewDataUrl,
  validateFile,
} from "../utils/certificateTemplateUtils.js";

export const useCertificateTemplates = () => {
  const [certifications, setCertifications] = useState([]);
  const [layoutsByCertId, setLayoutsByCertId] = useState({});
  const [selectedCertId, setSelectedCertId] = useState("");
  const [layout, setLayout] = useState({ ...DEFAULT_CERTIFICATE_LAYOUT });
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const autoSaveTimerRef = useRef(null);
  const [saveSuccess, setSaveSuccess] = useState(false);
  const [successMessage, setSuccessMessage] = useState("");
  const [errorMessage, setErrorMessage] = useState("");

  const [past, setPast] = useState([]);
  const [future, setFuture] = useState([]);
  const isUndoRedoActionRef = useRef(false);

  const pushToHistory = (currentLayout) => {
    const layoutSnapshot = {
      headerLeftUrl: currentLayout.headerLeftUrl,
      headerRightUrl: currentLayout.headerRightUrl,
      footerUrls: [...(currentLayout.footerUrls || [])],
      headerLogoSize: currentLayout.headerLogoSize,
      footerLogoSize: currentLayout.footerLogoSize,
      headerFontSize: currentLayout.headerFontSize,
      headerLines: [...(currentLayout.headerLines || [])],
    };
    setPast((prev) => [...prev, layoutSnapshot]);
    setFuture([]);
  };

  useEffect(() => {
    setPast([]);
    setFuture([]);
    isUndoRedoActionRef.current = false;
  }, [selectedCertId]);

  // Bulk apply checkbox states
  const [applyMainLogoToAll, setApplyMainLogoToAll] = useState(false);
  const [applyRightLogoToAll, setApplyRightLogoToAll] = useState(false);
  const [applyFooterLogosToAll, setApplyFooterLogosToAll] = useState(false);

  // Modal confirm state
  const [isResetConfirmOpen, setIsResetConfirmOpen] = useState(false);

  const dropdownCertifications = useMemo(() => {
    const byName = new Map();
    certifications.forEach((item) => {
      if (!item?.certificate_name) return;
      byName.set(item.certificate_name, item);
    });
    return Array.from(byName.values());
  }, [certifications]);

  const selectedCertification = useMemo(
    () => dropdownCertifications.find((item) => String(item.certificate_type_id) === selectedCertId),
    [dropdownCertifications, selectedCertId]
  );

  const isLockedCertification = Boolean(selectedCertification?.is_archived);

  const certificateOptions = useMemo(
    () => dropdownCertifications.map((item) => item.certificate_name),
    [dropdownCertifications]
  );

  const selectedCertificateName = selectedCertification?.certificate_name ?? "";
  const isPersistedCertification = Boolean(selectedCertification);

  // Mount logic
  useEffect(() => {
    const load = async () => {
      try {
        setLoading(true);
        const [certResult, layoutResult] = await Promise.allSettled([
          getCertifications(),
          getCertificationLayouts(),
        ]);
        const certRows = certResult.status === "fulfilled" ? toCertificateRows(certResult.value?.data) : [];
        const layoutRows = layoutResult.status === "fulfilled" ? toCertificateRows(layoutResult.value?.data) : [];

        const layoutMap = {};
        layoutRows.forEach((row) => {
          layoutMap[row.certificate_type_id] = normalizeCertificateLayout(row);
        });

        setCertifications(certRows);
        setLayoutsByCertId(layoutMap);

        if (certRows.length) {
          const firstCertId = String(certRows[0].certificate_type_id);
          setSelectedCertId(firstCertId);
          setLayout(layoutMap[certRows[0].certificate_type_id] ?? { ...DEFAULT_CERTIFICATE_LAYOUT });
        } else {
          setSelectedCertId("");
          setLayout({ ...DEFAULT_CERTIFICATE_LAYOUT });
        }
      } catch (error) {
        console.error("Failed to load certification layouts:", error);
      } finally {
        setLoading(false);
      }
    };

    load();
  }, []);

  // Auto-save logic
  useEffect(() => {
    if (!selectedCertId || !isPersistedCertification || loading || saving || isLockedCertification) return;
    if (hasPreviewDataUrl(layout)) return;

    if (isUndoRedoActionRef.current) {
      isUndoRedoActionRef.current = false;
      return;
    }

    // Access by number/string key in case backend/frontend mismatch occurs
    const savedLayout = layoutsByCertId[selectedCertId] ?? layoutsByCertId[Number(selectedCertId)];
    if (savedLayout && JSON.stringify(layout) === JSON.stringify(savedLayout)) {
      return;
    }

    if (autoSaveTimerRef.current) {
      clearTimeout(autoSaveTimerRef.current);
    }

    autoSaveTimerRef.current = setTimeout(async () => {
      try {
        const payload = toLayoutPayload(layout);
        await updateCertificationLayout(selectedCertId, payload);
        setLayoutsByCertId((prev) => ({
          ...prev,
          [selectedCertId]: normalizeCertificateLayout(layout),
        }));
      } catch (error) {
        console.error("Auto-save layout failed:", error);
      }
    }, 600);

    return () => {
      if (autoSaveTimerRef.current) {
        clearTimeout(autoSaveTimerRef.current);
      }
    };
  }, [layout, selectedCertId, isPersistedCertification, loading, saving, layoutsByCertId, isLockedCertification]);

  const handleCertChange = (event) => {
    const nextName = event.target.value;
    const nextCertification = dropdownCertifications.find((item) => item.certificate_name === nextName);
    const nextId = nextCertification ? String(nextCertification.certificate_type_id) : "";

    setSelectedCertId(nextId);
    setLayout(layoutsByCertId[nextId] ?? layoutsByCertId[Number(nextId)] ?? { ...DEFAULT_CERTIFICATE_LAYOUT });
  };

  const uploadSingleLogo = async (slot, file) => {
    if (!selectedCertId || !file) return;
    const certTypeId = selectedCertId;

    const formData = new FormData();
    formData.append("logo", file);
    formData.append("slot", slot);
    const response = await uploadCertificationLayoutLogo(certTypeId, formData);
    return response?.data?.data?.url;
  };

  const updateMainLogo = async (files) => {
    const [file] = files;
    if (!file) return;
    const error = validateFile(file);
    if (error) {
      setErrorMessage(error);
      return;
    }
    const previousUrl = layout.headerLeftUrl;
    try {
      const previewUrl = await toDataUrl(file);
      setLayout((prev) => ({ ...prev, headerLeftUrl: previewUrl }));

      const logoUrl = await uploadSingleLogo("header_left", file);
      if (logoUrl) {
        pushToHistory(layout);
        setLayout((prev) => ({ ...prev, headerLeftUrl: logoUrl }));

        if (applyMainLogoToAll) {
          setSaving(true);
          try {
            const otherCerts = dropdownCertifications.filter(c => String(c.certificate_type_id) !== selectedCertId);
            const updatedLayouts = {};
            for (const cert of otherCerts) {
              const certId = cert.certificate_type_id;
              const currentLayout = layoutsByCertId[certId] ?? { ...DEFAULT_CERTIFICATE_LAYOUT };
              const updatedLayout = { ...currentLayout, headerLeftUrl: logoUrl };
              const payload = toLayoutPayload(updatedLayout);
              await updateCertificationLayout(certId, payload);
              updatedLayouts[certId] = normalizeCertificateLayout(updatedLayout);
            }
            setLayoutsByCertId((prev) => ({
              ...prev,
              ...updatedLayouts,
            }));
            setSaveSuccess(true);
            setSuccessMessage("Main logo applied to all templates successfully!");
            setTimeout(() => {
              setSaveSuccess(false);
              setSuccessMessage("");
            }, 2000);
          } catch (err) {
            console.error("Failed to apply main logo to all templates:", err);
            setErrorMessage("Logo uploaded, but failed to apply to all certificate templates.");
          } finally {
            setSaving(false);
          }
        }
      } else {
        setLayout((prev) => ({ ...prev, headerLeftUrl: previousUrl }));
        setErrorMessage("Failed to upload main logo. Please try again.");
      }
    } catch (error) {
      console.error("Failed to upload main logo:", error);
      setLayout((prev) => ({ ...prev, headerLeftUrl: previousUrl }));
      setErrorMessage("Failed to upload main logo. Please try again.");
    }
  };

  const updateRightLogo = async (files) => {
    const [file] = files;
    if (!file) return;
    const error = validateFile(file);
    if (error) {
      setErrorMessage(error);
      return;
    }
    const previousUrl = layout.headerRightUrl;
    try {
      const previewUrl = await toDataUrl(file);
      setLayout((prev) => ({ ...prev, headerRightUrl: previewUrl }));

      const logoUrl = await uploadSingleLogo("header_right", file);
      if (logoUrl) {
        pushToHistory(layout);
        setLayout((prev) => ({ ...prev, headerRightUrl: logoUrl }));

        if (applyRightLogoToAll) {
          setSaving(true);
          try {
            const otherCerts = dropdownCertifications.filter(c => String(c.certificate_type_id) !== selectedCertId);
            const updatedLayouts = {};
            for (const cert of otherCerts) {
              const certId = cert.certificate_type_id;
              const currentLayout = layoutsByCertId[certId] ?? { ...DEFAULT_CERTIFICATE_LAYOUT };
              const updatedLayout = { ...currentLayout, headerRightUrl: logoUrl };
              const payload = toLayoutPayload(updatedLayout);
              await updateCertificationLayout(certId, payload);
              updatedLayouts[certId] = normalizeCertificateLayout(updatedLayout);
            }
            setLayoutsByCertId((prev) => ({
              ...prev,
              ...updatedLayouts,
            }));
            setSaveSuccess(true);
            setSuccessMessage("Right logo applied to all templates successfully!");
            setTimeout(() => {
              setSaveSuccess(false);
              setSuccessMessage("");
            }, 2000);
          } catch (err) {
            console.error("Failed to apply right logo to all templates:", err);
            setErrorMessage("Logo uploaded, but failed to apply to all certificate templates.");
          } finally {
            setSaving(false);
          }
        }
      } else {
        setLayout((prev) => ({ ...prev, headerRightUrl: previousUrl }));
        setErrorMessage("Failed to upload right logo. Please try again.");
      }
    } catch (error) {
      console.error("Failed to upload right logo:", error);
      setLayout((prev) => ({ ...prev, headerRightUrl: previousUrl }));
      setErrorMessage("Failed to upload right logo. Please try again.");
    }
  };

  const addFooterLogos = async (files) => {
    if (!selectedCertId || !files.length) return;
    const invalidFile = files.find(file => validateFile(file));
    if (invalidFile) {
      const error = validateFile(invalidFile);
      setErrorMessage(error);
      return;
    }
    const previousFooterUrls = [...layout.footerUrls];
    try {
      const previewUrls = await Promise.all(files.map((file) => toDataUrl(file)));
      setLayout((prev) => ({
        ...prev,
        footerUrls: [...prev.footerUrls, ...previewUrls],
      }));

      const uploadedUrls = await Promise.all(files.map(async (file) => {
        try {
          return await uploadSingleLogo("footer", file);
        } catch {
          return null;
        }
      }));

      const allSuccess = uploadedUrls.every(url => !!url);
      if (allSuccess) {
        pushToHistory(layout);
        let finalFooterUrls = [];
        setLayout((prev) => {
          const updated = prev.footerUrls.map((url) => {
            const index = previewUrls.indexOf(url);
            return index !== -1 ? uploadedUrls[index] : url;
          });
          finalFooterUrls = updated;
          return { ...prev, footerUrls: updated };
        });

        if (applyFooterLogosToAll) {
          setSaving(true);
          try {
            const otherCerts = dropdownCertifications.filter(c => String(c.certificate_type_id) !== selectedCertId);
            const updatedLayouts = {};
            for (const cert of otherCerts) {
              const certId = cert.certificate_type_id;
              const currentLayout = layoutsByCertId[certId] ?? { ...DEFAULT_CERTIFICATE_LAYOUT };
              const updatedLayout = { ...currentLayout, footerUrls: finalFooterUrls };
              const payload = toLayoutPayload(updatedLayout);
              await updateCertificationLayout(certId, payload);
              updatedLayouts[certId] = normalizeCertificateLayout(updatedLayout);
            }
            setLayoutsByCertId((prev) => ({
              ...prev,
              ...updatedLayouts,
            }));
            setSaveSuccess(true);
            setSuccessMessage("Footer logos applied to all templates successfully!");
            setTimeout(() => {
              setSaveSuccess(false);
              setSuccessMessage("");
            }, 2000);
          } catch (err) {
            console.error("Failed to apply footer logos to all templates:", err);
            setErrorMessage("Logos uploaded, but failed to apply to all certificate templates.");
          } finally {
            setSaving(false);
          }
        }
      } else {
        setLayout((prev) => ({
          ...prev,
          footerUrls: previousFooterUrls,
        }));
        setErrorMessage("Failed to upload one or more footer logos. Please try again.");
      }
    } catch (error) {
      console.error("Failed to upload footer logos:", error);
      setLayout((prev) => ({
        ...prev,
        footerUrls: previousFooterUrls,
      }));
      setErrorMessage("Failed to upload footer logos. Please try again.");
    }
  };

  const removeFooterLogo = (index) => {
    pushToHistory(layout);
    setLayout((prev) => ({
      ...prev,
      footerUrls: prev.footerUrls.filter((_, i) => i !== index),
    }));
  };

  const saveLayout = async () => {
    if (!selectedCertId) return;
    if (isLockedCertification) {
      setErrorMessage("This certificate is archived — its template is read-only. Restore it first to make changes.");
      return;
    }
    if (hasPreviewDataUrl(layout)) {
      console.warn("Skipping layout save until image uploads finish.");
      return;
    }

    try {
      setSaving(true);
      setSaveSuccess(false);
      const certTypeId = selectedCertId;

      const payload = toLayoutPayload(layout);
      const response = await updateCertificationLayout(certTypeId, payload);

      const freshDbData = response?.data?.data;
      const freshLayout = freshDbData
        ? normalizeCertificateLayout(freshDbData)
        : normalizeCertificateLayout(layout);

      setLayoutsByCertId((prev) => ({
        ...prev,
        [certTypeId]: freshLayout,
      }));

      setLayout(freshLayout);

      window.dispatchEvent(
        new CustomEvent(CERT_TEMPLATE_LAYOUT_CHANGED, {
          detail: {
            certTypeId: Number(certTypeId),
            layout: freshLayout,
          },
        })
      );

      setSaveSuccess(true);
      setSuccessMessage("Layout saved successfully!");
      setTimeout(() => {
        setSaveSuccess(false);
        setSuccessMessage("");
      }, 2000);
    } catch (error) {
      console.error("Failed to save certification layout:", error);
    } finally {
      setSaving(false);
    }
  };

  const resetLayout = () => {
    pushToHistory(layout);
    setLayout({ ...DEFAULT_CERTIFICATE_LAYOUT });
    setSuccessMessage("Layout reset to defaults.");
    setTimeout(() => {
      setSuccessMessage("");
    }, 3000);
  };

  const undo = () => {
    if (past.length === 0) return;

    const previousState = past[past.length - 1];
    const newPast = past.slice(0, -1);

    const currentState = {
      headerLeftUrl: layout.headerLeftUrl,
      headerRightUrl: layout.headerRightUrl,
      footerUrls: [...(layout.footerUrls || [])],
      headerLogoSize: layout.headerLogoSize,
      footerLogoSize: layout.footerLogoSize,
      headerFontSize: layout.headerFontSize,
      headerLines: [...(layout.headerLines || [])],
    };

    setFuture((prevFuture) => [currentState, ...prevFuture]);
    setPast(newPast);

    isUndoRedoActionRef.current = true;
    setLayout((prev) => ({
      ...prev,
      ...previousState,
    }));
    setSaveSuccess(false);
  };

  const redo = () => {
    if (future.length === 0) return;

    const nextState = future[0];
    const newFuture = future.slice(1);

    const currentState = {
      headerLeftUrl: layout.headerLeftUrl,
      headerRightUrl: layout.headerRightUrl,
      footerUrls: [...(layout.footerUrls || [])],
      headerLogoSize: layout.headerLogoSize,
      footerLogoSize: layout.footerLogoSize,
      headerFontSize: layout.headerFontSize,
      headerLines: [...(layout.headerLines || [])],
    };

    setPast((prevPast) => [...prevPast, currentState]);
    setFuture(newFuture);

    isUndoRedoActionRef.current = true;
    setLayout((prev) => ({
      ...prev,
      ...nextState,
    }));
    setSaveSuccess(false);
  };

  const resetAllLayouts = async () => {
    try {
      setSaving(true);
      setSaveSuccess(false);

      const defaultLayout = { ...DEFAULT_CERTIFICATE_LAYOUT };
      const payload = toLayoutPayload(defaultLayout);

      await Promise.all(
        dropdownCertifications.map(async (cert) => {
          const certId = cert.certificate_type_id;
          await updateCertificationLayout(certId, payload);
          setLayoutsByCertId((prev) => ({
            ...prev,
            [certId]: normalizeCertificateLayout(defaultLayout),
          }));
        })
      );

      setLayout(defaultLayout);
      setSuccessMessage("All certificate layouts reset to defaults.");
      setTimeout(() => {
        setSuccessMessage("");
      }, 3000);
    } catch (error) {
      console.error("Failed to reset all certificate layouts:", error);
      setErrorMessage("Failed to reset all layouts. Please try again.");
    } finally {
      setSaving(false);
    }
  };

  return {
    certifications,
    layoutsByCertId,
    selectedCertId,
    setSelectedCertId,
    layout,
    setLayout,
    loading,
    saving,
    saveSuccess,
    successMessage,
    setSuccessMessage,
    errorMessage,
    setErrorMessage,
    past,
    future,
    applyMainLogoToAll,
    setApplyMainLogoToAll,
    applyRightLogoToAll,
    setApplyRightLogoToAll,
    applyFooterLogosToAll,
    setApplyFooterLogosToAll,
    isResetConfirmOpen,
    setIsResetConfirmOpen,
    isLockedCertification,
    certificateOptions,
    selectedCertificateName,
    handleCertChange,
    updateMainLogo,
    updateRightLogo,
    addFooterLogos,
    saveLayout,
    resetLayout,
    undo,
    redo,
    resetAllLayouts,
    removeFooterLogo,
  };
};
