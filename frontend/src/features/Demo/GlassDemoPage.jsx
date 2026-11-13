import GlassWorkspace from '../../components/ui/adjustable-glass-dashboard'

// A trial page for the glass dashboard component (sample data only, nothing from the school). Open it at /admin/glass-demo.
const transactions = [
  { id: '1', name: 'Studio retainer', category: 'Income', date: 'Today', amount: 4800, status: 'Completed' },
  { id: '2', name: 'Sunday Coffee', category: 'Dining', date: 'Today', amount: -18.5, status: 'Completed' },
  { id: '3', name: 'Aesop', category: 'Shopping', date: 'Yesterday', amount: -86, status: 'Completed' },
  { id: '4', name: 'Weekend in Sedona', category: 'Travel', date: 'Nov 03', amount: -320, status: 'Pending' },
  { id: '5', name: 'November rent', category: 'Home', date: 'Nov 01', amount: -1850, status: 'Completed' },
  { id: '6', name: 'Website project', category: 'Income', date: 'Nov 01', amount: 6250, status: 'Completed' },
  { id: '7', name: 'Whole Foods Market', category: 'Shopping', date: 'Oct 31', amount: -124.8, status: 'Completed' },
]
const goals = [
  { id: 'reserve', name: 'Emergency fund', saved: 8400, target: 12000 },
  { id: 'travel', name: 'Travel fund', saved: 2350, target: 5000 },
]
const cashFlowPeriods = [
  {
    id: 'week',
    label: 'Week',
    description: 'Sample daily totals for the selected week.',
    points: [
      { label: 'Mon', income: 680, expenses: 112 },
      { label: 'Tue', income: 940, expenses: 188 },
      { label: 'Wed', income: 720, expenses: 84 },
      { label: 'Thu', income: 1120, expenses: 245 },
      { label: 'Fri', income: 760, expenses: 98 },
      { label: 'Sat', income: 340, expenses: 174 },
      { label: 'Sun', income: 240, expenses: 65 },
    ],
  },
  {
    id: 'month',
    label: 'Month',
    description: 'Sample totals across November. Each point represents its labeled interval.',
    points: [
      { label: 'Nov 01', income: 560, expenses: 124 },
      { label: 'Nov 03', income: 660, expenses: 168 },
      { label: 'Nov 06', income: 575, expenses: 149 },
      { label: 'Nov 08', income: 840, expenses: 174 },
      { label: 'Nov 11', income: 785, expenses: 144 },
      { label: 'Nov 13', income: 995, expenses: 223 },
      { label: 'Nov 16', income: 940, expenses: 193 },
      { label: 'Nov 18', income: 1120, expenses: 238 },
      { label: 'Nov 21', income: 1010, expenses: 213 },
      { label: 'Nov 23', income: 1205, expenses: 258 },
      { label: 'Nov 26', income: 1135, expenses: 223 },
      { label: 'Nov 30', income: 1225, expenses: 292.3 },
    ],
  },
  {
    id: 'year',
    label: 'Year',
    description: 'Sample monthly totals for 2026.',
    points: [
      { label: 'Jan', income: 7300, expenses: 4200 },
      { label: 'Feb', income: 8100, expenses: 3850 },
      { label: 'Mar', income: 7600, expenses: 4540 },
      { label: 'Apr', income: 9200, expenses: 3900 },
      { label: 'May', income: 8500, expenses: 4720 },
      { label: 'Jun', income: 10200, expenses: 4350 },
      { label: 'Jul', income: 9800, expenses: 5600 },
      { label: 'Aug', income: 11700, expenses: 4800 },
      { label: 'Sep', income: 10800, expenses: 6200 },
      { label: 'Oct', income: 12300, expenses: 5400 },
      { label: 'Nov', income: 11050, expenses: 2399.3 },
      { label: 'Dec', income: 13900, expenses: 6100 },
    ],
  },
]

export default function GlassDemoPage() {
  return (
    <GlassWorkspace
      brand="Madrasati"
      userName="Troy"
      balance={28430.6}
      balanceChange={{ percent: 8.2, label: 'compared with last month' }}
      account={{ name: 'Everyday account', lastFour: '4829' }}
      periodLabel="November 2026"
      cashFlowPeriods={cashFlowPeriods}
      initialPeriodId="month"
      footerNote="All figures are sample data"
      savingsNote="Your progress is shown from the sample goals supplied to this demo."
      glass={{ blur: 22, opacity: 0.3, saturation: 1.2, edge: 0.6, tint: '#eadbc7' }}
      transactions={transactions}
      goals={goals}
      backgroundImage="https://images.unsplash.com/photo-1506744038136-46273834b3fb?auto=format&fit=crop&w=2000&q=80"
    />
  )
}
