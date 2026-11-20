import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import Swal from 'sweetalert2'
import { ApiError, request } from './api'
import './UserManagement.css'
import './BranchManagement.css'

export type Role = { id: number; name: string; permissions: string[]; branch_ids: number[]; users_count: number }
type Options = {
  global: Record<string, string>
  branch: Record<string, string>
  branches: { id: number; name: string; code: string; is_active: boolean }[]
}

function RoleManagement({ token, roles, onSaved, onSessionExpired }: {
  token: string
  roles: Role[]
  onSaved: () => void
  onSessionExpired: () => void
}) {
  const [options, setOptions] = useState<Options | null>(null)
  const [editing, setEditing] = useState<Role | 'new' | null>(null)
  const [name, setName] = useState('')
  const [permissions, setPermissions] = useState<string[]>([])
  const [branchIds, setBranchIds] = useState<number[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [retry, setRetry] = useState(0)

  useEffect(() => {
    let active = true
    request<Options>('/roles/options', { headers: { Authorization: `Bearer ${token}` } })
      .then((data) => { if (active) setOptions(data) })
      .catch((err: unknown) => {
        if (!active) return
        if (err instanceof ApiError && err.status === 401) onSessionExpired()
        else setError(err instanceof Error ? err.message : 'No se pudieron cargar las opciones.')
      })
    return () => { active = false }
  }, [token, onSessionExpired, retry])

  function openForm(role: Role | 'new') {
    setEditing(role)
    setName(role === 'new' ? '' : role.name)
    setPermissions(role === 'new' ? [] : role.permissions)
    setBranchIds(role === 'new' ? [] : role.branch_ids)
    setNotice('')
    setError('')
  }

  function handleError(err: unknown) {
    if (err instanceof ApiError && err.status === 401) onSessionExpired()
    else setError(err instanceof Error ? err.message : 'No se pudo completar la operación.')
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!editing) return
    setBusy(true)
    setError('')
    try {
      await request<Role>(editing === 'new' ? '/roles' : `/roles/${editing.id}`, {
        method: editing === 'new' ? 'POST' : 'PUT',
        headers: { Authorization: `Bearer ${token}` },
        body: JSON.stringify({ name: name.trim(), permissions, branch_ids: branchIds }),
      })
      setEditing(null)
      setNotice('Rol guardado correctamente.')
      onSaved()
    } catch (err) {
      handleError(err)
    } finally {
      setBusy(false)
    }
  }

  async function remove(role: Role) {
    const result = await Swal.fire({
      title: '¿Eliminar este rol?',
      text: `Se eliminará el rol ${role.name}. Esta acción no se puede deshacer.`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Sí, eliminar',
      cancelButtonText: 'Cancelar',
      confirmButtonColor: '#14284f',
      cancelButtonColor: '#66758b',
      focusCancel: true,
      heightAuto: false,
    })
    if (!result.isConfirmed) return
    setBusy(true)
    setError('')
    setNotice('')
    try {
      await request(`/roles/${role.id}`, { method: 'DELETE', headers: { Authorization: `Bearer ${token}` } })
      setNotice('Rol eliminado correctamente.')
      onSaved()
    } catch (err) {
      handleError(err)
    } finally {
      setBusy(false)
    }
  }

  function permissionGroup(title: string, entries: Record<string, string>) {
    return <fieldset className="branch-users"><legend>{title}</legend><div className="branch-user-list">{Object.entries(entries).map(([key, label]) => (
      <label key={key}><input type="checkbox" checked={permissions.includes(key)} onChange={(event) => setPermissions(event.target.checked ? [...permissions, key] : permissions.filter((item) => item !== key))} /><span>{label}</span></label>
    ))}</div></fieldset>
  }

  return <div className="user-management branch-management">
    {notice && <p className="user-notice" role="status">{notice}</p>}
    {error && <p className="user-error" role="alert">{error}</p>}
    {!options ? error ? <button className="user-secondary" type="button" onClick={() => { setError(''); setRetry((value) => value + 1) }}>Reintentar carga de opciones</button> : <p className="workspace-message" role="status">Cargando opciones...</p> : editing ? (
      <form className="user-form" onSubmit={submit}>
        <div className="user-form-heading"><h2>{editing === 'new' ? 'Crear rol' : 'Editar rol'}</h2><p>Los permisos por sucursal solo aplican a las sucursales seleccionadas. Solo puedes delegar acceso dentro de tu alcance.</p></div>
        <div className="user-fields"><div className="user-field"><label htmlFor="role-name">Nombre del rol</label><input id="role-name" required minLength={3} maxLength={100} pattern={'[a-z0-9._\\-]+'} readOnly={editing !== 'new' && ['supervisor', 'cajero'].includes(editing.name)} value={name} onChange={(event) => setName(event.target.value)} /><small>Letras minúsculas, números, puntos, guiones y guiones bajos.</small></div></div>
        {permissionGroup('Permisos globales', options.global)}
        {permissionGroup('Permisos por sucursal', options.branch)}
        <fieldset className="branch-users"><legend>Sucursales autorizadas</legend><p>Todos los usuarios de este rol comparten las sucursales seleccionadas.</p><div className="branch-user-list">{options.branches.map((branch) => <label key={branch.id}><input type="checkbox" checked={branchIds.includes(branch.id)} onChange={(event) => setBranchIds(event.target.checked ? [...branchIds, branch.id] : branchIds.filter((id) => id !== branch.id))} /><span>{branch.name} · {branch.code}{!branch.is_active && ' (inactiva)'}</span></label>)}</div>{options.branches.length === 0 && <p>No hay sucursales disponibles.</p>}</fieldset>
        <div className="user-form-actions"><button className="user-primary" disabled={busy} type="submit">{busy ? 'Guardando...' : 'Guardar rol'}</button><button className="user-secondary" disabled={busy} type="button" onClick={() => { setEditing(null); setError('') }}>Cancelar</button></div>
      </form>
    ) : <>
      <div className="user-toolbar"><p>Configura los permisos y las sucursales de cada rol.</p><button className="user-primary" disabled={busy} type="button" onClick={() => openForm('new')}>+ Crear rol</button></div>
      {roles.length === 0 ? <p className="workspace-message">No hay roles disponibles.</p> : <div className="record-list">{roles.map((role) => <div className="record-row user-row" key={role.id}><div className="record-symbol" aria-hidden="true">♙</div><div className="record-copy"><strong>{role.name}</strong><span>{role.name === 'admin' ? 'Acceso global protegido' : `${role.permissions.length} permisos · ${role.branch_ids.length} sucursales · ${role.users_count} usuarios`}</span></div>{role.name !== 'admin' && <div className="user-row-actions"><button type="button" disabled={busy} onClick={() => openForm(role)}>Editar</button><button type="button" disabled={busy || role.users_count > 0 || role.branch_ids.length > 0} title={role.users_count > 0 || role.branch_ids.length > 0 ? 'Quita primero las asignaciones de usuarios y sucursales' : 'Eliminar rol'} onClick={() => remove(role)}>Eliminar</button></div>}</div>)}</div>}
    </>}
  </div>
}

export default RoleManagement
