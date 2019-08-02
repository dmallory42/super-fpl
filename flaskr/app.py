from unidecode import unidecode
from flask import (
    Flask, flash, g, redirect, render_template, request, session, url_for, jsonify, current_app
)

from .api import Api
from . import create_app

app = create_app()
api = Api()

@app.route('/', methods=['GET'])
def home():
    players = api.get_players()
    players = sorted(players, key=lambda k: unidecode(k['name']))

    valid_players = []
    
    for player in players:
        if player['minutes'] > 0:
            valid_players.append(player)

    return render_template('fpl/home.html', title='Super FPL - Player Comparison Tool', players=valid_players)


@app.route('/players', methods=['GET'])
def get_players():
    players = api.get_players()
    return jsonify(players)

if __name__ == "__main__":
    app.run(debug=True)