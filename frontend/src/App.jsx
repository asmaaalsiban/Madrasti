import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useMemo } from 'react'
import { RouterProvider } from 'react-router-dom'
import './i18n'
import { createRouter } from './app/router'
import { AuthProvider } from './features/Auth/AuthProvider'

const queryClient = new QueryClient()

export default function App() {
  const router = useMemo(() => createRouter(), [])
  return (
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <RouterProvider router={router} />
      </AuthProvider>
    </QueryClientProvider>
  )
}
