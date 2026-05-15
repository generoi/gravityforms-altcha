// Entry-point override so the only file shipped to the browser is the
// ALTCHA widget bundle. wp-scripts' defaults expect `src/index.js`, but the
// rest of this plugin is PHP-only.
const defaults = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
  ...defaults,
  entry: {
    widget: path.resolve(__dirname, 'resources/scripts/widget.js'),
  },
  output: {
    ...defaults.output,
    path: path.resolve(__dirname, 'build'),
  },
};
