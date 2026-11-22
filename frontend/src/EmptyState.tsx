import type { MouseEvent } from 'react'

function EmptyState({ title, description, actionLabel, onAction, secondaryLabel, onSecondaryAction }: {
  title: string
  description: string
  actionLabel?: string
  onAction?: (event: MouseEvent<HTMLButtonElement>) => void
  secondaryLabel?: string
  onSecondaryAction?: () => void
}) {
  return (
    <div className="empty-state" aria-live="polite">
      <span className="empty-state-icon" aria-hidden="true">⌕</span>
      <h3>{title}</h3>
      <p>{description}</p>
      {(actionLabel && onAction) || (secondaryLabel && onSecondaryAction) ? (
        <div className="empty-state-actions">
          {actionLabel && onAction && <button className="user-primary" type="button" onClick={onAction}>{actionLabel}</button>}
          {secondaryLabel && onSecondaryAction && <button className="user-secondary" type="button" onClick={onSecondaryAction}>{secondaryLabel}</button>}
        </div>
      ) : null}
    </div>
  )
}

export default EmptyState
