import { useEffect, useRef, useState, useCallback } from "react";
import { getEcho } from "../services/echo";
import { useAuth } from "../context/AuthProvider";

/**
 * Countdown timer hook for HTTP 429 Retry-After responses.
 */
export const useRetryAfterCooldown = () => {
  const [secondsLeft, setSecondsLeft] = useState(0);

  const startCooldown = useCallback((seconds) => {
    const parsed = Number.parseInt(seconds, 10);
    if (Number.isFinite(parsed) && parsed > 0) {
      setSecondsLeft(parsed);
    } else {
      setSecondsLeft(15);
    }
  }, []);

  useEffect(() => {
    if (secondsLeft <= 0) return undefined;
    const timer = setInterval(() => {
      setSecondsLeft((prev) => (prev <= 1 ? 0 : prev - 1));
    }, 1000);
    return () => clearInterval(timer);
  }, [secondsLeft]);

  const clearCooldown = useCallback(() => setSecondsLeft(0), []);

  return {
    secondsLeft,
    isCoolingDown: secondsLeft > 0,
    startCooldown,
    clearCooldown,
  };
};

/**
 * Subscribes to Laravel Reverb private ticket channels (`tickets.staff` and/or `ticket.{publicId}`)
 * and invokes `onTicketUpdated(payload)` whenever `.TicketUpdated` broadcasts.
 *
 * Uses the same safe connection listener cleanup pattern as `useNotifications.js`
 * so Pusher's internal reconnect listeners are never unbound.
 */
export const useTicketLiveUpdates = ({
  staffQueue = false,
  ticketId = null,
  enabled = true,
  onTicketUpdated,
}) => {
  const { user } = useAuth();
  const callbackRef = useRef(onTicketUpdated);

  useEffect(() => {
    callbackRef.current = onTicketUpdated;
  }, [onTicketUpdated]);

  useEffect(() => {
    if (!enabled || !user) return undefined;
    if (!staffQueue && !ticketId) return undefined;

    const echo = getEcho();
    if (!echo) return undefined;

    const channels = [];
    if (staffQueue) channels.push("tickets.staff");
    if (ticketId) channels.push(`ticket.${ticketId}`);

    let unsubscribed = false;

    const handleEvent = (payload) => {
      if (!unsubscribed && typeof callbackRef.current === "function") {
        callbackRef.current(payload);
      }
    };

    const subscribeAll = () => {
      channels.forEach((channelName) => {
        echo
          .private(channelName)
          .listen(".TicketUpdated", handleEvent)
          .error(() => {
            // Silently ignore if current policy lacks channel authorization
          });
      });
    };

    const handleStateChange = ({ current, previous }) => {
      if (current === "connected" && previous === "disconnected" && !unsubscribed) {
        if (typeof callbackRef.current === "function") {
          callbackRef.current({ reconnected: true });
        }
      }
    };

    echo.connector.pusher.connection.bind("state_change", handleStateChange);

    let pendingConnectedHandler = null;
    if (echo.connector.pusher.connection.state === "connected") {
      subscribeAll();
    } else {
      pendingConnectedHandler = () => {
        echo.connector.pusher.connection.unbind("connected", pendingConnectedHandler);
        pendingConnectedHandler = null;
        if (!unsubscribed) subscribeAll();
      };
      echo.connector.pusher.connection.bind("connected", pendingConnectedHandler);
    }

    return () => {
      unsubscribed = true;
      echo.connector.pusher.connection.unbind("state_change", handleStateChange);
      if (pendingConnectedHandler) {
        echo.connector.pusher.connection.unbind("connected", pendingConnectedHandler);
        pendingConnectedHandler = null;
      }
      channels.forEach((channelName) => {
        try {
          echo.private(channelName).stopListening(".TicketUpdated", handleEvent);
          // Only leave ticket.{id} completely if not shared, or leave channel cleanly
          echo.leave(channelName);
        } catch {
          // ignore teardown errors
        }
      });
    };
  }, [enabled, user, staffQueue, ticketId]);
};
