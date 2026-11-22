import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import Swal from 'sweetalert2'
import { ApiError, request } from './api'
import type { User } from './api'
import { clearSessionFilters } from './useSessionFilter'
import illustration from './assets/contabilidad.png'
import Workspace from './Workspace'
import './App.css'

const tokenKey = 'cuadre-caja-token'

function App() {
  const [token, setToken] = useState(() => sessionStorage.getItem(tokenKey))
  const [user, setUser] = useState<User | null>(null)
  const [checking, setChecking] = useState(Boolean(token))
  const [retry, setRetry] = useState(0)
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  const expireSession = useCallback(() => {
    sessionStorage.removeItem(tokenKey)
    clearSessionFilters()
    setToken(null)
    setUser(null)
    setError('Tu sesión ha expirado. Inicia sesión nuevamente.')
  }, [])

  const passwordChanged = useCallback(() => {
    sessionStorage.removeItem(tokenKey)
    clearSessionFilters()
    setToken(null)
    setUser(null)
    setError('Contraseña actualizada. Inicia sesión nuevamente.')
  }, [])

  useEffect(() => {
    if (!token) return

    let active = true
    request<User>('/user', { headers: { Authorization: `Bearer ${token}` } })
      .then((profile) => {
        if (active) {
          setUser(profile)
          setError('')
          setChecking(false)
        }
      })
      .catch((err: unknown) => {
        if (!active) return
        if (err instanceof ApiError && err.status === 401) {
          expireSession()
        } else {
          setError(err instanceof Error ? err.message : 'No se pudo verificar la sesión.')
        }
        setChecking(false)
      })
    return () => { active = false }
  }, [token, retry, expireSession])

  async function handleLogin(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError('')
    setBusy(true)
    try {
      const result = await request<{ token: string; user: User }>('/login', {
        method: 'POST',
        body: JSON.stringify({ username: username.trim(), password }),
      })
      sessionStorage.setItem(tokenKey, result.token)
      setChecking(true)
      setToken(result.token)
      setPassword('')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'No se pudo iniciar sesión.')
    } finally {
      setBusy(false)
    }
  }

  async function handleLogout() {
    if (!token) return
    const confirmation = await Swal.fire({
      title: '¿Deseas cerrar sesión?',
      text: 'Tendrás que iniciar sesión nuevamente para acceder.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Sí, cerrar sesión',
      cancelButtonText: 'Cancelar',
      confirmButtonColor: '#14284f',
      cancelButtonColor: '#66758b',
      focusCancel: true,
      heightAuto: false,
    })
    if (!confirmation.isConfirmed) return
    setBusy(true)
    setError('')
    try {
      await request('/logout', {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}` },
      })
      sessionStorage.removeItem(tokenKey)
      clearSessionFilters()
      setToken(null)
      setUser(null)
    } catch (err) {
      if (err instanceof ApiError && err.status === 401) {
        expireSession()
      } else {
        setError(err instanceof Error ? err.message : 'No se pudo cerrar sesión.')
      }
    } finally {
      setBusy(false)
    }
  }

  if (token && user && !checking) {
    return <Workspace token={token} user={user} onUserUpdated={setUser} onSessionExpired={expireSession} onPasswordChanged={passwordChanged} onLogout={handleLogout} logoutBusy={busy} logoutError={error} />
  }

  return (
    <div className="page-shell">
      <header className="topbar">
        <div className="brand">
          <span className="brand-mark" aria-hidden="true">$</span>
          <span>Cuadre<span className="brand-accent">Caja</span></span>
        </div>
        <span className="topbar-label"><span className="status-dot" /> Tu espacio financiero</span>
      </header>

      <main className="layout">
        <section className="intro" aria-labelledby="intro-title">
          <div className="intro-copy">
            <span className="eyebrow"><span className="eyebrow-dot" /> TU NEGOCIO, EN ORDEN</span>
            <h1 id="intro-title">Las cuentas claras,<br /><span>todos los días.</span></h1>
            <p>Un espacio para llevar el control de tu caja y tomar mejores decisiones para tu negocio.</p>
          </div>
          <div className="illustration-wrap">
            <div className="illustration-halo" />
            <img className="illustration" src={illustration} alt="Personaje de contabilidad con una corona, monedas y gráficos" />
          </div>
          <div className="intro-footer"><span className="footer-line" /> UNA MEJOR FORMA DE LLEVAR TUS CUENTAS</div>
        </section>

        <section className="access-panel" aria-label="Acceso al aplicativo">
          <div className="card">
            {checking ? (
              <div className="session-state" role="status">Verificando tu sesión...</div>
            ) : token && !user ? (
              <div className="session-state">
                <h2>No pudimos verificar tu sesión</h2>
                <p className="error" role="alert">{error}</p>
                <button className="primary-button" type="button" onClick={() => { setError(''); setChecking(true); setRetry(retry + 1) }}>Reintentar</button>
              </div>
            ) : (
              <>
                <div className="card-icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><rect x="4" y="10" width="16" height="11" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3" /></svg>
                </div>
                <span className="card-kicker">BIENVENIDO DE NUEVO</span>
                <h2>Inicia sesión</h2>
                <p className="card-description">Ingresa tus datos para acceder a tu espacio de trabajo.</p>

                <form aria-busy={busy} onSubmit={handleLogin}>
                  <div className="field">
                    <label htmlFor="username">Usuario</label>
                    <div className="input-wrap">
                      <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" /></svg>
                      <input id="username" type="text" autoFocus autoComplete="username" autoCapitalize="none" spellCheck={false} placeholder="Ingresa tu usuario" maxLength={64} required value={username} onChange={(event) => setUsername(event.target.value)} />
                    </div>
                  </div>
                  <div className="field">
                    <label htmlFor="password">Contraseña</label>
                    <div className="input-wrap">
                      <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><rect x="4" y="10" width="16" height="11" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3" /></svg>
                      <input id="password" type={showPassword ? 'text' : 'password'} autoComplete="current-password" placeholder="Ingresa tu contraseña" required value={password} onChange={(event) => setPassword(event.target.value)} />
                      <button className="show-password" type="button" onClick={() => setShowPassword(!showPassword)} aria-label={showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'} aria-pressed={showPassword}>{showPassword ? 'Ocultar' : 'Mostrar'}</button>
                    </div>
                  </div>
                  {error && <p className="error" role="alert">{error}</p>}
                  <button className="primary-button" type="submit" disabled={busy}>
                    {busy ? 'Ingresando...' : 'Ingresar a mi cuenta'} <span aria-hidden="true">→</span>
                  </button>
                </form>
                <div className="card-note"><span aria-hidden="true">✦</span> ¿Necesitas acceso? Contacta al administrador.</div>
              </>
            )}
          </div>
          <p className="secure-note"><span aria-hidden="true">◇</span> Acceso para usuarios autorizados</p>
        </section>
      </main>
      <footer className="site-footer"><span>© CuadreCaja</span><span>Hecho para acompañar tu negocio.</span></footer>
    </div>
  )
}

export default App
