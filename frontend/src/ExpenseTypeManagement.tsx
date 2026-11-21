import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import Swal from 'sweetalert2'
import { ApiError, request } from './api'
import './UserManagement.css'

type ExpenseType = { id: number; name: string; is_active: boolean }
type LoadState = { status: 'loading' } | { status: 'ready'; data: ExpenseType[] } | { status: 'error'; message: string }

function ExpenseTypeManagement({ token, onSessionExpired }: { token: string; onSessionExpired: () => void }) {
  const [types, setTypes] = useState<LoadState>({ status: 'loading' })
  const [version, setVersion] = useState(0)
  const [editing, setEditing] = useState<ExpenseType | 'new' | null>(null)
  const [name, setName] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const dialogRef = useRef<HTMLDialogElement>(null)
  const triggerRef = useRef<HTMLElement | null>(null)

  useEffect(() => {
    let active = true
    request<ExpenseType[]>('/expense-types?include_inactive=1', { headers: { Authorization: `Bearer ${token}` } })
      .then((data) => {
        if (!Array.isArray(data)) throw new Error('El servidor devolvió una respuesta inválida.')
        if (active) setTypes({ status: 'ready', data })
      })
      .catch((err: unknown) => {
        if (!active) return
        if (err instanceof ApiError && err.status === 401) onSessionExpired()
        else setTypes({ status: 'error', message: err instanceof Error ? err.message : 'No se pudieron cargar los tipos de gasto.' })
      })
    return () => { active = false }
  }, [token, onSessionExpired, version])

  useEffect(() => {
    if (!editing) return
    const dialog = dialogRef.current
    if (!dialog) return
    dialog.showModal()
    return () => { dialog.close() }
  }, [editing])

  function reload() {
    setTypes((current) => current.status === 'ready' ? current : { status: 'loading' })
    setVersion((value) => value + 1)
  }

  function closeForm() {
    setEditing(null)
    setError('')
    window.requestAnimationFrame(() => triggerRef.current?.focus())
  }

  function openForm(item: ExpenseType | 'new', trigger: HTMLElement) {
    triggerRef.current = trigger
    setEditing(item)
    setName(item === 'new' ? '' : item.name)
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
    setBusy(true)
    setError('')
    try {
      await request<ExpenseType>(editing === 'new' ? '/expense-types' : `/expense-types/${editing.id}`, {
        method: editing === 'new' ? 'POST' : 'PUT',
        headers: { Authorization: `Bearer ${token}` },
        body: JSON.stringify({ name: name.trim() }),
      })
      closeForm()
      setNotice('Tipo de gasto guardado correctamente.')
      reload()
    } catch (err) {
      handleError(err)
    } finally {
      setBusy(false)
    }
  }

  async function toggle(item: ExpenseType) {
    const action = item.is_active ? 'desactivar' : 'reactivar'
    const result = await Swal.fire({
      title: `¿Deseas ${action} este tipo de gasto?`,
      text: item.is_active ? `${item.name} dejará de aparecer en el catálogo de tipos activos.` : `${item.name} volverá a aparecer en el catálogo de tipos activos.`,
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
      await request<ExpenseType>(`/expense-types/${item.id}`, {
        method: 'PUT',
        headers: { Authorization: `Bearer ${token}` },
        body: JSON.stringify({ is_active: !item.is_active }),
      })
      setNotice(item.is_active ? 'Tipo de gasto desactivado correctamente.' : 'Tipo de gasto reactivado correctamente.')
      reload()
    } catch (err) {
      handleError(err)
    } finally {
      setBusy(false)
    }
  }

  return <div className="user-management">
    {notice && <p className="user-notice" role="status">{notice}</p>}
    {error && !editing && <p className="user-error" role="alert">{error}</p>}
    <div className="user-toolbar"><p>Estos tipos están disponibles para todas las sucursales.</p><button className="user-primary" type="button" disabled={busy || types.status !== 'ready'} onClick={(event) => openForm('new', event.currentTarget)}>+ Crear tipo de gasto</button></div>
    {types.status === 'loading' ? <p className="workspace-message" role="status">Cargando tipos de gasto...</p> : types.status === 'error' ? <div className="resource-error" role="alert"><p>{types.message}</p><button type="button" onClick={reload}>Reintentar</button></div> : types.data.length === 0 ? <p className="workspace-message">No hay tipos de gasto registrados.</p> : <div className="record-list">{types.data.map((item) => <div className="record-row user-row" key={item.id}>
      <div className="record-symbol" aria-hidden="true">$</div>
      <div className="record-copy"><strong>{item.name}</strong></div>
      <span className={`record-status ${item.is_active ? 'active' : 'inactive'}`}>{item.is_active ? 'Activo' : 'Inactivo'}</span>
      <div className="user-row-actions"><button type="button" disabled={busy} aria-label={`Editar ${item.name}`} onClick={(event) => openForm(item, event.currentTarget)}>Editar</button><button type="button" disabled={busy} aria-label={`${item.is_active ? 'Desactivar' : 'Reactivar'} ${item.name}`} onClick={() => toggle(item)}>{item.is_active ? 'Desactivar' : 'Reactivar'}</button></div>
    </div>)}</div>}
    {editing && <dialog ref={dialogRef} className="profile-dialog" aria-labelledby="expense-type-title" onCancel={(event) => { event.preventDefault(); if (!busy) closeForm() }} onKeyDown={(event) => {
      if (event.key === 'Escape') {
        event.preventDefault()
        event.stopPropagation()
        if (!busy) closeForm()
      }
    }}>
      <form className="user-form" onSubmit={submit}>
        <div className="user-form-heading"><h2 id="expense-type-title">{editing === 'new' ? 'Crear tipo de gasto' : 'Editar tipo de gasto'}</h2><p>El catálogo es compartido por todas las sucursales.</p></div>
        {error && <p className="user-error" role="alert">{error}</p>}
        <div className="user-fields"><div className="user-field"><label htmlFor="expense-type-name">Nombre</label><input id="expense-type-name" autoFocus required maxLength={255} value={name} onChange={(event) => setName(event.target.value)} /></div></div>
        <div className="user-form-actions"><button className="user-primary" disabled={busy} type="submit">{busy ? 'Guardando...' : 'Guardar'}</button><button className="user-secondary" disabled={busy} type="button" onClick={closeForm}>Cancelar</button></div>
      </form>
    </dialog>}
  </div>
}

export default ExpenseTypeManagement
