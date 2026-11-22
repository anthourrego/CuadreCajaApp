import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import Swal from 'sweetalert2'
import { ApiError, request } from './api'
import EmptyState from './EmptyState'
import { useSessionFilter } from './useSessionFilter'
import './UserManagement.css'
import './BranchManagement.css'

type Department = { code: string; name: string; is_active: boolean }
type Municipality = { code: string; name: string; is_active: boolean; department: Department }
export type Branch = {
  id: number
  name: string
  code: string
  address: string | null
  municipality_code: string | null
  municipality: Municipality | null
  is_active: boolean
  can_update: boolean
}
type Location = { code: string; name: string }
type LocationState = { status: 'loading' } | { status: 'ready'; data: Location[] } | { status: 'error'; message: string }

function BranchManagement({ token, branches, canCreate, onSaved, onSessionExpired }: {
  token: string
  branches: Branch[]
  canCreate: boolean
  onSaved: () => void
  onSessionExpired: () => void
}) {
  const [editing, setEditing] = useState<Branch | 'new' | null>(null)
  const [name, setName] = useState('')
  const [code, setCode] = useState('')
  const [address, setAddress] = useState('')
  const [departmentCode, setDepartmentCode] = useState('')
  const [municipalityCode, setMunicipalityCode] = useState('')
  const [departments, setDepartments] = useState<LocationState>({ status: 'loading' })
  const [municipalities, setMunicipalities] = useState<LocationState>({ status: 'loading' })
  const [retry, setRetry] = useState(0)
  const [search, setSearch] = useSessionFilter('branches.search', '')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  useEffect(() => {
    if (!editing) return
    let active = true
    request<Location[]>('/departments', { headers: { Authorization: `Bearer ${token}` } })
      .then((data) => {
        if (!Array.isArray(data)) throw new Error('El servidor devolvió una respuesta inválida.')
        if (active) setDepartments({ status: 'ready', data })
      })
      .catch((err: unknown) => {
        if (!active) return
        if (err instanceof ApiError && err.status === 401) onSessionExpired()
        else setDepartments({ status: 'error', message: err instanceof Error ? err.message : 'No se pudieron cargar los departamentos.' })
      })
    return () => { active = false }
  }, [editing, token, onSessionExpired, retry])

  const current = editing && editing !== 'new' ? editing.municipality : null
  const departmentInactive = current && departmentCode === current.department.code && !current.department.is_active

  useEffect(() => {
    if (!editing || !departmentCode || departmentInactive) return
    let active = true
    request<Location[]>(`/departments/${departmentCode}/municipalities`, { headers: { Authorization: `Bearer ${token}` } })
      .then((data) => {
        if (!Array.isArray(data)) throw new Error('El servidor devolvió una respuesta inválida.')
        if (active) setMunicipalities({ status: 'ready', data })
      })
      .catch((err: unknown) => {
        if (!active) return
        if (err instanceof ApiError && err.status === 401) onSessionExpired()
        else setMunicipalities({ status: 'error', message: err instanceof Error ? err.message : 'No se pudieron cargar los municipios.' })
      })
    return () => { active = false }
  }, [editing, departmentCode, departmentInactive, token, onSessionExpired, retry])

  function openForm(branch: Branch | 'new') {
    setEditing(branch)
    setName(branch === 'new' ? '' : branch.name)
    setCode(branch === 'new' ? '' : branch.code)
    setAddress(branch === 'new' ? '' : branch.address ?? '')
    setDepartmentCode(branch === 'new' ? '' : branch.municipality?.department.code ?? '')
    setMunicipalityCode(branch === 'new' ? '' : branch.municipality_code ?? '')
    setDepartments({ status: 'loading' })
    setMunicipalities({ status: 'loading' })
    setError('')
    setNotice('')
  }

  function handleError(err: unknown) {
    if (err instanceof ApiError && err.status === 401) onSessionExpired()
    else setError(err instanceof Error ? err.message : 'No se pudo completar la operación.')
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!editing) return
    setError('')
    if (departmentCode && !municipalityCode) {
      setError('Selecciona un municipio o deja la ubicación sin asignar.')
      return
    }
    setBusy(true)
    try {
      await request<Branch>(editing === 'new' ? '/branches' : `/branches/${editing.id}`, {
        method: editing === 'new' ? 'POST' : 'PUT',
        headers: { Authorization: `Bearer ${token}` },
        body: JSON.stringify({
          name: name.trim(), code: code.trim(), address: address.trim() || null,
          ...(editing === 'new' || municipalityCode !== (editing.municipality_code ?? '') ? { municipality_code: municipalityCode || null } : {}),
        }),
      })
      setEditing(null)
      setNotice('Sucursal guardada correctamente.')
      onSaved()
    } catch (err) {
      handleError(err)
    } finally {
      setBusy(false)
    }
  }

  async function toggle(branch: Branch) {
    const action = branch.is_active ? 'desactivar' : 'reactivar'
    const result = await Swal.fire({
      title: `¿Deseas ${action} esta sucursal?`,
      text: branch.is_active
        ? `${branch.name}: se conservarán sus roles, cajas y cuentas, pero no se podrán crear nuevas cajas o cuentas mientras esté inactiva.`
        : `${branch.name} volverá a estar disponible para crear cajas y cuentas.`,
      icon: branch.is_active ? 'warning' : 'question',
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
      await request<Branch>(`/branches/${branch.id}`, {
        method: 'PUT',
        headers: { Authorization: `Bearer ${token}` },
        body: JSON.stringify({ is_active: !branch.is_active }),
      })
      setNotice(branch.is_active ? 'Sucursal desactivada correctamente.' : 'Sucursal reactivada correctamente.')
      onSaved()
    } catch (err) {
      handleError(err)
    } finally {
      setBusy(false)
    }
  }

  const filtered = branches.filter((branch) => `${branch.name} ${branch.code} ${branch.municipality?.name ?? ''}`.toLocaleLowerCase().includes(search.trim().toLocaleLowerCase()))
  const currentDepartmentMissing = current && departments.status === 'ready' && !departments.data.some((item) => item.code === current.department.code)
  const currentMunicipalityMissing = current && departmentCode === current.department.code && (departmentInactive || (municipalities.status === 'ready' && !municipalities.data.some((item) => item.code === current.code)))

  return (
    <div className="user-management branch-management">
      {notice && <p className="user-notice" role="status">{notice}</p>}
      {error && <p className="user-error" role="alert">{error}</p>}
      {editing ? (
        <form className="user-form" aria-busy={busy} onSubmit={submit}>
          <div className="user-form-heading"><h2>{editing === 'new' ? 'Crear sucursal' : 'Editar sucursal'}</h2><p>La ubicación es opcional. El acceso se configura desde los roles.</p></div>
          <div className="user-fields">
            <div className="user-field"><label htmlFor="branch-name">Nombre</label><input id="branch-name" required maxLength={255} value={name} onChange={(event) => setName(event.target.value)} /></div>
            <div className="user-field"><label htmlFor="branch-code">Código</label><input id="branch-code" required maxLength={255} value={code} onChange={(event) => setCode(event.target.value)} /><small>Debe ser único para cada sucursal.</small></div>
            <div className="user-field"><label htmlFor="branch-address">Dirección (opcional)</label><input id="branch-address" maxLength={255} value={address} onChange={(event) => setAddress(event.target.value)} /></div>
            <div className="user-field"><label htmlFor="branch-department">Departamento (opcional)</label><select id="branch-department" value={departmentCode} disabled={departments.status !== 'ready'} onChange={(event) => { setDepartmentCode(event.target.value); setMunicipalityCode(''); setMunicipalities({ status: 'loading' }) }}><option value="">Sin ubicación</option>{currentDepartmentMissing && <option value={current.department.code}>{current.department.name} (inactivo)</option>}{departments.status === 'ready' && departments.data.map((item) => <option key={item.code} value={item.code}>{item.name}</option>)}</select>{departments.status === 'loading' && <small>Cargando departamentos...</small>}{departments.status === 'error' && <small role="alert">{departments.message} <button type="button" onClick={() => { setDepartments({ status: 'loading' }); setRetry((value) => value + 1) }}>Reintentar</button></small>}</div>
            {departmentCode && <div className="user-field"><label htmlFor="branch-municipality">Municipio</label><select id="branch-municipality" value={municipalityCode} disabled={!departmentInactive && municipalities.status !== 'ready'} onChange={(event) => setMunicipalityCode(event.target.value)}><option value="">Selecciona un municipio</option>{currentMunicipalityMissing && <option value={current.code}>{current.name} (ubicación inactiva actual)</option>}{!departmentInactive && municipalities.status === 'ready' && municipalities.data.map((item) => <option key={item.code} value={item.code}>{item.name}</option>)}</select>{departmentInactive ? <small>La ubicación actual se conserva; selecciona otro departamento para cambiarla.</small> : municipalities.status === 'loading' ? <small>Cargando municipios...</small> : municipalities.status === 'error' ? <small role="alert">{municipalities.message} <button type="button" onClick={() => { setMunicipalities({ status: 'loading' }); setRetry((value) => value + 1) }}>Reintentar</button></small> : null}</div>}
          </div>
          <div className="user-form-actions"><button className="user-primary" disabled={busy} type="submit">{busy ? 'Guardando...' : 'Guardar sucursal'}</button><button className="user-secondary" disabled={busy} type="button" onClick={() => { setEditing(null); setError('') }}>Cancelar</button></div>
        </form>
      ) : (
        <>
          <div className="user-toolbar branch-toolbar"><div className="user-field"><label htmlFor="branch-search">Buscar por nombre, código o municipio</label><input id="branch-search" value={search} onChange={(event) => setSearch(event.target.value)} /></div>{canCreate && <button className="user-primary" type="button" disabled={busy} onClick={() => openForm('new')}>+ Crear sucursal</button>}</div>
          {filtered.length === 0 ? <EmptyState
            title={branches.length === 0 ? 'Aún no hay sucursales' : 'No encontramos resultados'}
            description={branches.length === 0 ? 'Crea una sucursal para organizar las cajas y operaciones de tu negocio.' : 'Prueba con otro nombre, código o municipio.'}
            actionLabel={branches.length === 0 && canCreate ? '+ Crear sucursal' : undefined}
            onAction={branches.length === 0 && canCreate ? () => openForm('new') : undefined}
            secondaryLabel={branches.length > 0 && search ? 'Limpiar búsqueda' : undefined}
            onSecondaryAction={branches.length > 0 && search ? () => setSearch('') : undefined}
          /> : <div className="record-list">{filtered.map((branch) => <div className="record-row user-row" key={branch.id}><div className="record-symbol" aria-hidden="true">▦</div><div className="record-copy"><strong>{branch.name}</strong><span>{[branch.code, branch.municipality?.name, branch.municipality?.department.name, branch.address].filter(Boolean).join(' · ')}</span></div><span className={`record-status ${branch.is_active ? 'active' : 'inactive'}`}>{branch.is_active ? 'Activa' : 'Inactiva'}</span>{branch.can_update && <div className="user-row-actions"><button type="button" disabled={busy} aria-label={`Editar ${branch.name}`} onClick={() => openForm(branch)}>Editar</button><button type="button" disabled={busy} aria-label={`${branch.is_active ? 'Desactivar' : 'Reactivar'} ${branch.name}`} onClick={() => toggle(branch)}>{branch.is_active ? 'Desactivar' : 'Reactivar'}</button></div>}</div>)}</div>}
        </>
      )}
    </div>
  )
}

export default BranchManagement
