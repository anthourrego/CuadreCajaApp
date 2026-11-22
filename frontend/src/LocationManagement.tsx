import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import Swal from 'sweetalert2'
import { ApiError, request } from './api'
import EmptyState from './EmptyState'
import { useSessionFilter } from './useSessionFilter'
import './UserManagement.css'
import './LocationManagement.css'

type Department = { code: string; name: string; is_active: boolean }
type Municipality = Department & { department_code: string; type: string }
type Item = Department | Municipality
type Tab = 'departments' | 'municipalities'
type LoadState<T> = { status: 'loading' } | { status: 'ready'; data: T[] } | { status: 'error'; message: string }

function LocationManagement({ token, onSessionExpired }: { token: string; onSessionExpired: () => void }) {
  const [storedTab, setStoredTab] = useSessionFilter('locations.tab', 'departments')
  const tab: Tab = storedTab === 'municipalities' ? 'municipalities' : 'departments'
  const setTab = (value: Tab) => setStoredTab(value)
  const [departments, setDepartments] = useState<LoadState<Department>>({ status: 'loading' })
  const [municipalities, setMunicipalities] = useState<LoadState<Municipality>>({ status: 'loading' })
  const [departmentCode, setDepartmentCode] = useSessionFilter('locations.department', '')
  const [version, setVersion] = useState(0)
  const [editing, setEditing] = useState<Item | 'new' | null>(null)
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [type, setType] = useState('Municipio')
  const [search, setSearch] = useSessionFilter('locations.search', '')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  useEffect(() => {
    let active = true
    request<Department[]>('/departments?include_inactive=1', { headers: { Authorization: `Bearer ${token}` } })
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
  }, [token, onSessionExpired, version])

  useEffect(() => {
    if (!departmentCode || tab !== 'municipalities') return
    let active = true
    request<Municipality[]>(`/departments/${departmentCode}/municipalities?include_inactive=1`, { headers: { Authorization: `Bearer ${token}` } })
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
  }, [token, onSessionExpired, departmentCode, tab, version])

  function reload() {
    setDepartments({ status: 'loading' })
    setMunicipalities({ status: 'loading' })
    setVersion((value) => value + 1)
  }

  function handleError(err: unknown) {
    if (err instanceof ApiError && err.status === 401) onSessionExpired()
    else setError(err instanceof Error ? err.message : 'No se pudo completar la operación.')
  }

  function openForm(item: Item | 'new') {
    setEditing(item)
    setCode(item === 'new' ? '' : item.code)
    setName(item === 'new' ? '' : item.name)
    setType(item !== 'new' && 'type' in item ? item.type : 'Municipio')
    setError('')
    setNotice('')
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!editing) return
    setBusy(true)
    setError('')
    const isNew = editing === 'new'
    const data = {
      name: name.trim(),
      ...(isNew ? { code, ...(tab === 'municipalities' ? { department_code: departmentCode } : {}) } : {}),
      ...(tab === 'municipalities' ? { type } : {}),
    }
    try {
      await request<Item>(isNew ? `/${tab}` : `/${tab}/${editing.code}`, {
        method: isNew ? 'POST' : 'PUT',
        headers: { Authorization: `Bearer ${token}` },
        body: JSON.stringify(data),
      })
      setEditing(null)
      setNotice('Registro guardado correctamente.')
      reload()
    } catch (err) {
      handleError(err)
    } finally {
      setBusy(false)
    }
  }

  async function toggle(item: Item) {
    const action = item.is_active ? 'desactivar' : 'reactivar'
    const result = await Swal.fire({
      title: `¿Deseas ${action} este registro?`,
      text: tab === 'departments' && item.is_active
        ? `${item.name}: sus municipios no estarán disponibles para nuevas sucursales. Las ubicaciones existentes se conservan.`
        : `${item.name}${item.is_active ? ' no estará disponible para nuevas sucursales. Las ubicaciones existentes se conservan.' : ' volverá a estar disponible si su departamento está activo.'}`,
      icon: item.is_active ? 'warning' : 'question',
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
      await request<Item>(`/${tab}/${item.code}`, {
        method: 'PUT',
        headers: { Authorization: `Bearer ${token}` },
        body: JSON.stringify({ is_active: !item.is_active }),
      })
      setNotice(item.is_active ? 'Registro desactivado correctamente.' : 'Registro reactivado correctamente.')
      reload()
    } catch (err) {
      handleError(err)
    } finally {
      setBusy(false)
    }
  }

  const selectedDepartment = departments.status === 'ready' ? departments.data.find((item) => item.code === departmentCode) : undefined
  const list = tab === 'departments' ? departments : municipalities
  const items = list.status === 'ready' ? list.data.filter((item) => `${item.code} ${item.name}`.toLocaleLowerCase().includes(search.trim().toLocaleLowerCase())) : []

  return (
    <div className="user-management location-management">
      <div className="location-tabs" aria-label="Catálogos">
        <button type="button" className={tab === 'departments' ? 'selected' : ''} disabled={busy} onClick={() => { setTab('departments'); setEditing(null); setError(''); setNotice(''); setSearch('') }}>Departamentos</button>
        <button type="button" className={tab === 'municipalities' ? 'selected' : ''} disabled={busy} onClick={() => { if (tab !== 'municipalities') setMunicipalities({ status: 'loading' }); setTab('municipalities'); setEditing(null); setError(''); setNotice(''); setSearch('') }}>Municipios</button>
      </div>
      {notice && <p className="user-notice" role="status">{notice}</p>}
      {error && <p className="user-error" role="alert">{error}</p>}
      {editing ? (
        <form className="user-form" aria-busy={busy} onSubmit={submit}>
          <div className="user-form-heading"><h2>{editing === 'new' ? 'Crear' : 'Editar'} {tab === 'departments' ? 'departamento' : 'municipio'}</h2><p>{tab === 'municipalities' ? `Departamento: ${selectedDepartment?.name ?? departmentCode}` : 'Los códigos existentes no se pueden modificar.'}</p></div>
          <div className="user-fields">
            <div className="user-field"><label htmlFor="location-code">Código DIVIPOLA</label><input id="location-code" required inputMode="numeric" pattern={tab === 'departments' ? '[0-9]{2}' : '[0-9]{5}'} maxLength={tab === 'departments' ? 2 : 5} disabled={editing !== 'new'} value={code} onChange={(event) => setCode(event.target.value)} /><small>{tab === 'departments' ? 'Dos dígitos.' : `Cinco dígitos, comienza por ${departmentCode}.`}</small></div>
            <div className="user-field"><label htmlFor="location-name">Nombre</label><input id="location-name" required maxLength={255} value={name} onChange={(event) => setName(event.target.value)} /></div>
            {tab === 'municipalities' && <div className="user-field"><label htmlFor="location-type">Tipo</label><select id="location-type" value={type} onChange={(event) => setType(event.target.value)}><option>Municipio</option><option>Isla</option><option>Área no municipalizada</option></select></div>}
          </div>
          <div className="user-form-actions"><button className="user-primary" disabled={busy} type="submit">{busy ? 'Guardando...' : 'Guardar'}</button><button className="user-secondary" disabled={busy} type="button" onClick={() => { setEditing(null); setError('') }}>Cancelar</button></div>
        </form>
      ) : (
        <>
          {tab === 'municipalities' && <div className="location-filter user-field"><label htmlFor="location-department">Departamento</label><select id="location-department" value={departmentCode} disabled={departments.status !== 'ready'} onChange={(event) => { setDepartmentCode(event.target.value); setMunicipalities({ status: 'loading' }); setSearch('') }}><option value="">Selecciona un departamento</option>{departments.status === 'ready' && departments.data.map((item) => <option key={item.code} value={item.code}>{item.code} · {item.name}{item.is_active ? '' : ' (inactivo)'}</option>)}</select>{selectedDepartment && !selectedDepartment.is_active && <small>Departamento inactivo: sus municipios no están disponibles para nuevas sucursales.</small>}</div>}
          {departments.status === 'error' ? <div className="resource-error" role="alert"><p>{departments.message}</p><button type="button" onClick={reload}>Reintentar</button></div> : (
            <>
              <div className="user-toolbar location-toolbar"><div className="user-field"><label htmlFor="location-search">Buscar por nombre o código</label><input id="location-search" value={search} onChange={(event) => setSearch(event.target.value)} /></div><button className="user-primary" type="button" disabled={busy || departments.status !== 'ready' || (tab === 'municipalities' && !selectedDepartment?.is_active)} onClick={() => openForm('new')}>+ Crear {tab === 'departments' ? 'departamento' : 'municipio'}</button></div>
              {tab === 'municipalities' && !departmentCode ? <EmptyState title="Selecciona un departamento" description="Elige un departamento para consultar sus municipios." /> : list.status === 'loading' ? <p className="workspace-message" role="status">Cargando información...</p> : list.status === 'error' ? <div className="resource-error" role="alert"><p>{list.message}</p><button type="button" onClick={reload}>Reintentar</button></div> : items.length === 0 ? <EmptyState
                title={search ? 'No encontramos resultados' : `Aún no hay ${tab === 'departments' ? 'departamentos' : 'municipios'}`}
                description={search ? 'Prueba con otro nombre o código.' : 'Puedes agregar el primer registro para completar el catálogo.'}
                actionLabel={!search ? `+ Crear ${tab === 'departments' ? 'departamento' : 'municipio'}` : undefined}
                onAction={!search ? () => openForm('new') : undefined}
                secondaryLabel={search ? 'Limpiar búsqueda' : undefined}
                onSecondaryAction={search ? () => setSearch('') : undefined}
              /> : <div className="record-list">{items.map((item) => <div className="record-row user-row" key={item.code}><div className="record-symbol" aria-hidden="true">⌖</div><div className="record-copy"><strong>{item.name}</strong><span>{item.code}{'type' in item ? ` · ${item.type}` : ''}</span></div><span className={`record-status ${item.is_active ? 'active' : 'inactive'}`}>{item.is_active ? 'Activo' : 'Inactivo'}</span><div className="user-row-actions"><button type="button" disabled={busy} aria-label={`Editar ${item.name}`} onClick={() => openForm(item)}>Editar</button><button type="button" disabled={busy} aria-label={`${item.is_active ? 'Desactivar' : 'Reactivar'} ${item.name}`} onClick={() => toggle(item)}>{item.is_active ? 'Desactivar' : 'Reactivar'}</button></div></div>)}</div>}
            </>
          )}
        </>
      )}
    </div>
  )
}

export default LocationManagement
