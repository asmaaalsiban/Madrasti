export default function FormField({ label, error, children }) {
  return (
    <label className="block text-sm">
      {label}
      <div className="mt-1">{children}</div>
      {error && <span role="alert" className="mt-1 block text-red-600">{error}</span>}
    </label>
  )
}
