import puplogoimage from "../assets/puplogoimage.png";
import bagongPilipinasLogo from "../assets/Bagong_Pilipinas_logo.png";
import certificateFooter from "../assets/certificate_footer.png";

export const CERT_TEMPLATE_LAYOUT_CHANGED = "certificate-template-layout-changed";

export const DEFAULT_CERTIFICATE_LAYOUT = {
	headerLeftUrl: puplogoimage,
	headerRightUrl: bagongPilipinasLogo,
	footerUrls: [certificateFooter],
	headerLogoSize: 56,
	footerLogoSize: 56,
	headerFontSize: 14,
	headerLines: [
		"REPUBLIC OF THE PHILIPPINES",
		"Polytechnic University of the Philippines",
		"OFFICE OF THE VICE PRESIDENT FOR CAMPUSES",
		"TAGUIG CAMPUS",
	],
};

const coerceSize = (value, fallback) => {
	const parsed = Number(value);
	return Number.isFinite(parsed) ? parsed : fallback;
};

const normalizeUrlArray = (value) => {
	if (!Array.isArray(value)) return [];
	return value.filter((item) => typeof item === "string" && item.trim().length > 0);
};

const normalizeStringArray = (value, fallback) => {
	if (!Array.isArray(value) || value.length === 0) return [...fallback];
	return value.map((item) => (typeof item === "string" ? item : String(item ?? "")));
};

const APP_URL = (import.meta.env.VITE_BACKEND_URL || "http://localhost:8000").replace(/\/$/, "");
const toAbsoluteUrl = (url, fallback) => {
  if (!url || typeof url !== "string" || !url.trim()) return fallback;

  // Already absolute — use as-is
  if (url.startsWith("http://") || url.startsWith("https://")) return url;

  // Vite/frontend asset — never persisted, use fallback
  if (
    url.startsWith("/assets/") ||
    url.startsWith("/src/") ||
    url.includes("/src/assets/")
  ) return fallback;

  // Strip ALL leading storage prefixes (handles "storage/...", "/storage/", "/storage//storage/")
  const pathBefore = url;
  const cleaned = url.replace(/^(\/?)storage\/+/i, "");

  const result = `${APP_URL}/storage/${cleaned}`;
  return result;
};

export const normalizeCertificateLayout = (rawLayout) => {
	if (!rawLayout || typeof rawLayout !== "object") {
		return { ...DEFAULT_CERTIFICATE_LAYOUT };
	}

	const rawLeft = rawLayout.layout_header_left_url ?? rawLayout.headerLeftUrl;
	const rawRight = rawLayout.layout_header_right_url ?? rawLayout.headerRightUrl;
	const rawFooter = rawLayout.layout_footer_urls ?? rawLayout.footerUrls;
	const rawHeaderLines = rawLayout.layout_header_lines ?? rawLayout.headerLines;
	const headerLeftUrl = toAbsoluteUrl(rawLeft, DEFAULT_CERTIFICATE_LAYOUT.headerLeftUrl);
	const headerRightUrl = toAbsoluteUrl(rawRight, DEFAULT_CERTIFICATE_LAYOUT.headerRightUrl);

	const footerUrls = normalizeUrlArray(rawFooter)
		.map((url) => toAbsoluteUrl(url, null))
		.filter(Boolean);

	return {
		headerLeftUrl,
		headerRightUrl,
		footerUrls: footerUrls.length ? footerUrls : [...DEFAULT_CERTIFICATE_LAYOUT.footerUrls],
		headerLogoSize: coerceSize(
			rawLayout.layout_header_logo_size ?? rawLayout.headerLogoSize,
			DEFAULT_CERTIFICATE_LAYOUT.headerLogoSize
		),
		footerLogoSize: coerceSize(
			rawLayout.layout_footer_logo_size ?? rawLayout.footerLogoSize,
			DEFAULT_CERTIFICATE_LAYOUT.footerLogoSize
		),
		headerFontSize: coerceSize(
			rawLayout.layout_header_font_size ?? rawLayout.headerFontSize,
			DEFAULT_CERTIFICATE_LAYOUT.headerFontSize
		),
		headerLines: normalizeStringArray(rawHeaderLines, DEFAULT_CERTIFICATE_LAYOUT.headerLines),
	};
};

// Returns true for URLs that are safe to persist to the backend:
//   /storage/...          — relative storage path (local disk)
//   https?://...          — absolute URL (could be S3 or full APP_URL)
// Returns false for display-only values that must NOT be saved:
//   data:...              — base64 preview blob (upload still in flight)
//   /assets/...           — Vite-bundled frontend asset (hash-named, not a storage file)
//   anything else falsy
const isPersistableUrl = (url) => {
	if (!url || typeof url !== "string") return false;
	if (url.startsWith("data:")) return false; // upload still in progress
	if (url.startsWith("/assets/")) return false; // Vite bundle — never persisted
	if (url.startsWith("/src/")) return false; // Vite dev path — never persisted
	if (url.includes("/src/assets/")) return false; // mangled frontend path
	if (/\/storage\/(\/storage\/)+/.test(url)) return false; // duplicated storage prefix
	return true;
};

const toStoragePathForPersistence = (url) => {
	if (!url || typeof url !== "string") return null;

	if (
		url.startsWith("/assets/") ||
		url.startsWith("/src/") ||
		url.includes("/src/assets/")
	) {
		return null;
	}

	if (url.startsWith("data:")) return null;

	let path = url;
	try {
		const parsed = new URL(url);
		path = parsed.pathname;
	} catch {
		// not an absolute URL, use as-is
	}

	path = path.replace(/^(\/?(storage\/)+)/i, "");

	if (!path) return null;
	return path;
};

export const toLayoutPayload = (layout) => {
	const normalized = normalizeCertificateLayout(layout);
	const headerLeftPath = toStoragePathForPersistence(normalized.headerLeftUrl);
	const headerRightPath = toStoragePathForPersistence(normalized.headerRightUrl);
	const footerPaths = normalized.footerUrls
		.map(toStoragePathForPersistence)
		.filter(Boolean);

	return {
		// Send null for display-only defaults so the backend stores null and
		// serves the frontend's own bundled fallback — never store /assets/ paths.
		layout_header_left_url: headerLeftPath,
		layout_header_right_url: headerRightPath,
		layout_footer_urls: footerPaths,
		layout_header_logo_size: normalized.headerLogoSize,
		layout_footer_logo_size: normalized.footerLogoSize,
		layout_header_font_size: normalized.headerFontSize,
		layout_header_lines: normalized.headerLines,
	};
};
