import { useEffect, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { ApiError, request } from './api'
import type { User } from './api'
import UserManagement from './UserManagement'
import ProfileManagement from './ProfileManagement'
import LocationManagement from './LocationManagement'
import ExpenseTypeManagement from './ExpenseTypeManagement'
import BranchManagement from './BranchManagement'
import type { Branch } from './BranchManagement'
import RoleManagement from './RoleManagement'
import type { Role } from './RoleManagement'
import CashRegisterManagement from './CashRegisterManagement'
import type { Register } from './CashRegisterManagement'
import './Workspace.css'

type Account = { id: number; name: string; type: string; is_active: boolean; branch: { name: string } }
type Section = 'inicio' | 'sucursales' | 'cajas' | 'cuentas' | 'usuarios' | 'ubicaciones' | 'roles' | 'tipos-gasto'
const accountsSectionEnabled = false
const settingsSections: Section[] = ['ubicaciones', 'sucursales', 'roles', 'usuarios', 'tipos-gasto']
type Resource<T> =
  | { status: 'loading' }
  | { status: 'ready'; data: T[] }
  | { status: 'error'; message: string }

const labels: Record<Section, string> = {
  inicio: 'Inicio',
  sucursales: 'Sucursales',
  cajas: 'Cajas',
  cuentas: 'Cuentas',
  usuarios: 'Usuarios',
  ubicaciones: 'Ubicaciones',
  roles: 'Roles',
  'tipos-gasto': 'Tipos de gasto',
}

const accountTypes: Record<string, string> = {
  caja_mayor: 'Caja mayor',
  caja_menor: 'Caja menor',
  banco: 'Banco',
  qr: 'QR',
}

function useResource<T>(path: string, token: string, enabled: boolean, onSessionExpired: () => void) {
  const [resource, setResource] = useState<Resource<T>>({ status: 'loading' })
  const [retry, setRetry] = useState(0)

  useEffect(() => {
    if (!enabled) return
    let active = true
    request<unknown>(path, { headers: { Authorization: `Bearer ${token}` } })
      .then((result) => {
        if (!Array.isArray(result)) throw new Error('El servidor devolvió una respuesta inválida.')
        if (active) setResource({ status: 'ready', data: result as T[] })
      })
      .catch((error: unknown) => {
        if (!active) return
        if (error instanceof ApiError && error.status === 401) {
          onSessionExpired()
        } else {
          setResource({ status: 'error', message: error instanceof Error ? error.message : 'No se pudieron cargar los datos.' })
        }
      })
    return () => { active = false }
  }, [path, token, enabled, onSessionExpired, retry])

  function reload() {
    setResource((previous) => previous.status === 'ready' ? previous : { status: 'loading' })
    setRetry((previous) => previous + 1)
  }

  return { resource, reload }
}

function ResourceView<T>({ resource, reload, empty, render }: {
  resource: Resource<T>
  reload: () => void
  empty: string
  render: (item: T) => ReactNode
}) {
  if (resource.status === 'loading') return <p className="workspace-message" role="status">Cargando información...</p>
  if (resource.status === 'error') {
    return <div className="resource-error" role="alert"><p>{resource.message}</p><button type="button" onClick={reload}>Reintentar</button></div>
  }
  if (resource.data.length === 0) return <p className="workspace-message">{empty}</p>
  return <div className="record-list">{resource.data.map(render)}</div>
}

function RecordRow({ title, detail, status }: { title: string; detail: string; status?: boolean }) {
  return (
    <div className="record-row">
      <div className="record-symbol" aria-hidden="true">▦</div>
      <div className="record-copy"><strong>{title}</strong><span>{detail}</span></div>
      {status !== undefined && <span className={`record-status ${status ? 'active' : 'inactive'}`}>{status ? 'Activa' : 'Inactiva'}</span>}
    </div>
  )
}

function Workspace({ token, user, onUserUpdated, onSessionExpired, onPasswordChanged, onLogout, logoutBusy, logoutError }: {
  token: string
  user: User
  onUserUpdated: (user: User) => void
  onSessionExpired: () => void
  onPasswordChanged: () => void
  onLogout: () => void
  logoutBusy: boolean
  logoutError: string
}) {
  const [section, setSection] = useState<Section>('inicio')
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false)
  const [settingsOpen, setSettingsOpen] = useState(false)
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false)
  const [userMenuOpen, setUserMenuOpen] = useState(false)
  const [profileMode, setProfileMode] = useState<'perfil' | 'contrasena' | null>(null)
  const [profileNotice, setProfileNotice] = useState('')
  const userMenuRef = useRef<HTMLDivElement>(null)
  const userMenuButtonRef = useRef<HTMLButtonElement>(null)
  const sidebarRef = useRef<HTMLElement>(null)
  const mobileMenuButtonRef = useRef<HTMLButtonElement>(null)
  const mobileCloseButtonRef = useRef<HTMLButtonElement>(null)
  const can = (permission: string) => user.permissions.includes(permission)
  const canViewBranches = can('branches.view') || can('branches.create')
  const canViewAccounts = accountsSectionEnabled && can('accounts.view')
  const canManageUsers = can('users.manage')
  const canManageRoles = can('roles.manage')
  const canManageLocations = can('locations.manage')
  const canManageExpenseTypes = can('expenses.manage_types')
  const [refreshError, setRefreshError] = useState('')
  const branches = useResource<Branch>('/branches', token, canViewBranches, onSessionExpired)
  const registers = useResource<Register>('/registers', token, true, onSessionExpired)
  const accounts = useResource<Account>('/accounts', token, canViewAccounts, onSessionExpired)
  const users = useResource<User>('/users', token, canManageUsers, onSessionExpired)
  const roles = useResource<Role>('/roles', token, canManageUsers || canManageRoles, onSessionExpired)
  const sections: Section[] = ['inicio', 'cajas']
  if (canViewAccounts) sections.push('cuentas')

  function refreshAfterRoleChange() {
    roles.reload()
    users.reload()
    branches.reload()
    registers.reload()
    accounts.reload()
    setRefreshError('')
    request<User>('/user', { headers: { Authorization: `Bearer ${token}` } })
      .then((updated) => {
        onUserUpdated(updated)
        if (!updated.permissions.includes('roles.manage')) setSection('inicio')
      })
      .catch((error: unknown) => {
        if (error instanceof ApiError && error.status === 401) onSessionExpired()
        else setRefreshError(error instanceof Error ? error.message : 'No se pudieron actualizar tus permisos.')
      })
  }

  useEffect(() => {
    if (!userMenuOpen) return
    const handleClick = (event: PointerEvent) => {
      if (event.target instanceof Node && !userMenuRef.current?.contains(event.target)) setUserMenuOpen(false)
    }
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setUserMenuOpen(false)
        userMenuButtonRef.current?.focus()
      }
    }
    document.addEventListener('pointerdown', handleClick)
    document.addEventListener('keydown', handleKeyDown)
    return () => {
      document.removeEventListener('pointerdown', handleClick)
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [userMenuOpen])

  useEffect(() => {
    if (!mobileMenuOpen) return

    const previousOverflow = document.body.style.overflow
    const menuButton = mobileMenuButtonRef.current
    document.body.style.overflow = 'hidden'
    const focusFrame = window.requestAnimationFrame(() => mobileCloseButtonRef.current?.focus())
    const desktop = window.matchMedia('(min-width: 761px)')
    const handleResize = () => { if (desktop.matches) setMobileMenuOpen(false) }
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault()
        setMobileMenuOpen(false)
      } else if (event.key === 'Tab' && sidebarRef.current) {
        const buttons = Array.from(sidebarRef.current.querySelectorAll<HTMLButtonElement>('button:not(:disabled)'))
          .filter((button) => button.getClientRects().length > 0)
        const first = buttons[0]
        const last = buttons[buttons.length - 1]
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault()
          last?.focus()
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault()
          first?.focus()
        }
      }
    }
    document.addEventListener('keydown', handleKeyDown)
    desktop.addEventListener('change', handleResize)
    handleResize()

    return () => {
      document.body.style.overflow = previousOverflow
      window.cancelAnimationFrame(focusFrame)
      document.removeEventListener('keydown', handleKeyDown)
      desktop.removeEventListener('change', handleResize)
      if (!desktop.matches) menuButton?.focus()
    }
  }, [mobileMenuOpen])

  function navigate(item: Section) {
    setSection(item)
    if (settingsSections.includes(item)) setSettingsOpen(true)
    setMobileMenuOpen(false)
    setUserMenuOpen(false)
  }

  function openProfile(mode: 'perfil' | 'contrasena') {
    setUserMenuOpen(false)
    setProfileNotice('')
    setProfileMode(mode)
  }

  function closeProfile() {
    setProfileMode(null)
    window.requestAnimationFrame(() => userMenuButtonRef.current?.focus())
  }

  const summaries = [
    ...(canViewBranches ? [{ section: 'sucursales' as const, description: 'Puntos del negocio', resource: branches.resource }] : []),
    { section: 'cajas' as const, description: 'Cajas registradas', resource: registers.resource },
    ...(canViewAccounts ? [{ section: 'cuentas' as const, description: 'Medios y fondos', resource: accounts.resource }] : []),
    ...(canManageUsers ? [{ section: 'usuarios' as const, description: 'Personas con acceso', resource: users.resource }] : []),
  ]

  return (
    <div className={`workspace${sidebarCollapsed ? ' sidebar-collapsed' : ''}${mobileMenuOpen ? ' mobile-menu-open' : ''}`}>
      {mobileMenuOpen && <div className="sidebar-backdrop" aria-hidden="true" onClick={() => setMobileMenuOpen(false)} />}
      <aside ref={sidebarRef} id="workspace-sidebar" className="workspace-sidebar" role={mobileMenuOpen ? 'dialog' : undefined} aria-modal={mobileMenuOpen ? true : undefined} aria-label={mobileMenuOpen ? 'Menú de navegación' : undefined}>
        <div className="workspace-sidebar-top">
          <div className="workspace-brand"><span className="workspace-brand-mark">$</span><span className="workspace-brand-name">Cuadre<span>Caja</span></span></div>
          <button
            className="sidebar-toggle"
            type="button"
            aria-label={sidebarCollapsed ? 'Expandir menú lateral' : 'Contraer menú lateral'}
            aria-expanded={!sidebarCollapsed}
            aria-controls="workspace-navigation"
            onClick={() => setSidebarCollapsed((collapsed) => !collapsed)}
          >
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none">
              <path d="m14 6-6 6 6 6" />
            </svg>
          </button>
          <button ref={mobileCloseButtonRef} className="mobile-sidebar-close" type="button" aria-label="Cerrar menú" onClick={() => setMobileMenuOpen(false)}>
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none"><path d="M6 6 18 18M18 6 6 18" /></svg>
          </button>
        </div>
        <p className="sidebar-label">ESPACIO DE TRABAJO</p>
        <nav className="workspace-nav" id="workspace-navigation" aria-label="Navegación principal">
          {sections.map((item) => (
            <button type="button" key={item} title={labels[item]} aria-label={labels[item]} className={section === item ? 'selected' : ''} aria-current={section === item ? 'page' : undefined} onClick={() => navigate(item)}>
              <span className="nav-icon" aria-hidden="true">{item === 'inicio' ? '⌂' : item === 'cajas' ? '▣' : '◈'}</span>
              <span className="nav-label">{labels[item]}</span>
            </button>
          ))}
          {(canManageLocations || canViewBranches || canManageRoles || canManageUsers || canManageExpenseTypes) && (
            <div className="nav-group">
              <button type="button" className={settingsSections.includes(section) ? 'nav-group-active' : ''} title="Configuración" aria-label="Configuración" aria-expanded={settingsOpen} aria-controls="settings-navigation" onClick={() => {
                if (sidebarCollapsed && window.matchMedia('(min-width: 761px)').matches) {
                  setSidebarCollapsed(false)
                  setSettingsOpen(true)
                } else {
                  setSettingsOpen((open) => !open)
                }
              }}>
                <span className="nav-icon" aria-hidden="true">⚙</span>
                <span className="nav-label">Configuración</span>
                <svg className={`nav-chevron${settingsOpen ? ' open' : ''}`} aria-hidden="true" viewBox="0 0 24 24" fill="none">
                  <path d="m6 9 6 6 6-6" />
                </svg>
              </button>
              <div className="nav-submenu" id="settings-navigation" hidden={!settingsOpen}>
                {canManageLocations && <button type="button" title="Ubicaciones" aria-label="Ubicaciones" className={section === 'ubicaciones' ? 'selected' : ''} aria-current={section === 'ubicaciones' ? 'page' : undefined} onClick={() => navigate('ubicaciones')}>
                  <span className="submenu-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M19 10c0 5-7 10-7 10S5 15 5 10a7 7 0 1 1 14 0Z" /><circle cx="12" cy="10" r="2.5" /></svg>
                  </span>
                  <span className="nav-label submenu-copy"><span>Ubicaciones</span><small>Departamentos y municipios</small></span>
                </button>}
                {canViewBranches && <button type="button" title="Sucursales" aria-label="Sucursales" className={section === 'sucursales' ? 'selected' : ''} aria-current={section === 'sucursales' ? 'page' : undefined} onClick={() => navigate('sucursales')}>
                  <span className="submenu-icon" aria-hidden="true">▦</span>
                  <span className="nav-label submenu-copy"><span>Sucursales</span><small>Puntos del negocio</small></span>
                </button>}
                {canManageExpenseTypes && <button type="button" title="Tipos de gasto" aria-label="Tipos de gasto" className={section === 'tipos-gasto' ? 'selected' : ''} aria-current={section === 'tipos-gasto' ? 'page' : undefined} onClick={() => navigate('tipos-gasto')}>
                  <span className="submenu-icon" aria-hidden="true">$</span>
                  <span className="nav-label submenu-copy"><span>Tipos de gasto</span><small>Catálogo global</small></span>
                </button>}
                {canManageRoles && <button type="button" title="Roles" aria-label="Roles" className={section === 'roles' ? 'selected' : ''} aria-current={section === 'roles' ? 'page' : undefined} onClick={() => navigate('roles')}>
                  <span className="submenu-icon" aria-hidden="true">♙</span>
                  <span className="nav-label submenu-copy"><span>Roles</span><small>Permisos por módulo</small></span>
                </button>}
                {canManageUsers && <button type="button" title="Usuarios" aria-label="Usuarios" className={section === 'usuarios' ? 'selected' : ''} aria-current={section === 'usuarios' ? 'page' : undefined} onClick={() => navigate('usuarios')}>
                  <span className="submenu-icon" aria-hidden="true">♙</span>
                  <span className="nav-label submenu-copy"><span>Usuarios</span><small>Personas con acceso</small></span>
                </button>}
              </div>
            </div>
          )}
        </nav>
        <div className="sidebar-bottom"><span className="sidebar-dot" /> Tu negocio, en orden</div>
      </aside>

      <div className="workspace-body">
        <header className="workspace-header">
          <div className="header-heading">
            <button ref={mobileMenuButtonRef} className="mobile-menu-button" type="button" aria-label="Abrir menú" aria-expanded={mobileMenuOpen} aria-controls="workspace-sidebar" onClick={() => setMobileMenuOpen(true)}>
              <svg aria-hidden="true" viewBox="0 0 24 24" fill="none"><path d="M4 7h16M4 12h16M4 17h16" /></svg>
            </button>
            <div className="header-title"><span className="header-overline">MI ESPACIO</span><strong>{labels[section]}</strong></div>
          </div>
          <div className="header-actions">
            <div className="user-menu" ref={userMenuRef}>
              <button ref={userMenuButtonRef} className="header-user" type="button" aria-label="Opciones de usuario" aria-expanded={userMenuOpen} aria-controls="user-menu-options" onClick={() => setUserMenuOpen((open) => !open)}>
                <span className="user-avatar">{user.name.charAt(0).toUpperCase()}</span><div><strong>{user.name}</strong><span>{user.role.name}</span></div><span aria-hidden="true">▾</span>
              </button>
              {userMenuOpen && <div className="user-menu-options" id="user-menu-options">
                <button type="button" onClick={() => openProfile('perfil')}><svg aria-hidden="true" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="3.5" /><path d="M5 20a7 7 0 0 1 14 0" /></svg>Editar perfil</button>
                <button type="button" onClick={() => openProfile('contrasena')}><svg aria-hidden="true" viewBox="0 0 24 24" fill="none"><rect x="5" y="10" width="14" height="10" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v2" /></svg>Cambiar contraseña</button>
                <button type="button" disabled={logoutBusy} onClick={onLogout}><svg aria-hidden="true" viewBox="0 0 24 24" fill="none"><path d="M10 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4M14 8l4 4-4 4M8 12h10" /></svg>{logoutBusy ? 'Cerrando...' : 'Cerrar sesión'}</button>
              </div>}
            </div>
          </div>
        </header>

        <main className="workspace-content">
          {logoutError && <p className="error" role="alert">{logoutError}</p>}
          {refreshError && <p className="error" role="alert">{refreshError}</p>}
          {profileNotice && <p className="user-notice" role="status">{profileNotice}</p>}
          {section === 'inicio' ? (
            <>
              <div className="welcome-panel">
                <div><span className="welcome-kicker">TU ESPACIO DE TRABAJO</span><h1>¡Hola, {user.name}!</h1><p>Consulta la información disponible de tu negocio desde un solo lugar.</p></div>
                <span className="welcome-symbol" aria-hidden="true">$</span>
              </div>
              <div className="section-heading"><div><span className="workspace-kicker">UN VISTAZO GENERAL</span><h2>Tu organización</h2><p>Información disponible según tus permisos.</p></div></div>
              <div className="summary-grid">
                {summaries.map(({ section: item, description, resource }) => (
                  <button className="summary-card" key={item} type="button" onClick={() => navigate(item)}>
                    <span className="summary-icon" aria-hidden="true">{item === 'sucursales' ? '▦' : item === 'cajas' ? '▣' : item === 'cuentas' ? '◈' : '♙'}</span>
                    <span className="summary-title">{labels[item]}</span>
                    <strong>{resource.status === 'ready' ? resource.data.length : resource.status === 'loading' ? '…' : '—'}</strong>
                    <span className="summary-description">{resource.status === 'error' ? 'No se pudo cargar' : description}</span>
                    <span className="summary-link">Ver detalle <span aria-hidden="true">→</span></span>
                  </button>
                ))}
              </div>
              <div className="coming-panel"><span className="coming-icon" aria-hidden="true">✦</span><div><strong>Seguimos construyendo tu espacio</strong><p>El registro de ventas, gastos y los cuadres diarios se incorporarán en la siguiente fase.</p></div></div>
            </>
          ) : (
            <>
              <div className="section-heading"><div><span className="workspace-kicker">{settingsSections.includes(section) ? 'CONFIGURACIÓN' : 'MI ORGANIZACIÓN'}</span><h1>{labels[section]}</h1><p>{section === 'usuarios' ? 'Gestiona los usuarios y su rol.' : section === 'roles' ? 'Administra los permisos de cada rol.' : section === 'ubicaciones' ? 'Administra los departamentos y municipios.' : section === 'sucursales' ? 'Consulta y administra tus sucursales según tus permisos.' : 'Consulta la información disponible para tu usuario.'}</p></div></div>
              <section className="records-panel" aria-label={`Listado de ${labels[section].toLowerCase()}`}>
                <div className="records-heading"><h2>{section === 'ubicaciones' ? 'Ubicaciones' : `Listado de ${labels[section].toLowerCase()}`}</h2>{section === 'cuentas' && <span>Solo consulta</span>}</div>
                {section === 'sucursales' && (branches.resource.status === 'ready'
                  ? <BranchManagement token={token} branches={branches.resource.data} canCreate={can('branches.create')} onSaved={branches.reload} onSessionExpired={onSessionExpired} />
                  : <ResourceView<Branch> resource={branches.resource} reload={branches.reload} empty="No hay sucursales disponibles para tu usuario." render={(branch) => <RecordRow key={branch.id} title={branch.name} detail={[branch.code, branch.municipality?.name, branch.municipality?.department.name, branch.address].filter(Boolean).join(' · ')} status={branch.is_active} /> } />)}
                {section === 'cajas' && (registers.resource.status === 'ready'
                  ? <CashRegisterManagement token={token} registers={registers.resource.data} canCreate={can('registers.create')} onSaved={registers.reload} onSessionExpired={onSessionExpired} />
                  : <ResourceView resource={registers.resource} reload={registers.reload} empty="No hay cajas disponibles para tu usuario." render={() => null} />)}
                {section === 'cuentas' && canViewAccounts && <ResourceView resource={accounts.resource} reload={accounts.reload} empty="No hay cuentas disponibles para tu usuario." render={(account) => <RecordRow key={account.id} title={account.name} detail={`${accountTypes[account.type] ?? account.type} · ${account.branch.name}`} status={account.is_active} />} />}
                {section === 'usuarios' && canManageUsers && (users.resource.status === 'ready' && roles.resource.status === 'ready'
                  ? <UserManagement token={token} currentUser={user} users={users.resource.data} availableRoles={roles.resource.data} onSaved={users.reload} onSessionExpired={onSessionExpired} onUserUpdated={(updated) => { if (!updated.permissions.includes('users.manage')) setSection('inicio'); onUserUpdated(updated); roles.reload(); branches.reload(); registers.reload(); accounts.reload() }} />
                  : roles.resource.status !== 'ready'
                    ? <ResourceView resource={roles.resource} reload={roles.reload} empty="No hay roles disponibles." render={() => null} />
                  : <ResourceView resource={users.resource} reload={users.reload} empty="No hay usuarios registrados." render={() => null} />)}
                {section === 'roles' && canManageRoles && (roles.resource.status === 'ready'
                  ? <RoleManagement token={token} roles={roles.resource.data} availablePermissions={user.permissions} onSaved={refreshAfterRoleChange} onSessionExpired={onSessionExpired} />
                  : <ResourceView resource={roles.resource} reload={roles.reload} empty="No hay roles disponibles." render={() => null} />)}
                {section === 'ubicaciones' && canManageLocations && <LocationManagement token={token} onSessionExpired={onSessionExpired} />}
                {section === 'tipos-gasto' && canManageExpenseTypes && <ExpenseTypeManagement token={token} onSessionExpired={onSessionExpired} />}
              </section>
            </>
          )}
        </main>
        <footer className="workspace-footer">CuadreCaja <span>·</span> Un espacio para tu negocio</footer>
      </div>
      {profileMode && <ProfileManagement key={profileMode} mode={profileMode} user={user} token={token} onClose={closeProfile} onUserUpdated={(updated) => { onUserUpdated(updated); if (canManageUsers) users.reload(); setProfileNotice('Perfil actualizado correctamente.') }} onSessionExpired={onSessionExpired} onPasswordChanged={onPasswordChanged} />}
    </div>
  )
}

export default Workspace
