import { createBrowserRouter, Navigate } from 'react-router-dom'
import DashboardLayout from '../layouts/DashboardLayout'
import { useAuth } from '../features/Auth/AuthProvider'
import LoginPage from '../features/Auth/pages/LoginPage'
import RoleHome from '../features/Auth/pages/RoleHome'
import { homeFor, RequireAuth, RequireRole } from './guards'

const ROLES = ['admin', 'teacher', 'student', 'parent']

function Root() {
  const { user } = useAuth()
  return <Navigate to={user ? homeFor(user.role) : '/login'} replace />
}

export const routes = [
  { path: '/', element: <Root /> },
  { path: '/login', element: <LoginPage /> },
  {
    element: <RequireAuth />,
    children: [
      {
        element: <DashboardLayout />,
        children: ROLES.map((role) => ({
          path: `/${role}`,
          element: <RequireRole role={role} />,
          children: [{ index: true, element: <RoleHome /> }],
        })),
      },
    ],
  },
]

export const createRouter = () => createBrowserRouter(routes)
