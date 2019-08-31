from datetime import date

class Utils:
    POSITION_LOOKUP = {
        1: 'GKP',
        2: 'DEF',
        3: 'MID',
        4: 'FWD'
    }

    def format_player(self, player):
        player['name'] = player['first_name'] + ' ' + player['second_name']
        player['now_cost'] = player['now_cost'] / 10
        player['goal_involvements'] = player['goals_scored'] + player['assists'] 
        player['position'] = self.POSITION_LOOKUP[player['element_type']]
        player = self.prepare_analysis_fields(player)

        return player

    def prepare_analysis_fields(self, player):
        player['bps_per_min']         = self.calc_per_min(player['bps'], player['minutes'])
        player['clean_sheets_per_90'] = self.calc_per_90(player['clean_sheets'], player['minutes'])
        player['goals_conc_per_90']   = self.calc_per_90(player['goals_conceded'], player['minutes'])
        player['goals_scored_per_90'] = self.calc_per_90(player['goals_scored'], player['minutes'])
        player['assists_per_90']      = self.calc_per_90(player['assists'], player['minutes'])
        player['creativity_per_min']  = self.calc_per_min(float(player['creativity']), player['minutes'])
        player['threat_per_min']      = self.calc_per_min(float(player['threat']), player['minutes'])
        player['influence_per_min']   = self.calc_per_min(float(player['influence']), player['minutes'])
        player['points_per_min']      = self.calc_per_min(player['total_points'], player['minutes'])
        player['points_per_90']       = self.calc_per_90(player['total_points'], player['minutes'])
        player['points_per_mil']      = self.calc_per_mil(player['total_points'], player['now_cost'])
        player['saves_per_90']        = self.calc_per_90(player['saves'], player['minutes'])
        return player

    def cacl_per_match(self, metric, matches):
        if matches == 0:
            return 0

        return round((metric / matches), 2)

    def calc_per_min(self, metric, minutes):
        if minutes == 0:
            return 0

        return round((metric / minutes), 2)

    def calc_per_mil(self, metric, price):
        return round((metric / price), 2)

    def calc_per_90(self, metric, minutes):
        if minutes == 0:
            return 0

        return round((metric / minutes) * 90, 2)
    