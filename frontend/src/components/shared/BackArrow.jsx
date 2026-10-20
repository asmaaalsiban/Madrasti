// "Back" arrow that points the right way in both directions (left in LTR, right in RTL).
export default function BackArrow() {
  return <span aria-hidden="true" className="inline-block rtl:rotate-180">←</span>
}
