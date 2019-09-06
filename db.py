from pymongo import MongoClient
from datetime import date
from logging import Logger

class Db:
    def __init__(self, logger : Logger):
        self.logger : Logger = logger
        self.client : MongoClient = self.connect()
        self.db = self.client.fpl

    def connect(self):
        self.logger.debug('testing the logging in the DB')
        try:
            client = MongoClient()
        except Exception as e:
            self.logger.error(e)
        
        return client

    def get_min_and_max_value(self):
        cursor = self.db.players.aggregate([
            { "$match": {"date_generated": self.get_date()} },
            { 
                "$group": {
                    "_id": {}, 
                    "min_value": { "$min": "$now_cost" },
                    "max_value": { "$max": "$now_cost" } 
                }
            }
        ])

        return list(cursor)[0]


    def get_teams_from_db(self):
        cursor = self.db.teams.find({"date_generated": self.get_date()})
        teams = []

        for team in cursor:
            teams.append(team)

        if len(teams) == 0:
            return False

        return teams
            
    def get_players_from_db(
        self, 
        min_price: float = None, 
        max_price: float = None, 
        min_minutes_played: int = None, 
        positions: list = None,
        max_ownership: float = None
    ):
        search_query = {"date_generated": self.get_date()}

        if min_price is not None or max_price is not None:
            search_query['now_cost'] = {}

            if min_price is not None:
                search_query['now_cost']['$gte'] = float(min_price)

            if max_price is not None:
                search_query['now_cost']['$lte'] = float(max_price)

        if min_minutes_played is not None:
            search_query['minutes'] = {"$gte": int(min_minutes_played)}

        if positions is not None and len(positions) > 0:
            search_query['$or'] = []
            for position in positions:
                search_query['$or'].append({'position': position.upper()})

        if max_ownership is not None:
            search_query['selected_by_percent'] = {'$lte': float(max_ownership)}
        
        print(search_query)
        cursor = self.db.players.find(search_query)

        players = []

        for player in cursor:
            players.append(player)
    
        if len(players) == 0:
            return False

        return players
        
    def get_fixtures_from_db(self):
        cursor = self.db.fixtures.find({"date_generated": self.get_date()})
        fixtures = []

        for fixture in cursor:
            fixtures.append(fixture)

        if len(fixtures) == 0:
            return False

        return fixtures

    def insert_teams_to_db(self, teams):
        for team in teams:
            team['_id'] = self.generate_uid(team['id'])
            team['date_generated'] = self.get_date()
            self.db.teams.save(team)


    def insert_fixtures_to_db(self, fixtures):
        for fixture in fixtures:
            fixture['_id'] = self.generate_uid(fixture['id'])
            fixture['date_generated'] = self.get_date()
            self.db.fixtures.save(fixture)

    def insert_players_to_db(self, players):
        for player in players:
            # Generate our UID (used to track changes)
            player['_id'] = self.generate_uid(player['id'])
            player['date_generated'] = self.get_date()
            self.db.players.save(player)

    def generate_uid(self, id) -> str:
        # use today's date in our unique ID:
        return str(id) + '_' + self.get_date()

    # Helper method to get today's date as a string
    def get_date(self) -> str:
        return date.today().strftime("%d%m%Y")

    def check_record_exists(self, player_id):
        uid = self.generate_uid(player_id)
        return self.db.players.find_one({"_id": uid}, {"_id": 1}).limit(1)
