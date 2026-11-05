# Import sample files

Ready-made Excel files to try the import screen (`/admin/import`) on a TEST school. They depend on each other, so upload them in this order:

1. `1_الصفوف.xlsx` (grades)
2. `2_المواد.xlsx` (subjects)
3. `3_الموظفون.xlsx` (staff)
4. `4_الشعب.xlsx` (sections)
5. `5_توزيع_المعلمين.xlsx` (teaching)
6. `6_الطلاب.xlsx` (students with their parents)
7. `7_العلامات.xlsx` (marks)
8. `8_عقود_الموظفين.xlsx` (staff contracts and salaries; after the staff file)
9. `7ب_العلامات_فيها_أخطاء_للتجربة.xlsx`: 5 of its 6 rows have a deliberate mistake, to see how errors are reported.

Before the marks, the school needs a current academic year named `2026-2027` with a term named `الفصل الأول`.
Each file was checked by importing the whole set into an empty school with no problems.
