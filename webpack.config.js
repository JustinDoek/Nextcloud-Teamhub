const path = require('path')
const { VueLoaderPlugin } = require('vue-loader')

module.exports = {
    entry: {
        teamhub: path.join(__dirname, 'src', 'main.js'),
        admin:   path.join(__dirname, 'src', 'admin.js'),
    },
    output: {
        path: path.join(__dirname, 'js'),
        filename: '[name].js',
        chunkFilename: 'chunks/[name]-[hash].js',
    },
    module: {
        rules: [
            { test: /\.vue$/, loader: 'vue-loader' },
            {
                test: /\.js$/,
                loader: 'babel-loader',
                exclude: /node_modules/,
                options: { presets: ['@babel/preset-env'] },
            },
            { test: /\.css$/, use: ['vue-style-loader', 'css-loader'] },
            { test: /\.scss$/, use: ['vue-style-loader', 'css-loader', 'sass-loader'] },
        ],
    },
    plugins: [new VueLoaderPlugin()],
    resolve: {
        extensions: ['.js', '.vue'],
        alias: { vue$: 'vue/dist/vue.esm.js' },
        fallback: { path: false, string_decoder: false },
    },
    mode: 'production',
    devtool: 'source-map',
}
