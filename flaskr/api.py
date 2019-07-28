from . import json_interface
from .utils import Utils

import urllib.request
import json


class Api:

    # Constants: the various routes of interest
    API_URL = 'https://fantasy.premierleague.com/api'
    ALL_DATA = '/bootstrap-static'

    utils = Utils()

    def get_players(self):
        players = json_interface.get_json('players-2018')

        if players is not False:
            return players
        else:
            with urllib.request.urlopen(self.API_URL + self.ALL_DATA) as url:
                data = json.loads(url.read().decode())
                if 'elements' in data:
                    # Format our players to add additional metrics we may be interested in:
                    players = self.utils.format_players(data['elements'])

                    # Write the data to a JSON file within our project
                    json_interface.write_json('players-2018', data['elements'])

                    # Return the data to the frontend:
                    return data['elements']
                else:
                    return False

    # Redis logic. May re-use in future
    # def get_key(self, key):
    #     val = self.redis.get(key)
    #     try:
    #         parsed = json.loads(val)
    #         return parsed
    #     except ValueError:
    #         # If this is not a JSON object, then just return as is:
    #         return val

    # def set_key(self, key, val):
    #     if isinstance(val, dict):
    #         self.redis.set(key, json.dumps(val))
    #     else:
    #         self.redis.set(key, val)

    # def del_key(self, key):
    #     self.redis.delete(key)
