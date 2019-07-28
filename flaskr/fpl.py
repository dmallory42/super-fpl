from unidecode import unidecode
from flask import (
    Flask, flash, g, redirect, render_template, request, session, url_for, jsonify, current_app
)

from .api import Api
from . import create_app

app = create_app()
api = Api()

# Redis logic, commented out for now
# api = Api(app.extensions['redis'])



@app.route('/', methods=['GET'])
def home():
    players = api.get_players()
    players = sorted(players, key=lambda k: unidecode(k['name']))
    return render_template('fpl/home.html', players=players)


@app.route('/players', methods=['GET'])
def get_players():
    players = api.get_players()
    return jsonify(players)
