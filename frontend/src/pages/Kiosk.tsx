import { useCallback, useEffect, useState } from 'react'
import QRCode from 'qrcode'
import { api, ApiError, formatRupiah, type CartLine, type Checkout, type Tenant } from '../api'

type Props = {
  onOpenDashboard: (view: 'tenant' | 'operator') => void
}

function cartSignature(lines: CartLine[]): string {
  return [...lines]
    .sort((a, b) => a.product_id - b.product_id)
    .map((line) => `${line.product_id}x${line.quantity}`)
    .join('|')
}

function newIntentKey(): string {
  return crypto.randomUUID()
}

export default function Kiosk({ onOpenDashboard }: Props) {
  const [tenants, setTenants] = useState<Tenant[]>([])
  const [activeTenantId, setActiveTenantId] = useState<number | 'all'>('all')
  const [cart, setCart] = useState<Record<number, number>>({})
  const [checkout, setCheckout] = useState<Checkout | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [loadingCatalog, setLoadingCatalog] = useState(true)

  const loadCatalog = useCallback(async () => {
    setLoadingCatalog(true)
    try {
      const response = await api.tenants()
      setTenants(response.data)
      setError(null)
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Gagal memuat katalog.')
    } finally {
      setLoadingCatalog(false)
    }
  }, [])

  useEffect(() => {
    // oxlint-disable-next-line react/set-state-in-effect -- initial catalog fetch is intentional external synchronisation
    void loadCatalog()
  }, [loadCatalog])

  const products = tenants.flatMap((tenant) =>
    tenant.products.map((product) => ({ ...product, tenantId: tenant.id, tenantName: tenant.name })),
  )

  const visibleProducts =
    activeTenantId === 'all' ? products : products.filter((product) => product.tenantId === activeTenantId)

  const lines: CartLine[] = Object.entries(cart)
    .filter(([, quantity]) => quantity > 0)
    .map(([productId, quantity]) => ({ product_id: Number(productId), quantity }))

  const cartTotal = lines.reduce((total, line) => {
    const product = products.find((candidate) => candidate.id === line.product_id)
    return total + (product ? product.price * line.quantity : 0)
  }, 0)

  function addToCart(productId: number) {
    setCart((current) => ({ ...current, [productId]: (current[productId] ?? 0) + 1 }))
  }

  function changeQuantity(productId: number, delta: number) {
    setCart((current) => {
      const next = Math.max(0, (current[productId] ?? 0) + delta)
      const copy = { ...current }
      if (next === 0) {
        delete copy[productId]
      } else {
        copy[productId] = next
      }
      return copy
    })
  }

  async function submitOrder() {
    if (lines.length === 0) return

    setSubmitting(true)
    setError(null)

    try {
      const storageKey = `gmfs:intent:${cartSignature(lines)}`
      let intentKey = localStorage.getItem(storageKey)

      if (!intentKey) {
        intentKey = newIntentKey()
        localStorage.setItem(storageKey, intentKey)
      }

      const response = await api.createCheckout(lines, intentKey)

      if (!response.meta.replayed) {
        localStorage.removeItem(storageKey)
      }

      setCheckout(response.data)
      setCart({})

      if (response.meta.payment_error) {
        setError(`Pembayaran belum bisa dimulai: ${response.meta.payment_error}`)
      }
    } catch (caught) {
      if (caught instanceof ApiError && caught.status === 409) {
        setError('Keranjang ini sudah pernah diproses. Mulai pesanan baru.')
      } else {
        setError(caught instanceof ApiError ? caught.message : 'Checkout gagal.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  async function retryPayment() {
    if (!checkout) return

    setSubmitting(true)
    setError(null)

    try {
      const response = await api.retryPayment(checkout.reference)
      setCheckout(response.data)
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Gagal membuat QR baru.')
    } finally {
      setSubmitting(false)
    }
  }

  function startOver() {
    setCheckout(null)
    setCart({})
    setError(null)
    void loadCatalog()
  }

  if (checkout) {
    return (
      <PaymentView
        checkout={checkout}
        onChange={setCheckout}
        onRetry={retryPayment}
        onStartOver={startOver}
        busy={submitting}
        error={error}
      />
    )
  }

  return (
    <div className="kiosk">
      <header className="kiosk-head">
        <div>
          <p className="eyebrow">GMFS Food Court</p>
          <h1>Pesan dari beberapa tenant, bayar sekali.</h1>
        </div>
        <button className="ghost" onClick={() => onOpenDashboard('tenant')}>
          Dashboard tenant
        </button>
      </header>

      {error && <div className="alert">{error}</div>}

      <div className="tenant-filter" role="tablist" aria-label="Pilih tenant">
        <button
          className={activeTenantId === 'all' ? 'chip active' : 'chip'}
          onClick={() => setActiveTenantId('all')}
        >
          Semua tenant
        </button>
        {tenants.map((tenant) => (
          <button
            key={tenant.id}
            className={activeTenantId === tenant.id ? 'chip active' : 'chip'}
            onClick={() => setActiveTenantId(tenant.id)}
          >
            {tenant.name}
          </button>
        ))}
      </div>

      <div className="kiosk-body">
        <section className="catalog" aria-busy={loadingCatalog}>
          {loadingCatalog && <p className="muted">Memuat katalog…</p>}

          {!loadingCatalog && visibleProducts.length === 0 && (
            <p className="muted">Belum ada produk aktif. Jalankan seeder terlebih dahulu.</p>
          )}

          <div className="product-grid">
            {visibleProducts.map((product) => (
              <article key={product.id} className="product-card">
                <div>
                  <p className="tenant-label">{product.tenantName}</p>
                  <h3>{product.name}</h3>
                  <p className="price">{formatRupiah(product.price)}</p>
                </div>
                <div className="product-actions">
                  {cart[product.id] ? (
                    <div className="stepper">
                      <button aria-label="Kurangi" onClick={() => changeQuantity(product.id, -1)}>
                        −
                      </button>
                      <span>{cart[product.id]}</span>
                      <button aria-label="Tambah" onClick={() => changeQuantity(product.id, 1)}>
                        +
                      </button>
                    </div>
                  ) : (
                    <button className="primary" onClick={() => addToCart(product.id)}>
                      Tambah
                    </button>
                  )}
                </div>
              </article>
            ))}
          </div>
        </section>

        <aside className="cart" aria-label="Keranjang">
          <h2>Keranjang</h2>

          {lines.length === 0 && <p className="muted">Keranjang masih kosong. Pilih menu dari beberapa tenant.</p>}

          <ul className="cart-lines">
            {lines.map((line) => {
              const product = products.find((candidate) => candidate.id === line.product_id)
              if (!product) return null

              return (
                <li key={line.product_id}>
                  <div>
                    <strong>{product.name}</strong>
                    <small>{product.tenantName}</small>
                  </div>
                  <div className="cart-line-right">
                    <span>{formatRupiah(product.price * line.quantity)}</span>
                    <div className="stepper small">
                      <button aria-label="Kurangi" onClick={() => changeQuantity(line.product_id, -1)}>
                        −
                      </button>
                      <span>{line.quantity}</span>
                      <button aria-label="Tambah" onClick={() => changeQuantity(line.product_id, 1)}>
                        +
                      </button>
                    </div>
                  </div>
                </li>
              )
            })}
          </ul>

          <div className="cart-total">
            <span>Total</span>
            <strong>{formatRupiah(cartTotal)}</strong>
          </div>

          <button className="primary wide" disabled={lines.length === 0 || submitting} onClick={submitOrder}>
            {submitting ? 'Memproses…' : 'Bayar dengan QRIS'}
          </button>
          <p className="fineprint">Satu pembayaran, dipisah otomatis menjadi pesanan per tenant.</p>
        </aside>
      </div>
    </div>
  )
}

type PaymentProps = {
  checkout: Checkout
  onChange: (checkout: Checkout) => void
  onRetry: () => void
  onStartOver: () => void
  busy: boolean
  error: string | null
}

function PaymentView({ checkout, onChange, onRetry, onStartOver, busy, error }: PaymentProps) {
  // Keep the rendered QR tied to the payload it was generated from, so a new
  // attempt can never briefly show the previous attempt's code.
  const [qr, setQr] = useState<{ payload: string; dataUrl: string } | null>(null)
  const [now, setNow] = useState(() => Date.now())
  const [pollError, setPollError] = useState<string | null>(null)

  const isOpen = checkout.status === 'unpaid'
  const qrString = checkout.payment?.qr_string ?? null

  useEffect(() => {
    if (!qrString) return

    let cancelled = false
    QRCode.toDataURL(qrString, { margin: 1, width: 320 })
      .then((dataUrl) => {
        if (!cancelled) setQr({ payload: qrString, dataUrl })
      })
      .catch(() => {
        if (!cancelled) setQr(null)
      })

    return () => {
      cancelled = true
    }
  }, [qrString])

  const qrDataUrl = qr && qr.payload === qrString ? qr.dataUrl : null

  useEffect(() => {
    if (!isOpen) return

    const timer = window.setInterval(() => setNow(Date.now()), 1000)
    return () => window.clearInterval(timer)
  }, [isOpen])

  useEffect(() => {
    if (!isOpen) return

    let cancelled = false

    const poll = async () => {
      try {
        const response = await api.checkout(checkout.reference)
        if (cancelled) return

        onChange(response.data)

        if (response.data.status !== 'unpaid') {
          setPollError(null)
        }
      } catch (caught) {
        if (!cancelled) {
          setPollError(caught instanceof ApiError ? caught.message : 'Status pembayaran belum bisa diperiksa.')
        }
      }
    }

    const timer = window.setInterval(poll, 3000)
    void poll()

    return () => {
      cancelled = true
      window.clearInterval(timer)
    }
  }, [checkout.reference, isOpen, onChange])

  const expiresAt = checkout.expires_at ? new Date(checkout.expires_at).getTime() : null
  const secondsLeft = expiresAt ? Math.max(0, Math.floor((expiresAt - now) / 1000)) : null
  const minutes = secondsLeft !== null ? Math.floor(secondsLeft / 60) : null
  const seconds = secondsLeft !== null ? secondsLeft % 60 : null

  if (checkout.status === 'paid') {
    return (
      <div className="payment-page">
        <div className="receipt">
          <div className="receipt-head">
            <p className="eyebrow">Pembayaran diterima</p>
            <h1>Pesanan diteruskan ke tenant</h1>
            <p className="muted">
              Referensi {checkout.reference} · {formatRupiah(checkout.total_amount)}
            </p>
          </div>

          <div className="tenant-codes">
            {checkout.tenant_orders.map((order) => (
              <article key={order.public_code} className="code-card">
                <p className="tenant-label">{order.tenant_name}</p>
                <p className="code">{order.public_code}</p>
                <p className="muted">{formatRupiah(order.total_amount)}</p>
                <span className={`badge ${order.status}`}>{order.status}</span>
              </article>
            ))}
          </div>

          <button className="primary wide" onClick={onStartOver}>
            Pesanan baru
          </button>
        </div>
      </div>
    )
  }

  if (checkout.status !== 'unpaid') {
    return (
      <div className="payment-page">
        <div className="receipt">
          <p className="eyebrow">Pembayaran ditutup</p>
          <h1>{checkout.status === 'expired' ? 'Waktu pembayaran habis' : 'Checkout dibatalkan'}</h1>
          <p className="muted">Tidak ada dana yang ditarik. Silakan mulai pesanan baru.</p>
          <button className="primary wide" onClick={onStartOver}>
            Mulai lagi
          </button>
        </div>
      </div>
    )
  }

  return (
    <div className="payment-page">
      <div className="pay-card">
        <div className="pay-head">
          <div>
            <p className="eyebrow">Scan QRIS</p>
            <h1>{formatRupiah(checkout.total_amount)}</h1>
          </div>
          <div className="countdown">
            <span>Berlaku</span>
            <strong>
              {minutes !== null && seconds !== null
                ? `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
                : '—'}
            </strong>
          </div>
        </div>

        {(error || pollError) && <div className="alert">{error ?? pollError}</div>}

        <div className="qr-box">
          {qrDataUrl ? (
            <img src={qrDataUrl} alt="QRIS pembayaran" width={320} height={320} />
          ) : checkout.payment?.qr_image_url ? (
            <img src={checkout.payment.qr_image_url} alt="QRIS pembayaran" width={320} height={320} />
          ) : (
            <div className="qr-missing">
              <p>QR belum tersedia.</p>
              <p className="muted">Periksa konfigurasi Midtrans, lalu buat QR baru.</p>
            </div>
          )}
        </div>

        <div className="pay-status">
          <span className="spinner" aria-hidden="true" />
          Menunggu pembayaran… halaman ini memperbarui status otomatis.
        </div>

        <div className="tenant-codes compact">
          {checkout.tenant_orders.map((order) => (
            <div key={order.public_code} className="code-row">
              <span>{order.tenant_name}</span>
              <span>{formatRupiah(order.total_amount)}</span>
              <span className={`badge ${order.status}`}>{order.status}</span>
            </div>
          ))}
        </div>

        <div className="pay-actions">
          <button className="ghost" onClick={onRetry} disabled={busy}>
            Buat QR baru
          </button>
          <button className="ghost danger" onClick={onStartOver}>
            Batalkan
          </button>
        </div>
      </div>
    </div>
  )
}
