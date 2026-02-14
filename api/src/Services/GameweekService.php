<?php

declare(strict_types=1);

namespace SuperFPL\Api\Services;

use SuperFPL\Api\Database;

class GameweekService
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Get the current gameweek (first unfinished gameweek)
     */
    public function getCurrentGameweek(): int
    {
        $fixture = $this->db->fetchOne(
            "SELECT DISTINCT gameweek FROM fixtures
             WHERE finished = 0 AND gameweek IS NOT NULL AND gameweek > 0
             ORDER BY gameweek ASC LIMIT 1"
        );

        return $fixture ? (int) $fixture['gameweek'] : 38;
    }

    /**
     * Get the next N gameweeks starting from the given GW (or current).
     */
    public function getUpcomingGameweeks(int $count = 6, ?int $from = null): array
    {
        $start = $from ?? $this->getCurrentGameweek();
        $gameweeks = [];

        for ($i = 0; $i < $count && ($start + $i) <= 38; $i++) {
            $gameweeks[] = $start + $i;
        }

        return $gameweeks;
    }

    /**
     * Check if a gameweek has started (earliest kickoff is in the past).
     * Once started, transfers are locked — you can't plan for this GW.
     */
    public function hasGameweekStarted(int $gameweek): bool
    {
        $row = $this->db->fetchOne(
            "SELECT MIN(kickoff_time) as first_kickoff FROM fixtures WHERE gameweek = ?",
            [$gameweek]
        );

        if (!$row || !$row['first_kickoff']) {
            return false;
        }

        return strtotime($row['first_kickoff']) <= time();
    }

    /**
     * Get the next gameweek you can still make transfers for.
     * If the current GW has started, returns currentGw + 1.
     */
    public function getNextActionableGameweek(): int
    {
        $current = $this->getCurrentGameweek();
        if ($this->hasGameweekStarted($current)) {
            return min($current + 1, 38);
        }
        return $current;
    }

    /**
     * Check if a gameweek is fully finished
     */
    public function isGameweekFinished(int $gameweek): bool
    {
        $unfinished = $this->db->fetchOne(
            "SELECT id FROM fixtures WHERE gameweek = ? AND finished = 0 LIMIT 1",
            [$gameweek]
        );

        return $unfinished === null;
    }

    /**
     * Get fixtures for upcoming gameweeks with team fixture counts (for DGW detection)
     */
    public function getFixtureCounts(array $gameweeks): array
    {
        if (empty($gameweeks)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($gameweeks), '?'));

        $rows = $this->db->fetchAll(
            "SELECT gameweek, home_club_id as team_id, COUNT(*) as fixture_count
             FROM fixtures
             WHERE gameweek IN ($placeholders)
             GROUP BY gameweek, home_club_id
             UNION ALL
             SELECT gameweek, away_club_id as team_id, COUNT(*) as fixture_count
             FROM fixtures
             WHERE gameweek IN ($placeholders)
             GROUP BY gameweek, away_club_id",
            array_merge($gameweeks, $gameweeks)
        );

        $counts = [];
        foreach ($rows as $row) {
            $gw = (int) $row['gameweek'];
            $team = (int) $row['team_id'];
            if (!isset($counts[$gw])) {
                $counts[$gw] = [];
            }
            if (!isset($counts[$gw][$team])) {
                $counts[$gw][$team] = 0;
            }
            $counts[$gw][$team] += (int) $row['fixture_count'];
        }

        return $counts;
    }

    /**
     * Get teams with double gameweeks
     */
    public function getDoubleGameweekTeams(int $gameweek): array
    {
        $counts = $this->getFixtureCounts([$gameweek]);
        $dgwTeams = [];

        if (isset($counts[$gameweek])) {
            foreach ($counts[$gameweek] as $teamId => $count) {
                if ($count >= 2) {
                    $dgwTeams[] = $teamId;
                }
            }
        }

        return $dgwTeams;
    }

    /**
     * Get teams with blank gameweeks (no fixtures)
     */
    public function getBlankGameweekTeams(int $gameweek): array
    {
        // Get all teams
        $allTeams = $this->db->fetchAll("SELECT id FROM clubs");
        $allTeamIds = array_column($allTeams, 'id');

        // Get teams with fixtures this gameweek
        $teamsWithFixtures = $this->db->fetchAll(
            "SELECT DISTINCT home_club_id as team_id FROM fixtures WHERE gameweek = ?
             UNION
             SELECT DISTINCT away_club_id as team_id FROM fixtures WHERE gameweek = ?",
            [$gameweek, $gameweek]
        );
        $fixtureTeamIds = array_column($teamsWithFixtures, 'team_id');

        // Return teams without fixtures
        return array_values(array_diff($allTeamIds, $fixtureTeamIds));
    }

    /**
     * Get DGW teams for multiple gameweeks in a single query batch.
     * @param int[] $gameweeks
     * @return array<int, int[]> Map of gameweek => team IDs with 2+ fixtures
     */
    public function getMultipleDoubleGameweekTeams(array $gameweeks, ?array $precomputedCounts = null): array
    {
        $counts = $precomputedCounts ?? $this->getFixtureCounts($gameweeks);
        $result = [];
        foreach ($counts as $gw => $teams) {
            $dgw = array_keys(array_filter($teams, fn($count) => $count >= 2));
            if (!empty($dgw)) {
                $result[$gw] = $dgw;
            }
        }
        return $result;
    }

    /**
     * Get BGW teams for multiple gameweeks in a single query batch.
     * @param int[] $gameweeks
     * @return array<int, int[]> Map of gameweek => team IDs with no fixtures
     */
    public function getMultipleBlankGameweekTeams(array $gameweeks, ?array $precomputedCounts = null): array
    {
        if (empty($gameweeks)) {
            return [];
        }

        $allTeams = $this->db->fetchAll("SELECT id FROM clubs");
        $allTeamIds = array_map(fn($t) => (int) $t['id'], $allTeams);

        $counts = $precomputedCounts ?? $this->getFixtureCounts($gameweeks);
        $result = [];
        foreach ($gameweeks as $gw) {
            $teamsWithFixtures = array_keys($counts[$gw] ?? []);
            $blank = array_values(array_diff($allTeamIds, $teamsWithFixtures));
            if (!empty($blank)) {
                $result[$gw] = $blank;
            }
        }
        return $result;
    }
}
