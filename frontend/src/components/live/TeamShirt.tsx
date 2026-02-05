// Premier League team colors mapped to actual FPL API team IDs
const TEAM_COLORS: Record<number, { primary: string; secondary: string }> = {
  1: { primary: '#EF0107', secondary: '#FFFFFF' }, // Arsenal - Red/White
  2: { primary: '#670E36', secondary: '#95BFE5' }, // Aston Villa - Claret/Blue
  3: { primary: '#6C1D45', secondary: '#99D6EA' }, // Burnley - Claret/Blue
  4: { primary: '#DA291C', secondary: '#000000' }, // Bournemouth - Red/Black
  5: { primary: '#E30613', secondary: '#FFFFFF' }, // Brentford - Red/White
  6: { primary: '#0057B8', secondary: '#FFFFFF' }, // Brighton - Blue/White
  7: { primary: '#034694', secondary: '#FFFFFF' }, // Chelsea - Blue/White
  8: { primary: '#1B458F', secondary: '#C4122E' }, // Crystal Palace - Blue/Red
  9: { primary: '#003399', secondary: '#FFFFFF' }, // Everton - Blue/White
  10: { primary: '#FFFFFF', secondary: '#000000' }, // Fulham - White/Black
  11: { primary: '#FFCD00', secondary: '#1D428A' }, // Leeds - Yellow/Blue
  12: { primary: '#C8102E', secondary: '#FFFFFF' }, // Liverpool - Red/White
  13: { primary: '#6CABDD', secondary: '#FFFFFF' }, // Man City - Sky Blue/White
  14: { primary: '#DA291C', secondary: '#FBE122' }, // Man Utd - Red/Yellow
  15: { primary: '#241F20', secondary: '#FFFFFF' }, // Newcastle - Black/White
  16: { primary: '#E53233', secondary: '#FFFFFF' }, // Nott'm Forest - Red/White
  17: { primary: '#EB172B', secondary: '#FFFFFF' }, // Sunderland - Red/White
  18: { primary: '#132257', secondary: '#FFFFFF' }, // Spurs - Navy/White
  19: { primary: '#7A263A', secondary: '#1BB1E7' }, // West Ham - Claret/Blue
  20: { primary: '#FDB913', secondary: '#231F20' }, // Wolves - Gold/Black
}

// Fallback for unknown teams
const DEFAULT_COLORS = { primary: '#00FF87', secondary: '#FFFFFF' }

interface TeamShirtProps {
  teamId: number
  className?: string
  size?: number
}

export function TeamShirt({ teamId, className = '', size = 56 }: TeamShirtProps) {
  const colors = TEAM_COLORS[teamId] || DEFAULT_COLORS

  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 64 64"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      className={className}
    >
      {/* Shirt body */}
      <path
        d="M16 20 L12 16 L4 20 L8 28 L12 26 L12 56 L52 56 L52 26 L56 28 L60 20 L52 16 L48 20 L44 16 C40 12 24 12 20 16 L16 20 Z"
        fill={colors.primary}
        stroke={colors.secondary}
        strokeWidth="2"
      />
      {/* Collar */}
      <path d="M24 16 C28 20 36 20 40 16" stroke={colors.secondary} strokeWidth="2" fill="none" />
      {/* Left sleeve trim */}
      <path d="M12 26 L8 28" stroke={colors.secondary} strokeWidth="2" />
      {/* Right sleeve trim */}
      <path d="M52 26 L56 28" stroke={colors.secondary} strokeWidth="2" />
    </svg>
  )
}

export function getTeamColors(teamId: number): { primary: string; secondary: string } {
  return TEAM_COLORS[teamId] || DEFAULT_COLORS
}
