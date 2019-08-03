FROM nikolaik/python-nodejs:latest

COPY . /app

WORKDIR /app

RUN yarn install
RUN pip install -r requirements.txt

EXPOSE 8000

CMD ["gunicorn", "-b", "0.0.0.0:8000", "app"]