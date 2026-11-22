import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { ApiError, request } from './api'
import type { User } from './api'
import './UserManagement.css'

function ProfileManagement({ mode, user, token, onUserUpdated, onSessionExpired, onPasswordChanged, onClose }: {
  mode: 'perfil' | 'contrasena'
  user: User
  token: string
  onUserUpdated: (user: User) => void
  onSessionExpired: () => void
  onPasswordChanged: () => void
  onClose: () => void
}) {
  const dialogRef = useRef<HTMLDialogElement>(null)
  const [name, setName] = useState(user.name)
  const [email, setEmail] = useState(user.email)
  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    const dialog = dialogRef.current
    if (!dialog) return
    dialog.showModal()
    return () => { dialog.close() }
  }, [])

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError('')
    if (mode === 'contrasena' && (password.length < 12 || password !== confirmation)) {
      setError('La contraseña debe tener al menos 12 caracteres y coincidir con la confirmación.')
      return
    }
    setBusy(true)
    try {
      if (mode === 'perfil') {
        const updated = await request<User>('/user/profile', {
          method: 'PUT',
          headers: { Authorization: `Bearer ${token}` },
          body: JSON.stringify({ name: name.trim(), email: email.trim() }),
        })
        onUserUpdated(updated)
        onClose()
      } else {
        await request('/user/password', {
          method: 'PUT',
          headers: { Authorization: `Bearer ${token}` },
          body: JSON.stringify({ current_password: currentPassword, password, password_confirmation: confirmation }),
        })
        onPasswordChanged()
      }
    } catch (err) {
      if (err instanceof ApiError && err.status === 401) onSessionExpired()
      else setError(err instanceof Error ? err.message : 'No se pudo completar la operación.')
    } finally {
      setBusy(false)
    }
  }

  return <dialog ref={dialogRef} className="profile-dialog" aria-labelledby="profile-dialog-title" onCancel={(event) => { event.preventDefault(); if (!busy) onClose() }} onKeyDown={(event) => {
    if (event.key === 'Escape') {
      event.preventDefault()
      event.stopPropagation()
      if (!busy) onClose()
    }
  }}>
    <form className="user-form" aria-busy={busy} onSubmit={submit}>
    <div className="user-form-heading"><h2 id="profile-dialog-title">{mode === 'perfil' ? 'Editar perfil' : 'Cambiar contraseña'}</h2><p>{mode === 'perfil' ? 'Actualiza tus datos personales.' : 'Al cambiar tu contraseña se cerrarán todas tus sesiones.'}</p></div>
    {error && <p className="user-error" role="alert">{error}</p>}
    <div className="user-fields">
      {mode === 'perfil' ? <>
        <div className="user-field"><label htmlFor="profile-name">Nombre completo</label><input id="profile-name" required maxLength={255} value={name} onChange={(event) => setName(event.target.value)} /></div>
        <div className="user-field"><label htmlFor="profile-username">Usuario</label><input id="profile-username" value={user.username} readOnly /><small>El nombre de usuario no se puede modificar.</small></div>
        <div className="user-field"><label htmlFor="profile-email">Correo electrónico</label><input id="profile-email" type="email" required maxLength={255} value={email} onChange={(event) => setEmail(event.target.value)} /></div>
      </> : <>
        <div className="user-field"><label htmlFor="current-password">Contraseña actual</label><input id="current-password" type="password" autoComplete="current-password" required value={currentPassword} onChange={(event) => setCurrentPassword(event.target.value)} /></div>
        <div className="user-field"><label htmlFor="new-password">Nueva contraseña</label><input id="new-password" type="password" autoComplete="new-password" required minLength={12} value={password} onChange={(event) => setPassword(event.target.value)} /><small>Mínimo 12 caracteres.</small></div>
        <div className="user-field"><label htmlFor="confirm-password">Confirmar nueva contraseña</label><input id="confirm-password" type="password" autoComplete="new-password" required minLength={12} value={confirmation} onChange={(event) => setConfirmation(event.target.value)} /></div>
      </>}
    </div>
    <div className="user-form-actions"><button className="user-primary" type="submit" disabled={busy}>{busy ? 'Guardando...' : 'Guardar cambios'}</button><button className="user-secondary" type="button" disabled={busy} onClick={onClose}>Cancelar</button></div>
    </form>
  </dialog>
}

export default ProfileManagement
