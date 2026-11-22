import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import Swal from 'sweetalert2'
import { ApiError, request } from './api'
import type { BranchSummary } from './api'
import EmptyState from './EmptyState'
import { useSessionFilter } from './useSessionFilter'
import './UserManagement.css'

type BranchOption = BranchSummary & { is_active: boolean }
export type Register = {
  id: number
  branch_id: number
  name: string
  code: string
  is_active: boolean
  can_update: boolean
  branch: BranchSummary
}
type OptionsState =
  | { status: 'loading' }
  | { status: 'ready'; data: BranchOption[] }
  | { status: 'error'; message: string }
type DefaultBranchState =
  | { status: 'loading' }
  | { status: 'ready'; branch: BranchSummary | null; branches: BranchSummary[] }
  | { status: 'error'; message: string }

function CashRegisterManagement({ token, registers, canCreate, onSaved, onSessionExpired }: {
  token: string
  registers: Register[]
  canCreate: boolean
  onSaved: () => void
  onSessionExpired: () => void
}) {
  const [editing, setEditing] = useState<Register | 'new' | null>(null)
  const [name, setName] = useState('')
  const [code, setCode] = useState('')
  const [branchId, setBranchId] = useState('')
  const [storedFilterOverride, setStoredFilterOverride] = useSessionFilter('registers.branch', '')
  const filterOverride = storedFilterOverride || null
  const setFilterBranchId = (value: string | null) => setStoredFilterOverride(value ?? '')
  const [defaultBranchState, setDefaultBranchState] = useState<DefaultBranchState>({ status: 'loading' })
  const [defaultBranchRetry, setDefaultBranchRetry] = useState(0)
  const [options, setOptions] = useState<OptionsState>({ status: 'loading' })
  const [retry, setRetry] = useState(0)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  useEffect(() => {
    let active = true
    request<{ default_branch: BranchSummary | null; branches: BranchSummary[] }>('/user', { headers: { Authorization: `Bearer ${token}` } })
      .then(({ default_branch, branches }) => { if (active) setDefaultBranchState({ status: 'ready', branch: default_branch, branches }) })
      .catch((err: unknown) => {
        if (!active) return
        if (err instanceof ApiError && err.status === 401) onSessionExpired()
        else setDefaultBranchState({ status: 'error', message: err instanceof Error ? err.message : 'No se pudo cargar la sucursal por defecto.' })
      })
    return () => { active = false }
  }, [token, onSessionExpired, defaultBranchRetry])

  useEffect(() => {
    if (!canCreate) return
    let active = true
    request<BranchOption[]>('/registers/options', { headers: { Authorization: `Bearer ${token}` } })
      .then((data) => {
        if (!Array.isArray(data)) throw new Error('El servidor devolvió una respuesta inválida.')
        if (active) setOptions({ status: 'ready', data })
      })
      .catch((err: unknown) => {
        if (!active) return
        if (err instanceof ApiError && err.status === 401) onSessionExpired()
        else setOptions({ status: 'error', message: err instanceof Error ? err.message : 'No se pudieron cargar las sucursales.' })
      })
    return () => { active = false }
  }, [token, canCreate, onSessionExpired, retry])

  function handleError(err: unknown) {
    if (err instanceof ApiError && err.status === 401) onSessionExpired()
    else setError(err instanceof Error ? err.message : 'No se pudo completar la operación.')
  }

  function openForm(register: Register | 'new') {
    setEditing(register)
    setName(register === 'new' ? '' : register.name)
    setCode(register === 'new' ? '' : register.code)
    setBranchId(register === 'new' ? '' : String(register.branch_id))
    setError('')
    setNotice('')
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!editing) return
    setBusy(true)
    setError('')
    try {
      await request<Register>(editing === 'new' ? '/registers' : `/registers/${editing.id}`, {
        method: editing === 'new' ? 'POST' : 'PUT',
        headers: { Authorization: `Bearer ${token}` },
        body: JSON.stringify({
          name: name.trim(), code: code.trim(),
          ...(editing === 'new' ? { branch_id: Number(branchId) } : {}),
        }),
      })
      if (editing === 'new') setFilterBranchId(branchId)
      setEditing(null)
      setNotice('Caja guardada correctamente.')
      onSaved()
    } catch (err) {
      handleError(err)
    } finally {
      setBusy(false)
    }
  }

  async function toggle(register: Register) {
    const action = register.is_active ? 'desactivar' : 'reactivar'
    const result = await Swal.fire({
      title: `¿Deseas ${action} esta caja?`,
      text: `${register.name} · ${register.branch.name}`,
      icon: register.is_active ? 'warning' : 'question',
      showCancelButton: true,
      confirmButtonText: `Sí, ${action}`,
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
      await request(`/registers/${register.id}`, {
        method: 'PUT',
        headers: { Authorization: `Bearer ${token}` },
        body: JSON.stringify({ is_active: !register.is_active }),
      })
      setNotice(register.is_active ? 'Caja desactivada correctamente.' : 'Caja reactivada correctamente.')
      onSaved()
    } catch (err) {
      handleError(err)
    } finally {
      setBusy(false)
    }
  }

  const defaultBranch = defaultBranchState.status === 'ready' ? defaultBranchState.branch : null
  const branches = Array.from(new Map([
    ...registers.map((register) => [register.branch.id, register.branch] as const),
    ...(canCreate && options.status === 'ready' ? options.data.map((branch) => [branch.id, branch] as const) : []),
    ...(defaultBranchState.status === 'ready' ? defaultBranchState.branches.map((branch) => [branch.id, branch] as const) : []),
  ]).values()).sort((a, b) => a.name.localeCompare(b.name))
  const filterBranchId = filterOverride ?? (defaultBranch ? String(defaultBranch.id) : '')
  const filtered = registers.filter((register) => filterBranchId === 'all' || String(register.branch_id) === filterBranchId)
  const hasActiveBranch = options.status === 'ready' && options.data.some((branch) => branch.is_active)

  return <div className="user-management">
    {notice && <p className="user-notice" role="status">{notice}</p>}
    {error && <p className="user-error" role="alert">{error}</p>}
    {defaultBranchState.status === 'error' && <div className="resource-error" role="alert"><p>No se pudo cargar la sucursal por defecto. {defaultBranchState.message}</p><button type="button" onClick={() => { setDefaultBranchState({ status: 'loading' }); setDefaultBranchRetry((value) => value + 1) }}>Reintentar</button></div>}
    {canCreate && options.status === 'error' && <div className="resource-error" role="alert"><p>{options.message}</p><button type="button" onClick={() => { setOptions({ status: 'loading' }); setRetry((value) => value + 1) }}>Reintentar carga de sucursales</button></div>}
    {editing ? <form className="user-form" aria-busy={busy} onSubmit={submit}>
      <div className="user-form-heading"><h2>{editing === 'new' ? 'Crear caja' : 'Editar caja'}</h2><p>Cada caja pertenece a una sucursal y tiene un código único dentro de ella.</p></div>
      <div className="user-fields">
        <div className="user-field"><label htmlFor="register-branch">Sucursal</label><select id="register-branch" required disabled={editing !== 'new' || options.status !== 'ready'} value={branchId} onChange={(event) => setBranchId(event.target.value)}><option value="" disabled>Selecciona una sucursal</option>{editing !== 'new' ? <option value={editing.branch_id}>{editing.branch.name} · {editing.branch.code}</option> : options.status === 'ready' && options.data.map((branch) => <option key={branch.id} value={branch.id} disabled={!branch.is_active}>{branch.name} · {branch.code}{!branch.is_active && ' (inactiva)'}</option>)}</select></div>
        <div className="user-field"><label htmlFor="register-name">Nombre</label><input id="register-name" required maxLength={255} value={name} onChange={(event) => setName(event.target.value)} /></div>
        <div className="user-field"><label htmlFor="register-code">Código</label><input id="register-code" required maxLength={255} value={code} onChange={(event) => setCode(event.target.value)} /><small>Puede repetirse en otra sucursal, pero no en la misma.</small></div>
      </div>
      <div className="user-form-actions"><button className="user-primary" disabled={busy || (editing === 'new' && !hasActiveBranch)} type="submit">{busy ? 'Guardando...' : 'Guardar caja'}</button><button className="user-secondary" disabled={busy} type="button" onClick={() => { setEditing(null); setError('') }}>Cancelar</button></div>
    </form> : <>
      <div className="user-toolbar">
        <div className="user-field"><label htmlFor="register-filter-branch">Filtrar por sucursal</label><select id="register-filter-branch" disabled={defaultBranchState.status !== 'ready'} value={filterBranchId} onChange={(event) => setFilterBranchId(event.target.value)}><option value="">Selecciona una sucursal</option><option value="all">Todas las sucursales disponibles</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name} · {branch.code}</option>)}</select></div>
        {canCreate && <button className="user-primary" type="button" disabled={busy || !hasActiveBranch} onClick={() => openForm('new')}>+ Crear caja</button>}
      </div>
      {canCreate && options.status === 'loading' && <p className="workspace-message" role="status">Cargando sucursales...</p>}
      {canCreate && options.status === 'ready' && !hasActiveBranch && <EmptyState title="No hay sucursales activas disponibles" description="Necesitas una sucursal activa y autorizada para crear cajas." />}
      {defaultBranchState.status === 'loading' ? <p className="workspace-message" role="status">Cargando filtro de sucursal...</p> : defaultBranchState.status === 'error' ? null : !filterBranchId ? <EmptyState title="Selecciona una sucursal" description="Elige una sucursal para consultar sus cajas." /> : filtered.length === 0 ? <EmptyState
        title={registers.length === 0 ? 'Aún no hay cajas' : 'No hay cajas para esta selección'}
        description={registers.length === 0 ? 'Crea una caja para empezar a registrar operaciones.' : 'Prueba otra sucursal o muestra todas las cajas disponibles.'}
        actionLabel={registers.length === 0 && canCreate && hasActiveBranch ? '+ Crear caja' : undefined}
        onAction={registers.length === 0 && canCreate && hasActiveBranch ? () => openForm('new') : undefined}
        secondaryLabel={registers.length > 0 ? 'Ver todas las sucursales' : undefined}
        onSecondaryAction={registers.length > 0 ? () => setFilterBranchId('all') : undefined}
      /> : <div className="record-list">{filtered.map((register) => <div className="record-row user-row" key={register.id}>
        <div className="record-symbol" aria-hidden="true">$</div>
        <div className="record-copy"><strong>{register.name}</strong><span>{register.code} · {register.branch.name}</span></div>
        <span className={`record-status ${register.is_active ? 'active' : 'inactive'}`}>{register.is_active ? 'Activa' : 'Inactiva'}</span>
        {register.can_update && <div className="user-row-actions"><button type="button" disabled={busy} aria-label={`Editar ${register.name}`} onClick={() => openForm(register)}>Editar</button><button type="button" disabled={busy} aria-label={`${register.is_active ? 'Desactivar' : 'Reactivar'} ${register.name}`} onClick={() => toggle(register)}>{register.is_active ? 'Desactivar' : 'Reactivar'}</button></div>}
      </div>)}</div>}
    </>}
  </div>
}

export default CashRegisterManagement
