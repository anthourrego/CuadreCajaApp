import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { DayPicker } from 'react-day-picker'
import { es } from 'react-day-picker/locale/es'
import 'react-day-picker/style.css'
import { createPortal } from 'react-dom'
import { ApiError, request } from './api'
import type { BranchSummary } from './api'
import type { Register } from './CashRegisterManagement'
import EmptyState from './EmptyState'
import { useSessionFilter } from './useSessionFilter'
import './UserManagement.css'
import './ReconciliationManagement.css'

type ExpenseType = { id: number; name: string; is_active: boolean }
type Expense = { id?: number; expense_type_id: number; type_name: string; detail: string; amount: string }
type Reconciliation = {
  id: number; date: string; cash_register_id: number; branch_id: number
  cash_register: BranchSummary; branch: BranchSummary
  sales: string; cash: string; bank: string; qr: string
  expenses: Expense[]; expenses_total: string; difference: string; version: number; can_update: boolean
}
type Resource<T> = { status: 'loading' } | { status: 'ready'; data: T } | { status: 'error'; message: string }
type DraftExpense = { key: number; id?: number; originalTypeId?: string; typeId: string; typeName: string; detail: string; amount: string }
type ExpenseEntry = { typeId: string; detail: string; amount: string }
type FormIconName = 'branch' | 'register' | 'date' | 'sales' | 'cash' | 'bank' | 'qr' | 'expense' | 'add' | 'remove' | 'save'

const moneyInputPattern = '(?:0|[1-9][0-9]{0,11}|[1-9][0-9]{0,2}(?:\\.[0-9]{3}){1,3})(?:,[0-9]{1,2})?'
const moneyPattern = new RegExp(`^${moneyInputPattern}$`)
const moneyDraftPattern = new RegExp(`^(?:${moneyInputPattern}|(?:0|[1-9][0-9]{0,11}|[1-9][0-9]{0,2}(?:\\.[0-9]{3}){1,3}),)$`)

