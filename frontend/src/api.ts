export type BranchSummary = { id: number; name: string; code: string }

export type User = {
  id: number
  name: string
  username: string
  email: string
  role_id: number
  default_branch_id: number | null
  branch_ids: number[]
  role: { id: number; name: string }
  permissions: string[]
  is_active: boolean
}

export class ApiError extends Error {
  status: number

  constructor(status: number, message: string) {
    super(message)
    this.status = status
  }
}

export async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  let response: Response
  try {
    const apiOrigin = (import.meta.env.VITE_API_ORIGIN || '').replace(/\/+$/, '')
    response = await fetch(`${apiOrigin}/api${path}`, {
      ...options,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...options.headers,
      },
    })
  } catch {
    throw new Error('No fue posible conectar con el servidor. Verifica tu conexión e inténtalo de nuevo.')
  }

  if (!response.ok) {
    if (response.status === 401) throw new ApiError(401, 'Usuario o contraseña incorrectos.')
    if (response.status === 403) throw new ApiError(403, 'No tienes permiso para realizar esta operación.')
    if (response.status === 409) throw new ApiError(409, 'El cuadre fue modificado por otro usuario. Recarga antes de guardar.')
    if (response.status === 429) throw new ApiError(429, 'Demasiados intentos. Espera un momento e inténtalo de nuevo.')
    if (response.status === 422) {
      const body: unknown = await response.json().catch(() => null)
      if (body && typeof body === 'object' && 'errors' in body && body.errors && typeof body.errors === 'object') {
        const messages = Object.values(body.errors).flatMap((value: unknown) =>
          Array.isArray(value) ? value.filter((message): message is string => typeof message === 'string') : [],
        )
        if (messages.length) throw new ApiError(422, messages.join(' '))
      }
      if (body && typeof body === 'object' && 'message' in body && typeof body.message === 'string') {
        throw new ApiError(422, body.message)
      }
      throw new ApiError(422, 'Revisa los datos ingresados e inténtalo de nuevo.')
    }
    throw new ApiError(response.status, 'Ocurrió un error en el servidor. Inténtalo de nuevo.')
  }

  try {
    return await response.json() as T
  } catch {
    throw new Error('El servidor devolvió una respuesta inválida.')
  }
}
