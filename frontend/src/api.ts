const API_BASE = (import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api/v1').replace(/\/$/, '')

export type CartLine = { product_id: number; quantity: number }

export type Product = {
  id: number
  name: string
  price: number
  outlet_id: number
}

export type Tenant = {
  id: number
  name: string
  slug: string
  outlets: { id: number; name: string }[]
  products: Product[]
}

export type TenantOrderSummary = {
  public_code: string
  tenant_id: number
  tenant_name: string | null
  total_amount: number
  status: string
  items: { product_name: string; quantity: number; line_total: number }[]
}

export type Payment = {
  attempt_no: number
  status: string
  amount: number
  qr_string: string | null
  qr_image_url: string | null
  expires_at: string | null
  failure_reason: string | null
}

export type Checkout = {
  reference: string
  status: 'unpaid' | 'paid' | 'expired' | 'cancelled'
  total_amount: number
  expires_at: string | null
  paid_at: string | null
  items: { product_name: string; quantity: number; line_total: number }[]
  tenant_orders: TenantOrderSummary[]
  payment: Payment | null
}

export type TenantOrder = {
  id: number
  public_code: string
  tenant_id: number
  tenant_name: string | null
  checkout_reference: string
  total_amount: number
  tenant_share_amount: number | null
  platform_fee_amount: number | null
  status: 'unpaid' | 'paid' | 'queued' | 'cancelled'
  paid_at: string | null
  queued_at: string | null
  items: { product_name: string; quantity: number; unit_price: number; line_total: number }[]
}

export type Settlement = {
  rows: {
    tenant_id: number
    tenant_name: string | null
    order_count: number
    gross_amount: number
    tenant_share: number
    platform_fee: number
  }[]
  totals: {
    order_count: number
    gross_amount: number
    tenant_share: number
    platform_fee: number
  }
  platform_fee_percent: number
}

export class ApiError extends Error {
  status: number
  details: Record<string, string[]>

  constructor(message: string, status: number, details: Record<string, string[]> = {}) {
    super(message)
    this.status = status
    this.details = details
  }
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  let response: Response

  try {
    response = await fetch(`${API_BASE}${path}`, init)
  } catch {
    throw new ApiError('Tidak bisa menghubungi server. Pastikan backend berjalan.', 0)
  }

  const text = await response.text()
  const body = text ? (JSON.parse(text) as unknown) : null

  if (!response.ok) {
    const data = (body ?? {}) as { message?: string; errors?: Record<string, string[]> }
    throw new ApiError(data.message ?? `Permintaan gagal (${response.status}).`, response.status, data.errors ?? {})
  }

  return body as T
}

function authHeaders(token: string): HeadersInit {
  return {
    Accept: 'application/json',
    Authorization: `Bearer ${token}`,
  }
}

export const api = {
  tenants: () => request<{ data: Tenant[] }>('/catalog/tenants'),

  createCheckout: (items: CartLine[], idempotencyKey: string) =>
    request<{ data: Checkout; meta: { replayed: boolean; payment_error: string | null } }>('/checkouts', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'Idempotency-Key': idempotencyKey,
      },
      body: JSON.stringify({ items }),
    }),

  checkout: (reference: string) => request<{ data: Checkout }>(`/checkouts/${reference}`),

  retryPayment: (reference: string) =>
    request<{ data: Checkout }>(`/checkouts/${reference}/payment-attempts`, {
      method: 'POST',
      headers: { Accept: 'application/json' },
    }),

  tenantOrders: (token: string) =>
    request<{ data: TenantOrder[] }>('/tenant/orders', { headers: authHeaders(token) }),

  queueOrder: (id: number, token: string) =>
    request<{ data: TenantOrder }>(`/tenant/orders/${id}/queue-preparation`, {
      method: 'POST',
      headers: authHeaders(token),
    }),

  settlements: (token: string, from: string, to: string) => {
    const query = new URLSearchParams()
    if (from) query.set('from', from)
    if (to) query.set('to', to)
    const suffix = query.toString() ? `?${query.toString()}` : ''

    return request<{ data: Settlement }>(`/operator/settlements${suffix}`, { headers: authHeaders(token) })
  },
}

export function formatRupiah(value: number): string {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(value)
}
