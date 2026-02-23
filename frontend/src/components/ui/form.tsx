import * as React from 'react'
import { cn } from '../../lib/utils'

interface FormSectionCardProps extends React.HTMLAttributes<HTMLDivElement> {
  heading: React.ReactNode
  description?: React.ReactNode
}

export function FormSectionCard({
  heading,
  description,
  className,
  children,
  ...props
}: FormSectionCardProps) {
  return (
    <section className={cn('form-section-card', className)} {...props}>
      <h4 className="form-section-title">{heading}</h4>
      {description ? <p className="mt-2 form-help-text">{description}</p> : null}
      {children ? <div className="mt-3">{children}</div> : null}
    </section>
  )
}

interface FormFieldProps extends React.HTMLAttributes<HTMLDivElement> {
  label: React.ReactNode
}

export function FormField({ label, className, children, ...props }: FormFieldProps) {
  return (
    <div className={cn('form-field', className)} {...props}>
      <div className="form-label">{label}</div>
      {children}
    </div>
  )
}

export const FormInput = React.forwardRef<
  HTMLInputElement,
  React.InputHTMLAttributes<HTMLInputElement>
>(({ className, ...props }, ref) => (
  <input ref={ref} className={cn('form-control', className)} {...props} />
))
FormInput.displayName = 'FormInput'

export const FormSelect = React.forwardRef<
  HTMLSelectElement,
  React.SelectHTMLAttributes<HTMLSelectElement>
>(({ className, ...props }, ref) => (
  <select ref={ref} className={cn('form-control', className)} {...props} />
))
FormSelect.displayName = 'FormSelect'

export function SearchResultsList({
  className,
  children,
  ...props
}: React.HTMLAttributes<HTMLDivElement>) {
  return (
    <div className={cn('form-results', className)} {...props}>
      {children}
    </div>
  )
}

export const SearchResultButton = React.forwardRef<
  HTMLButtonElement,
  React.ButtonHTMLAttributes<HTMLButtonElement>
>(({ className, type = 'button', ...props }, ref) => (
  <button ref={ref} type={type} className={cn('form-result-item', className)} {...props} />
))
SearchResultButton.displayName = 'SearchResultButton'

interface SelectionPillListProps extends React.HTMLAttributes<HTMLDivElement> {
  emptyText: string
  hasItems: boolean
}

export function SelectionPillList({
  emptyText,
  hasItems,
  className,
  children,
  ...props
}: SelectionPillListProps) {
  return (
    <div className={cn('form-pill-list', className)} {...props}>
      {hasItems ? children : <span className="form-empty-text">{emptyText}</span>}
    </div>
  )
}

type SelectionPillTone = 'lock' | 'avoid' | 'team'

const selectionPillToneClasses: Record<SelectionPillTone, string> = {
  lock: 'form-pill-lock',
  avoid: 'form-pill-avoid',
  team: 'form-pill-team',
}

interface SelectionPillProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  tone: SelectionPillTone
}

export const SelectionPill = React.forwardRef<HTMLButtonElement, SelectionPillProps>(
  ({ tone, className, type = 'button', ...props }, ref) => (
    <button
      ref={ref}
      type={type}
      className={cn('form-pill', selectionPillToneClasses[tone], className)}
      {...props}
    />
  )
)
SelectionPill.displayName = 'SelectionPill'
