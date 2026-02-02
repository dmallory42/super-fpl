import { ReactNode } from 'react'
import { LiveIndicator } from './LiveIndicator'

interface TabItem {
  id: string
  label: string
  isLive?: boolean
  icon?: ReactNode
}

interface TabNavProps {
  tabs: TabItem[]
  activeTab: string
  onTabChange: (tabId: string) => void
  className?: string
}

export function TabNav({ tabs, activeTab, onTabChange, className = '' }: TabNavProps) {
  return (
    <nav className={`tab-nav ${className}`}>
      {tabs.map((tab) => (
        <button
          key={tab.id}
          onClick={() => onTabChange(tab.id)}
          className={`tab-nav-item ${activeTab === tab.id ? 'active' : ''}`}
        >
          <span className="flex items-center gap-2">
            {tab.icon && <span className="opacity-70">{tab.icon}</span>}
            <span>{tab.label}</span>
            {tab.isLive && <LiveIndicator size="sm" showText={false} />}
          </span>
        </button>
      ))}
    </nav>
  )
}
