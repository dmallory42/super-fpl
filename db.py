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

    def get_teams_from_db(self):
        today = date.today()

        cursor = self.db.teams.find({"date_generated": today.strftime("%d%m%Y")})
        teams = []

        for team in cursor:
            teams.append(team)

        if len(teams) == 0:
            return False

        return teams
            
    def get_players_from_db(self):
        today = date.today()

        cursor = self.db.players.find({"date_generated": today.strftime("%d%m%Y")})    
        players = []

        for player in cursor:
            players.append(player)
    
        if len(players) == 0:
            return False

        return players
        
    def get_fixtures_from_db(self):
        today = date.today()

        cursor = self.db.fixtures.find({"date_generated": today.strftime("%d%m%Y")})
        fixtures = []

        for fixture in cursor:
            fixtures.append(fixture)

        if len(fixtures) == 0:
            return False

        return fixtures

    def insert_teams_to_db(self, teams):
        for team in teams:
            team['_id'] = self.generate_uid(team['id'])
            team['date_generated'] = date.today().strftime('%d%m%Y')
            self.db.teams.save(team)


    def insert_fixtures_to_db(self, fixtures):
        for fixture in fixtures:
            fixture['_id'] = self.generate_uid(fixture['id'])
            fixture['date_generated'] = date.today().strftime('%d%m%Y')
            self.db.fixtures.save(fixture)

    def insert_players_to_db(self, players):
        for player in players:
            # Generate our UID (used to track changes)
            player['_id'] = self.generate_uid(player['id'])
            player['date_generated'] = date.today().strftime('%d%m%Y')
            self.db.players.save(player)

    def generate_uid(self, id):
        # use todays date in our unique ID:
        today = date.today()
        return str(id) + '_' + today.strftime("%d%m%Y")

    def check_record_exists(self, player_id):
        uid = self.generate_uid(player_id)
        return self.db.players.find_one({"_id": uid}, {"_id": 1}).limit(1)