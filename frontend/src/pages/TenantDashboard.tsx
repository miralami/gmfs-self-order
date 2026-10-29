import { useCallback, useEffect, useState } from 'react'
import { api, ApiError, formatRupiah, type TenantOrder } from '../api'

const TOKEN_STORAGE_KEY = 'gmfs:tenant-token'

const STATUS_LABELS: Record<string, string> = {
  unpaid: 'Belum dibayar',
  paid: 'Dibayar',
  queued: 'Antrean dapur',
  cancelled: 'Dibatalkan',
}

export default function TenantDashboard() {
  const [token, setToken] = useState(() => localStorage.getItem(TOKEN_STORAGE_KEY) ?? '')
  const [orders, setOrders] = useState<TenantOrder[]>([])
  const [filter, setFilter] = useState<'all' | 'paid' | 'queued'>('all')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const load = useCallback(
    async (activeToken: string) => {
      if (!activeToken.trim()) return

      setLoading(true)
      setError(null)

      try {
        const response = await api.tenantOrders(activeToken.trim())
        setOrders(response.data)
        localStorage.setItem(TOKEN_STORAGE_KEY, activeToken.trim())
      } catch (caught) {
        setError(caught instanceof ApiError ? caught.message : 'Gagal memuat pesanan.')
      } finally {
        setLoading(false)
      }
    },
    [],
  )

  useEffect(() => {
    if (token) void load(token)
    // Only run on first mount; the button handles later loads.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  async function queue(order: TenantOrder) {
    setNotice(null)
    setError(null)

    try {
      const response = await api.queueOrder(order.id, token.trim())
      setOrders((current) => current.map((item) => (item.id === response.data.id ? response.data : item)))
      setNotice(`Pesanan ${response.data.public_code} masuk antrean dapur.`)
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Gagal mengantrekan pesanan.')
    }
  }

  const visibleOrders = filter === 'all' ? orders : orders.filter((order) => order.status === filter)
  const paidCount = orders.filter((order) => order.status === 'paid').length
  const queuedCount = orders.filter((order) => order.status === 'queued').length

  return (
    <div className="dashboard">
      <header className="dash-head">
        <div>
          <p className="eyebrow">Dashboard tenant</p>
          <h1>Pesanan masuk untuk stall kamu</h1>
          <p className="muted">
            Token manager deterministik dari seeder: <code>noodle-house-token</code>, <code>burger-point-token</code>, dan
            seterusnya.
          </p>
        </div>
      </header>

      <section className="token-bar">
        <label>
          Bearer token
          <input
            value={token}
            onChange={(event) => setToken(event.target.value)}
            placeholder="noodle-house-token"
            autoComplete="off"
          />
        </label>
        <button className="primary" onClick={() => void load(token)} disabled={loading || !token.trim()}>
          {loading ? 'Memuat…' : 'Muat pesanan'}
        </button>
      </section>

      {error && <div className="alert">{error}</div>}
      {notice && <div className="notice">{notice}</div>}

      <div className="stat-row">
        <div className="stat">
          <span>Perlu disiapkan</span>
          <strong>{paidCount}</strong>
        </div>
        <div className="stat">
          <span>Di antrean</span>
          <strong>{queuedCount}</strong>
        </div>
        <div className="stat">
          <span>Total pesanan</span>
          <strong>{orders.length}</strong>
        </div>
      </div>

      <div className="tenant-filter">
        {(
          [
            ['all', 'Semua'],
            ['paid', 'Dibayar'],
            ['queued', 'Antrean'],
          ] as const
        ).map(([value, label]) => (
          <button key={value} className={filter === value ? 'chip active' : 'chip'} onClick={() => setFilter(value)}>
            {label}
          </button>
        ))}
      </div>

      <div className="order-grid">
        {visibleOrders.length === 0 && <p className="muted">Belum ada pesanan pada filter ini.</p>}

        {visibleOrders.map((order) => (
          <article key={order.id} className="order-card">
            <div className="order-top">
              <div>
                <p className="code">{order.public_code}</p>
                <p className="muted">
                  {order.tenant_name} · {order.checkout_reference.slice(0, 8)}
                </p>
              </div>
              <span className={`badge ${order.status}`}>{STATUS_LABELS[order.status] ?? order.status}</span>
            </div>

            <ul className="order-items">
              {order.items.map((item) => (
                <li key={`${order.id}-${item.product_name}`}>
                  <span>
                    {item.quantity}× {item.product_name}
                  </span>
                  <span>{formatRupiah(item.line_total)}</span>
                </li>
              ))}
            </ul>

            <div className="order-total">
              <span>Total</span>
              <strong>{formatRupiah(order.total_amount)}</strong>
            </div>

            {order.status === 'paid' && (
              <button className="primary wide" onClick={() => void queue(order)}>
                Masukkan antrean dapur
              </button>
            )}

            {order.status === 'queued' && <p className="fineprint">Sudah masuk antrean dapur.</p>}
            {order.status === 'unpaid' && <p className="fineprint">Menunggu pembayaran dikonfirmasi Midtrans.</p>}
          </article>
        ))}
      </div>
    </div>
  )
}
