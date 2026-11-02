import { useAuth } from '../../Auth/AuthProvider'
import ContractsPage from './ContractsPage'

// The accountant manages contracts; the principal only reads them.
export default function ContractsRoute({ base }) {
  const { user } = useAuth()
  return <ContractsPage base={base} canManage={user?.staff_type === 'accountant'} />
}
