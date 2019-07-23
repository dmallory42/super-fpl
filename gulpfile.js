const { series, parallel, src, dest } = require('gulp');
const sass = require('gulp-sass');

sass.compiler = require('node-sass');

function bulma(cb) {
    return src('node_modules/bulma/css/*.css')
        .pipe(dest('flaskr/static/css'));
}

function jquery(cb) {
    return src('node_modules/jquery/dist/jquery.js')
        .pipe(dest('flaskr/static/js'));
}

function underscore(cb) {
    return src('node_modules/underscore/underscore.js')
        .pipe(dest('flaskr/static/js'));
}

function chartjs(cb) {
    return src('node_modules/chart.js/dist/Chart.js')
        .pipe(dest('flaskr/static/js'));
}

function chartjsCss(cb) {
    return src('node_modules/chart.js/dist/Chart.css')
        .pipe(dest('flaskr/static/css'));
}

function compileCss(cb) {
    return src('sass/**/*.scss')
        .pipe(sass().on('error', sass.logError))
        .pipe(dest('flaskr/static/css'));
}

exports.default = series(
    parallel(bulma, jquery, underscore, chartjs, chartjsCss, compileCss)     // compile static assets
);