function FormIcon({ name }: { name: FormIconName }) {
  return <svg className="reconciliation-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
    {name === 'branch' && <><path d="M4 20h16M6 20V5h8v15M14 9h4v11M9 8h2M9 11h2M9 14h2" /></>}
    {name === 'register' && <><path d="M5 10h14l1.5 9h-17L5 10ZM7 10V5h10v5M7 15h3M14 15h2" /><circle cx="18" cy="15" r=".4" /></>}
    {name === 'date' && <><rect x="4" y="6" width="16" height="14" rx="2" /><path d="M8 4v4M16 4v4M4 10h16M8 14h2M14 14h2M8 17h2" /></>}
    {name === 'sales' && <><path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3ZM9 7h6M9 10h6M9 13h3" /></>}
    {name === 'cash' && <><rect x="3" y="6" width="18" height="12" rx="2" /><circle cx="12" cy="12" r="2.5" /><path d="M6 9h1M17 15h1" /></>}
    {name === 'bank' && <><path d="M3 9 12 4l9 5H3ZM5 11v6M9 11v6M15 11v6M19 11v6M3 20h18" /></>}
    {name === 'qr' && <><path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM19 14v2M14 20h2M19 19v1" /></>}
    {name === 'expense' && <><path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3ZM9 8h6M9 12h6M9 16h3" /></>}
    {name === 'add' && <path d="M12 5v14M5 12h14" />}
    {name === 'remove' && <><path d="M4 7h16M9 4h6M7 7l1 13h8l1-13M10 11v5M14 11v5" /></>}
    {name === 'save' && <><path d="M5 4h12l3 3v13H4V4h1ZM8 4v6h8V4M8 20v-6h8v6" /></>}
  </svg>
}

function canonicalMoney(value: string): string | null {
  return moneyPattern.test(value) ? value.replace(/\./g, '').replace(',', '.') : null
}

function groupMoney(value: string): string {
  const [whole, fraction] = value.replace(/\./g, '').split(',')
  return whole.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + (fraction === undefined ? '' : `,${fraction}`)
}

function displayMoney(value: string): string {
  return groupMoney(value.replace('.', ','))
}

function MoneyField({ id, label, value, onChange, icon, showHelp = true, required = true, hideLabel = false, disabled = false }: { id: string; label: string; value: string; onChange: (value: string) => void; icon?: FormIconName; showHelp?: boolean; required?: boolean; hideLabel?: boolean; disabled?: boolean }) {
  const inputRef = useRef<HTMLInputElement>(null)

  function update(raw: string, cursor: number, allowRegroup: boolean) {
    const ungrouped = raw.replace(/\./g, '')
    const canRegroup = allowRegroup && /^(?:[0-9]{0,12})(?:,[0-9]{0,2})?$/.test(ungrouped)
    const next = moneyDraftPattern.test(raw) || canRegroup ? groupMoney(raw) : raw
    let position = cursor
    if (next !== raw) {
      const significantBeforeCursor = raw.slice(0, cursor).replace(/\./g, '').length
      position = 0
      let significant = 0
      while (position < next.length && significant < significantBeforeCursor) {
        if (next[position] !== '.') significant++
        position++
      }
    }
    onChange(next)
    window.requestAnimationFrame(() => {
      const input = inputRef.current
      if (input && document.activeElement === input && input.value === next) input.setSelectionRange(position, position)
    })
  }

  return <div className="user-field"><label className={hideLabel ? 'reconciliation-visually-hidden' : icon ? 'reconciliation-field-label' : undefined} htmlFor={id}>{icon && <FormIcon name={icon} />}{label}</label><input ref={inputRef} id={id} type="text" inputMode="decimal" pattern={moneyInputPattern} title="Usa puntos para miles y coma para hasta dos decimales; por ejemplo, 1.234,50." required={required} disabled={disabled} value={value} onChange={(event) => {
    const native = event.nativeEvent
    const inserted = native instanceof InputEvent ? native.data : null
    const allowRegroup = (value === '' || moneyDraftPattern.test(value)) && inserted !== '.'
    update(event.target.value, event.target.selectionStart ?? event.target.value.length, allowRegroup)
  }} onKeyDown={(event) => {
    const input = event.currentTarget
    const start = input.selectionStart
    const end = input.selectionEnd
    if (start === null || start !== end || !moneyDraftPattern.test(value)) return
    if (event.key === 'Backspace' && value[start - 1] === '.' && start >= 2) {
      event.preventDefault()
      update(value.slice(0, start - 2) + value.slice(start - 1), start - 2, true)
    } else if (event.key === 'Delete' && value[start] === '.' && start + 1 < value.length) {
      event.preventDefault()
      update(value.slice(0, start + 1) + value.slice(start + 2), start, true)
    }
  }} onPaste={(event) => {
    event.preventDefault()
    const input = event.currentTarget
    const start = input.selectionStart ?? input.value.length
    const end = input.selectionEnd ?? start
    const pasted = event.clipboardData.getData('text')
    update(input.value.slice(0, start) + pasted + input.value.slice(end), start + pasted.length,
      /^[0-9]+$/.test(pasted) && (value === '' || moneyDraftPattern.test(value)))
  }} />{showHelp && <small>Ejemplo: 1.234,50 (punto para miles y coma para decimales).</small>}</div>
}

function cents(value: string): bigint | null {
  if (!/^(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,2})?$/.test(value)) return null
  const [whole, fraction = ''] = value.split('.')
  return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'))
}

function formatCents(amount: bigint): string {
  const negative = amount < 0n
  const value = negative ? -amount : amount
  return `${negative ? '-' : ''}$${(value / 100n).toLocaleString('es-CO')},${(value % 100n).toString().padStart(2, '0')}`
}

function formatAmount(value: string): string {
  const parsed = cents(value)
  return parsed === null ? value : formatCents(parsed)
}

function colombiaDate(): string {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Bogota', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date())
  const values = Object.fromEntries(parts.map((part) => [part.type, part.value]))
  return `${values.year}-${values.month}-${values.day}`
}

function dateFromValue(value: string): Date | undefined {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (!match) return undefined
  const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]), 12)
  return date.getFullYear() === Number(match[1]) && date.getMonth() === Number(match[2]) - 1 && date.getDate() === Number(match[3]) ? date : undefined
}

