import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import Swal from 'sweetalert2'
import { ApiError, request } from './api'
import type { BranchSummary, User } from './api'
import EmptyState from './EmptyState'
import './UserManagement.css'
import './BranchManagement.css'

type Props = {
  token: string
  currentUser: User
  users: User[]
  availableRoles: { id: number; name: string }[]
  onSaved: () => void
  onUserUpdated: (user: User) => void
  onSessionExpired: () => void
}

type BranchOptions =
  | { status: 'loading' }
  | { status: 'ready'; data: (BranchSummary & { is_active: boolean })[] }
  | { status: 'error'; message: string }

function UserManagement({ token, currentUser, users, availableRoles, onSaved, onUserUpdated, onSessionExpired }: Props) {
  const [editing, setEditing] = useState<User | 'new' | null>(null)
  const [name, setName] = useState('')
  const [username, setUsername] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [roleId, setRoleId] = useState('')
  const [selectedBranchIds, setSelectedBranchIds] = useState<number[]>([])
  const [defaultBranchId, setDefaultBranchId] = useState('')
  const [branchOptions, setBranchOptions] = useState<BranchOptions>({ status: 'loading' })
  const [branchRetry, setBranchRetry] = useState(0)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const formOpen = editing !== null

  useEffect(() => {
    if (!formOpen) return
    let active = true
    request<(BranchSummary & { is_active: boolean })[]>('/users/branch-options', { headers: { Authorization: `Bearer ${token}` } })
      .then((data) => {
        if (!Array.isArray(data)) throw new Error('El servidor devolvió una respuesta inválida.')
        if (active) setBranchOptions({ status: 'ready', data })
      })
      .catch((err: unknown) => {
        if (!active) return
        if (err instanceof ApiError && err.status === 401) onSessionExpired()
        else setBranchOptions({ status: 'error', message: err instanceof Error ? err.message : 'No se pudieron cargar las sucursales.' })
      })
    return () => { active = false }
  }, [formOpen, token, branchRetry, onSessionExpired])

  const eligibleBranches = branchOptions.status === 'ready'
    ? branchOptions.data.filter((branch) => branch.is_active && selectedBranchIds.includes(branch.id))
    : []
  const selectedBranchId = eligibleBranches.some((branch) => String(branch.id) === defaultBranchId) ? defaultBranchId : ''

  function openForm(user: User | 'new') {
    setEditing(user)
    setName(user === 'new' ? '' : user.name)
    setUsername(user === 'new' ? '' : user.username)
    setEmail(user === 'new' ? '' : user.email)
    setRoleId(user === 'new' ? '' : String(user.role_id))
    setSelectedBranchIds(user === 'new' ? [] : user.branch_ids)
    setDefaultBranchId(user === 'new' || user.default_branch_id === null ? '' : String(user.default_branch_id))
    setBranchOptions({ status: 'loading' })
    setPassword('')
    setConfirmation('')
    setError('')
    setNotice('')
  }

  function handleError(err: unknown) {
    if (err instanceof ApiError && err.status === 401) {
      onSessionExpired()
    } else {
      setError(err instanceof Error ? err.message : 'No se pudo completar la operación.')
    }
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!editing) return
    setError('')
    if (!roleId) {
      setError('Selecciona un rol.')
      return
    }
    if (branchOptions.status !== 'ready') {
      setError('Carga las sucursales disponibles antes de guardar.')
      return
    }
    if ((editing === 'new' || password || confirmation) && (password.length < 12 || password !== confirmation)) {
      setError('La contraseña debe tener al menos 12 caracteres y coincidir con la confirmación.')
      return
    }
    setBusy(true)
    try {
      const data = {
        name: name.trim(), email: email.trim(), role_id: Number(roleId),
        ...(editing === 'new' ? { username: username.trim() } : {}),
        branch_ids: selectedBranchIds,
        default_branch_id: selectedBranchId ? Number(selectedBranchId) : null,
        ...(password ? { password, password_confirmation: confirmation } : {}),
      }
      const result = await request<User>(editing === 'new' ? '/users' : `/users/${editing.id}`, {
        method: editing === 'new' ? 'POST' : 'PUT',
        headers: { Authorization: `Bearer ${token}` },
        body: JSON.stringify(data),
      })
      if (result.id === currentUser.id && password) {
        onSessionExpired()
        return
      }
      if (result.id === currentUser.id) onUserUpdated(result)
      setEditing(null)
      setPassword('')
      setConfirmation('')
      setNotice('Usuario guardado correctamente.')
      onSaved()
    } catch (err) {
      handleError(err)
    } finally {
      setBusy(false)
    }
  }

  async function toggleActive(user: User) {
    const action = user.is_active ? 'desactivar' : 'reactivar'
    const confirmation = await Swal.fire({
      title: `¿Deseas ${action} a este usuario?`,
      text: `${user.name}${user.is_active ? '. Se cerrarán sus sesiones.' : ' podrá volver a iniciar sesión.'}`,
      icon: user.is_active ? 'warning' : 'question',
      showCancelButton: true,
      confirmButtonText: user.is_active ? 'Sí, desactivar' : 'Sí, reactivar',
      cancelButtonText: 'Cancelar',
      confirmButtonColor: '#14284f',
      cancelButtonColor: '#66758b',
      focusCancel: true,
      heightAuto: false,
    })
    if (!confirmation.isConfirmed) return
    setBusy(true)
    setError('')
    setNotice('')
    try {
      await request<User>(`/users/${user.id}`, {
        method: 'PUT',
        headers: { Authorization: `Bearer ${token}` },
        body: JSON.stringify({ is_active: !user.is_active }),
      })
      setNotice(user.is_active ? 'Usuario desactivado correctamente.' : 'Usuario reactivado correctamente.')
      onSaved()
    } catch (err) {
      handleError(err)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="user-management">
      {notice && <p className="user-notice" role="status">{notice}</p>}
      {error && <p className="user-error" role="alert">{error}</p>}
      {editing ? (
        <form className="user-form" aria-busy={busy} onSubmit={handleSubmit}>
          <div className="user-form-heading"><div><h2>{editing === 'new' ? 'Crear usuario' : 'Editar usuario'}</h2><p>Completa la información y asigna un rol de acceso.</p></div></div>
          <div className="user-fields">
            <div className="user-field"><label htmlFor="user-name">Nombre completo</label><input id="user-name" required maxLength={255} value={name} onChange={(event) => setName(event.target.value)} /></div>
            <div className="user-field"><label htmlFor="user-username">Usuario</label><input id="user-username" required minLength={3} maxLength={64} pattern={'[A-Za-z0-9._\\-]+'} autoCapitalize="none" autoComplete="off" value={username} readOnly={editing !== 'new'} onChange={(event) => setUsername(event.target.value)} /><small>{editing === 'new' ? 'Letras, números, puntos, guiones y guiones bajos. No se podrá modificar después de crear el usuario.' : 'El nombre de usuario no se puede modificar.'}</small></div>
            <div className="user-field"><label htmlFor="user-email">Correo electrónico</label><input id="user-email" type="email" required maxLength={255} value={email} onChange={(event) => setEmail(event.target.value)} /></div>
            <div className="user-field"><label htmlFor="user-password">{editing === 'new' ? 'Contraseña' : 'Nueva contraseña (opcional)'}</label><input id="user-password" type="password" autoComplete="new-password" required={editing === 'new'} minLength={12} value={password} onChange={(event) => setPassword(event.target.value)} /><small>Mínimo 12 caracteres. Al cambiarla se cierran las sesiones existentes.</small></div>
            <div className="user-field"><label htmlFor="user-confirm">Confirmar contraseña</label><input id="user-confirm" type="password" autoComplete="new-password" required={editing === 'new' || Boolean(password)} minLength={12} value={confirmation} onChange={(event) => setConfirmation(event.target.value)} /></div>
            <div className="user-field"><label htmlFor="user-role">Rol</label><select id="user-role" required value={roleId} onChange={(event) => setRoleId(event.target.value)}><option value="" disabled>Selecciona un rol</option>{availableRoles.map((role) => <option key={role.id} value={role.id}>{role.name}</option>)}</select></div>
            <fieldset className="branch-users user-branch-assignment"><legend>Sucursales asignadas</legend><p>El usuario solo podrá acceder a sucursales asignadas aquí y autorizadas por sus permisos.</p><div className="branch-user-list">{branchOptions.status === 'ready' ? branchOptions.data.map((branch) => <label key={branch.id}><input type="checkbox" checked={selectedBranchIds.includes(branch.id)} onChange={(event) => setSelectedBranchIds(event.target.checked ? [...selectedBranchIds, branch.id] : selectedBranchIds.filter((id) => id !== branch.id))} /><span>{branch.name} · {branch.code}{!branch.is_active && ' (inactiva)'}</span></label>) : null}</div>{branchOptions.status === 'ready' && branchOptions.data.length === 0 && <p>No tienes sucursales disponibles para asignar.</p>}</fieldset>
            <div className="user-field">
              <label htmlFor="user-default-branch">Sucursal por defecto (opcional)</label>
              <select id="user-default-branch" value={selectedBranchId} disabled={branchOptions.status !== 'ready'} onChange={(event) => setDefaultBranchId(event.target.value)}>
                <option value="">Sin sucursal por defecto</option>
                {eligibleBranches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name} · {branch.code}</option>)}
              </select>
              {branchOptions.status === 'loading' && <small role="status">Cargando sucursales...</small>}
              {branchOptions.status === 'error' && <small role="alert">{branchOptions.message} <button type="button" onClick={() => { setBranchOptions({ status: 'loading' }); setBranchRetry((value) => value + 1) }}>Reintentar</button></small>}
              {branchOptions.status === 'ready' && <small>{eligibleBranches.length ? 'Se selecciona al entrar a Cajas. No cambia los permisos del usuario.' : 'Asigna una sucursal activa para poder elegir una predeterminada.'}</small>}
            </div>
          </div>
          <div className="user-form-actions"><button type="submit" className="user-primary" disabled={busy}>{busy ? 'Guardando...' : 'Guardar usuario'}</button><button type="button" className="user-secondary" disabled={busy} onClick={() => { setEditing(null); setError(''); setPassword(''); setConfirmation('') }}>Cancelar</button></div>
        </form>
      ) : (
        <>
          <div className="user-toolbar"><p>Administra el acceso y el rol de tus usuarios.</p><button className="user-primary" type="button" disabled={busy} onClick={() => openForm('new')}>+ Crear usuario</button></div>
          {users.length === 0 ? <EmptyState title="Aún no hay usuarios" description="Crea cuentas para dar acceso a tu equipo y asigna a cada persona el rol adecuado." actionLabel="+ Crear usuario" onAction={() => openForm('new')} /> : (
            <div className="record-list">
              {users.map((item) => (
                <div className="record-row user-row" key={item.id}>
                  <div className="record-symbol" aria-hidden="true">♙</div>
                  <div className="record-copy"><strong>{item.name}</strong><span>@{item.username} · {item.email}</span><span>{item.role.name}</span></div>
                  <span className={`record-status ${item.is_active ? 'active' : 'inactive'}`}>{item.is_active ? 'Activo' : 'Inactivo'}</span>
                  <div className="user-row-actions"><button type="button" disabled={busy} aria-label={`Editar ${item.name}`} onClick={() => openForm(item)}>Editar</button>{item.id !== currentUser.id && <button type="button" disabled={busy} aria-label={`${item.is_active ? 'Desactivar' : 'Reactivar'} ${item.name}`} onClick={() => toggleActive(item)}>{item.is_active ? 'Desactivar' : 'Reactivar'}</button>}</div>
                </div>
              ))}
            </div>
          )}
        </>
      )}
    </div>
  )
}

export default UserManagement
