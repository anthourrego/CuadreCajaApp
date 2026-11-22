import { useState } from 'react'

const prefix = 'cuadrecaja:filter:'

export function useSessionFilter(key: string, initialValue: string) {
  const storageKey = `${prefix}${key}`
  const [value, setValue] = useState(() => sessionStorage.getItem(storageKey) ?? initialValue)

  function updateValue(nextValue: string) {
    sessionStorage.setItem(storageKey, nextValue)
    setValue(nextValue)
  }

  return [value, updateValue] as const
}

export function clearSessionFilters() {
  for (let index = sessionStorage.length - 1; index >= 0; index--) {
    const key = sessionStorage.key(index)
    if (key?.startsWith(prefix)) sessionStorage.removeItem(key)
  }
}
