# Accounting module: roadmap

Goal: a full accounting application inside the platform (double entry), built in phases. Each phase ships working and tested.

| Phase | What | State |
|---|---|---|
| 1 | **Year-end carry-over**: unpaid balances move to the next year in installments; the old year closes; undo until a payment is taken | done |
| 2 | **Cash boxes and bank accounts**: balances, transfers between them, corrections, the book of each, bank reconciliation; every payment lands in one | done |
| 3 | **Expenses and suppliers**: bills, payments out of an account, categories, links to purchase requests and payroll | done |
| 4 | Chart of accounts and journal (next): every money event posts a balanced entry (debit = credit), manual entries, locked periods | |
| 5 | Reports: trial balance, income statement, balance sheet, account ledger, receivables ageing, export to Excel/PDF | |
| 6 | Budgets per year/department and variance; tax (VAT) if the country needs it | |

Notes
- We build the features from standard accounting practice; no code or design of another product is copied.
- Phase 1 is done the way an accountant does it (a non-cash "carried forward" entry closes the old fee, a new "carried" fee opens in the new year), so phases 4-5 can post it as `Dr Receivable (new year) / Cr Receivable (old year)` without changing the data.
