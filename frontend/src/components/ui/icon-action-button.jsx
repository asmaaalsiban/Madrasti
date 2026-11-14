// A row action (edit, delete, reset password...) or a link as an icon. On a desktop with a mouse it is a round icon button that fills with its colour
// on hover (CSS .ihb-icon in index.css, same effect as InteractiveHoverButton); the name stays for screen readers and as a tooltip. On a phone the
// icon is shown with its name. tone: 'brand' | 'slate' | 'red'. `as` makes it a link: as={Link} to="..." or as="a" href="...".
export function IconActionButton({ as: Tag = 'button', icon: Icon, label, tone = 'brand', className = '', ...props }) {
  return (
    <Tag {...(Tag === 'button' ? { type: 'button' } : {})} title={label} className={`ihb ihb-plain ihb-icon ihb-${tone} ${className}`} {...props}>
      <span className="ihb-text"><Icon size={18} aria-hidden /><span className="ihb-label">{label}</span></span>
      <span aria-hidden className="ihb-dot" />
    </Tag>
  )
}
export default IconActionButton
