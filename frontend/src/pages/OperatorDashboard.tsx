import { useCallback, useEffect, useState } from 'react'
import { api, ApiError, formatRupiah, type Settlement } from '../api'

const TOKEN_STORAGE_KEY = 'gmfs:operator-token'

export default function OperatorDashboard() {
  const [token, setToken] = useState(() => localStorage.getItem(TOKEN_STORAGE_KEY) ?? 'operator-token')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [settlement, setSettlement] = useState<Settlement | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    if (!token.trim()) return

    setLoading(true)
    setError(null)

    try {
      const response = await api.settlements(token.trim(), from, to)
      setSettlement(response.data)
      localStorage.setItem(TOKEN_STORAGE_KEY, token.trim())
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Gagal memuat settlement.')
    } finally {
      setLoading(false)
    }
  }, [token, from, to])

  useEffect(() => {
    void load()
    // Load once on mount; the button refreshes with the current filters.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  return (
    <div className="dashboard">
      <header className="dash-head">
        <div>
          <p className="eyebrow">Dashboard operator</p>
          <h1>Settlement lintas tenant</h1>
          <p className="muted">
            Hanya pesanan yang sudah dibayar. Split {settlement?.platform_fee_percent ?? 20}% platform /{' '}
            {100 - (settlement?.platform_fee_percent ?? 20)}% tenant dihitung saat pembayaran dikonfirmasi.
          </p>
        </div>
      </header>

      <section className="token-bar wrap">
        <label>
          Bearer token
          <input
            value={token}
            onChange={(event) => setToken(event.target.value)}
            placeholder="operator-token"
            autoComplete="off"
          />
        </label>
        <label>
          Dari tanggal
          <input type="date" value={from} onChange={(event) => setFrom(event.target.value)} />
        </label>
        <label>
          Sampai tanggal
          <input type="date" value={to} onChange={(event) => setTo(event.target.value)} />
        </label>
        <button className="primary" onClick={() => void load()} disabled={loading || !token.trim()}>
          {loading ? 'Memuat…' : 'Muat settlement'}
        </button>
      </section>

      {error && <div className="alert">{error}</div>}

      {settlement && (
        <>
          <div className="stat-row">
            <div className="stat">
              <span>Pesanan dibayar</span>
              <strong>{settlement.totals.order_count}</strong>
            </div>
            <div className="stat">
              <span>Nilai transaksi</span>
              <strong>{formatRupiah(settlement.totals.gross_amount)}</strong>
            </div>
            <div className="stat">
              <span>Hak tenant</span>
              <strong>{formatRupiah(settlement.totals.tenant_share)}</strong>
            </div>
            <div className="stat">
              <span>Fee platform</span>
              <strong>{formatRupiah(settlement.totals.platform_fee)}</strong>
            </div>
          </div>

          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Tenant</th>
                  <th className="right">Pesanan</th>
                  <th className="right">Bruto</th>
                  <th className="right">Hak tenant</th>
                  <th className="right">Fee platform</th>
                </tr>
              </thead>
              <tbody>
                {settlement.rows.length === 0 && (
                  <tr>
                    <td colSpan={5} className="muted">
                      Belum ada pembayaran pada rentang ini.
                    </td>
                  </tr>
                )}

                {settlement.rows.map((row) => (
                  <tr key={row.tenant_id}>
                    <td>{row.tenant_name}</td>
                    <td className="right">{row.order_count}</td>
                    <td className="right">{formatRupiah(row.gross_amount)}</td>
                    <td className="right">{formatRupiah(row.tenant_share)}</td>
                    <td className="right">{formatRupiah(row.platform_fee)}</td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr>
                  <th>Total</th>
                  <th className="right">{settlement.totals.order_count}</th>
                  <th className="right">{formatRupiah(settlement.totals.gross_amount)}</th>
                  <th className="right">{formatRupiah(settlement.totals.tenant_share)}</th>
                  <th className="right">{formatRupiah(settlement.totals.platform_fee)}</th>
                </tr>
              </tfoot>
            </table>
          </div>

          <p className="fineprint">
            Angka diambil dari nilai yang tersimpan saat pembayaran dikonfirmasi. Tidak ada payout, pajak, atau biaya
            listrik tenant di dalam perhitungan ini.
          </p>
        </>
      )}
    </div>
  )
}
