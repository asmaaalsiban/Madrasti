import AuthShell from '../../components/shared/AuthShell'

// Header + container shared by the pages of the central (no-school) site.
export default function CentralShell({ children }) {
  return <AuthShell width="max-w-md">{children}</AuthShell>
}
