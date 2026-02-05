import { useState, useEffect, useCallback } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { TeamAnalyzer } from './pages/TeamAnalyzer'
import { LeagueAnalyzer } from './pages/LeagueAnalyzer'
import { Live } from './pages/Live'
import { Planner } from './pages/Planner'
import { TabNav } from './components/ui/TabNav'
import { GradientText } from './components/ui/GradientText'
import './index.css'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      refetchOnWindowFocus: false,
      retry: 1,
    },
  },
})

type Page = 'season-review' | 'league-analyzer' | 'live' | 'planner'

const tabs = [
  { id: 'season-review', label: 'Season' },
  { id: 'league-analyzer', label: 'League' },
  { id: 'live', label: 'Live', isLive: true },
  { id: 'planner', label: 'Planner' },
]

const validPages = new Set(['season-review', 'league-analyzer', 'live', 'planner'])

function getInitialPage(): Page {
  const params = new URLSearchParams(window.location.search)
  const tab = params.get('tab')
  if (tab && validPages.has(tab)) {
    return tab as Page
  }
  return 'season-review'
}

function App() {
  const [page, setPage] = useState<Page>(getInitialPage)

  // Update URL when page changes
  const handlePageChange = useCallback((newPage: Page) => {
    setPage(newPage)
    const params = new URLSearchParams(window.location.search)
    params.set('tab', newPage)
    const newUrl = `${window.location.pathname}?${params.toString()}`
    window.history.pushState({}, '', newUrl)
  }, [])

  // Handle browser back/forward
  useEffect(() => {
    const handlePopState = () => {
      setPage(getInitialPage())
    }
    window.addEventListener('popstate', handlePopState)
    return () => window.removeEventListener('popstate', handlePopState)
  }, [])

  return (
    <QueryClientProvider client={queryClient}>
      <div className="min-h-screen bg-background text-foreground">
        {/* Header */}
        <header className="sticky top-0 z-50 border-b border-border glass">
          <div className="container mx-auto px-4 py-4">
            <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
              {/* Logo */}
              <div className="animate-fade-in-up">
                <h1 className="font-display text-2xl sm:text-3xl font-bold tracking-wider">
                  <GradientText>SUPERFPL</GradientText>
                </h1>
                <p className="text-xs sm:text-sm text-foreground-muted tracking-wide">
                  Fantasy Analytics
                </p>
              </div>

              {/* Navigation */}
              <TabNav
                tabs={tabs}
                activeTab={page}
                onTabChange={(id) => handlePageChange(id as Page)}
                className="animate-fade-in-up animation-delay-100 w-full sm:w-auto"
              />
            </div>
          </div>
        </header>

        {/* Main Content */}
        <main className="container mx-auto px-4 py-6 sm:py-8">
          <div className="animate-fade-in-up animation-delay-200">
            {page === 'season-review' && <TeamAnalyzer />}
            {page === 'league-analyzer' && <LeagueAnalyzer />}
            {page === 'live' && <Live />}
            {page === 'planner' && <Planner />}
          </div>
        </main>

        {/* Footer */}
        <footer className="border-t border-border mt-auto">
          <div className="container mx-auto px-4 py-4 text-center">
            <p className="text-xs text-foreground-dim">Data from Official FPL API</p>
          </div>
        </footer>
      </div>
    </QueryClientProvider>
  )
}

export default App
