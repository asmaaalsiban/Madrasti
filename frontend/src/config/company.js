// Who runs the platform: shown in the legal pages and the footer. Set these in frontend/.env (VITE_COMPANY_*) before going live;
// a missing value is simply not shown (never a made-up one).
const env = import.meta.env
export const company = {
  name: env.VITE_COMPANY_NAME || 'Madrasati',
  legalName: env.VITE_COMPANY_LEGAL_NAME || '',
  registration: env.VITE_COMPANY_REGISTRATION || '', // commercial registration / tax number
  address: env.VITE_COMPANY_ADDRESS || '',
  email: env.VITE_COMPANY_EMAIL || '',
  phone: env.VITE_COMPANY_PHONE || '',
  privacyEmail: env.VITE_COMPANY_PRIVACY_EMAIL || env.VITE_COMPANY_EMAIL || '',
  country: env.VITE_COMPANY_COUNTRY || '',
  // The legal texts are drafts until a lawyer has reviewed them: set VITE_LEGAL_DRAFT=false then.
  draft: env.VITE_LEGAL_DRAFT !== 'false',
}
