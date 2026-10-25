// An amount with its currency, always left-to-right so the digits never flip in Arabic.
export default function Money({ value, currency }) {
  return <span dir="ltr" className="whitespace-nowrap">{Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} {currency}</span>
}
