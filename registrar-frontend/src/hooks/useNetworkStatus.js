import { useEffect, useState } from "react";

const getOnlineStatus = () =>
  typeof navigator === "undefined" ? true : navigator.onLine;

export default function useNetworkStatus() {
  const [isOnline, setIsOnline] = useState(getOnlineStatus);

  useEffect(() => {
    const handleOnline = () => setIsOnline(true);
    const handleOffline = () => setIsOnline(false);
    const handleNetworkError = () => setIsOnline(false);

    window.addEventListener("online", handleOnline);
    window.addEventListener("offline", handleOffline);
    window.addEventListener("ris:network-error", handleNetworkError);

    return () => {
      window.removeEventListener("online", handleOnline);
      window.removeEventListener("offline", handleOffline);
      window.removeEventListener("ris:network-error", handleNetworkError);
    };
  }, []);

  const retry = () => {
    if (getOnlineStatus()) {
      setIsOnline(true);
      return;
    }

    window.dispatchEvent(new Event("offline"));
  };

  return { isOnline, retry };
}
