// hint is shown under the field, outside the <label>, so it does not become part of the field's accessible name.
export default function FormField({ label, error, hint, children }) {
  return (
    <div>
      <label className="block text-sm">
        {label}
        <div className="mt-1">{children}</div>
        {error && <span role="alert" className="mt-1 block text-red-600">{error}</span>}
      </label>
      {hint && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
    </div>
  )
}
