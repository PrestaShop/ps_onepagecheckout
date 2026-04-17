/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 */

const path = require('path');
const TerserPlugin = require('terser-webpack-plugin');

module.exports = {
  entry: {
    'opc-submit': './js/opc-submit.js',
    'opc-guest-init': './js/opc-guest-init.js',
    'opc-address': './js/opc-address.js',
    'opc-address-modal': './js/opc-address-modal.js',
    'opc-carrier-list': './js/opc-carrier-list.js',
    'opc-carrier-select': './js/opc-carrier-select.js',
    'opc-payment-list': './js/opc-payment-list.js',
    'opc-payment-select': './js/opc-payment-select.js',
  },
  output: {
    path: path.resolve(__dirname, 'public'),
    filename: '[name].bundle.js',
  },
  externals: {
    jquery: 'jQuery',
    prestashop: 'prestashop',
  },
  module: {
    rules: [
      {
        test: /\.js$/,
        exclude: /node_modules/,
        use: {
          loader: 'babel-loader',
          options: {
            presets: ['@babel/preset-env'],
          },
        },
      },
    ],
  },
  optimization: {
    minimize: process.env.NODE_ENV === 'production',
    minimizer: [new TerserPlugin()],
  },
};
