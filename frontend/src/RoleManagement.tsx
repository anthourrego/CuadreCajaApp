import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import Swal from 'sweetalert2'
import { ApiError, request } from './api'
import './UserManagement.css'
import './BranchManagement.css'

export type Role = { id: number; name: string; permissions: string[]; users_count: number }
type Options = {
  global: Record<string, string>
  branch: Record<string, string>
}

type PermissionOption = { key: string; label: string; scope: 'Global' | 'Por sucursal' }
const modules = [
  { prefix: 'branches', label: 'Sucursales' },
  { prefix: 'registers', label: 'Cajas' },
  { prefix: 'users', label: 'Usuarios' },
  { prefix: 'roles', label: 'Roles' },
  { prefix: 'locations', label: 'Ubicaciones' },
  { prefix: 'expenses', label: 'Gastos' },
  { prefix: 'accounts', label: 'Cuentas' },
]

function RoleManagement({ token, roles, availablePermissions, onSaved, onSessionExpired }: {
  token: string
  roles: Role[]
  availablePermissions: string[]
  onSaved: () => void
  onSessionExpired: () => void
}) {
  const [options, setOptions] = useState<Options | null>(null)
  const [editing, setEditing] = useState<Role | 'new' | null>(null)
  const [name, setName] = useState('')
  const [permissions, setPermissions] = useState<string[]>([])
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
        body: JSON.stringify({ name: name.trim(), permissions }),
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

  function permissionTree(options: Options) {
    const entries: PermissionOption[] = [
      ...Object.entries(options.global).map(([key, label]) => ({ key, label, scope: 'Global' as const })),
      ...Object.entries(options.branch).map(([key, label]) => ({ key, label, scope: 'Por sucursal' as const })),
    ]
    const groups = modules.map(({ prefix, label }) => ({ label, entries: entries.filter((entry) => entry.key.startsWith(`${prefix}.`)) }))
    const other = entries.filter((entry) => !modules.some(({ prefix }) => entry.key.startsWith(`${prefix}.`)))
    if (other.length) groups.push({ label: 'Otros permisos', entries: other })

    return <div className="permission-tree">{groups.filter((group) => group.entries.length > 0).map((group) => {
      const available = group.entries.filter((entry) => availablePermissions.includes(entry.key)).map((entry) => entry.key)
      const selected = available.filter((key) => permissions.includes(key)).length
      return <details key={group.label} className="permission-module" open>
        <summary><span>{group.label}</span><small>{group.entries.filter((entry) => permissions.includes(entry.key)).length} de {group.entries.length} seleccionados</small></summary>
        <div className="permission-module-content">
          <label className="permission-select-all"><input type="checkbox" checked={available.length > 0 && selected === available.length} disabled={busy || available.length === 0} ref={(input) => { if (input) input.indeterminate = selected > 0 && selected < available.length }} onChange={(event) => {
            const checked = event.target.checked
            setPermissions((current) => checked ? [...current, ...available.filter((key) => !current.includes(key))] : current.filter((key) => !available.includes(key)))
          }} />Seleccionar todos los permisos disponibles</label>
          <div className="permission-list">{group.entries.map(({ key, label, scope }) => (
            <label key={key} className="permission-item"><input type="checkbox" disabled={busy || !availablePermissions.includes(key)} checked={permissions.includes(key)} onChange={(event) => {
              const checked = event.target.checked
              setPermissions((current) => checked ? [...current, key] : current.filter((item) => item !== key))
            }} /><span>{label}</span><small>{scope}</small></label>
          ))}</div>
        </div>
      </details>
    })}</div>
  }

  return <div className="user-management branch-management">
    {notice && <p className="user-notice" role="status">{notice}</p>}
    {error && <p className="user-error" role="alert">{error}</p>}
    {!options ? error ? <button className="user-secondary" type="button" onClick={() => { setError(''); setRetry((value) => value + 1) }}>Reintentar carga de opciones</button> : <p className="workspace-message" role="status">Cargando opciones...</p> : editing ? (
      <form className="user-form" onSubmit={submit}>
        <div className="user-form-heading"><h2>{editing === 'new' ? 'Crear rol' : 'Editar rol'}</h2><p>Los roles definen permisos; el acceso a sucursales se asigna a cada usuario. Solo puedes delegar permisos dentro de tu alcance.</p></div>
        <div className="user-fields"><div className="user-field"><label htmlFor="role-name">Nombre del rol</label><input id="role-name" required minLength={3} maxLength={100} pattern={'[a-z0-9._\\-]+'} readOnly={editing !== 'new' && ['supervisor', 'cajero'].includes(editing.name)} value={name} onChange={(event) => setName(event.target.value)} /><small>Letras minúsculas, números, puntos, guiones y guiones bajos.</small></div></div>
        {permissionTree(options)}
        <div className="user-form-actions"><button className="user-primary" disabled={busy} type="submit">{busy ? 'Guardando...' : 'Guardar rol'}</button><button className="user-secondary" disabled={busy} type="button" onClick={() => { setEditing(null); setError('') }}>Cancelar</button></div>
      </form>
    ) : <>
      <div className="user-toolbar"><p>Configura los permisos de cada rol.</p><button className="user-primary" disabled={busy} type="button" onClick={() => openForm('new')}>+ Crear rol</button></div>
      {roles.length === 0 ? <p className="workspace-message">No hay roles disponibles.</p> : <div className="record-list">{roles.map((role) => <div className="record-row user-row" key={role.id}><div className="record-symbol" aria-hidden="true">♙</div><div className="record-copy"><strong>{role.name}</strong><span>{role.name === 'admin' ? 'Acceso global protegido' : `${role.permissions.length} permisos · ${role.users_count} usuarios`}</span></div>{role.name !== 'admin' && <div className="user-row-actions"><button type="button" disabled={busy} onClick={() => openForm(role)}>Editar</button><button type="button" disabled={busy || role.users_count > 0} title={role.users_count > 0 ? 'Quita primero las asignaciones de usuarios' : 'Eliminar rol'} onClick={() => remove(role)}>Eliminar</button></div>}</div>)}</div>}
    </>}
  </div>
}

export default RoleManagement
