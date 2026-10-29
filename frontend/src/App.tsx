import { useEffect, useState } from 'react'
import Kiosk from './pages/Kiosk'
import OperatorDashboard from './pages/OperatorDashboard'
import TenantDashboard from './pages/TenantDashboard'
import './App.css'

type View = 'kiosk' | 'tenant' | 'operator'

function viewFromHash(): View {
  const hash = window.location.hash.replace('#/', '')
  return hash === 'tenant' || hash === 'operator' ? hash : 'kiosk'
}

export default function App() {
  const [view, setView] = useState<View>(viewFromHash)

  useEffect(() => {
    const onHashChange = () => setView(viewFromHash())
    window.addEventListener('hashchange', onHashChange)
    return () => window.removeEventListener('hashchange', onHashChange)
  }, [])

  function navigate(next: View) {
    window.location.hash = `#/${next}`
    setView(next)
  }

  return (
    <div className="app-shell">
      <nav className="topbar">
        <button className="brand" onClick={() => navigate('kiosk')}>
          <span className="brand-mark">G</span>
          <span>
            GMFS
            <small>self-order kiosk</small>
          </span>
        </button>

        <div className="nav-links">
          <button className={view === 'kiosk' ? 'nav-link active' : 'nav-link'} onClick={() => navigate('kiosk')}>
            Kiosk
          </button>
          <button className={view === 'tenant' ? 'nav-link active' : 'nav-link'} onClick={() => navigate('tenant')}>
            Tenant
          </button>
          <button className={view === 'operator' ? 'nav-link active' : 'nav-link'} onClick={() => navigate('operator')}>
            Operator
          </button>
        </div>
      </nav>

      <main>
        {view === 'kiosk' && <Kiosk onOpenDashboard={(next) => navigate(next)} />}
        {view === 'tenant' && <TenantDashboard />}
        {view === 'operator' && <OperatorDashboard />}
      </main>

      <footer className="footer">
        <span>Prototype akademik · Midtrans sandbox · QRIS</span>
        <span>Uang selalu bilangan bulat rupiah</span>
      </footer>
    </div>
  )
}
