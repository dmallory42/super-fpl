import { useState } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { Home } from './pages/Home'
import { TeamAnalyzer } from './pages/TeamAnalyzer'
import { Predictor } from './pages/Predictor'
import { Compare } from './pages/Compare'
import './index.css'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      refetchOnWindowFocus: false,
      retry: 1,
    },
  },
})

type Page = 'home' | 'team-analyzer' | 'predictor' | 'compare'

function App() {
  const [page, setPage] = useState<Page>('home')

  return (
    <QueryClientProvider client={queryClient}>
      <div className="min-h-screen bg-gray-900 text-white">
        <header className="border-b border-gray-800">
          <div className="container mx-auto px-4 py-4">
            <div className="flex items-center justify-between">
              <div>
                <h1 className="text-2xl font-bold text-green-400">SuperFPL</h1>
                <p className="text-sm text-gray-400">Fantasy Premier League Analytics</p>
              </div>
              <nav className="flex gap-2">
                <button
                  onClick={() => setPage('home')}
                  className={`px-4 py-2 rounded-lg transition-colors ${
                    page === 'home'
                      ? 'bg-green-600 text-white'
                      : 'bg-gray-800 text-gray-300 hover:bg-gray-700'
                  }`}
                >
                  Players
                </button>
                <button
                  onClick={() => setPage('team-analyzer')}
                  className={`px-4 py-2 rounded-lg transition-colors ${
                    page === 'team-analyzer'
                      ? 'bg-green-600 text-white'
                      : 'bg-gray-800 text-gray-300 hover:bg-gray-700'
                  }`}
                >
                  Team Analyzer
                </button>
                <button
                  onClick={() => setPage('predictor')}
                  className={`px-4 py-2 rounded-lg transition-colors ${
                    page === 'predictor'
                      ? 'bg-green-600 text-white'
                      : 'bg-gray-800 text-gray-300 hover:bg-gray-700'
                  }`}
                >
                  Predictor
                </button>
                <button
                  onClick={() => setPage('compare')}
                  className={`px-4 py-2 rounded-lg transition-colors ${
                    page === 'compare'
                      ? 'bg-green-600 text-white'
                      : 'bg-gray-800 text-gray-300 hover:bg-gray-700'
                  }`}
                >
                  Compare
                </button>
              </nav>
            </div>
          </div>
        </header>
        <main className="container mx-auto px-4 py-8">
          {page === 'home' && <Home />}
          {page === 'team-analyzer' && <TeamAnalyzer />}
          {page === 'predictor' && <Predictor />}
          {page === 'compare' && <Compare />}
        </main>
      </div>
    </QueryClientProvider>
  )
}

export default App
