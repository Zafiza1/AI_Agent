import { useQuery } from '@tanstack/react-query'
import { api } from './api'
import type { Meta } from './types'

/** Enumerations and the role matrix from the backend; they only change on deploy. */
export function useMeta() {
  return useQuery({
    queryKey: ['meta'],
    queryFn: () => api<{ data: Meta }>('/meta', { tenant: false }).then((r) => r.data),
    staleTime: Infinity,
  })
}
