import { createBrowserRouter } from 'react-router-dom'
import DashboardLayout from '../layouts/DashboardLayout'
import LoginPage from '../features/Auth/pages/LoginPage'
import LandingPage from '../features/Auth/pages/LandingPage'
import RoleHome from '../features/Auth/pages/RoleHome'
import { RequireAuth, RequireRole } from './guards'

const ROLES = ['admin', 'teacher', 'student', 'parent']

export const routes = [
  { path: '/', element: <LandingPage /> },
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
