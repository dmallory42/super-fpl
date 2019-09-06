import os

from unidecode import unidecode
from flask import (
    Flask, flash, g, redirect, render_template, request, session, url_for, jsonify, current_app
)
from flask.logging import create_logger

from api import Api
from datetime import date

import logging

def create_app(test_config=None):
    # create and configure the app
    app = Flask(__name__, instance_relative_config=True)
    app.config.from_mapping(
        SECRET_KEY='dev',
        DATABASE=os.path.join(app.instance_path, 'flaskr.sqlite'),
        REDIS_HOST='localhost',
        REDIS_PORT=6379,
        REDIS_DB=0
    )

    if test_config is None:
        # load the instance config, if it exists, when not testing
        app.config.from_pyfile('config.py', silent=True)
    else:
        # load the test config if passed in
        app.config.from_mapping(test_config)

    # Configure logging:
    logging.basicConfig(
        filename='logs/' + date.today().strftime('%d-%m-%Y') + '.log', 
        level=logging.WARNING,
        format='%(asctime)s %(levelname)s %(name)s %(threadName)s : %(message)s'
    )

    # ensure the instance folder exists
    try:
        os.makedirs(app.instance_path)
    except OSError:
        pass

    return app

app = create_app()  # Flask
logger = create_logger(app)
api = Api(logger)

@app.route('/', methods=['GET'])
def home():
    players = api.get_players()
    players = sorted(players, key=lambda k: unidecode(k['name']))

    valid_players = []
    
    for player in players:
        if player['minutes'] > 0:
            valid_players.append(player)

    return render_template('fpl/home.html', title='Super FPL - Player Comparison Tool', players=valid_players)


@app.route('/player_stats', methods=['GET'])
def get_player_stats():
    # Get the players (make sure we have players in our mongodb):
    players = api.get_players()

    # get our teams and then format them:
    teams = api.get_teams()
    teams_formatted = {}
    for team in teams:
        teams_formatted[team['id']] = team

    value_range = api.get_value_range()
    return render_template('fpl/player_stats.html', title='Super FPL - Player Stats Database', teams=teams_formatted, value_range=value_range)


@app.route('/ajax/players', methods=['GET'])
def get_ajax_players():
    players = api.get_players()
    return jsonify(players)


@app.route('/ajax/player_stats', methods=['GET'])
def get_ajax_player_stats():
    positions = get_parsed_positions(request.args)
    min_price = request.args.get('minPrice', 0)
    max_price = request.args.get('maxPrice', 15)
    min_minutes_played = request.args.get('minsPlayed', 0)
    max_ownership = request.args.get('maxOwnership', 100)

    players = api.get_players(
        min_price, 
        max_price, 
        min_minutes_played, 
        positions, 
        max_ownership)

    if players is None:
        return jsonify({"data": []})
    
    players = sorted(players, key=lambda k: unidecode(k['name']))

    fixtures = api.get_fixtures()
    team_fixtures = {}

    for fixture in fixtures:
        home_team_id = fixture['team_h']
        away_team_id = fixture['team_a']

        if home_team_id not in team_fixtures:
            team_fixtures[home_team_id] = []

        if away_team_id not in team_fixtures:
            team_fixtures[away_team_id] = []

        team_fixtures[home_team_id].append(fixture)
        team_fixtures[away_team_id].append(fixture)

    # get our teams and then format them:
    teams = api.get_teams()
    teams_formatted = {}
    for team in teams:
        code = team['code']
        team['fixtures'] = team_fixtures[team['id']]
        teams_formatted[code] = team

    for player in players:
        player['team'] = teams_formatted[player['team_code']]

    # Return in a format readable by DataTables
    return jsonify({"data": players})

def get_parsed_positions(request_args):
    positions = []

    if request_args.get('positions[gkp]') is not None:
        positions.append('gkp')

    if request_args.get('positions[def]') is not None:
        positions.append('def')

    if request_args.get('positions[mid]') is not None:
        positions.append('mid')

    if request_args.get('positions[fwd]') is not None:
        positions.append('fwd')

    return positions
    
if __name__ == "__main__":
    app.run(host="0.0.0.0", port=8000)
