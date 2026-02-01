import { useState, useCallback } from 'react'

interface ManagerSearchProps {
  onSearch: (managerId: number) => void
  isLoading: boolean
}

export function ManagerSearch({ onSearch, isLoading }: ManagerSearchProps) {
  const [inputValue, setInputValue] = useState('')
  const [error, setError] = useState<string | null>(null)

  const handleSubmit = useCallback((e: React.FormEvent) => {
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
  }, [inputValue, onSearch])

  return (
    <form onSubmit={handleSubmit} className="flex flex-col sm:flex-row gap-3">
      <div className="flex-1">
        <input
          type="text"
          value={inputValue}
          onChange={(e) => setInputValue(e.target.value)}
          placeholder="Enter FPL Manager ID (e.g., 12345)"
          className="w-full px-4 py-2 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:border-green-500"
          disabled={isLoading}
        />
        {error && <p className="text-red-400 text-sm mt-1">{error}</p>}
      </div>
      <button
        type="submit"
        disabled={isLoading}
        className="px-6 py-2 bg-green-600 hover:bg-green-700 disabled:bg-gray-600 disabled:cursor-not-allowed text-white font-medium rounded-lg transition-colors"
      >
        {isLoading ? 'Loading...' : 'Analyze'}
      </button>
    </form>
  )
}
