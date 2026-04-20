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
        // [hash] is deprecated in webpack 5 — use [chunkhash] for chunk-level cache busting.
        chunkFilename: 'chunks/[name]-[chunkhash].js',
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
    // Nextcloud apps bundle @nextcloud/vue + Vue runtime together, so their
    // output legitimately exceeds webpack's default 244 KiB hint threshold.
    // Raise the threshold to 6 MiB so the warning only fires if something
    // genuinely unexpected inflates the bundle.
    performance: {
        maxAssetSize:      6 * 1024 * 1024,
        maxEntrypointSize: 6 * 1024 * 1024,
    },
    mode: 'production',
    devtool: 'source-map',
}
