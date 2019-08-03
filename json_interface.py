import json
import os.path

JSON_PATH = 'json/'

def write_json(file_name, data):
    file_path = format_filepath(file_name)

    with open(file_path, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, indent=4)

def get_json(file_name):
    file_path = format_filepath(file_name)
    
    if os.path.exists(file_path) is False:
        return False

    with open(file_path) as f:
        return json.load(f)

def format_filepath(file_name):
    if file_name.endswith('.json') is not True:
        file_name = file_name + '.json'
    
    return JSON_PATH + file_name