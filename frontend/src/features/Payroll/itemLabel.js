// The text of a payslip line: lines the system added from the commitment rules are written in the reader's language.
export const itemLabel = (t, item) => (item.auto_code ? t(`payroll.auto.${item.auto_code}.${item.kind}`, { score: item.detail }) : item.label)