function dateToValue(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

function formatDateValue(value: string): string {
  const date = dateFromValue(value)
  return date ? new Intl.DateTimeFormat('es-CO', { day: 'numeric', month: 'long', year: 'numeric' }).format(date) : 'Selecciona una fecha'
}

function DatePicker({ id, value, onChange, maxDate, disabled = false, readOnly = false, required = false, onOpen }: {
  id: string
  value: string
  onChange: (value: string) => void
  maxDate?: string
  disabled?: boolean
  readOnly?: boolean
  required?: boolean
  onOpen?: () => void
}) {
  const [open, setOpen] = useState(false)
  const pickerRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const popoverRef = useRef<HTMLDivElement>(null)
  const selected = dateFromValue(value)
  const maximum = maxDate ? dateFromValue(maxDate) : undefined
  const today = dateFromValue(colombiaDate())

  useLayoutEffect(() => {
    if (!open) return
    const positionPopover = () => {
      const trigger = triggerRef.current
      const popover = popoverRef.current
      if (!trigger || !popover) return
      const triggerRect = trigger.getBoundingClientRect()
      const popoverRect = popover.getBoundingClientRect()
      const gutter = 12
      const gap = 8
      const spaceBelow = window.innerHeight - triggerRect.bottom - gutter
      const spaceAbove = triggerRect.top - gutter
      const placeAbove = spaceBelow < popoverRect.height && spaceAbove > spaceBelow
      const preferredTop = placeAbove ? triggerRect.top - popoverRect.height - gap : triggerRect.bottom + gap
      const maxTop = Math.max(gutter, window.innerHeight - popoverRect.height - gutter)
      const maxLeft = Math.max(gutter, window.innerWidth - popoverRect.width - gutter)
      popover.style.top = `${Math.max(gutter, Math.min(preferredTop, maxTop))}px`
      popover.style.left = `${Math.max(gutter, Math.min(triggerRect.left, maxLeft))}px`
      popover.style.visibility = 'visible'
    }
    positionPopover()
    window.addEventListener('resize', positionPopover)
    window.addEventListener('scroll', positionPopover, true)
    return () => {
      window.removeEventListener('resize', positionPopover)
      window.removeEventListener('scroll', positionPopover, true)
    }
  }, [open])

  useEffect(() => {
    if (!open) return
    function handlePointerDown(event: PointerEvent) {
      if (event.target instanceof Node && !pickerRef.current?.contains(event.target) && !popoverRef.current?.contains(event.target)) setOpen(false)
    }
    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        setOpen(false)
        triggerRef.current?.focus()
      }
    }
    document.addEventListener('pointerdown', handlePointerDown)
    document.addEventListener('keydown', handleKeyDown)
    return () => {
      document.removeEventListener('pointerdown', handlePointerDown)
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [open])

  function toggleCalendar() {
    if (disabled || readOnly) return
    if (!open) onOpen?.()
    if (!open && popoverRef.current) popoverRef.current.style.visibility = 'hidden'
    setOpen((current) => !current)
  }

  return <div className="reconciliation-date-picker" ref={pickerRef} data-required={required ? 'true' : undefined}>
    <button
      ref={triggerRef}
      id={id}
      type="button"
      className={`reconciliation-date-trigger${readOnly ? ' is-readonly' : ''}`}
      aria-haspopup="dialog"
      aria-expanded={open}
      aria-required={required || undefined}
      disabled={disabled}
      onClick={toggleCalendar}
    >
      <FormIcon name="date" />
      <span>{formatDateValue(value)}</span>
      <svg aria-hidden="true" viewBox="0 0 16 16" fill="none"><path d="m4 6 4 4 4-4" /></svg>
    </button>
    {open && createPortal(<div ref={popoverRef} className="reconciliation-date-popover" id={`${id}-calendar`} role="dialog" aria-label="Seleccionar fecha">
      <DayPicker
        mode="single"
        selected={selected}
        onSelect={(nextDate) => {
          onChange(nextDate ? dateToValue(nextDate) : '')
          setOpen(false)
          triggerRef.current?.focus()
        }}
        locale={es}
        weekStartsOn={1}
        today={today}
        defaultMonth={selected ?? maximum ?? today}
        endMonth={maximum}
        disabled={maximum ? { after: maximum } : undefined}
        showOutsideDays
        fixedWeeks
        autoFocus
      />
    </div>, document.body)}
  </div>
}

function ReconciliationManagement({ token, registers, canView, canCreate, onSessionExpired }: {
  token: string; registers: Register[]; canView: boolean; canCreate: boolean; onSessionExpired: () => void
}) {
  const [profile, setProfile] = useState<Resource<{ default_branch: BranchSummary | null; branches: BranchSummary[] }>>({ status: 'loading' })
  const [types, setTypes] = useState<Resource<ExpenseType[]>>({ status: 'loading' })
  const [records, setRecords] = useState<Resource<Reconciliation[]>>({ status: 'loading' })
  const [reloadVersion, setReloadVersion] = useState(0)
  const [storedFilter, setStoredFilter] = useSessionFilter('reconciliations.branch', '')
  const filter = storedFilter || null
  const setFilter = (value: string | null) => setStoredFilter(value ?? '')
  const [dateFilter, setDateFilter] = useSessionFilter('reconciliations.date', colombiaDate())
  const [today, setToday] = useState(colombiaDate)
  const [editing, setEditing] = useState<Reconciliation | 'new' | null>(null)
  const [branchId, setBranchId] = useState('')
  const [registerId, setRegisterId] = useState('')
  const [date, setDate] = useState('')
  const [sales, setSales] = useState('')
  const [cash, setCash] = useState('')
  const [bank, setBank] = useState('')
  const [qr, setQr] = useState('')
  const [expenses, setExpenses] = useState<DraftExpense[]>([])
  const [expenseEntry, setExpenseEntry] = useState<ExpenseEntry>({ typeId: '', detail: '', amount: '' })
  const [expenseEntryError, setExpenseEntryError] = useState('')
  const [nextKey, setNextKey] = useState(0)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [availability, setAvailability] = useState<{ registerId: string; date: string; result: Resource<boolean> } | null>(null)
  const [availabilityRetry, setAvailabilityRetry] = useState(0)
  const dateIsFuture = date !== '' && (date.length !== 10 || date > today)
  useEffect(() => {
    let active = true
    request<{ default_branch: BranchSummary | null; branches: BranchSummary[] }>('/user', { headers: { Authorization: `Bearer ${token}` } })
      .then((data) => { if (active) setProfile({ status: 'ready', data }) })
      .catch((err: unknown) => {
        if (!active) return
        if (err instanceof ApiError && err.status === 401) onSessionExpired()
        else setProfile({ status: 'error', message: err instanceof Error ? err.message : 'No se pudo cargar la sucursal por defecto.' })
      })
    return () => { active = false }
  }, [token, onSessionExpired, reloadVersion])

  useEffect(() => {
    let active = true
    request<ExpenseType[]>('/expense-types', { headers: { Authorization: `Bearer ${token}` } })
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
  }, [token, onSessionExpired, reloadVersion])

  useEffect(() => {
    if (!canView) return
    let active = true
    request<Reconciliation[]>('/reconciliations', { headers: { Authorization: `Bearer ${token}` } })
      .then((data) => {
        if (!Array.isArray(data)) throw new Error('El servidor devolvió una respuesta inválida.')
        if (active) setRecords({ status: 'ready', data })
      })
      .catch((err: unknown) => {
        if (!active) return
        if (err instanceof ApiError && err.status === 401) onSessionExpired()
        else setRecords({ status: 'error', message: err instanceof Error ? err.message : 'No se pudieron cargar los cuadres.' })
      })
    return () => { active = false }
  }, [token, canView, onSessionExpired, reloadVersion])

  useEffect(() => {
    if (editing !== 'new' || !registerId || !date || dateIsFuture) return
    let active = true
    const controller = new AbortController()
    const query = new URLSearchParams({ cash_register_id: registerId, date })
    request<unknown>(`/reconciliations/availability?${query}`, { headers: { Authorization: `Bearer ${token}` }, signal: controller.signal })
      .then((data) => {
        if (!data || typeof data !== 'object' || !('available' in data) || typeof data.available !== 'boolean') {
          throw new Error('El servidor devolvió una respuesta inválida.')
        }
        if (active) setAvailability({ registerId, date, result: { status: 'ready', data: data.available } })
      })
      .catch((err: unknown) => {
        if (!active) return
        if (err instanceof ApiError && err.status === 401) onSessionExpired()
        else setAvailability({ registerId, date, result: { status: 'error', message: err instanceof Error ? err.message : 'No se pudo verificar el cuadre.' } })
      })
    return () => { active = false; controller.abort() }
  }, [editing, registerId, date, dateIsFuture, token, onSessionExpired, availabilityRetry])

  const branches = Array.from(new Map([
    ...registers.map((register) => [register.branch.id, register.branch] as const),
    ...(records.status === 'ready' ? records.data.map((record) => [record.branch.id, record.branch] as const) : []),
    ...(profile.status === 'ready' && profile.data.default_branch ? [[profile.data.default_branch.id, profile.data.default_branch] as const] : []),
  ]).values()).sort((a, b) => a.name.localeCompare(b.name))
  const selectedFilter = filter ?? (profile.status === 'ready' && profile.data.default_branch ? String(profile.data.default_branch.id) : '')
  const formBranches = Array.from(new Map([
    ...registers.map((register) => [register.branch.id, register.branch] as const),
    ...(profile.status === 'ready' ? profile.data.branches.map((branch) => [branch.id, branch] as const) : []),
  ]).values()).sort((a, b) => a.name.localeCompare(b.name))
  const availableRegisters = registers.filter((register) => register.is_active && String(register.branch_id) === branchId)
  const availabilityResult = availability?.registerId === registerId && availability.date === date ? availability.result : null
  const canFill = editing !== 'new' || (!dateIsFuture && availabilityResult?.status === 'ready' && availabilityResult.data)
  const visible = records.status === 'ready' ? records.data.filter((record) =>
    (selectedFilter === 'all' || String(record.branch_id) === selectedFilter) && (!dateFilter || record.date === dateFilter)) : []
  const entryStarted = expenseEntry.typeId !== '' || expenseEntry.amount !== '' || expenseEntry.detail !== ''
  const entryTypeValid = types.status === 'ready' && types.data.some((type) => String(type.id) === expenseEntry.typeId)
  const entryValid = entryTypeValid && canonicalMoney(expenseEntry.amount) !== null
  const inputValues = [sales, cash, bank, qr, ...expenses.map((item) => item.amount)].map((value) => { const canonical = canonicalMoney(value); return canonical === null ? null : cents(canonical) })
  const expenseValues = expenses.map((item) => { const canonical = canonicalMoney(item.amount); return canonical === null ? null : cents(canonical) })
  if (entryStarted) {
    const amount = canonicalMoney(expenseEntry.amount)
    expenseValues.push(entryValid && amount !== null ? cents(amount) : null)
  }
  const total = expenseValues.every((value) => value !== null) ? expenseValues.reduce<bigint>((sum, value) => sum + (value ?? 0n), 0n) : null
  const difference = inputValues.every((value) => value !== null) && total !== null
    ? (inputValues[1] ?? 0n) + (inputValues[2] ?? 0n) + (inputValues[3] ?? 0n) + total - (inputValues[0] ?? 0n) : null

  function openForm(record: Reconciliation | 'new') {
    setToday(colombiaDate())
    setAvailability(null)
    setEditing(record)
    setBranchId(record === 'new' ? (selectedFilter !== 'all' && formBranches.some((branch) => String(branch.id) === selectedFilter) ? selectedFilter : '') : String(record.branch_id))
    setRegisterId(record === 'new' ? '' : String(record.cash_register_id))
    setDate(record === 'new' ? colombiaDate() : record.date)
    setSales(record === 'new' ? '' : displayMoney(record.sales))
    setCash(record === 'new' ? '' : displayMoney(record.cash))
    setBank(record === 'new' ? '' : displayMoney(record.bank))
    setQr(record === 'new' ? '' : displayMoney(record.qr))
    const items = record === 'new' ? [] : record.expenses.map((item, index) => ({ key: index, id: item.id, originalTypeId: String(item.expense_type_id), typeId: String(item.expense_type_id), typeName: item.type_name, detail: item.detail, amount: displayMoney(item.amount) }))
    setExpenses(items)
    setExpenseEntry({ typeId: '', detail: '', amount: '' })
    setExpenseEntryError('')
    setNextKey(items.length)
    setError('')
    setNotice('')
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!editing) return
    if (!canFill) {
      setError('Verifica la disponibilidad de la caja y la fecha antes de guardar.')
      return
    }
    if (entryStarted && !validateExpenseEntry()) return
    const finalExpenses = entryStarted ? [...expenses, { key: nextKey, typeName: '', ...expenseEntry }] : expenses
    const amounts = [sales, cash, bank, qr, ...finalExpenses.map((item) => item.amount)].map(canonicalMoney)
    if (amounts.some((amount) => amount === null)) {
      setError('Revisa los valores: usa puntos para miles y coma para hasta dos decimales.')
      return
    }
    setBusy(true)
    setError('')
    try {
      const saved = await request<Reconciliation>(editing === 'new' ? '/reconciliations' : `/reconciliations/${editing.id}`, {
        method: editing === 'new' ? 'POST' : 'PUT',
        headers: { Authorization: `Bearer ${token}` },
        body: JSON.stringify({
          ...(editing === 'new' ? { cash_register_id: Number(registerId), date } : { version: editing.version }),
          sales: amounts[0], cash: amounts[1], bank: amounts[2], qr: amounts[3],
          expenses: finalExpenses.map((item, index) => ({ ...(item.id ? { id: item.id } : {}), expense_type_id: Number(item.typeId), detail: item.detail.trim(), amount: amounts[index + 4] })),
        }),
      })
      setFilter(String(saved.branch_id))
      setDateFilter(saved.date)
      setEditing(null)
      setNotice('Cuadre guardado correctamente.')
      setReloadVersion((value) => value + 1)
    } catch (err) {
      if (err instanceof ApiError && err.status === 401) onSessionExpired()
      else {
        if (err instanceof ApiError && err.status === 409) setReloadVersion((value) => value + 1)
        if (editing === 'new' && err instanceof ApiError && err.status === 422) {
          setAvailability(null)
          setAvailabilityRetry((value) => value + 1)
        }
        setError(err instanceof Error ? err.message : 'No se pudo guardar el cuadre.')
      }
    } finally {
      setBusy(false)
    }
  }

  function updateExpense(key: number, patch: Partial<DraftExpense>) {
    setExpenses((current) => current.map((item) => item.key === key ? { ...item, ...patch } : item))
  }

  function updateExpenseEntry(patch: Partial<ExpenseEntry>) {
    setExpenseEntry((current) => ({ ...current, ...patch }))
    setExpenseEntryError('')
  }

  function validateExpenseEntry(): boolean {
    if (expenses.length >= 100) {
      setExpenseEntryError('El cuadre admite hasta 100 gastos.')
      return false
    }
    if (!entryTypeValid) {
      setExpenseEntryError('Selecciona el tipo de gasto.')
      document.getElementById('expense-entry-type')?.focus()
      return false
    }
    if (canonicalMoney(expenseEntry.amount) === null) {
      setExpenseEntryError('Ingresa un valor válido para el gasto.')
      document.getElementById('expense-entry-amount')?.focus()
      return false
    }
    return true
  }

  function addExpense() {
    if (!validateExpenseEntry()) return
    setExpenses((current) => [...current, { key: nextKey, typeName: '', ...expenseEntry }])
    setNextKey((value) => value + 1)
    setExpenseEntry({ typeId: '', detail: '', amount: '' })
    setExpenseEntryError('')
    if (expenses.length + 1 < 100) document.getElementById('expense-entry-type')?.focus()
  }

  return <div className="user-management reconciliation-management">
    {notice && <p className="user-notice" role="status">{notice}</p>}
    {error && <p className="user-error" role="alert">{error}</p>}
    {profile.status === 'error' && <div className="resource-error" role="alert"><p>{profile.message}</p><button type="button" onClick={() => setReloadVersion((value) => value + 1)}>Reintentar</button></div>}
    {types.status === 'error' && <div className="resource-error" role="alert"><p>{types.message}</p><button type="button" onClick={() => setReloadVersion((value) => value + 1)}>Reintentar</button></div>}
    {editing ? <form className="user-form" aria-busy={busy} onSubmit={submit}>
      <div className="user-form-heading"><h2>{editing === 'new' ? 'Registrar cuadre' : 'Editar cuadre'}</h2><p>Un cuadre por caja y fecha. La venta es el valor informado por la registradora.</p></div>
      <div className="user-fields">
        <div className="user-field"><label className="reconciliation-field-label" htmlFor="reconciliation-branch"><FormIcon name="branch" />Sucursal</label>{editing !== 'new'
          ? <input id="reconciliation-branch" value={`${editing.branch.name} · ${editing.branch.code}`} readOnly />
          : <select id="reconciliation-branch" required disabled={busy} value={branchId} onChange={(event) => { setError(''); setAvailability(null); setBranchId(event.target.value); setRegisterId('') }}><option value="">Selecciona una sucursal</option>{formBranches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name} · {branch.code}</option>)}</select>}</div>
        <div className="user-field"><label className="reconciliation-field-label" htmlFor="reconciliation-register"><FormIcon name="register" />Caja</label><select id="reconciliation-register" required disabled={busy || editing !== 'new' || !branchId} value={registerId} onChange={(event) => { setError(''); setAvailability(null); setRegisterId(event.target.value) }}><option value="">Selecciona una caja</option>{editing !== 'new' ? <option value={editing.cash_register_id}>{editing.cash_register.name} · {editing.cash_register.code}</option> : availableRegisters.map((register) => <option key={register.id} value={register.id}>{register.name} · {register.code}</option>)}</select>{editing === 'new' && branchId && availableRegisters.length === 0 && <small>No hay cajas activas disponibles en esta sucursal.</small>}</div>
        <div className="user-field"><label className="reconciliation-field-label" htmlFor="reconciliation-date"><FormIcon name="date" />Fecha</label><DatePicker id="reconciliation-date" value={date} required maxDate={editing === 'new' ? today : undefined} readOnly={editing !== 'new'} disabled={busy} onOpen={() => setToday(colombiaDate())} onChange={(value) => { setError(''); setAvailability(null); setDate(value) }} /></div>
      </div>
      {editing === 'new' && <div className="reconciliation-availability" aria-live="polite">
        {dateIsFuture ? <p role="alert">La fecha del cuadre no puede ser posterior a la fecha actual de Colombia.</p>
          : !registerId || !date ? <p>Selecciona una caja y una fecha para verificar si ya existe un cuadre.</p>
          : !availabilityResult || availabilityResult.status === 'loading' ? <p role="status">Verificando la caja y la fecha...</p>
          : availabilityResult.status === 'error' ? <div role="alert"><p>No se pudo verificar la disponibilidad: {availabilityResult.message}</p><button className="user-secondary" type="button" onClick={() => { setAvailability(null); setAvailabilityRetry((value) => value + 1) }}>Reintentar</button></div>
          : availabilityResult.data ? <p role="status">No hay un cuadre registrado para esta caja y fecha.</p>
          : <p role="alert">Ya existe un cuadre para esta caja y fecha. Selecciona otra caja o fecha.</p>}
      </div>}
      <fieldset className="reconciliation-inputs" disabled={!canFill || busy}>
      <div className="user-fields">
        <MoneyField id="reconciliation-sales" label="Venta" icon="sales" value={sales} onChange={setSales} />
        <MoneyField id="reconciliation-cash" label="Efectivo" icon="cash" value={cash} onChange={setCash} />
        <MoneyField id="reconciliation-bank" label="Banco" icon="bank" value={bank} onChange={setBank} />
        <MoneyField id="reconciliation-qr" label="QR" icon="qr" value={qr} onChange={setQr} />
      </div>
      <div className="reconciliation-expense-heading"><div><h3><FormIcon name="expense" />Gastos</h3><p>Diligencia la fila y pulsa Agregar. El detalle es opcional.</p></div><span>Total: <strong>{total === null ? 'Revisa los valores' : formatCents(total)}</strong></span></div>
      {types.status === 'loading' && <p role="status">Cargando tipos de gasto...</p>}
      {types.status === 'ready' && types.data.length === 0 && <p>No hay tipos de gasto activos disponibles.</p>}
      <table className="reconciliation-expense-table">
        <caption className="reconciliation-visually-hidden">Gastos del cuadre</caption>
        <colgroup><col /><col /><col /><col /></colgroup>
        <thead><tr><th scope="col">Tipo de gasto</th><th scope="col">Valor</th><th scope="col">Detalle (opcional)</th><th scope="col">Acción</th></tr></thead>
        <tbody>
          {expenses.map((item, index) => <tr key={item.key}>
            <td data-label="Tipo de gasto"><div className="user-field"><label className="reconciliation-visually-hidden" htmlFor={`expense-type-${item.key}`}>Tipo de gasto {index + 1}</label><select id={`expense-type-${item.key}`} required value={item.typeId} onChange={(event) => updateExpense(item.key, { typeId: event.target.value })}><option value="">Selecciona un tipo</option>{item.originalTypeId && types.status === 'ready' && !types.data.some((type) => String(type.id) === item.originalTypeId) && <option value={item.originalTypeId}>{item.typeName} (inactivo)</option>}{types.status === 'ready' && types.data.map((type) => <option key={type.id} value={type.id}>{type.name}</option>)}</select></div></td>
            <td data-label="Valor"><MoneyField id={`expense-amount-${item.key}`} label={`Valor del gasto ${index + 1}`} value={item.amount} onChange={(value) => updateExpense(item.key, { amount: value })} showHelp={false} hideLabel /></td>
            <td data-label="Detalle (opcional)"><div className="user-field"><label className="reconciliation-visually-hidden" htmlFor={`expense-detail-${item.key}`}>Detalle del gasto {index + 1} (opcional)</label><input id={`expense-detail-${item.key}`} maxLength={255} value={item.detail} onChange={(event) => updateExpense(item.key, { detail: event.target.value })} /></div></td>
            <td data-label="Acción"><button className="user-secondary reconciliation-expense-action" type="button" aria-label={`Quitar gasto ${index + 1}`} disabled={busy} onClick={() => setExpenses((current) => current.filter((entry) => entry.key !== item.key))}><FormIcon name="remove" />Quitar</button></td>
          </tr>)}
          <tr className="reconciliation-expense-entry">
            <td data-label="Tipo de gasto"><div className="user-field"><label className="reconciliation-visually-hidden" htmlFor="expense-entry-type">Tipo del nuevo gasto</label><select id="expense-entry-type" disabled={busy || types.status !== 'ready' || types.data.length === 0 || expenses.length >= 100} value={expenseEntry.typeId} onChange={(event) => updateExpenseEntry({ typeId: event.target.value })}><option value="">Selecciona un tipo</option>{types.status === 'ready' && types.data.map((type) => <option key={type.id} value={type.id}>{type.name}</option>)}</select></div></td>
            <td data-label="Valor"><MoneyField id="expense-entry-amount" label="Valor del nuevo gasto" value={expenseEntry.amount} onChange={(value) => updateExpenseEntry({ amount: value })} showHelp={false} hideLabel required={false} disabled={busy || types.status !== 'ready' || types.data.length === 0 || expenses.length >= 100} /></td>
            <td data-label="Detalle (opcional)"><div className="user-field"><label className="reconciliation-visually-hidden" htmlFor="expense-entry-detail">Detalle del nuevo gasto (opcional)</label><input id="expense-entry-detail" maxLength={255} disabled={busy || types.status !== 'ready' || types.data.length === 0 || expenses.length >= 100} value={expenseEntry.detail} onChange={(event) => updateExpenseEntry({ detail: event.target.value })} /></div></td>
            <td data-label="Acción"><button className="user-secondary reconciliation-expense-action" type="button" disabled={busy || types.status !== 'ready' || types.data.length === 0 || expenses.length >= 100} onClick={addExpense}><FormIcon name="add" />Agregar</button></td>
          </tr>
        </tbody>
      </table>
      {expenses.length >= 100 && <p className="reconciliation-expense-message">Se alcanzó el límite de 100 gastos.</p>}
      {entryStarted && <p className="reconciliation-expense-message">La fila pendiente se incluirá al guardar si está completa.</p>}
      {expenseEntryError && <p className="reconciliation-expense-error" role="alert">{expenseEntryError}</p>}
      <div className="reconciliation-result" aria-live="polite"><p>Total gastos: <strong>{total === null ? 'Revisa los valores' : formatCents(total)}</strong></p><p>Cuadre: <strong>{difference === null ? 'Revisa los valores' : `${difference < 0n ? 'Faltante' : difference > 0n ? 'Sobrante' : 'Cuadrado'} · ${formatCents(difference)}`}</strong></p></div>
      </fieldset>
      <div className="user-form-actions"><button className="user-primary reconciliation-form-action" disabled={busy || types.status !== 'ready' || !canFill} type="submit"><FormIcon name="save" />{busy ? 'Guardando...' : 'Guardar cuadre'}</button><button className="user-secondary" disabled={busy} type="button" onClick={() => { setEditing(null); setError('') }}>Cancelar</button></div>
    </form> : <>
      <div className="user-toolbar reconciliation-toolbar">
        {canView && <div className="user-field"><label htmlFor="reconciliation-filter">Sucursal</label><select id="reconciliation-filter" disabled={profile.status !== 'ready'} value={selectedFilter} onChange={(event) => setFilter(event.target.value)}><option value="">Selecciona una sucursal</option><option value="all">Todas las sucursales disponibles</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name} · {branch.code}</option>)}</select></div>}
        {canView && <div className="user-field"><label htmlFor="reconciliation-filter-date">Fecha</label><DatePicker id="reconciliation-filter-date" value={dateFilter} onChange={setDateFilter} /></div>}
        {canCreate && <button className="user-primary" type="button" disabled={busy || types.status !== 'ready' || profile.status !== 'ready'} onClick={() => openForm('new')}>+ Registrar cuadre</button>}
      </div>
      {!canView ? <p className="workspace-message">No tienes permiso para consultar cuadres guardados.</p> : profile.status === 'loading' || records.status === 'loading' ? <p className="workspace-message" role="status">Cargando cuadres...</p> : records.status === 'error' ? <div className="resource-error" role="alert"><p>{records.message}</p><button type="button" onClick={() => setReloadVersion((value) => value + 1)}>Reintentar</button></div> : profile.status === 'error' ? null : !selectedFilter ? <EmptyState title="Selecciona una sucursal" description="Elige una sucursal para consultar sus cuadres guardados." /> : visible.length === 0 ? <EmptyState
        title={records.status === 'ready' && records.data.length === 0 ? 'Aún no hay cuadres registrados' : 'No encontramos cuadres con estos filtros'}
        description={records.status === 'ready' && records.data.length === 0 ? 'Los cuadres que registres aparecerán aquí.' : 'Prueba con otra sucursal o fecha, o limpia los filtros para ver todos los resultados.'}
        actionLabel={records.status === 'ready' && records.data.length === 0 && canCreate ? '+ Registrar cuadre' : undefined}
        onAction={records.status === 'ready' && records.data.length === 0 && canCreate ? () => openForm('new') : undefined}
        secondaryLabel={records.status === 'ready' && records.data.length > 0 ? 'Limpiar filtros' : undefined}
        onSecondaryAction={records.status === 'ready' && records.data.length > 0 ? () => { setFilter('all'); setDateFilter('') } : undefined}
      /> : <div className="record-list">{visible.map((record) => {
        const result = cents(record.difference.startsWith('-') ? record.difference.slice(1) : record.difference)
        const signed = result === null ? null : record.difference.startsWith('-') ? -result : result
        const amounts = `Venta ${formatAmount(record.sales)} · Efectivo ${formatAmount(record.cash)} · Banco ${formatAmount(record.bank)} · QR ${formatAmount(record.qr)} · Gastos ${formatAmount(record.expenses_total)}`
        return <div className="record-row user-row reconciliation-record" key={record.id}><div className="record-symbol" aria-hidden="true">$</div><div className="record-copy"><strong>{record.cash_register.name} · {record.date}</strong><span>{record.branch.name} · {signed === null ? record.difference : `${signed < 0n ? 'Faltante' : signed > 0n ? 'Sobrante' : 'Cuadrado'} ${formatCents(signed)}`}</span><span className="reconciliation-record-amounts">{amounts}</span><details className="reconciliation-mobile-amounts"><summary>Ver valores</summary><span>{amounts}</span></details><details><summary>Detalle de gastos</summary>{record.expenses.length === 0 ? <span>Sin gastos</span> : record.expenses.map((expense) => <span key={expense.id}>{expense.type_name}{expense.detail && ` · ${expense.detail}`} · {formatAmount(expense.amount)}</span>)}</details></div>{record.can_update && <div className="user-row-actions"><button type="button" onClick={() => openForm(record)}>Editar</button></div>}</div>
      })}</div>}
    </>}
  </div>
}

export default ReconciliationManagement
