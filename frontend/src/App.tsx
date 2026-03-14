import { useState, useEffect, useCallback } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { TeamAnalyzer } from './pages/TeamAnalyzer'
import { LeagueAnalyzer } from './pages/LeagueAnalyzer'
import { Live } from './pages/Live'
import { Planner } from './pages/Planner'
import { Admin } from './pages/Admin'
import { TabNav } from './components/ui/TabNav'
import { useSyncStatus } from './hooks/useSyncStatus'
import './index.css'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      refetchOnWindowFocus: false,
      retry: 1,
    },
  },
})

type Page = 'season-review' | 'league-analyzer' | 'live' | 'planner' | 'admin'

const tabs = [
  { id: 'season-review', label: 'Season' },
  { id: 'league-analyzer', label: 'League' },
  { id: 'live', label: 'Live', isLive: true },
  { id: 'planner', label: 'Planner' },
]

const pageNumbers: Record<string, string> = {
  'season-review': 'P101',
  'league-analyzer': 'P201',
  live: 'P301',
  planner: 'P401',
  admin: 'P901',
}

const validPages = new Set(['season-review', 'league-analyzer', 'live', 'planner', 'admin'])

function getInitialPage(): Page {
  const params = new URLSearchParams(window.location.search)
  const tab = params.get('tab')
  if (tab && validPages.has(tab)) {
    return tab as Page
  }
  return 'season-review'
}

function SyncWatcher() {
  useSyncStatus()
  return null
}

function CeefaxTime() {
  const [time, setTime] = useState(new Date())

  useEffect(() => {
    const timer = setInterval(() => setTime(new Date()), 60000)
    return () => clearInterval(timer)
  }, [])

  const dateStr = time.toLocaleDateString('en-GB', {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
  })
  const timeStr = time.toLocaleTimeString('en-GB', {
    hour: '2-digit',
    minute: '2-digit',
  })

  return (
    <span className="text-tt-white">
      {dateStr} {timeStr}
    </span>
  )
}

function FontToggle() {
  const [isPixel, setIsPixel] = useState(() => {
    return localStorage.getItem('superfpl-font') !== 'mono'
  })

  const toggle = useCallback(() => {
    setIsPixel((prev) => {
      const next = !prev
      if (next) {
        document.documentElement.classList.remove('font-mono-mode')
        localStorage.setItem('superfpl-font', 'pixel')
      } else {
        document.documentElement.classList.add('font-mono-mode')
        localStorage.setItem('superfpl-font', 'mono')
      }
      return next
    })
  }, [])

  useEffect(() => {
    if (!isPixel) {
      document.documentElement.classList.add('font-mono-mode')
    }
  }, [isPixel])

  return (
    <button onClick={toggle} className="text-tt-dim hover:text-tt-cyan text-xs uppercase">
      [A] FONT: {isPixel ? 'PIXEL' : 'MONO'}
    </button>
  )
}

function App() {
  const [page, setPage] = useState<Page>(getInitialPage)

  const handlePageChange = useCallback((newPage: Page) => {
    setPage(newPage)
    const params = new URLSearchParams(window.location.search)
    params.set('tab', newPage)
    const newUrl = `${window.location.pathname}?${params.toString()}`
    window.history.pushState({}, '', newUrl)
  }, [])

  useEffect(() => {
    const handlePopState = () => {
      setPage(getInitialPage())
    }
    window.addEventListener('popstate', handlePopState)
    return () => window.removeEventListener('popstate', handlePopState)
  }, [])

  return (
    <QueryClientProvider client={queryClient}>
      <SyncWatcher />
      <div className="min-h-screen bg-background text-foreground">
        {/* Ceefax page header */}
        <header className="border-b border-border">
          <div className="container mx-auto px-4 py-3">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-4">
                <span className="text-tt-white">{pageNumbers[page] ?? 'P100'}</span>
                <h1 className="text-2xl sm:text-3xl font-bold">
                  <span className="text-tt-cyan">SUPERFPL</span>
                </h1>
              </div>
              <CeefaxTime />
            </div>

            {/* Navigation - colored keys */}
            <div className="mt-3">
              <TabNav
                tabs={tabs}
                activeTab={page}
                onTabChange={(id) => handlePageChange(id as Page)}
                className="w-full sm:w-auto"
              />
            </div>
          </div>
        </header>

        {/* Main Content */}
        <main className="container mx-auto px-4 py-6 sm:py-8">
          <div>
            {page === 'season-review' && <TeamAnalyzer />}
            {page === 'league-analyzer' && <LeagueAnalyzer />}
            {page === 'live' && <Live />}
            {page === 'planner' && <Planner />}
            {page === 'admin' && <Admin />}
          </div>
        </main>

        {/* Footer */}
        <footer className="border-t border-border mt-auto">
          <div className="container mx-auto px-4 py-4 flex items-center justify-between">
            <p className="text-xs text-tt-dim">Data from Official FPL API</p>
            <FontToggle />
          </div>
        </footer>
      </div>
    </QueryClientProvider>
  )
}

export default App
