const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
    ...defaultConfig,
    entry: {
        picker: path.resolve(__dirname, 'assets/src/picker/index.js'),
        settings: path.resolve(__dirname, 'assets/src/settings/index.js'),
        'editors/gutenberg': path.resolve(__dirname, 'assets/src/editors/gutenberg.js'),
        'editors/elementor': path.resolve(__dirname, 'assets/src/editors/elementor.js'),
    },
    output: {
        ...defaultConfig.output,
        path: path.resolve(__dirname, 'assets/build'),
        filename: '[name].js',
    },
};
