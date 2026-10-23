/**
 * requestRef — the identifier the API expects for a document request.
 *
 * The backend addresses a request by its `public_id` (a ULID), not the
 * sequential integer `request_id`. Every API call that puts a request in a
 * URL should take its id from here, so there is exactly one place that
 * decides which identifier goes over the wire.
 *
 * Accepts any shape a request travels in:
 *   - a raw API request            ({ public_id, request_id, ... })
 *   - a mapped dashboard request   ({ id, publicId, rawRequest, ... })
 *
 * Fallback: while the backend's expand/contract switch
 * (PUBLIC_IDS_ACCEPT_LEGACY_NUMERIC) is on, the numeric request_id still
 * resolves, so a payload from an older deploy that has no public_id keeps
 * working. Once that switch is off, a numeric fallback answers 404, which is
 * the correct failure: it means the payload is missing public_id.
 *
 * Do NOT use this for the local React keys (selection, expanded rows,
 * "updating" spinners). Those stay on the numeric id; this is only for what
 * is sent to the server.
 *
 * @param {object|null|undefined} request
 * @returns {string|number|null}
 */
export const getRequestRef = (request) => {
  if (!request) return null;
  const raw = request.rawRequest ?? null;

  return (
    request.public_id
    ?? request.publicId
    ?? raw?.public_id
    ?? request.request_id
    ?? raw?.request_id
    ?? null
  );
};
