import { useState, useCallback } from 'react'

interface ManagerSearchProps {
  onSearch: (managerId: number) => void
  isLoading: boolean
}

export function ManagerSearch({ onSearch, isLoading }: ManagerSearchProps) {
  const [inputValue, setInputValue] = useState('')
  const [error, setError] = useState<string | null>(null)

  const handleSubmit = useCallback(
    (e: React.FormEvent) => {
      e.preventDefault()
      setError(null)

      const trimmed = inputValue.trim()
      if (!trimmed) {
        setError('Please enter a manager ID')
        return
      }

      const id = parseInt(trimmed, 10)
      if (isNaN(id) || id <= 0) {
        setError('Please enter a valid manager ID (positive number)')
        return
      }

      onSearch(id)
    },
    [inputValue, onSearch]
  )

  return (
    <form onSubmit={handleSubmit} className="flex flex-col sm:flex-row gap-3">
      <div className="flex-1">
        <input
          type="text"
          value={inputValue}
          onChange={(e) => setInputValue(e.target.value)}
          placeholder="Enter FPL Manager ID (e.g., 12345)"
          className="input-broadcast"
          disabled={isLoading}
        />
        {error && <p className="text-destructive text-sm mt-1 animate-fade-in-up">{error}</p>}
      </div>
      <button type="submit" disabled={isLoading} className="btn-primary">
        {isLoading ? (
          <span className="flex items-center gap-2">
            <span className="w-4 h-4 border-2 border-current border-t-transparent rounded-full animate-spin" />
            Loading
          </span>
        ) : (
          'Analyze'
        )}
      </button>
    </form>
  )
}
